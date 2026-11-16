<?php

declare(strict_types=1);

namespace App\Domain\Finance\Actions;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Variant;
use App\Domain\Finance\Models\ChannelChargeRule;
use App\Domain\Orders\Actions\RecordOrderCharge;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\OrderCharge;
use App\Domain\Orders\Models\OrderLine;
use App\Domain\Orders\Support\OrderContribution;
use App\Support\Money\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Kanal kesinti varsayılanlarından BEKLENEN kesinti yazar (kâr paneli B).
 *
 *   komisyon  = − Σ satır geliri × (kategori yüzdesi ?? varsayılan yüzde)
 *   kargo     = − sipariş başı kargo kesintisi
 *   paketleme = − sipariş başı paketleme maliyeti   (tür: other)
 *
 * Kayıtlar `source = 'rule'` ve sabit `external_ref` ile yazılır; tekillik
 * dizini (tenant, source, external_ref) sayesinde aynı sipariş için İKİNCİ
 * çağrı hiçbir şey yazmaz (idempotent). `order_charges` ekleme-yalnızdır:
 * kural sonradan değişirse geçmiş kayıt değişmez; iptal, ayrı bir
 * `commission_refund` / ters kargo kaydıyla düşülür.
 *
 * KANALDAN gelen beklenen kesinti (source ≠ rule) varsa o tür için kural
 * YAZILMAZ — kanalın rakamı tahminden üstündür.
 *
 * Komisyon yüzdesi bilinmeyen tek bir satır bile varsa komisyon HİÇ
 * yazılmaz: eksik toplam "bilinen" gibi görünmesin (kâr paneli ilkesi).
 */
final class ApplyChargeRules
{
    public const SOURCE = 'rule';

    public function __construct(private readonly RecordOrderCharge $record) {}

    /** @return int yazılan kayıt sayısı */
    public function forOrder(Order $order): int
    {
        $rules = $this->rules($order->channel_connection_id);

        if ($rules === []) {
            return 0;
        }

        $default = $rules[ChannelChargeRule::DEFAULT_CATEGORY] ?? null;
        $currency = strtoupper((string) $order->currency);
        $foreign = $this->foreignExpectedKinds($order);
        $at = $order->placed_at ?? $order->created_at ?? now();
        $written = 0;

        if (! in_array('commission', $foreign, true)) {
            $commission = $this->commission($order, $rules, $default);

            if ($commission !== null) {
                $written += (int) $this->record->run(
                    $order, 'commission', OrderCharge::BASIS_EXPECTED,
                    Money::normalize($commission['amount']->negated()), $currency,
                    self::SOURCE, "rule:commission:{$order->id}", $at,
                    rate: $commission['rate'], raw: ['line_rates' => $commission['rates']],
                );
            }
        }

        if ($this->fullyCancelled($order)) {
            return $written;
        }

        if (! in_array('shipping_fee', $foreign, true) && $default?->shipping_fee !== null) {
            $written += (int) $this->record->run(
                $order, 'shipping_fee', OrderCharge::BASIS_EXPECTED,
                Money::normalize(Money::decimal((string) $default->shipping_fee)->negated()), $currency,
                self::SOURCE, "rule:shipping:{$order->id}", $at,
            );
        }

        if ($default?->packaging_fee !== null) {
            $written += (int) $this->record->run(
                $order, 'other', OrderCharge::BASIS_EXPECTED,
                Money::normalize(Money::decimal((string) $default->packaging_fee)->negated()), $currency,
                self::SOURCE, "rule:packaging:{$order->id}", $at,
                raw: ['label' => 'packaging'],
            );
        }

        return $written;
    }

