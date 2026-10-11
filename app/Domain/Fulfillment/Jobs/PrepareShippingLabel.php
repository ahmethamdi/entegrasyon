<?php

declare(strict_types=1);

namespace App\Domain\Fulfillment\Jobs;

use App\Domain\Channels\Contracts\SupportsCommonLabel;
use App\Domain\Channels\Registry\AdapterRegistry;
use App\Domain\Channels\Support\ChannelRateLimiter;
use App\Domain\Channels\Support\CircuitBreaker;
use App\Domain\Fulfillment\Enums\FulfillmentReason;
use App\Domain\Fulfillment\Exceptions\LabelRejected;
use App\Domain\Fulfillment\Models\ShippingLabel;
use App\Domain\Fulfillment\Support\CommonLabel;
use App\Domain\Fulfillment\Support\FulfillmentPolicy;
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
 * Ortak kargo etiketini kanaldan alır: paketi oku → etiket var mı sor →
 * yoksa BİR KEZ iste → hazır olana kadar sor.
 *
 * POST EN FAZLA BİR KEZ (satır başına): `requested_at` koşullu güncellemeyle
 * İSTEKTEN ÖNCE yazılır; aynı satırın ikinci işi (kuyruk tekrarı, çift
 * kuyruğa alma) bu yarışı kaybeder ve yalnız etiketi sorar. Sonucu belirsiz
 * istek (zaman aşımı, 5xx) yeniden GÖNDERİLMEZ — kanal isteği almış
 * olabilir; etiket sorulur, gelmezse satır `LABEL_NOT_READY` ile düşer ve
 * yeniden istemek satıcının AÇIK kararıdır. Kesin ret (4xx) isteğin
 * kanalda iz bırakmadığını gösterir; `requested_at` geri alınır.
 *
 * ÖNCE SOR: etiket satıcı panelinden ya da önceki belirsiz istekten zaten
 * üretilmiş olabilir — varsa hiç istek gönderilmez.
 *
 * İskelet `IssueInvoice` ile aynı: devre kesici + hız sınırı kanal isteği
 * için, `RetryPolicy`, 24 saat `retryUntil`, durum satırın kendisinde.
 * Kuyrukta kişisel veri yok — yalnız satır ve kiracı kimliği.
 */
