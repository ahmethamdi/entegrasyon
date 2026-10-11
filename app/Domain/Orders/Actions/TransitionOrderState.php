<?php

declare(strict_types=1);

namespace App\Domain\Orders\Actions;

use App\Domain\Orders\Enums\OrderState;
use App\Domain\Orders\Models\Order;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Kanonik sipariş durumunun TEK yazma noktası (ADR-001).
 *
 * GEÇİŞ VERİTABANINDA KOŞULLUDUR: `UPDATE … WHERE state IS NULL OR state IN
 * (izinli kaynaklar)`. İki işçi aynı siparişe sırasız iki durum yazsa da
 * (biri `shipped`, öteki eski `awaiting_shipment`) sonuç sıradan bağımsız
 * aynı çıkar: geri giden yazım satırı hiç bulmaz. Okuyup-karşılaştırıp-yazma
 * yarışa açıktı.
 *
 * Reddedilen geçiş HATA DEĞİLDİR (sırasız olay normaldir); yalnız bilgi
 * günlüğü yazılır. Ham kanal kelimesi `orders.status`'ta zaten iz olarak kalır.
 */
final class TransitionOrderState
{
    /** @return bool geçiş yazıldı mı */
    public function run(Order $order, ?OrderState $to): bool
    {
        if ($to === null) {
            return false;
        }

        $from = array_values(array_filter(
            OrderState::cases(),
            static fn (OrderState $s): bool => OrderState::canTransition($s, $to),
        ));

        $updated = DB::table('orders')
            ->where('tenant_id', TenantContext::idOrFail())
            ->where('id', $order->id)
            ->where(static function ($q) use ($from): void {
                $q->whereNull('state');

                if ($from !== []) {
                    $q->orWhereIn('state', array_map(static fn (OrderState $s): string => $s->value, $from));
                }
            })
            ->update(['state' => $to->value, 'state_changed_at' => now(), 'updated_at' => now()]);

        if ($updated === 0) {
            Log::info('orders.state_transition_rejected', [
                'order' => $order->id,
                'from' => $order->fresh()?->state,
                'to' => $to->value,
            ]);

            return false;
        }

        $order->setRawAttributes([...$order->getAttributes(), 'state' => $to->value], sync: true);

        return true;
    }

    /**
     * İptal/iade sonrası durumu KALEM SAYAÇLARINDAN türetir.
     *
     * Kanal başlığı gecikebilir ya da hiç güncellenmez (Hepsiburada, ikas);
     * sayaçlar ise stok kadar doğrudur:
     *   tüm kalemler tamamen iptal          → cancelled
     *   kalan yok, en az bir iade            → returned
     *   en az bir iade, kalan var            → partially_returned
     */
    public function syncFromLines(Order $order): bool
    {
        $totals = DB::table('order_lines')
            ->where('tenant_id', TenantContext::idOrFail())
            ->where('order_id', $order->id)
            ->selectRaw('coalesce(sum(quantity), 0) AS qty, coalesce(sum(quantity_cancelled), 0) AS cancelled, coalesce(sum(quantity_returned), 0) AS returned')
            ->first();

        $qty = (int) ($totals->qty ?? 0);
        $cancelled = (int) ($totals->cancelled ?? 0);
        $returned = (int) ($totals->returned ?? 0);

        if ($qty <= 0) {
            return false;
        }

        $state = match (true) {
            $cancelled >= $qty => OrderState::CANCELLED,
            $returned > 0 && $cancelled + $returned >= $qty => OrderState::RETURNED,
            $returned > 0 => OrderState::PARTIALLY_RETURNED,
            default => null,
        };

        return $this->run($order, $state);
    }
}
