<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Sync\Support\OversellProtectionReport;
use Carbon\CarbonImmutable;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * S11 · "Fazla satış yok" kanıt ekranı — son 30 gün, salt okunur.
 *
 * Satışta ekran görüntüsü olarak kullanılır; tanımlar ekranda yazılıdır
 * (`OversellProtectionReport`). Depo üyesi görmez (katalog görme yetkisi).
 */
final class OversellReportController extends Controller
{
    public function index(OversellProtectionReport $report): InertiaResponse
    {
        $to = CarbonImmutable::now('Europe/Istanbul');
        $from = $to->subDays(OversellProtectionReport::DEFAULT_DAYS)->startOfDay();

        return Inertia::render('Reports/Oversell', [
            'report' => $report->build($from, $to),
            'days' => OversellProtectionReport::DEFAULT_DAYS,
        ]);
    }
}
