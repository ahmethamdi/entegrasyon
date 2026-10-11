<?php

declare(strict_types=1);

namespace App\Domain\Finance\Support;

use App\Domain\Finance\Models\ChannelFinancialTransaction as Tx;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\OrderCharge;
use App\Domain\Orders\Models\OrderRefund;
use App\Domain\Orders\Support\OrderContribution;
use App\Support\Money\Money;
use Brick\Math\BigDecimal;

/**
 * S1 · KATKI (KDV DAHİL) — muhasebe/net kâr DEĞİLDİR.
 *
 * Bütün tutarlar kanalın ve satıcının verdiği KDV DAHİL nakit tutarlardır.
 * KDV ayrıştırılmaz; KDV hariç kârlılık fatura/muhasebe aşamasına ertelendi
 * (S1 kararı 1). Maliyet = satış anındaki "KDV dahil alış maliyeti"
 * (`order_lines.unit_cost` görüntüsü — bugünkü maliyet ASLA okunmaz).
 *
 * ── BEKLENEN KATKI (KDV dahil) ─────────────────────────────────────────
 *   Satış      = Σ satır net_line_total × (adet − iade kaydıyla karşılanmayan iptal) / adet
 *   İade       = − Σ order_refunds.amount                (34Pazar'ın iç kaydı)
 *   Komisyon   = Σ order_charges[expected, commission|commission_refund]   yoksa BİLİNMİYOR
 *   Kargo      = Σ order_charges[expected, shipping_fee]                   yoksa BİLİNMİYOR
 *   Diğer      = Σ order_charges[expected, service_fee|withholding|other]  (kayıt yoksa 0)
 *   Maliyet    = − Σ unit_cost × (adet − iptal − iade)
 *   Katkı      = Satış + İade + Komisyon + Kargo + Diğer + Maliyet
 *                (yalnız bileşenlerin hepsi biliniyorsa; yoksa EKSİK)
 *
 * ── GERÇEKLEŞEN KATKI (KDV dahil) — yalnız DOĞRULANMIŞ kanal olgularından ─
 *   Satış      = Σ (alacak − borç)          eşleşmiş VERIFIED `sale` satırları
 *   Komisyon   = − Σ commissionAmount       aynı satırlar
 *                (Komisyon Faturası AYRI bir kesinti DEĞİLDİR: aynı
 *                 komisyonun belgesidir, ikinci kez DÜŞÜLMEZ — izde
 *                 "hariç" görünür)
 *   İade       = doğrulanmış iade türü YOK → sipariş iade/iptal içeriyorsa
 *                BİLİNMİYOR (iç kayıt "beklenen"de kalır; ikisi asla toplanmaz),
 *                içermiyorsa 0
 *   Kargo      = Trendyol kargo kesintisi henüz doğrulanmadı → BİLİNMİYOR
 *   Diğer      = eşleşmiş UNVERIFIED/UNKNOWN işlem varsa BİLİNMİYOR, yoksa 0
 *   Maliyet    = beklenendeki satış anı maliyeti (kanal olgusu değil, görüntü)
 *   Katkı      = hepsi biliniyorsa toplam; yoksa EKSİK (`partial` görünür)
 *
 * Eşleşmiş ama doğrulanmamış/bilinmeyen bir işlem gerçekleşen katkıyı HER
 * ZAMAN eksik yapar (S1 kararı 3, 10).
 */
final class ContributionCalculator
{
    public const BASIS_EXPECTED = 'expected';

    public const BASIS_SETTLED = 'settled';

    /** Doğrulanmış ama katkı bileşeni OLMAYAN türler. */
    private const NON_CONTRIBUTING = [
        TrendyolFinancialTypes::COMMISSION_INVOICE,
        TrendyolFinancialTypes::PAYOUT,
        TrendyolFinancialTypes::INCOMING_TRANSFER,
    ];

