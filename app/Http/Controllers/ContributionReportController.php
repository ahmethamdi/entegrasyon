<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Finance\Models\ChannelFinancialTransaction as Tx;
use App\Domain\Finance\Support\ContributionCalculator;
use App\Domain\Finance\Support\ContributionView;
use App\Domain\Orders\Models\Order;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * S1 · Katkı raporu (KDV dahil) — sipariş başına beklenen ve gerçekleşen.
 *
 * Amaç geniş analiz değil, satıcının "34Pazar bu siparişte neden X kazandığımı
 * söylüyor?" sorusunu cevaplamak: her rakam ayrıntı ekranında satır
 * görüntüsüne, maliyet görüntüsüne ve eşleşmiş ham kanal işlemlerine iner.
 *
 * Eksik katkı SON RAKAM gibi gösterilmez (`contribution = null`, `partial`
 * ayrıca "eksik" etiketiyle). Dönem toplamı yalnız TAMAM siparişleri toplar
 * ve kaç siparişin eksik olduğunu söyler.
 */
final class ContributionReportController extends Controller
{
    private const MAX_ORDERS = 200;

    private const COMPONENTS = ['sale', 'refund', 'commission', 'shipping', 'other', 'cost'];

    public function index(Request $request, ContributionCalculator $calculator): InertiaResponse
    {
        $tz = 'Europe/Istanbul';
        $to = $this->date($request->string('to')->toString(), $tz) ?? CarbonImmutable::now($tz);
        $from = $this->date($request->string('from')->toString(), $tz) ?? $to->subDays(30);

        $orders = Order::query()
            ->with('connection:id,channel_type_code,label')
            ->whereRaw('coalesce(orders.placed_at, orders.created_at) >= ?', [$from->startOfDay()->utc()])
            ->whereRaw('coalesce(orders.placed_at, orders.created_at) <= ?', [$to->endOfDay()->utc()])
            ->orderByRaw('coalesce(orders.placed_at, orders.created_at) DESC')
            ->limit(self::MAX_ORDERS + 1)
            ->get();

        $truncated = $orders->count() > self::MAX_ORDERS;
        $orders = $orders->take(self::MAX_ORDERS);

        $rows = [];
        $views = ['expected' => [], 'settled' => []];

        foreach ($orders as $order) {
            $expected = $calculator->expected($order);
            $settled = $calculator->settled($order);
            $views['expected'][] = $expected;
            $views['settled'][] = $settled;

            $rows[] = [
                'id' => $order->id,
                'number' => $order->external_number ?? $order->external_id,
                'channel' => $order->connection?->channel_type_code,
                'placedAt' => ($order->placed_at ?? $order->created_at)?->toIso8601String(),
                'state' => $order->state,
                'expected' => $this->strip($expected),
                'settled' => $this->strip($settled),
            ];
        }

        return Inertia::render('Reports/Contribution', [
            'rows' => $rows,
            'filters' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'truncated' => $truncated,
            'totals' => [
                'expected' => $this->totals($views['expected']),
                'settled' => $this->totals($views['settled']),
            ],
            'unmatched' => $this->unmatched(),
        ]);
    }

    public function show(string $order, ContributionCalculator $calculator): InertiaResponse
    {
        // Kiracı scope'u altında aranır: başka kiracının siparişi 404.
        $model = Order::query()->with('connection:id,channel_type_code,label')->findOrFail($order);

        return Inertia::render('Reports/ContributionShow', [
            'order' => [
                'id' => $model->id,
                'number' => $model->external_number ?? $model->external_id,
                'package' => $model->external_id,
                'channel' => $model->connection?->channel_type_code,
                'state' => $model->state,
                'currency' => $model->currency,
                'placedAt' => ($model->placed_at ?? $model->created_at)?->toIso8601String(),
            ],
            'expected' => $calculator->expected($model)->toArray(),
            'settled' => $calculator->settled($model)->toArray(),
        ]);
    }

    // ------------------------------------------------------------------ iç

    /** @return array<string, mixed> */
    private function strip(ContributionView $view): array
    {
        $data = $view->toArray();
        unset($data['trace']);

        return $data;
    }

    /**
     * Dönem toplamı — para birimi başına. Bileşen toplamı yalnız BİLİNEN
     * değerleri toplar ve bilinmeyen sayısını yanında taşır; katkı toplamı
     * yalnız TAMAM siparişlerden.
     *
     * @param  list<ContributionView>  $views
     * @return array<string, array<string, mixed>>
     */
    private function totals(array $views): array
    {
        $out = [];

        foreach ($views as $view) {
            $bucket = $out[$view->currency] ?? [
                'complete' => 0, 'incomplete' => 0, 'notAvailable' => 0,
                'contribution' => '0.00', 'unknown' => array_fill_keys(self::COMPONENTS, 0),
            ] + array_fill_keys(self::COMPONENTS, '0.00');

            if ($view->status === ContributionView::NOT_AVAILABLE) {
                $bucket['notAvailable']++;
                $out[$view->currency] = $bucket;

                continue;
            }

            foreach (self::COMPONENTS as $component) {
                $value = $view->{$component};

                if ($value === null) {
                    $bucket['unknown'][$component]++;
                } else {
                    $bucket[$component] = Money::add($bucket[$component], $value);
                }
            }

            if ($view->status === ContributionView::COMPLETE) {
                $bucket['complete']++;
                $bucket['contribution'] = Money::add($bucket['contribution'], (string) $view->contribution);
            } else {
                $bucket['incomplete']++;
            }

            $out[$view->currency] = $bucket;
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function unmatched(): array
    {
        $rows = Tx::query()
            ->whereIn('match_status', [Tx::UNMATCHED, Tx::AMBIGUOUS])
            ->orderByDesc('transaction_date')
            ->limit(100)
            ->get();

        return [
            'count' => Tx::query()->whereIn('match_status', [Tx::UNMATCHED, Tx::AMBIGUOUS])->count(),
            'unclassified' => Tx::query()->where('classification', '!=', Tx::VERIFIED)->count(),
            'rows' => $rows->map(fn (Tx $tx): array => [
                'id' => $tx->id,
                'externalId' => $tx->external_id,
                'typeRaw' => $tx->type_raw,
                'classification' => $tx->classification,
                'matchStatus' => $tx->match_status,
                'matchReason' => $tx->match_reason,
                'orderNumber' => $tx->order_number,
                'package' => $tx->shipment_package_id,
                'credit' => $tx->credit === null ? null : (string) $tx->credit,
                'debt' => $tx->debt === null ? null : (string) $tx->debt,
                'commission' => $tx->commission_amount === null ? null : (string) $tx->commission_amount,
                'currency' => $tx->currency,
                'date' => $tx->transaction_date?->toIso8601String(),
            ])->all(),
        ];
    }

    private function date(string $value, string $tz): ?CarbonImmutable
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        return CarbonImmutable::createFromFormat('Y-m-d', $value, $tz) ?: null;
    }
}