final class PrepareShippingLabel implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public const POLL_SECONDS = 20;

    /** İstekten sonra en çok bu kadar soru (~5 dk); sonra LABEL_NOT_READY. */
    public const MAX_POLLS = 15;

    public function __construct(
        public readonly string $labelId,
        public readonly string $tenantId,
    ) {}

    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours(24);
    }

    public function handle(
        AdapterRegistry $registry,
        ChannelErrorText $errorText,
        ?CircuitBreaker $breaker = null,
        ?ChannelRateLimiter $limiter = null,
    ): void {
        TenantContext::set($this->tenantId);

        try {
            $this->prepare($registry, $errorText, $breaker ?? app(CircuitBreaker::class), $limiter ?? app(ChannelRateLimiter::class));
        } finally {
            TenantContext::clear();
        }
    }

    public function failed(?Throwable $e): void
    {
        TenantContext::set($this->tenantId);

        try {
            ShippingLabel::query()
                ->whereKey($this->labelId)
                ->whereIn('status', [ShippingLabel::STATUS_PENDING, ShippingLabel::STATUS_REQUESTED])
                ->update([
                    'status' => ShippingLabel::STATUS_FAILED,
                    'reason_code' => FulfillmentReason::LABEL_CREATION_FAILED->value,
                    'error_detail' => mb_substr($e?->getMessage() ?? '', 0, 500),
                    'updated_at' => now(),
                ]);
        } finally {
            TenantContext::clear();
        }
    }

    private function prepare(AdapterRegistry $registry, ChannelErrorText $errorText, CircuitBreaker $breaker, ChannelRateLimiter $limiter): void
    {
        $label = ShippingLabel::query()->with('order.connection')->find($this->labelId);

        if ($label === null || ! $label->isOpen()) {
            return;
        }

        $order = $label->order;
        $connection = $order?->connection;

        if ($order === null || $connection === null || $connection->status !== 'active') {
            $this->fail($label, FulfillmentReason::CHANNEL_UNAVAILABLE);

            return;
        }

        if (! $breaker->allows($connection->id)) {
            $this->release(CircuitBreaker::PAUSE_SECONDS);

            return;
        }

        $adapter = $registry->for($connection);

        if (! $adapter instanceof SupportsCommonLabel) {
            $this->fail($label, FulfillmentReason::CHANNEL_UNSUPPORTED);

            return;
        }

        if (! $limiter->attempt($connection->id, $adapter->rateLimitProfile())) {
            $this->release(max($limiter->secondsUntilAvailable($connection->id, $adapter->rateLimitProfile()), 1));

            return;
        }

        try {
            $shipment = $adapter->fetchShipment($order);

            if ($label->requested_at === null) {
                // İstek gitmeden önce kanalın GÜNCEL durumu: veritabanımız
                // geride kalmış olabilir (kanalda iptal edilmiş paket).
                if ($shipment->state !== null && ! FulfillmentPolicy::allowsState($shipment->state, FulfillmentPolicy::CREATE_LABEL)) {
                    $this->fail($label, FulfillmentReason::INVALID_ORDER_STATE);

                    return;
                }

                if ($shipment->trackingNumber === null) {
                    $this->fail($label, FulfillmentReason::TRACKING_NUMBER_MISSING);

                    return;
                }

                if (! $shipment->commonLabelEligible) {
                    $label->forceFill(['cargo_provider' => $shipment->cargoProvider])->save();
                    $this->fail($label, FulfillmentReason::CARGO_PROVIDER_UNSUPPORTED);

                    return;
                }
            }

            $label->forceFill([
                'cargo_tracking_number' => $label->cargo_tracking_number ?? $shipment->trackingNumber,
                'cargo_provider' => $shipment->cargoProvider,
                'box_quantity' => $label->requested_at === null ? $shipment->boxQuantity : $label->box_quantity,
            ])->save();

            $existing = $adapter->fetchCommonLabel($shipment);

            if ($existing !== null) {
                $breaker->recordSuccess($connection->id);
                $this->markReady($label, $existing);

                return;
            }

            if ($label->requested_at === null) {
                $claimed = ShippingLabel::query()
                    ->whereKey($label->id)
                    ->whereNull('requested_at')
                    ->update(['requested_at' => now(), 'status' => ShippingLabel::STATUS_REQUESTED, 'updated_at' => now()]);

                if ($claimed === 1) {
                    try {
                        $adapter->requestCommonLabel($shipment, $label->box_quantity);
                    } catch (LabelRejected $e) {
                        // Kesin ret — istek kanalda iz bırakmadı. Sorguyla: bellekteki
                        // model talebi görmedi, `save()` "değişiklik yok" sayardı.
                        ShippingLabel::query()->whereKey($label->id)->update(['requested_at' => null, 'updated_at' => now()]);

                        throw $e;
                    }
                }

                $breaker->recordSuccess($connection->id);
                $label->forceFill(['status' => ShippingLabel::STATUS_REQUESTED, 'reason_code' => null, 'error_detail' => null])->save();
                $this->release(self::POLL_SECONDS);

                return;
            }

            // İstek gitmiş, etiket yok — sor, sınırda bırak.
            $breaker->recordSuccess($connection->id);
            $label->increment('attempts');

            if ($label->attempts >= self::MAX_POLLS) {
                $this->fail($label, FulfillmentReason::LABEL_NOT_READY);

                return;
            }

            $label->forceFill(['status' => ShippingLabel::STATUS_REQUESTED, 'reason_code' => FulfillmentReason::LABEL_NOT_READY->value])->save();
            $this->release(self::POLL_SECONDS);
        } catch (LabelRejected $e) {
            $this->fail($label, $e->reason, $errorText->redact($connection, $e->getMessage()));
        } catch (Throwable $e) {
            $class = AdapterReportedFailure::classify($e, $adapter);
            $breaker->recordFailure($connection->id, $class);
            $label->refresh();
            $label->increment('attempts');

            $delay = RetryPolicy::delayFor($class, $label->attempts, AdapterReportedFailure::retryAfterOf($e));
            $detail = $errorText->redact($connection, $e->getMessage());

            if ($delay !== null) {
                $label->forceFill(['error_detail' => $detail === null ? null : mb_substr($detail, 0, 500)])->save();
                $this->release($delay);

                return;
            }

            $this->fail($label, FulfillmentReason::LABEL_CREATION_FAILED, $detail);
        }
    }

    private function markReady(ShippingLabel $label, CommonLabel $common): void
    {
        $format = $common->format();
        $payload = $common->toJson();

        $label->forceFill([
            'status' => in_array($format, ['ZPL', 'PDF'], true) ? ShippingLabel::STATUS_READY : ShippingLabel::STATUS_FAILED,
            'reason_code' => in_array($format, ['ZPL', 'PDF'], true) ? null : FulfillmentReason::LABEL_FORMAT_UNSUPPORTED->value,
            // Etiketin ASLI her durumda saklanır — biçim tanınmasa da atılmaz.
            'format' => $format,
            'payload' => $payload,
            'payload_sha256' => hash('sha256', $payload),
            'ready_at' => now(),
            'error_detail' => null,
        ])->save();
    }

    private function fail(ShippingLabel $label, FulfillmentReason $reason, ?string $detail = null): void
    {
        $label->forceFill([
            'status' => ShippingLabel::STATUS_FAILED,
            'reason_code' => $reason->value,
            'error_detail' => $detail === null ? null : mb_substr($detail, 0, 500),
        ])->save();
    }
}
