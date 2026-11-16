<?php

declare(strict_types=1);

namespace App\Domain\Finance\Support;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Variant;
use App\Domain\Orders\Models\Order;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantContext;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Kâr paneli (KDV dahil katkı) — dönem hunisi ve kırılımlar. SALT OKUNUR.
 *
 * Sipariş başına hesap `ContributionCalculator::expectedWithLines()`'tan
 * gelir; burada YENİ bir kâr kuralı yoktur, yalnız toplama vardır. Katkı
 * raporu ile kâr paneli bu yüzden aynı siparişte aynı rakamı söyler.
 *
 *   Brüt satış (Σ satır net, iptal öncesi)
 *   → İptal (satış − brüt)  → İade  → Komisyon  → Kargo  → Diğer  → Maliyet
 *   → Katkı
 *
 * BİLİNMEYEN SIFIR SAYILMAZ: her basamak bilinen siparişlerin toplamıdır
 * ve "kaç siparişte biliniyor" kapsamını yanında taşır. Katkı yalnız TAMAM
 * siparişlerin toplamıdır.
 *
 * Kategori/marka kırılımında satır geliri ve satır maliyeti doğrudandır;
 * sipariş düzeyindeki kalemler (iade, komisyon, kargo, diğer) satıra satış
 * payıyla dağıtılır (satış sıfırsa eşit). Dağıtım kuruş yuvarlaması
 * içerebilir; ekranda dipnot olarak yazılır.
 */
final class ProfitReport
{
    /** Bir dönemde işlenecek en çok sipariş (sunucuyu korur). */
    public const MAX_ORDERS = 5000;

    public const CACHE_TTL_SECONDS = 600;

    /** Huni basamakları (sıra ekrandaki sıradır). */
    public const STEPS = ['gross', 'cancel', 'refund', 'commission', 'shipping', 'other', 'cost'];

    private const ORDER_LEVEL = ['refund', 'commission', 'shipping', 'other'];

    public function __construct(private readonly ContributionCalculator $calculator) {}

    /** @return array<string, mixed> */
    public function build(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $start = $from->startOfDay()->utc();
        $end = $to->endOfDay()->utc();
        $key = sprintf('profit-report:%s:%s:%s:%s', (string) TenantContext::id(), $from->toDateString(), $to->toDateString(), $this->fingerprint($start, $end));

        return Cache::remember($key, self::CACHE_TTL_SECONDS, fn (): array => $this->compute($start, $end));
    }

    // ------------------------------------------------------------------ iç

    /**
     * Önbellek anahtarı veri değişince kendiliğinden değişir: dönem
     * siparişlerinin, satırlarının, kesintilerinin ve iadelerinin sayısı
     * ve son güncellenme anı.
     */
    private function fingerprint(\DateTimeInterface $start, \DateTimeInterface $end): string
    {
        $ids = $this->periodQuery($start, $end)->select('orders.id');

        $parts = [
            $this->periodQuery($start, $end)->selectRaw('count(*) c, max(orders.updated_at) m')->toBase()->first(),
            DB::table('order_lines')->whereIn('order_id', $ids)->selectRaw('count(*) c, max(updated_at) m')->first(),
            DB::table('order_charges')->whereIn('order_id', $ids)->selectRaw('count(*) c, max(updated_at) m')->first(),
            DB::table('order_refunds')->whereIn('order_id', $ids)->selectRaw('count(*) c, max(updated_at) m')->first(),
        ];

        return md5((string) json_encode($parts));
    }

    /** @return Builder<Order> */
    private function periodQuery(\DateTimeInterface $start, \DateTimeInterface $end)
    {
        return Order::query()
            ->whereRaw('coalesce(orders.placed_at, orders.created_at) >= ?', [$start])
            ->whereRaw('coalesce(orders.placed_at, orders.created_at) <= ?', [$end]);
    }

