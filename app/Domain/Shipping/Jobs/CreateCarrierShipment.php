<?php

declare(strict_types=1);

namespace App\Domain\Shipping\Jobs;

use App\Domain\Channels\Contracts\SupportsShippingRecipient;
use App\Domain\Channels\Registry\AdapterRegistry;
use App\Domain\Channels\Support\ChannelRateLimiter;
use App\Domain\Channels\Support\CircuitBreaker;
use App\Domain\Fulfillment\Enums\FulfillmentReason;
use App\Domain\Shipping\Exceptions\CarrierException;
use App\Domain\Shipping\Exceptions\RecipientUnavailable;
use App\Domain\Shipping\Models\CarrierAccount;
use App\Domain\Shipping\Models\CarrierShipment;
use App\Domain\Shipping\Support\Carriers;
use App\Domain\Shipping\Support\ShipmentRequest;
use App\Domain\Sync\Enums\ErrorClass;
use App\Domain\Sync\Support\AdapterReportedFailure;
use App\Domain\Sync\Support\ChannelErrorText;
use App\Domain\Sync\Support\RetryPolicy;
use App\Support\Tenancy\TenantContext;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Gönderiyi kargo firmasında açar: alıcıyı kanaldan oku → firmaya gönder →
 * etiketi ve (varsa) takip numarasını sakla.
 *
 * TEKRAR GÜVENLİDİR: anahtar (`cargo_key`) satırla birlikte doğar ve hiç
 * değişmez; aynı anahtarla ikinci gönderim firmada ikinci gönderi AÇMAZ
 * (Yurtiçi 60020 "mevcuttur", Aras aynı kodu günceller — 23 Eki test
 * ortamında ölçüldü). Bu yüzden zaman aşımı/5xx sonrası iş aynı isteği
 * yeniden gönderebilir; etikette olduğu gibi "bir kez gönder" kilidi
 * gerekmez. `create_requested_at` yalnız "firmaya ulaşmış olabilir"
 * bilgisidir — firma değiştirmeyi engeller.
 *
 * ALICI BELLEKTE: kanaldan okunan ad/adres/telefon yalnız bu işin
 * belleğinde yaşar; kuyruğa (yalnız satır kimliği gider), veritabanına ve
 * log'a yazılmaz. Etiket belgesi S2 kuralıyla saklanır.
 *
 * Hata sınıfı → sonuç:
 *   AUTHENTICATION → hesap "koptu", satır CARRIER_AUTH_FAILED
 *   VALIDATION     → CARRIER_REJECTED + firmanın metni (yeniden denenmez)
 *   geçici         → RetryPolicy ile bekle; bütçe biterse CARRIER_UNAVAILABLE
 */