    public function expected(Order $order): ContributionView
    {
        $core = OrderContribution::for($order, expectedChargesOnly: true);
        $missing = $core->missing;
        $reasons = [];
        $trace = $this->snapshotTrace($order);

        $sale = $this->hasMissing($missing, 'net:') ? null : $core->revenue;
        if ($sale === null) {
            $reasons[] = __('Satırın satış anı net tutarı yok');
        }

        $refund = $this->hasMissing($missing, 'refund_') ? null : Money::normalize(Money::decimal($core->refunds)->negated());
        if ($refund === null) {
            $reasons[] = __('İade tutarı bilinmiyor');
        }

        $cost = $this->hasMissing($missing, 'cost') ? null : Money::normalize(Money::decimal($core->cost)->negated());
        if ($cost === null) {
            $reasons[] = __('Satış anı maliyeti yok');
        }

        $chargeMissing = $this->hasMissing($missing, 'charge_currency');
        $commission = $this->chargeSum($order, ['commission', 'commission_refund']);
        $shipping = $this->chargeSum($order, ['shipping_fee']);
        $other = $this->chargeSum($order, ['service_fee', 'withholding', 'other']) ?? '0.00';

        if ($commission === null) {
            $reasons[] = __('Beklenen komisyon bilinmiyor');
        }

        if ($shipping === null) {
            $reasons[] = __('Beklenen kargo kesintisi bilinmiyor');
        }

        if ($chargeMissing) {
            $reasons[] = __('Kesinti para birimi siparişten farklı');
        }

        foreach (OrderRefund::query()->where('order_id', $order->id)->get(['id', 'kind', 'amount', 'external_refund_id']) as $r) {
            $trace[] = $this->traceItem(__('İade kaydı (:kind)', ['kind' => (string) $r->kind]), $r->amount === null ? null : Money::normalize(Money::decimal((string) $r->amount)->negated()), true, __('34Pazar iade kaydı'), $r->external_refund_id, $r->amount === null ? __('tutar bilinmiyor') : null);
        }

        foreach (OrderCharge::query()->where('order_id', $order->id)->where('basis', OrderCharge::BASIS_EXPECTED)->get(['kind', 'amount', 'external_ref']) as $c) {
            $trace[] = $this->traceItem(__('Beklenen kesinti: :kind', ['kind' => (string) $c->kind]), (string) $c->amount, true, 'order_charges', $c->external_ref, null);
        }

        return $this->build(self::BASIS_EXPECTED, $core->currency, $sale, $refund, $commission, $shipping, $other, $cost, $reasons, $trace);
    }

    public function settled(Order $order): ContributionView
    {
        $currency = strtoupper((string) $order->currency);
        $txs = Tx::query()
            ->where('order_id', $order->id)
            ->where('match_status', Tx::MATCHED)
            ->orderBy('transaction_date')
            ->get();

        $reasons = [];
        $trace = [];

        if ($txs->isEmpty()) {
            return new ContributionView(
                basis: self::BASIS_SETTLED, status: ContributionView::NOT_AVAILABLE, currency: $currency,
                sale: null, refund: null, commission: null, shipping: null, other: null, cost: null,
                contribution: null, partial: '0.00', reasons: [__('Bu sipariş için hakediş kaydı henüz yok')], trace: [],
            );
        }

        $sale = BigDecimal::zero();
        $commission = BigDecimal::zero();
        $saleRows = 0;
        $unverified = 0;
        $serials = [];

        foreach ($txs as $tx) {
            $isSale = $tx->type === TrendyolFinancialTypes::SALE && $tx->classification === Tx::VERIFIED;

            if ($isSale && strtoupper((string) $tx->currency) !== $currency) {
                $reasons[] = __('İşlem :id para birimi (:currency) siparişten farklı', ['id' => $tx->external_id, 'currency' => (string) $tx->currency]);
                $trace[] = $this->txTrace($tx, false, __('para birimi farklı'));

                continue;
            }

            if ($isSale) {
                $saleRows++;
                $sale = $sale->plus(Money::decimal((string) $tx->credit)->minus(Money::decimal((string) $tx->debt)));
                $commission = $commission->minus(Money::decimal((string) $tx->commission_amount));
                $trace[] = $this->txTrace($tx, true, __('satış = alacak − borç; komisyon = commissionAmount'));

                if ($tx->commission_invoice_serial !== null) {
                    $serials[$tx->commission_invoice_serial] = true;
                }

                continue;
            }

            // DOĞRULANMIŞ AMA KATKIYA GİRMEYEN türler (komisyon faturası,
            // ödeme, havale) bir strateji onları siparişe bağlasa bile
            // DÜŞÜLMEZ: komisyon faturası satış satırındaki komisyonun
            // belgesidir — ikinci kez düşmek komisyonu 2 katına çıkarırdı.
            if ($tx->classification === Tx::VERIFIED && in_array($tx->type, self::NON_CONTRIBUTING, true)) {
                $trace[] = $this->txTrace($tx, false, __('komisyon faturası: satış satırındaki komisyonun belgesi — ikinci kez düşülmez'));

                continue;
            }

            $unverified++;
            $trace[] = $this->txTrace($tx, false, $tx->classification === Tx::UNKNOWN
                ? __('tanınmayan tür — katkıya girmez')
                : __('anlamı canlıda doğrulanmadı — katkıya girmez'));
        }

        // Komisyon faturaları izde HARİÇ görünür: aynı komisyonun belgesi.
        if ($serials !== []) {
            foreach (Tx::query()->where('channel_connection_id', $order->channel_connection_id)->whereIn('external_id', array_keys($serials))->get() as $invoice) {
                $trace[] = $this->txTrace($invoice, false, __('komisyon faturası: satış satırındaki komisyonun belgesi — ikinci kez düşülmez'));
            }
        }

        if ($unverified > 0) {
            $reasons[] = __(':count eşleşmiş işlem doğrulanmamış ya da tanınmayan türde', ['count' => $unverified]);
        }

        if ($saleRows === 0) {
            $reasons[] = __('Doğrulanmış satış hakedişi yok');
        }

        $refund = $this->orderHasRefundOrCancel($order) ? null : '0.00';
        if ($refund === null) {
            $reasons[] = __('İade/iptal var; Trendyol iade hakedişi henüz doğrulanmadı');
        }

        $reasons[] = __('Kargo kesintisi henüz doğrulanmadı (kargo faturası örneği yok)');

        $core = OrderContribution::for($order, expectedChargesOnly: true);
        $cost = $this->hasMissing($core->missing, 'cost') ? null : Money::normalize(Money::decimal($core->cost)->negated());
        if ($cost === null) {
            $reasons[] = __('Satış anı maliyeti yok');
        }

        return $this->build(
            self::BASIS_SETTLED,
            $currency,
            $saleRows > 0 ? Money::normalize($sale) : null,
            $refund,
            $saleRows > 0 ? Money::normalize($commission) : null,
            null,
            $unverified > 0 ? null : '0.00',
            $cost,
            $reasons,
            $trace,
        );
    }

