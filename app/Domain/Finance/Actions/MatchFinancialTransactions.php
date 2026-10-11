<?php

declare(strict_types=1);

namespace App\Domain\Finance\Actions;

use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Finance\Models\ChannelFinancialTransaction as Tx;
use App\Domain\Orders\Models\Order;

/**
 * Sipariş düzeyindeki ham işlemleri siparişe bağlar — TAHMİN ETMEDEN.
 *
 * Eşleme ham defterden AYRIDIR: yalnız `order_id`, `match_status`,
 * `match_reason`, `matched_at` yazılır; strateji eklemek ham satıra dokunmaz.
 *
 * STRATEJİLER (sırayla, ilk karar veren kazanır):
 *   1. PAKET: işlemde paket numarası varsa AYNI BAĞLANTIDA
 *      `orders.external_id = paket` (bağlantı içinde tekil —
 *      `orders_external_unique`). Bulunursa ve işlemin sipariş numarası
 *      siparişinkiyle çelişirse → `ambiguous` (iki kimlik ayrı şey söylüyor).
 *      Paket VAR ama sipariş YOKSA → `unmatched`; sipariş numarasına
 *      DÜŞÜLMEZ: aynı siparişin başka paketi ayrı sipariş kaydıdır ve oraya
 *      yazmak yanlış pakete para bağlardı.
 *   2. SİPARİŞ NUMARASI (paket yoksa): bağlantıda `external_number` ile tam
 *      bir sipariş → `matched`; birden çok (bölünmüş paket) → `ambiguous`.
 *
 * Eşleşmeyen işlem kâra girmez, rapordaki "eşleşmeyen" listesinde görünür.
 * Sipariş sonradan gelirse sonraki turda yeniden denenir.
 */
final class MatchFinancialTransactions
{
    /** @return array{matched: int, unmatched: int, ambiguous: int} */
    public function forConnection(ChannelConnection $connection): array
    {
        $counts = ['matched' => 0, 'unmatched' => 0, 'ambiguous' => 0];

        Tx::query()
            ->where('channel_connection_id', $connection->id)
            ->whereIn('match_status', [Tx::UNMATCHED, Tx::AMBIGUOUS])
            // KİMLİĞE GÖRE sayfalama: `each()` ofsetle sayfalar ve döngüde
            // `match_status` değiştikçe satır ATLARDI.
            ->lazyById(200)
            ->each(function (Tx $tx) use ($connection, &$counts): void {
                [$status, $orderId, $reason] = $this->decide($connection, $tx);

                $tx->forceFill([
                    'match_status' => $status,
                    'order_id' => $orderId,
                    'match_reason' => $reason,
                    'matched_at' => $status === Tx::MATCHED ? now() : null,
                ])->save();

                $counts[$status]++;
            });

        return $counts;
    }

    /**
     * @return array{0: string, 1: ?string, 2: string}
     */
    private function decide(ChannelConnection $connection, Tx $tx): array
    {
        foreach ([$this->byPackage(...), $this->byOrderNumber(...)] as $strategy) {
            $decision = $strategy($connection, $tx);

            if ($decision !== null) {
                return $decision;
            }
        }

        return [Tx::UNMATCHED, null, 'paket ve sipariş numarası yok'];
    }

    /** @return array{0: string, 1: ?string, 2: string}|null */
    private function byPackage(ChannelConnection $connection, Tx $tx): ?array
    {
        if ($tx->shipment_package_id === null) {
            return null;
        }

        $order = Order::query()
            ->where('channel_connection_id', $connection->id)
            ->where('external_id', $tx->shipment_package_id)
            ->first(['id', 'external_number']);

        if ($order === null) {
            return [Tx::UNMATCHED, null, "paket {$tx->shipment_package_id} bu bağlantıda sipariş değil"];
        }

        if ($tx->order_number !== null && $order->external_number !== null && $order->external_number !== $tx->order_number) {
            return [Tx::AMBIGUOUS, null, "paket {$tx->shipment_package_id} siparişi {$order->external_number}, işlem {$tx->order_number} diyor"];
        }

        return [Tx::MATCHED, $order->id, 'paket numarası'];
    }

    /** @return array{0: string, 1: ?string, 2: string}|null */
    private function byOrderNumber(ChannelConnection $connection, Tx $tx): ?array
    {
        if ($tx->order_number === null) {
            return null;
        }

        $ids = Order::query()
            ->where('channel_connection_id', $connection->id)
            ->where('external_number', $tx->order_number)
            ->limit(2)
            ->pluck('id');

        return match ($ids->count()) {
            0 => [Tx::UNMATCHED, null, "sipariş {$tx->order_number} bu bağlantıda yok"],
            1 => [Tx::MATCHED, $ids->first(), 'sipariş numarası'],
            default => [Tx::AMBIGUOUS, null, "sipariş {$tx->order_number} birden çok pakette"],
        };
    }
}
