<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Billing\Models\Plan;
use App\Domain\Channels\Models\ChannelType;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Tanıtım sitesi — 34pazar.com ana sayfası, herkese açık.
 *
 * DEĞİŞMEZ KURAL — SİTE VERİ UYDURMAZ:
 *   Fiyatlar `plans` tablosundan, kanallar `channel_types`'tan okunur.
 *   Sayfaya elle fiyat yazılsaydı panel ile site ayrışır ve satıcı
 *   sitede gördüğünden farklı bir fiyatla karşılaşırdı. Kanal listesi de
 *   aynı: yazılmamış bir kanal "destekleniyor" görünemez; kapalı olan
 *   "yakında" diye gösterilir.
 */
final class SiteController extends Controller
{
    public function home(Request $request): InertiaResponse
    {
        // Plan ve kanal türü kiracıya bağlı DEĞİLDİR: ziyaretçinin kiracı
        // bağlamı olmadan da okunur.
        $plans = Plan::query()
            ->where('is_public', true)
            ->orderBy('price_monthly')
            ->get(['code', 'name', 'price_monthly', 'currency', 'limits']);

        $channels = ChannelType::query()
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get(['code', 'name', 'is_active']);

        return Inertia::render('Site/Home', [
            'plans' => $plans->map(fn (Plan $plan): array => [
                'code' => $plan->code,
                'name' => $plan->name,
                'priceMonthly' => (string) $plan->price_monthly,
                'currency' => $plan->currency ?? 'TRY',
                'productLimit' => $plan->limits['products'] ?? null,
                'channelLimit' => $plan->limits['channels'] ?? null,
            ])->all(),
            'channels' => $channels->map(fn (ChannelType $type): array => [
                'code' => $type->code,
                'name' => $type->name,
                'available' => (bool) $type->is_active,
            ])->all(),
            'isLoggedIn' => $request->user() !== null,
        ]);
    }
}
