<?php

declare(strict_types=1);

namespace App\Domain\Fulfillment\Actions;

use App\Domain\Channels\Contracts\SupportsCommonLabel;
use App\Domain\Fulfillment\Enums\FulfillmentReason;
use App\Domain\Fulfillment\Jobs\PrepareShippingLabel;
use App\Domain\Fulfillment\Models\ShippingLabel;
use App\Domain\Fulfillment\Support\FulfillmentPolicy;
use App\Domain\Fulfillment\Support\ItemDecision;
use App\Domain\Orders\Models\Order;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Uid\UuidV7;

/**
 * Siparişe ortak kargo etiketi ister — satır bizde yazılır, kanal işi kuyrukta.
 *
 * İDEMPOTENS: paket başına TEK satır (`shipping_labels_order_unique`).
 *   · satır yok        → açılır, iş kuyruğa
 *   · hazır (ready)    → hiçbir şey gönderilmez, sonuç "başarılı"
 *   · sürüyor          → yeni iş YOK (çift tıklama, iki sekme, ikinci toplu
 *                        işlem); yalnız iş takılmışsa (STALE) yeniden kuyruğa
 *   · başarısız        → satıcının AÇIK yeniden denemesi: satır sıfırlanır,
 *                        iş yeniden kuyruğa
 *
 * Kanala gerçek istek (POST) işin içinde, satır başına bir kez gönderilir
 * (`PrepareShippingLabel`).
 */
final class RequestShippingLabel
{
    /** Bu süreden uzun "sürüyor" kalan satırın işi ölmüş sayılır. */
    public const STALE_MINUTES = 15;

    public function run(Order $order, ?string $actorId = null): ItemDecision
    {
        $order->loadMissing('connection.channelType');

        if (! FulfillmentPolicy::allows($order, FulfillmentPolicy::CREATE_LABEL)) {
            // Etiketi önceden alınmışsa (ör. kargolandı) yeniden basılabilir.
            $existing = ShippingLabel::query()->where('order_id', $order->id)->first();

            if ($existing?->status === ShippingLabel::STATUS_READY && FulfillmentPolicy::allows($order, FulfillmentPolicy::DOWNLOAD_LABEL)) {
                return ItemDecision::succeeded($existing->id);
            }

            return ItemDecision::actionRequired(FulfillmentReason::INVALID_ORDER_STATE);
        }

        $adapterClass = $order->connection?->channelType?->adapter_class ?? null;

        if (! is_string($adapterClass) || ! is_subclass_of($adapterClass, SupportsCommonLabel::class)) {
            return ItemDecision::actionRequired(FulfillmentReason::CHANNEL_UNSUPPORTED);
        }

        $tenantId = TenantContext::idOrFail();

        $inserted = DB::table('shipping_labels')->insertOrIgnore([
            'id' => (string) new UuidV7,
            'tenant_id' => $tenantId,
            'order_id' => $order->id,
            'channel_connection_id' => $order->channel_connection_id,
            'package_external_id' => (string) $order->external_id,
            'status' => ShippingLabel::STATUS_PENDING,
            'box_quantity' => 1,
            'attempts' => 0,
            'requested_by' => $actorId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $label = ShippingLabel::query()->where('order_id', $order->id)->firstOrFail();

        if ($inserted === 1) {
            $this->dispatch($label, $tenantId);

            return ItemDecision::queued($label->id);
        }

        if ($label->status === ShippingLabel::STATUS_READY) {
            return ItemDecision::succeeded($label->id);
        }

        if ($label->isOpen()) {
            if ($label->updated_at !== null && $label->updated_at->lt(now()->subMinutes(self::STALE_MINUTES))) {
                $label->touch();
                $this->dispatch($label, $tenantId);
            }

            return ItemDecision::queued($label->id);
        }

        // Başarısız → açık yeniden deneme. Koşullu güncelleme: iki eşzamanlı
        // yeniden deneme yalnız BİR iş kuyruğa koyar.
        $reset = ShippingLabel::query()
            ->whereKey($label->id)
            ->where('status', ShippingLabel::STATUS_FAILED)
            ->update([
                'status' => ShippingLabel::STATUS_PENDING,
                'reason_code' => null,
                'error_detail' => null,
                'attempts' => 0,
                'updated_at' => now(),
            ]);

        if ($reset === 1) {
            $this->dispatch($label, $tenantId);
        }

        return ItemDecision::queued($label->id);
    }

    private function dispatch(ShippingLabel $label, string $tenantId): void
    {
        PrepareShippingLabel::dispatch($label->id, $tenantId)->onQueue('orders:high')->afterCommit();
    }
}
