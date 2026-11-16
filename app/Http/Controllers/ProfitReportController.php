<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Finance\Support\ProfitReport;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Kâr paneli (KDV dahil katkı) — dönem hunisi, kanal/kategori/marka
 * kırılımı ve "eksik veri" kutusu. Salt okunur; hesap ProfitReport'ta.
 */
final class ProfitReportController extends Controller
{
    public function index(Request $request, ProfitReport $report): InertiaResponse
    {
        $tz = 'Europe/Istanbul';
        $to = $this->date($request->string('to')->toString(), $tz) ?? CarbonImmutable::now($tz);
        $from = $this->date($request->string('from')->toString(), $tz) ?? $to->startOfMonth();

        if ($from->greaterThan($to)) {
            [$from, $to] = [$to, $from];
        }

        return Inertia::render('Reports/Profit', [
            'filters' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'report' => $report->build($from, $to),
            'maxOrders' => ProfitReport::MAX_ORDERS,
        ]);
    }

    private function date(string $value, string $tz): ?CarbonImmutable
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        return CarbonImmutable::createFromFormat('Y-m-d', $value, $tz) ?: null;
    }
}