    /**
     * İptal edilen adedin komisyonunu geri yazar; sipariş tamamen iptal
     * olduysa kargo ve paketleme kesintisini de ters kayıtla sıfırlar.
     * Yalnız KURAL ile yazılmış komisyonu düzeltir (kanalın rakamına dokunmaz).
     *
     * @param  list<array{line: OrderLine, quantity: int}>  $cancelled
     */
    public function forCancellation(Order $order, string $eventId, array $cancelled): int
    {
        $currency = strtoupper((string) $order->currency);
        $written = 0;
        $ruleCharges = OrderCharge::query()
            ->where('order_id', $order->id)
            ->where('source', self::SOURCE)
            ->get()
            ->keyBy('external_ref');

        $commission = $ruleCharges["rule:commission:{$order->id}"] ?? null;

        if ($commission !== null) {
            $rates = (array) ($commission->raw['line_rates'] ?? []);
            $refund = BigDecimal::zero();

            foreach ($cancelled as $pair) {
                $line = $pair['line'];
                $rate = $rates[$line->id] ?? null;

                if ($rate === null || $line->net_line_total === null || (int) $line->quantity <= 0) {
                    continue;
                }

                $refund = $refund->plus(
                    Money::decimal((string) $line->net_line_total)
                        ->multipliedBy($pair['quantity'])
                        ->dividedBy((int) $line->quantity, 4, RoundingMode::HALF_UP)
                        ->multipliedBy(Money::decimal((string) $rate))
                        ->dividedBy(100, 2, RoundingMode::HALF_UP),
                );
            }

            if ($refund->isPositive()) {
                $written += (int) $this->record->run(
                    $order, 'commission_refund', OrderCharge::BASIS_EXPECTED,
                    Money::normalize($refund), $currency,
                    self::SOURCE, "rule:commission_refund:{$eventId}", now(),
                );
            }
        }

        if ($this->fullyCancelled($order->fresh() ?? $order)) {
            foreach (['shipping' => 'shipping_fee', 'packaging' => 'other'] as $name => $kind) {
                $charge = $ruleCharges["rule:{$name}:{$order->id}"] ?? null;

                if ($charge !== null) {
                    $written += (int) $this->record->run(
                        $order, $kind, OrderCharge::BASIS_EXPECTED,
                        Money::normalize(Money::decimal((string) $charge->amount)->negated()), $currency,
                        self::SOURCE, "rule:{$name}_reversal:{$order->id}", now(),
                    );
                }
            }
        }

        return $written;
    }

    // ------------------------------------------------------------------ iç

    /** @return array<string, ChannelChargeRule> kategori → kural */
    private function rules(string $connectionId): array
    {
        return ChannelChargeRule::query()
            ->where('channel_connection_id', $connectionId)
            ->get()
            ->keyBy('internal_category_id')
            ->all();
    }

    /** @return list<string> kanalın (kural dışı) beklenen kesinti türleri */
    private function foreignExpectedKinds(Order $order): array
    {
        return OrderCharge::query()
            ->where('order_id', $order->id)
            ->where('basis', OrderCharge::BASIS_EXPECTED)
            ->where('source', '!=', self::SOURCE)
            ->distinct()
            ->pluck('kind')
            ->all();
    }

    /**
     * @param  array<string, ChannelChargeRule>  $rules
     * @return array{amount: BigDecimal, rate: ?string, rates: array<string, string>}|null
     */
    private function commission(Order $order, array $rules, ?ChannelChargeRule $default): ?array
    {
        // Satır geliri katkı çekirdeğinden: iki ekran aynı tabanı kullanır.
        $lines = OrderContribution::for($order, expectedChargesOnly: true)->lines;

        if ($lines === []) {
            return null;
        }

        $categories = $this->categories(array_values(array_filter(array_column($lines, 'variant_id'))));
        $total = BigDecimal::zero();
        $rates = [];

        foreach ($lines as $line) {
            if ($line['revenue'] === null) {
                return null;
            }

            $category = $line['variant_id'] === null ? null : ($categories[$line['variant_id']] ?? null);
            $rate = ($category !== null ? $rules[$category]?->commission_percent ?? null : null) ?? $default?->commission_percent;

            if ($rate === null) {
                return null;
            }

            $rates[$line['line_id']] = (string) $rate;
            $total = $total->plus(
                Money::decimal($line['revenue'])->multipliedBy(Money::decimal((string) $rate))->dividedBy(100, 2, RoundingMode::HALF_UP),
            );
        }

        $distinct = array_values(array_unique($rates));

        return ['amount' => $total, 'rate' => count($distinct) === 1 ? $distinct[0] : null, 'rates' => $rates];
    }

    /**
     * @param  list<string>  $variantIds
     * @return array<string, string> varyant → iç kategori
     */
    private function categories(array $variantIds): array
    {
        if ($variantIds === []) {
            return [];
        }

        $productOf = Variant::query()->whereIn('id', $variantIds)->pluck('product_id', 'id')->all();
        $categoryOf = Product::query()->whereIn('id', array_values(array_unique($productOf)))
            ->whereNotNull('internal_category_id')
            ->pluck('internal_category_id', 'id')
            ->all();

        $out = [];
        foreach ($productOf as $variantId => $productId) {
            if (isset($categoryOf[$productId]) && $categoryOf[$productId] !== '') {
                $out[(string) $variantId] = (string) $categoryOf[$productId];
            }
        }

        return $out;
    }

    private function fullyCancelled(Order $order): bool
    {
        $lines = $order->lines()->get(['quantity', 'quantity_cancelled']);

        return $lines->isNotEmpty() && $lines->every(fn ($l): bool => (int) $l->quantity_cancelled >= (int) $l->quantity);
    }
}