    // ------------------------------------------------------------------ iç

    /**
     * @param  list<string>  $reasons
     * @param  list<array<string, mixed>>  $trace
     */
    private function build(string $basis, string $currency, ?string $sale, ?string $refund, ?string $commission, ?string $shipping, ?string $other, ?string $cost, array $reasons, array $trace): ContributionView
    {
        $parts = [$sale, $refund, $commission, $shipping, $other, $cost];
        $partial = BigDecimal::zero();

        foreach ($parts as $part) {
            if ($part !== null) {
                $partial = $partial->plus(Money::decimal($part));
            }
        }

        $complete = $reasons === [] && ! in_array(null, $parts, true);

        return new ContributionView(
            basis: $basis,
            status: $complete ? ContributionView::COMPLETE : ContributionView::INCOMPLETE,
            currency: $currency,
            sale: $sale,
            refund: $refund,
            commission: $commission,
            shipping: $shipping,
            other: $other,
            cost: $cost,
            contribution: $complete ? Money::normalize($partial) : null,
            partial: Money::normalize($partial),
            reasons: array_values(array_unique($reasons)),
            trace: $trace,
        );
    }

    /** @param list<string> $missing */
    private function hasMissing(array $missing, string $prefix): bool
    {
        foreach ($missing as $item) {
            if (str_starts_with($item, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /** @param list<string> $kinds */
    private function chargeSum(Order $order, array $kinds): ?string
    {
        $rows = OrderCharge::query()
            ->where('order_id', $order->id)
            ->where('basis', OrderCharge::BASIS_EXPECTED)
            ->whereIn('kind', $kinds)
            ->where('currency', strtoupper((string) $order->currency))
            ->pluck('amount');

        if ($rows->isEmpty()) {
            return null;
        }

        return Money::add(...$rows->map(fn ($a): string => (string) $a)->all());
    }

    private function orderHasRefundOrCancel(Order $order): bool
    {
        return OrderRefund::query()->where('order_id', $order->id)->exists()
            || $order->lines()->where(fn ($q) => $q->where('quantity_returned', '>', 0)->orWhere('quantity_cancelled', '>', 0))->exists();
    }

    /** @return list<array<string, mixed>> */
    private function snapshotTrace(Order $order): array
    {
        $trace = [];

        foreach ($order->lines()->get() as $line) {
            $trace[] = $this->traceItem(
                __('Satır :sku × :qty', ['sku' => (string) $line->sku, 'qty' => (int) $line->quantity]),
                $line->net_line_total === null ? null : (string) $line->net_line_total,
                $line->net_line_total !== null,
                __('satış anı görüntüsü (KDV dahil)'),
                $line->external_line_id,
                __('iptal :cancelled · iade :returned · birim maliyet :cost (:at)', [
                    'cancelled' => (int) $line->quantity_cancelled,
                    'returned' => (int) $line->quantity_returned,
                    'cost' => $line->unit_cost === null ? __('yok') : (string) $line->unit_cost,
                    'at' => $line->cost_captured_at?->toDateTimeString() ?? __('yakalanmadı'),
                ]),
            );
        }

        return $trace;
    }

    /** @return array<string, mixed> */
    private function txTrace(Tx $tx, bool $included, string $note): array
    {
        return $this->traceItem(
            "{$tx->type_raw} (".($tx->type ?? __('tanınmadı')).", {$tx->classification})",
            $tx->credit === null && $tx->debt === null ? null : Money::normalize(Money::decimal((string) ($tx->credit ?? '0'))->minus(Money::decimal((string) ($tx->debt ?? '0')))),
            $included,
            "Trendyol {$tx->source}",
            $tx->external_id,
            $note
                .($tx->commission_amount !== null ? ' · '.__('komisyon :amount', ['amount' => (string) $tx->commission_amount]) : '')
                .($tx->seller_revenue !== null ? ' · '.__('hakediş :amount', ['amount' => (string) $tx->seller_revenue]) : ''),
        );
    }

    /** @return array{label: string, amount: ?string, included: bool, source: string, ref: ?string, note: ?string} */
    private function traceItem(string $label, ?string $amount, bool $included, string $source, ?string $ref, ?string $note): array
    {
        return compact('label', 'amount', 'included', 'source', 'ref', 'note');
    }
}
