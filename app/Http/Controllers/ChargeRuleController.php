<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Catalog\Models\Product;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Finance\Jobs\ApplyChargeRulesToRecentOrders;
use App\Domain\Finance\Models\ChannelChargeRule;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Kanal kesinti varsayılanları (kâr paneli B): bağlantı başına komisyon
 * yüzdesi, sipariş başı kargo kesintisi ve paketleme maliyeti; kategori
 * başına komisyon istisnası. Kaydedince son 90 günün kesintisi olmayan
 * siparişlerine uygulanır (kuyrukta).
 */
final class ChargeRuleController extends Controller
{
    public function index(): InertiaResponse
    {
        $rules = ChannelChargeRule::query()->get()->groupBy('channel_connection_id');

        $connections = ChannelConnection::query()
            ->orderBy('created_at')
            ->get(['id', 'label', 'channel_type_code', 'status'])
            ->map(function (ChannelConnection $c) use ($rules): array {
                $own = $rules->get($c->id, collect());
                $default = $own->firstWhere('internal_category_id', ChannelChargeRule::DEFAULT_CATEGORY);

                return [
                    'id' => $c->id,
                    'label' => $c->label,
                    'channel' => $c->channel_type_code,
                    'status' => $c->status,
                    'commissionPercent' => $default?->commission_percent === null ? null : (string) $default->commission_percent,
                    'shippingFee' => $default?->shipping_fee === null ? null : (string) $default->shipping_fee,
                    'packagingFee' => $default?->packaging_fee === null ? null : (string) $default->packaging_fee,
                    'categories' => $own->where('internal_category_id', '!=', ChannelChargeRule::DEFAULT_CATEGORY)
                        ->sortBy('internal_category_id')
                        ->map(fn (ChannelChargeRule $r): array => [
                            'category' => $r->internal_category_id,
                            'commissionPercent' => $r->commission_percent === null ? null : (string) $r->commission_percent,
                        ])->values()->all(),
                ];
            });

        return Inertia::render('Settings/ChargeRules', [
            'connections' => $connections,
            'categories' => Product::query()->whereNotNull('internal_category_id')->where('internal_category_id', '!=', '')
                ->distinct()->orderBy('internal_category_id')->limit(500)->pluck('internal_category_id'),
            'days' => ApplyChargeRulesToRecentOrders::DAYS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'connection_id' => ['required', 'string'],
            'commission_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'shipping_fee' => ['nullable', 'numeric', 'min:0', 'max:99999'],
            'packaging_fee' => ['nullable', 'numeric', 'min:0', 'max:99999'],
            'categories' => ['array', 'max:200'],
            'categories.*.category' => ['required', 'string', 'max:255', 'distinct'],
            'categories.*.commission_percent' => ['required', 'numeric', 'min:0', 'max:100'],
        ]);

        // Kiracı scope'u: başka kiracının bağlantısı 404.
        $connection = ChannelConnection::query()->findOrFail($validated['connection_id']);

        DB::transaction(function () use ($connection, $validated): void {
            ChannelChargeRule::query()->updateOrCreate(
                ['channel_connection_id' => $connection->id, 'internal_category_id' => ChannelChargeRule::DEFAULT_CATEGORY],
                [
                    'commission_percent' => $validated['commission_percent'] ?? null,
                    'shipping_fee' => $validated['shipping_fee'] ?? null,
                    'packaging_fee' => $validated['packaging_fee'] ?? null,
                ],
            );

            $keep = collect($validated['categories'] ?? [])->pluck('category')->map(fn ($c): string => trim((string) $c))->filter()->all();

            ChannelChargeRule::query()
                ->where('channel_connection_id', $connection->id)
                ->where('internal_category_id', '!=', ChannelChargeRule::DEFAULT_CATEGORY)
                ->whereNotIn('internal_category_id', $keep)
                ->delete();

            foreach ($validated['categories'] ?? [] as $row) {
                $category = trim((string) $row['category']);

                if ($category === '') {
                    continue;
                }

                ChannelChargeRule::query()->updateOrCreate(
                    ['channel_connection_id' => $connection->id, 'internal_category_id' => $category],
                    ['commission_percent' => $row['commission_percent']],
                );
            }
        });

        ApplyChargeRulesToRecentOrders::dispatch(TenantContext::idOrFail(), $connection->id)->afterCommit();

        return back()->with('success', __('Kesinti varsayılanı kaydedildi. Son :days günün kesintisi olmayan siparişlerine uygulanıyor.', ['days' => ApplyChargeRulesToRecentOrders::DAYS]));
    }
}