    /** @return array<string, mixed> */
    private function compute(\DateTimeInterface $start, \DateTimeInterface $end): array
    {
        $total = $this->periodQuery($start, $end)->count();

        $orders = $this->periodQuery($start, $end)
            ->with('connection:id,channel_type_code,label')
            ->orderByRaw('coalesce(orders.placed_at, orders.created_at) DESC')
            ->limit(self::MAX_ORDERS)
            ->get();

        /** @var array<string, array<string, mixed>> $currencies */
        $currencies = [];
        $lineRows = [];

        foreach ($orders as $order) {
            [$view, $lines] = $this->calculator->expectedWithLines($order);
            $cur = $view->currency;
            $currencies[$cur] ??= ['funnel' => $this->emptyFunnel(), 'channels' => [], 'categories' => [], 'brands' => [], 'orders' => 0];
            $bucket = &$currencies[$cur];
            $bucket['orders']++;

            $gross = $this->sumLines($lines, 'gross');
            $values = [
                'gross' => $gross,
                'cancel' => $gross !== null && $view->sale !== null ? Money::normalize(Money::decimal($view->sale)->minus(Money::decimal($gross))) : null,
                'refund' => $view->refund,
                'commission' => $view->commission,
                'shipping' => $view->shipping,
                'other' => $view->other,
                'cost' => $view->cost,
            ];

            $this->addFunnel($bucket['funnel'], $values, $view);

            $channelKey = (string) $order->channel_connection_id;
            $bucket['channels'][$channelKey] ??= $this->emptyGroup([
                'label' => $order->connection?->label,
                'channel' => $order->connection?->channel_type_code,
            ]);
            $this->addGroup($bucket['channels'][$channelKey], $view->sale, $this->deductions($view), $view->cost, $view, 1);

            foreach ($this->allocate($view, $lines) as $share) {
                $lineRows[] = ['currency' => $cur, 'variant' => $share['variant_id'], 'view' => $view, 'share' => $share];
            }

            unset($bucket);
        }

        $this->addProductGroups($currencies, $lineRows);

        foreach ($currencies as $cur => $bucket) {
            $currencies[$cur]['funnel'] = $this->finishFunnel($bucket['funnel'], $bucket['orders']);
            foreach (['channels', 'categories', 'brands'] as $dim) {
                $currencies[$cur][$dim] = $this->finishGroups($bucket[$dim]);
            }
        }

        // En çok siparişli para birimi önde (genelde TRY).
        uasort($currencies, fn (array $a, array $b): int => $b['orders'] <=> $a['orders']);

        return [
            'orders' => $orders->count(),
            'truncated' => $total > self::MAX_ORDERS,
            'currencies' => $currencies,
            'gaps' => $this->gaps($orders->pluck('id')->all()),
            'computedAt' => CarbonImmutable::now()->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function emptyFunnel(): array
    {
        $funnel = ['complete' => 0, 'contribution' => '0.00', 'partial' => '0.00'];

        foreach (self::STEPS as $step) {
            $funnel[$step] = ['amount' => '0.00', 'known' => 0];
        }

        return $funnel;
    }

    /**
     * @param  array<string, mixed>  $funnel
     * @param  array<string, ?string>  $values
     */
    private function addFunnel(array &$funnel, array $values, ContributionView $view): void
    {
        foreach ($values as $step => $value) {
            if ($value !== null) {
                $funnel[$step]['amount'] = Money::add($funnel[$step]['amount'], $value);
                $funnel[$step]['known']++;
            }
        }

        $funnel['partial'] = Money::add($funnel['partial'], $view->partial);

        if ($view->status === ContributionView::COMPLETE) {
            $funnel['complete']++;
            $funnel['contribution'] = Money::add($funnel['contribution'], (string) $view->contribution);
        }
    }

    /**
     * @param  array<string, mixed>  $funnel
     * @return array<string, mixed>
     */
    private function finishFunnel(array $funnel, int $orders): array
    {
        $funnel['orders'] = $orders;
        $funnel['contribution'] = $funnel['complete'] > 0 ? $funnel['contribution'] : null;

        return $funnel;
    }

    /** Komisyon + kargo + diğer; biri bilinmiyorsa NULL. */
    private function deductions(ContributionView $view): ?string
    {
        if ($view->commission === null || $view->shipping === null || $view->other === null) {
            return null;
        }

        return Money::add($view->commission, $view->shipping, $view->other);
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private function emptyGroup(array $meta): array
    {
        return $meta + [
            'orders' => 0, 'complete' => 0,
            'sale' => '0.00', 'saleKnown' => 0,
            'deductions' => '0.00', 'deductionsKnown' => 0,
            'cost' => '0.00', 'costKnown' => 0,
            'contribution' => '0.00', 'completeSale' => '0.00',
            'units' => 0,
        ];
    }

    /** @param array<string, mixed> $group */
    private function addGroup(array &$group, ?string $sale, ?string $deductions, ?string $cost, ContributionView $view, int $count, ?string $contribution = null): void
    {
        $group['orders'] += $count;

        foreach (['sale' => $sale, 'deductions' => $deductions, 'cost' => $cost] as $field => $value) {
            if ($value !== null) {
                $group[$field] = Money::add($group[$field], $value);
                $group[$field.'Known'] += $count;
            }
        }

        if ($view->status === ContributionView::COMPLETE) {
            $group['complete'] += $count;
            $group['contribution'] = Money::add($group['contribution'], $contribution ?? (string) $view->contribution);
            $group['completeSale'] = Money::add($group['completeSale'], (string) $sale);
        }
    }

    /**
     * Sipariş düzeyi kalemleri satıra satış payıyla dağıtır.
     *
     * @param  list<array{line_id: string, variant_id: ?string, gross: ?string, revenue: ?string, cost: ?string}>  $lines
     * @return list<array<string, mixed>>
     */
    private function allocate(ContributionView $view, array $lines): array
    {
        if ($lines === []) {
            return [];
        }

        $sale = $view->sale === null ? null : Money::decimal($view->sale);
        $equal = $sale === null || $sale->isZero();
        $out = [];

        foreach ($lines as $line) {
            $weight = $equal || $line['revenue'] === null
                ? BigDecimal::one()->dividedBy(count($lines), 8, RoundingMode::HALF_UP)
                : Money::decimal($line['revenue'])->dividedBy($sale, 8, RoundingMode::HALF_UP);

            $share = ['variant_id' => $line['variant_id'], 'sale' => $line['revenue'], 'cost' => $line['cost']];

            foreach (self::ORDER_LEVEL as $field) {
                $value = $view->{$field};
                $share[$field] = $value === null ? null : (string) Money::decimal($value)->multipliedBy($weight)->toScale(2, RoundingMode::HALF_UP);
            }

            $out[] = $share;
        }

        return $out;
    }

    /**
     * @param  array<string, array<string, mixed>>  $currencies
     * @param  list<array<string, mixed>>  $rows
     */
    private function addProductGroups(array &$currencies, array $rows): void
    {
        $variantIds = array_values(array_unique(array_filter(array_column($rows, 'variant'))));
        $products = [];

        foreach (array_chunk($variantIds, 1000) as $chunk) {
            foreach (Variant::query()->whereIn('id', $chunk)->get(['id', 'product_id']) as $variant) {
                $products[(string) $variant->id] = (string) $variant->product_id;
            }
        }

        $meta = [];
        foreach (array_chunk(array_values(array_unique($products)), 1000) as $chunk) {
            foreach (Product::query()->whereIn('id', $chunk)->get(['id', 'brand', 'internal_category_id']) as $product) {
                $meta[(string) $product->id] = ['brand' => $product->brand, 'category' => $product->internal_category_id];
            }
        }

        foreach ($rows as $row) {
            $product = $row['variant'] === null ? null : ($products[$row['variant']] ?? null);
            $info = $product === null ? null : ($meta[$product] ?? null);
            $share = $row['share'];
            /** @var ContributionView $view */
            $view = $row['view'];

            $deductions = ($share['commission'] === null || $share['shipping'] === null || $share['other'] === null)
                ? null
                : Money::add($share['commission'], $share['shipping'], $share['other']);

            // Satır maliyeti işaretsizdir; panelde kesinti/maliyet negatiftir.
            $cost = $share['cost'] === null ? null : Money::normalize(Money::decimal($share['cost'])->negated());

            // Satır katkısı = satış + iade payı + kesinti payı − maliyet (yalnız tamam siparişte;
            // tamam siparişte bütün bileşenler biliniyordur).
            $contribution = $view->status === ContributionView::COMPLETE
                ? Money::add((string) $share['sale'], (string) $share['refund'], (string) $deductions, (string) $cost)
                : null;

            foreach (['categories' => $info === null ? null : $info['category'], 'brands' => $info === null ? null : $info['brand']] as $dim => $label) {
                $key = $product === null ? '__unmatched' : (($label === null || trim((string) $label) === '') ? '__none' : (string) $label);
                $group = &$currencies[$row['currency']][$dim];
                $group[$key] ??= $this->emptyGroup(['label' => in_array($key, ['__unmatched', '__none'], true) ? null : $key, 'kind' => match ($key) {
                    '__unmatched' => 'unmatched', '__none' => 'none', default => 'value',
                }]);
                $this->addGroup($group[$key], $share['sale'], $deductions, $cost, $view, 1, $contribution);
                unset($group);
            }
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $groups
     * @return list<array<string, mixed>>
     */
    private function finishGroups(array $groups): array
    {
        $out = [];

        foreach ($groups as $key => $group) {
            $group['key'] = (string) $key;
            $group['contribution'] = $group['complete'] > 0 ? $group['contribution'] : null;
            $completeSale = Money::decimal($group['completeSale']);
            $group['margin'] = $group['contribution'] !== null && $completeSale->isPositive()
                ? (float) (string) Money::decimal($group['contribution'])->multipliedBy(100)->dividedBy($completeSale, 1, RoundingMode::HALF_UP)
                : null;
            unset($group['completeSale'], $group['units']);
            $out[] = $group;
        }

        usort($out, fn (array $a, array $b): int => Money::decimal($b['sale'])->compareTo(Money::decimal($a['sale'])));

        return $out;
    }

    /**
     * "Eksik veri" kutusu — rakamı tamamlamak için ne yapılmalı.
     *
     * @param  list<string>  $orderIds
     * @return array<string, int>
     */
    private function gaps(array $orderIds): array
    {
        $count = fn (\Closure $where): int => array_sum(array_map(
            fn (array $chunk): int => (int) DB::table('order_lines')->whereIn('order_id', $chunk)->where($where)->count(),
            array_chunk($orderIds, 1000),
        ));

        $ordersWithout = fn (array $kinds): int => array_sum(array_map(
            fn (array $chunk): int => count($chunk) - (int) DB::table('order_charges')
                ->whereIn('order_id', $chunk)->where('basis', 'expected')->whereIn('kind', $kinds)
                ->distinct()->count('order_id'),
            array_chunk($orderIds, 1000),
        ));

        return [
            // Satış anında maliyeti olmayan satır (geçmiş sipariş — yalnız bilgi).
            'linesWithoutCost' => $count(fn ($q) => $q->whereNull('unit_cost')),
            // Bugün maliyeti boş varyant (yeni siparişlerde de eksik kalır).
            'variantsWithoutCost' => Variant::query()->whereNull('cost_price')->count(),
            'ordersWithoutCommission' => $orderIds === [] ? 0 : $ordersWithout(['commission', 'commission_refund']),
            'ordersWithoutShipping' => $orderIds === [] ? 0 : $ordersWithout(['shipping_fee']),
            'unmatchedLines' => $count(fn ($q) => $q->whereNull('variant_id')),
        ];
    }

    /** @param list<array<string, ?string>> $lines */
    private function sumLines(array $lines, string $field): ?string
    {
        $values = array_column($lines, $field);

        if ($values === [] || in_array(null, $values, true)) {
            return $values === [] ? '0.00' : null;
        }

        return Money::add(...$values);
    }
}
