<?php

declare(strict_types=1);

namespace App\Domain\Shipping\Actions;

use App\Domain\Channels\Contracts\SupportsShippingRecipient;
use App\Domain\Fulfillment\Enums\FulfillmentReason;
use App\Domain\Fulfillment\Support\FulfillmentPolicy;
use App\Domain\Fulfillment\Support\ItemDecision;
use App\Domain\Orders\Models\Order;
use App\Domain\Shipping\Jobs\CreateCarrierShipment;
use App\Domain\Shipping\Models\CarrierAccount;
use App\Domain\Shipping\Models\CarrierShipment;
use App\Domain\Shipping\Support\Carriers;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Uid\UuidV7;

/**
 * Siparişe satıcının kendi kargo sözleşmesiyle gönderi açar — satır bizde
 * yazılır, firma işi kuyrukta (`RequestShippingLabel` deseni).
 *
 * İDEMPOTENS: sipariş başına TEK satır (`carrier_shipments_order_unique`).
 *   · satır yok             → açılır (yeni anahtar), iş kuyruğa
 *   · firmada açık / kargoda → hiçbir şey gönderilmez, sonuç "başarılı"
 *   · sürüyor               → yeni iş YOK; yalnız iş takılmışsa yeniden kuyruğa
 *   · başarısız             → satıcının AÇIK yeniden denemesi; AYNI anahtarla
 *                             (firmada açılmışsa ikinci gönderi açılmaz)
 *   · iptal edilmiş         → yeni anahtarla yeni gönderi
 *
 * Firma DEĞİŞTİRME: başarısız ama firmaya ulaşmış olabilecek (`create_requested_at`)
 * gönderi başka firmayla yeniden açılmaz — öteki firmada da gönderi
 * olabilirdi. Satıcı önce iptal eder.
 */
final class RequestCarrierShipment
{
    public const STALE_MINUTES = 15;

    public function run(Order $order, string $provider, ?string $actorId = null): ItemDecision
    {
        // Acil kapatma anahtarı toplu işlem yolunu da kapatır.
        if (! config('shipping.enabled')) {
            return ItemDecision::actionRequired(FulfillmentReason::CHANNEL_UNSUPPORTED);
        }

        $order->loadMissing('connection.channelType');
        $existing = CarrierShipment::query()->where('order_id', $order->id)->first();

        if ($existing !== null && in_array($existing->status, [CarrierShipment::STATUS_CREATED, CarrierShipment::STATUS_SHIPPED, CarrierShipment::STATUS_DELIVERED], true)) {
            return ItemDecision::succeeded(shipmentId: $existing->id);
        }

        if (! FulfillmentPolicy::allows($order, FulfillmentPolicy::CREATE_LABEL)) {
            return ItemDecision::actionRequired(FulfillmentReason::INVALID_ORDER_STATE);
        }

        $account = CarrierAccount::query()->where('provider', $provider)->first();

        if ($account === null || ! $account->isUsable()) {
            return ItemDecision::actionRequired(FulfillmentReason::CARRIER_ACCOUNT_MISSING);
        }

        $adapterClass = $order->connection?->channelType?->adapter_class ?? null;

        if (! is_string($adapterClass) || ! is_subclass_of($adapterClass, SupportsShippingRecipient::class)) {
            return ItemDecision::actionRequired(FulfillmentReason::CHANNEL_UNSUPPORTED);
        }

        $tenantId = TenantContext::idOrFail();

        if ($existing === null) {
            $existing = $this->open($order, $account, $tenantId, $actorId);

            if ($existing->wasRecentlyCreated) {
                $this->dispatch($existing, $tenantId);

                return ItemDecision::queued(shipmentId: $existing->id);
            }
        }

        if ($existing->isOpen() || $existing->status === CarrierShipment::STATUS_CANCELLING) {
            if ($existing->isOpen() && $existing->updated_at !== null && $existing->updated_at->lt(now()->subMinutes(self::STALE_MINUTES))) {
                $existing->touch();
                $this->dispatch($existing, $tenantId);
            }

            return ItemDecision::queued(shipmentId: $existing->id);
        }

        if ($existing->status === CarrierShipment::STATUS_FAILED) {
            if ($existing->provider !== $provider && $existing->create_requested_at !== null) {
                return ItemDecision::actionRequired(FulfillmentReason::INVALID_ORDER_STATE);
            }

            // Koşullu: iki eşzamanlı yeniden deneme yalnız BİR iş kuyruğa koyar.
            $reset = CarrierShipment::query()
                ->whereKey($existing->id)
                ->where('status', CarrierShipment::STATUS_FAILED)
                ->update([
                    'status' => CarrierShipment::STATUS_PENDING,
                    // Firmaya hiç ulaşmamışsa firma değiştirilebilir; anahtar aynı kalır.
                    'provider' => $existing->create_requested_at === null ? $provider : $existing->provider,
                    'environment' => $existing->create_requested_at === null ? $account->environment : $existing->environment,
                    'reason_code' => null,
                    'error_detail' => null,
                    'attempts' => 0,
                    'updated_at' => now(),
                ]);

            if ($reset === 1) {
                $this->dispatch($existing, $tenantId);
            }

            return ItemDecision::queued(shipmentId: $existing->id);
        }

        // İptal edilmiş → yeni anahtarla yeniden aç.
        $reopened = CarrierShipment::query()
            ->whereKey($existing->id)
            ->where('status', CarrierShipment::STATUS_CANCELLED)
            ->update([
                ...$this->freshColumns($account),
                'requested_by' => $actorId,
                'updated_at' => now(),
            ]);

        if ($reopened === 1) {
            $this->dispatch($existing, $tenantId);
        }

        return ItemDecision::queued(shipmentId: $existing->id);
    }

    private function open(Order $order, CarrierAccount $account, string $tenantId, ?string $actorId): CarrierShipment
    {
        $inserted = DB::table('carrier_shipments')->insertOrIgnore([
            'id' => (string) new UuidV7,
            'tenant_id' => $tenantId,
            'order_id' => $order->id,
            ...$this->freshColumns($account),
            'requested_by' => $actorId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $row = CarrierShipment::query()->where('order_id', $order->id)->firstOrFail();
        $row->wasRecentlyCreated = $inserted === 1;

        return $row;
    }

    /** @return array<string, mixed> */
    private function freshColumns(CarrierAccount $account): array
    {
        return [
            'provider' => $account->provider,
            'environment' => $account->environment,
            'cargo_key' => Carriers::newCargoKey(),
            'status' => CarrierShipment::STATUS_PENDING,
            'reason_code' => null,
            'error_detail' => null,
            'carrier_status' => null,
            'carrier_status_text' => null,
            'tracking_number' => null,
            'tracking_url' => null,
            'package_count' => 1,
            'label_zpl' => null,
            'label_pdf' => null,
            'attempts' => 0,
            'create_requested_at' => null,
            'confirmed_at' => null,
            'shipped_at' => null,
            'delivered_at' => null,
            'cancel_requested_at' => null,
            'cancelled_at' => null,
            'last_tracked_at' => null,
            'fulfillment_id' => null,
        ];
    }

    private function dispatch(CarrierShipment $shipment, string $tenantId): void
    {
        CreateCarrierShipment::dispatch($shipment->id, $tenantId)->onQueue('orders:high')->afterCommit();
    }
}
