<?php

declare(strict_types=1);

namespace App\Domain\Orders\Support;

use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\OrderCharge;
use App\Domain\Orders\Models\OrderRefund;
use App\Support\Money\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;

/**
 * Siparişin katkı payı — TARİHSEL OLARAK DOĞRU kâr hesabının temeli (ADR-002).
 *
 *   gelir (satış anı net, iptal edilen ve parası iade edilmeyen adet hariç)
 *   − müşteriye geri ödenen tutar (order_refunds)
 *   ± kanal kesintileri (order_charges; tür başına GERÇEK varsa o, yoksa BEKLENEN)
 *   − satış anı maliyeti × elde kalmayan adet (iptal ve stoğa dönen iade hariç)
 *
 * Hiçbir değer bugünden okunmaz: maliyet `unit_cost` görüntüsüdür. Bilinmeyen
 * değer 0 sayılmaz — `missing` listesine girer ve `complete` false olur; rapor
 * eksik veriyi "kâr" diye göstermez.
 *
 * ÇİFT SAYIM YOK: Shopify iptal + para iadesinde iptal adedi iade kaydının
 * kalemiyle karşılanır — o adet gelirde kalır ve iade tutarıyla düşer. Parası
 * iade kaydı olmayan iptal (pazaryeri iptali) adedi gelirden çıkar.
 *
 * Bu bir RAPOR DEĞİL, hesap çekirdeğidir (S1 kâr raporu bunu kullanır).
 */
final class OrderContribution
{
    /**
     * @param  array<string, array{amount: string, basis: string}>  $charges
     * @param  list<string>  $missing
     */
    public function __construct(
        public readonly string $currency,
        public readonly string $revenue,
        public readonly string $refunds,
        public readonly array $charges,
        public readonly string $cost,
        public readonly string $contribution,
        public readonly array $missing,
    ) {}

    public function complete(): bool
    {
        return $this->missing === [];
    }

    public static function for(Order $order): self
    {
        $currency = strtoupper((string) $order->currency);
        $missing = [];

        $refundedQty = DB::table('order_refund_lines')
            ->join('order_refunds', 'order_refunds.id', '=', 'order_refund_lines.order_refund_id')
            ->where('order_refunds.order_id', $order->id)
            ->where('order_refunds.kind', OrderRefund::KIND_REFUND)
            ->whereNotNull('order_refund_lines.order_line_id')
            ->groupBy('order_refund_lines.order_line_id')
            ->selectRaw('order_refund_lines.order_line_id AS line_id, sum(order_refund_lines.quantity) AS qty')
            ->pluck('qty', 'line_id')
            ->all();

        $revenue = BigDecimal::zero();
        $cost = BigDecimal::zero();

        foreach ($order->lines()->get() as $line) {
            $qty = (int) $line->quantity;

            if ($qty <= 0) {
                continue;
            }

            $cancelled = (int) $line->quantity_cancelled;
            $returned = (int) $line->quantity_returned;
            $covered = min($cancelled, max(0, (int) ($refundedQty[$line->id] ?? 0) - $returned));
            $revenueQty = $qty - ($cancelled - $covered);

            if ($line->net_line_total === null) {
                $missing[] = "net:{$line->id}";
            } else {
                $revenue = $revenue->plus(
                    Money::decimal((string) $line->net_line_total)->multipliedBy($revenueQty)->dividedBy($qty, 2, RoundingMode::HALF_UP),
                );
            }

            $costQty = max(0, $qty - $cancelled - $returned);

            if ($costQty === 0) {
                continue;
            }

            if ($line->unit_cost === null) {
                $missing[] = "cost:{$line->id}";
            } elseif (strtoupper((string) $line->cost_currency) !== $currency) {
                $missing[] = "cost_currency:{$line->id}";
            } else {
                $cost = $cost->plus(Money::decimal((string) $line->unit_cost)->multipliedBy($costQty));
            }
        }

        $refunds = BigDecimal::zero();

        foreach (OrderRefund::query()->where('order_id', $order->id)->get(['id', 'amount', 'currency']) as $refund) {
            if ($refund->amount === null) {
                $missing[] = "refund_amount:{$refund->id}";
            } elseif (strtoupper((string) $refund->currency) !== $currency) {
                $missing[] = "refund_currency:{$refund->id}";
            } else {
                $refunds = $refunds->plus(Money::decimal((string) $refund->amount));
            }
        }

        $charges = [];
        $chargeTotal = BigDecimal::zero();
        $rows = OrderCharge::query()->where('order_id', $order->id)->get(['kind', 'basis', 'amount', 'currency']);

        foreach ($rows->groupBy('kind') as $kind => $group) {
            $actual = $group->where('basis', OrderCharge::BASIS_ACTUAL);
            $used = $actual->isNotEmpty() ? $actual : $group->where('basis', OrderCharge::BASIS_EXPECTED);
            $sum = BigDecimal::zero();

            foreach ($used as $charge) {
                if (strtoupper((string) $charge->currency) !== $currency) {
                    $missing[] = "charge_currency:{$kind}";

                    continue;
                }

                $sum = $sum->plus(Money::decimal((string) $charge->amount));
            }

            $charges[(string) $kind] = [
                'amount' => (string) $sum->toScale(2, RoundingMode::HALF_UP),
                'basis' => $actual->isNotEmpty() ? OrderCharge::BASIS_ACTUAL : OrderCharge::BASIS_EXPECTED,
            ];
            $chargeTotal = $chargeTotal->plus($sum);
        }

        $contribution = $revenue->minus($refunds)->plus($chargeTotal)->minus($cost);

        return new self(
            currency: $currency,
            revenue: (string) $revenue->toScale(2, RoundingMode::HALF_UP),
            refunds: (string) $refunds->toScale(2, RoundingMode::HALF_UP),
            charges: $charges,
            cost: (string) $cost->toScale(2, RoundingMode::HALF_UP),
            contribution: (string) $contribution->toScale(2, RoundingMode::HALF_UP),
            missing: $missing,
        );
    }
}