final class CreateCarrierShipment implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $shipmentId,
        public readonly string $tenantId,
    ) {}

    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours(24);
    }

    public function handle(
        AdapterRegistry $registry,
        Carriers $carriers,
        ChannelErrorText $errorText,
        ?CircuitBreaker $breaker = null,
        ?ChannelRateLimiter $limiter = null,
    ): void {
        TenantContext::set($this->tenantId);

        try {
            $this->create($registry, $carriers, $errorText, $breaker ?? app(CircuitBreaker::class), $limiter ?? app(ChannelRateLimiter::class));
        } finally {
            TenantContext::clear();
        }
    }

    public function failed(?Throwable $e): void
    {
        TenantContext::set($this->tenantId);

        try {
            CarrierShipment::query()
                ->whereKey($this->shipmentId)
                ->whereIn('status', [CarrierShipment::STATUS_PENDING, CarrierShipment::STATUS_CREATING])
                ->update([
                    'status' => CarrierShipment::STATUS_FAILED,
                    'reason_code' => FulfillmentReason::CARRIER_UNAVAILABLE->value,
                    'updated_at' => now(),
                ]);
        } finally {
            TenantContext::clear();
        }
    }

    private function create(AdapterRegistry $registry, Carriers $carriers, ChannelErrorText $errorText, CircuitBreaker $breaker, ChannelRateLimiter $limiter): void
    {
        $shipment = CarrierShipment::query()->with('order.connection')->find($this->shipmentId);

        if ($shipment === null || ! $shipment->isOpen()) {
            return;
        }

        $account = CarrierAccount::query()->where('provider', $shipment->provider)->first();

        if ($account === null || ! $account->isUsable()) {
            $this->fail($shipment, FulfillmentReason::CARRIER_ACCOUNT_MISSING);

            return;
        }

        $order = $shipment->order;
        $connection = $order?->connection;

        if ($order === null || $connection === null || $connection->status !== 'active') {
            $this->fail($shipment, FulfillmentReason::CHANNEL_UNAVAILABLE);

            return;
        }

        $adapter = $registry->for($connection);

        if (! $adapter instanceof SupportsShippingRecipient) {
            $this->fail($shipment, FulfillmentReason::CHANNEL_UNSUPPORTED);

            return;
        }

        if (! $breaker->allows($connection->id)) {
            $this->release(CircuitBreaker::PAUSE_SECONDS);

            return;
        }

        if (! $limiter->attempt($connection->id, $adapter->rateLimitProfile())) {
            $this->release(max($limiter->secondsUntilAvailable($connection->id, $adapter->rateLimitProfile()), 1));

            return;
        }

        // 1) Alıcı — kanaldan anlık.
        try {
            $recipient = $adapter->fetchShippingRecipient($order);
            $breaker->recordSuccess($connection->id);
        } catch (RecipientUnavailable $e) {
            $this->fail($shipment, FulfillmentReason::RECIPIENT_UNAVAILABLE, $e->getMessage());

            return;
        } catch (Throwable $e) {
            $class = AdapterReportedFailure::classify($e, $adapter);
            $breaker->recordFailure($connection->id, $class);
            $this->retryOrFail($shipment, $class, $errorText->redact($connection, $e->getMessage()), FulfillmentReason::CHANNEL_UNAVAILABLE);

            return;
        }

        // 2) Firmaya — "ulaşmış olabilir" işareti İSTEKTEN ÖNCE yazılır.
        CarrierShipment::query()
            ->whereKey($shipment->id)
            ->whereIn('status', [CarrierShipment::STATUS_PENDING, CarrierShipment::STATUS_CREATING])
            ->update([
                'status' => CarrierShipment::STATUS_CREATING,
                'create_requested_at' => $shipment->create_requested_at ?? now(),
                'updated_at' => now(),
            ]);

        try {
            $created = $carriers->for($shipment->provider)->create($account, new ShipmentRequest(
                cargoKey: $shipment->cargo_key,
                recipient: $recipient,
                orderNumber: (string) ($order->external_number ?? $order->external_id),
                packageCount: $shipment->package_count,
            ));
        } catch (CarrierException $e) {
            $this->handleCarrierError($shipment, $account, $e);

            return;
        }

        $shipment->forceFill([
            'status' => CarrierShipment::STATUS_CREATED,
            'reason_code' => null,
            'error_detail' => null,
            'tracking_number' => $created->trackingNumber,
            'label_zpl' => $created->labelZpl,
            'label_pdf' => $created->labelPdf,
            'confirmed_at' => now(),
            'attempts' => 0,
        ])->save();
    }

    private function handleCarrierError(CarrierShipment $shipment, CarrierAccount $account, CarrierException $e): void
    {
        $detail = mb_substr($e->getMessage(), 0, 500);

        if ($e->class === ErrorClass::AUTHENTICATION) {
            $account->forceFill(['status' => CarrierAccount::STATUS_REVOKED, 'last_error' => $detail])->save();
            $this->fail($shipment, FulfillmentReason::CARRIER_AUTH_FAILED, $detail);

            return;
        }

        if ($e->class->isPermanent()) {
            $this->fail($shipment, FulfillmentReason::CARRIER_REJECTED, $detail);

            return;
        }

        $this->retryOrFail($shipment, $e->class, $detail, FulfillmentReason::CARRIER_UNAVAILABLE);
    }

    private function retryOrFail(CarrierShipment $shipment, ErrorClass $class, ?string $detail, FulfillmentReason $exhausted): void
    {
        $shipment->refresh();
        $shipment->increment('attempts');
        $delay = RetryPolicy::delayFor($class, $shipment->attempts);

        if ($delay !== null) {
            $shipment->forceFill(['error_detail' => $detail === null ? null : mb_substr($detail, 0, 500)])->save();
            $this->release($delay);

            return;
        }

        $this->fail($shipment, $exhausted, $detail);
    }

    private function fail(CarrierShipment $shipment, FulfillmentReason $reason, ?string $detail = null): void
    {
        $shipment->forceFill([
            'status' => CarrierShipment::STATUS_FAILED,
            'reason_code' => $reason->value,
            'error_detail' => $detail === null ? null : mb_substr($detail, 0, 500),
        ])->save();
    }
}
