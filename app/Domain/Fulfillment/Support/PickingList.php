<?php

declare(strict_types=1);

namespace App\Domain\Fulfillment\Support;

use App\Domain\Catalog\Models\VariantOption;
use App\Domain\Fulfillment\Enums\FulfillmentReason;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\OrderLine;
use Illuminate\Support\Collection;

/**
 * Seçilen siparişlerin depo TOPLAMA LİSTESİ — iki görünüm:
 *
 *   A. toplama özeti: VARYANT başına toplam adet (rafa bir kez gidilir)
 *   B. paketleme dökümü: sipariş/paket başına kalemler
 *
 * VARYANT DOĞRULUĞU: anahtar varyanttır, ürün DEĞİL. Çok varyantlı ürün
 * "Tişört × 3" diye basılmaz; her satırda seçenekler ("Renk: Kırmızı ·
 * Beden: M"), SKU ve barkod yazılır. Katalogda eşlenmemiş kalem kanalın
 * SKU'suyla ayrı satır olur ve `VARIANT_UNMATCHED` ile işaretlenir —
 * tahminle bir varyanta yazılmaz.
 *
 * Adet = sipariş − iptal − iade (`effectiveQuantity`). Çok depo yok.
 */
final class PickingList
{
    /**
     * @param  Collection<int, Order>  $orders  kiracı scope'unda çözülmüş
     * @return array{summary: list<array<string, mixed>>, orders: list<array<string, mixed>>, totalUnits: int}
     */
    public function build(Collection $orders): array
    {
        $orders->loadMissing(['lines.variant.product:id,title', 'connection:id,channel_type_code,label']);

        $variantIds = $orders->flatMap(fn (Order $o) => $o->lines->pluck('variant_id'))->filter()->unique()->values()->all();
        $options = $this->optionsFor($variantIds);

        $summary = [];
        $perOrder = [];
        $total = 0;

        foreach ($orders as $order) {
            $items = [];

            foreach ($order->lines as $line) {
                $qty = $line->effectiveQuantity();

                if ($qty <= 0) {
                    continue;
                }

                $item = $this->describe($line, $options);
                $item['quantity'] = $qty;
                $items[] = $item;
                $total += $qty;

                $key = $line->variant_id ?? 'sku:'.$item['sku'];
                $summary[$key] ??= [...$item, 'quantity' => 0, 'orders' => 0];
                $summary[$key]['quantity'] += $qty;
                $summary[$key]['orders']++;
            }

            $perOrder[] = [
                'orderId' => $order->id,
                'number' => $order->external_number,
                'package' => $order->external_id,
                'channel' => $order->connection?->channel_type_code,
                'lines' => $items,
                'units' => array_sum(array_column($items, 'quantity')),
            ];
        }

        $summary = array_values($summary);
        usort($summary, static fn (array $a, array $b): int => [$a['sku'] ?? '', $a['options']] <=> [$b['sku'] ?? '', $b['options']]);

        return ['summary' => $summary, 'orders' => $perOrder, 'totalUnits' => $total];
    }

    /**
     * @param  array<string, string>  $options
     * @return array<string, mixed>
     */
    private function describe(OrderLine $line, array $options): array
    {
        $variant = $line->variant;

        return [
            'variantId' => $line->variant_id,
            'product' => $variant?->product?->title ?? $line->title,
            'options' => $line->variant_id === null ? null : ($options[$line->variant_id] ?? null),
            'sku' => $variant?->sku ?? $line->sku,
            'barcode' => $variant?->barcode,
            'reason' => $variant === null ? FulfillmentReason::VARIANT_UNMATCHED->value : null,
        ];
    }

    /**
     * Varyant → "Renk: Kırmızı · Beden: M" (seçenek sırasıyla).
     *
     * @param  list<string>  $variantIds
     * @return array<string, string>
     */
    private function optionsFor(array $variantIds): array
    {
        if ($variantIds === []) {
            return [];
        }

        $rows = VariantOption::query()
            ->whereIn('variant_id', $variantIds)
            ->with(['definition:id,name,position', 'value:id,value'])
            ->get()
            ->sortBy(fn (VariantOption $o): array => [$o->definition?->position ?? 0, $o->definition?->name ?? ''])
            ->groupBy('variant_id');

        return $rows->map(fn (Collection $opts): string => $opts
            ->map(fn (VariantOption $o): string => trim(($o->definition?->name ?? '').': '.($o->value?->value ?? '')))
            ->implode(' · '))
            ->all();
    }
}
