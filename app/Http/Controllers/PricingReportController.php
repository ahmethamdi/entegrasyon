<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Finance\Support\PricingReport;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Fiyat ve kâr — bağlantı seçilir, hedef kâr girilir; canlı ilanların adet
 * başı katkısı, zararsız ve önerilen fiyatı. Salt okunur.
 */
final class PricingReportController extends Controller
{
    private const DEFAULT_TARGET = '15';

    public function index(Request $request, PricingReport $report): InertiaResponse
    {
        $connections = ChannelConnection::query()
            ->orderBy('created_at')
            ->get(['id', 'label', 'channel_type_code'])
            ->map(fn (ChannelConnection $c): array => ['id' => $c->id, 'label' => $c->label, 'channel' => $c->channel_type_code])
            ->values();

        $selected = $request->string('connection')->toString();
        if (! $connections->contains('id', $selected)) {
            $selected = (string) ($connections->first()['id'] ?? '');
        }

        $raw = str_replace(',', '.', $request->string('target', self::DEFAULT_TARGET)->toString());
        $target = is_numeric($raw) && (float) $raw >= 0 && (float) $raw < 100 ? $raw : self::DEFAULT_TARGET;

        return Inertia::render('Reports/Pricing', [
            'connections' => $connections,
            'filters' => ['connection' => $selected, 'target' => $target],
            'report' => $selected === '' ? null : $report->build($selected, $target),
            'maxListings' => PricingReport::MAX_LISTINGS,
        ]);
    }
}
