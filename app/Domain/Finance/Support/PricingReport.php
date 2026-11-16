<?php

declare(strict_types=1);

namespace App\Domain\Finance\Support;

use App\Domain\Catalog\Models\Product;
use App\Domain\Finance\Models\ChannelChargeRule;
use App\Domain\Sync\Models\Listing;
use App\Support\Money\Money;

/**
 * Fiyat ve kâr — bir bağlantının CANLI ilanlarında adet başı katkı,
 * zararsız fiyat ve hedef kâr için önerilen fiyat. SALT OKUNUR: kanala
 * hiçbir şey gönderilmez, fiyat değiştirilmez.
 *
 * Kanal fiyatı `Listing::sourceEffectivePrice()`'tan gelir (kampanya ve
 * kanal fiyat kuralı uygulanmış, kur çevirisinden ÖNCE) — zarar korumasının
 * baktığı fiyatla aynı. Komisyon/kargo/paketleme kesinti varsayılanlarından
 * (`ChannelChargeRule`), maliyet varyantın bugünkü alış maliyetinden.
 */
final class PricingReport
{
    public const MAX_LISTINGS = 2000;

    public const STATUS_LOSS = 'loss';

    public const STATUS_BELOW_TARGET = 'below_target';

    public const STATUS_OK = 'ok';

    public const STATUS_UNKNOWN = 'unknown';

    /** @return array<string, mixed> */
    public function build(string $connectionId, ?string $targetPercent): array
    {
        $rules = ChannelChargeRule::query()->where('channel_connection_id', $connectionId)->get()->keyBy('internal_category_id');
        $default = $rules->get(ChannelChargeRule::DEFAULT_CATEGORY);
        $perOrder = Money::add((string) ($default?->shipping_fee ?? '0'), (string) ($default?->packaging_fee ?? '0'));

        $query = Listing::query()->live()->where('channel_connection_id', $connectionId);
        $total = (clone $query)->count();
        $listings = $query->with('variant')->orderBy('id')->limit(self::MAX_LISTINGS)->get();

        $products = Product::query()
            ->whereIn('id', $listings->pluck('variant.product_id')->filter()->unique()->values())
            ->get(['id', 'title', 'internal_category_id'])
            ->keyBy('id');

        $rows = [];
        $counts = array_fill_keys([self::STATUS_LOSS, self::STATUS_BELOW_TARGET, self::STATUS_OK, self::STATUS_UNKNOWN], 0);

        foreach ($listings as $listing) {
            $variant = $listing->variant;
            $product = $variant === null ? null : $products->get($variant->product_id);
            $category = $product?->internal_category_id;
            $rate = ($category !== null && $category !== '' ? $rules->get($category)?->commission_percent : null) ?? $default?->commission_percent;
            $price = $listing->sourceEffectivePrice();
            $cost = $variant?->cost_price === null ? null : (string) $variant->cost_price;

            $econ = UnitEconomics::of($price, $cost, $rate === null ? null : (string) $rate, $perOrder, $targetPercent);
            $status = $this->status($econ, $price, $targetPercent);
            $counts[$status]++;

            $rows[] = [
                'id' => $listing->id,
                'productId' => $variant?->product_id,
                'title' => $product?->title,
                'sku' => $variant?->sku,
                'price' => $price,
                'currency' => $listing->sourceCurrency() ?? $variant?->currency,
                'cost' => $cost,
                'commissionPercent' => $rate === null ? null : (string) $rate,
                'perOrder' => $perOrder,
                'status' => $status,
                'missing' => array_values(array_filter([
                    $cost === null ? 'cost' : null,
                    $rate === null ? 'commission' : null,
                    $price === null ? 'price' : null,
                ])),
            ] + $econ;
        }

        // Önce zararına, sonra hedef altı, sonra hesaplanamayan; içte marja göre.
        $order = [self::STATUS_LOSS => 0, self::STATUS_BELOW_TARGET => 1, self::STATUS_UNKNOWN => 2, self::STATUS_OK => 3];
        usort($rows, fn (array $a, array $b): int => [$order[$a['status']], $a['margin'] ?? 0] <=> [$order[$b['status']], $b['margin'] ?? 0]);

        return [
            'rows' => $rows,
            'counts' => $counts,
            'total' => $total,
            'truncated' => $total > self::MAX_LISTINGS,
            'rule' => [
                'commissionPercent' => $default?->commission_percent === null ? null : (string) $default->commission_percent,
                'shippingFee' => $default?->shipping_fee === null ? null : (string) $default->shipping_fee,
                'packagingFee' => $default?->packaging_fee === null ? null : (string) $default->packaging_fee,
                'categoryExceptions' => $rules->count() - ($default === null ? 0 : 1),
            ],
        ];
    }

    /** @param array{contribution: ?string, margin: ?float} $econ */
    private function status(array $econ, ?string $price, ?string $targetPercent): string
    {
        if ($econ['contribution'] === null || $price === null) {
            return self::STATUS_UNKNOWN;
        }

        if (Money::decimal($econ['contribution'])->isNegative()) {
            return self::STATUS_LOSS;
        }

        if ($targetPercent !== null && $econ['margin'] !== null && $econ['margin'] < (float) $targetPercent) {
            return self::STATUS_BELOW_TARGET;
        }

        return self::STATUS_OK;
    }
}
