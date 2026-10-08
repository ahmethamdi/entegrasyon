<?php

declare(strict_types=1);

namespace App\Domain\Identity\Support;

use App\Domain\Billing\Models\Subscription;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Süper admin özet sayıları — TÜM kiracılar üzerinden.
 *
 * Sorgular `DB::table` ile yazılı: kiracı kapsamı (`BelongsToTenant`) yalnız
 * Eloquent modellerinde çalışır, bu yüzden sayılar bağlamdan bağımsız olarak
 * TÜM kiracıları kapsar. `runAsSystem()` niyeti belgeler ve buraya ileride
 * bir Eloquent sorgusu eklenirse onun da tek kiracıya daralmasını önler.
 *
 * "KAYIT" ile "GERÇEK KULLANICI" ayrı sayılır: kayıt olup e-postasını
 * doğrulamayan ya da hiç kanal bağlamayan hesap ürünü kullanmıyordur.
 * Huni: kayıt → doğrulanmış → kanal bağlamış → ücretli.
 */
final class PlatformStats
{
    /** @return array<string, mixed> */
    public function summary(): array
    {
        return TenantContext::runAsSystem(function (): array {
            $since30 = now()->subDays(30);
            $since7 = now()->subDays(7);

            $users = DB::table('users');

            $tenantsWithChannel = DB::table('channel_connections')
                ->whereNotNull('connected_at')
                ->distinct()
                ->count('tenant_id');

            $paying = DB::table('subscriptions')
                ->whereIn('status', Subscription::ACTIVE_STATUSES)
                ->join('plans', 'plans.code', '=', 'subscriptions.plan_code')
                ->where('plans.price_monthly', '>', 0)
                ->distinct()
                ->count('subscriptions.tenant_id');

            return [
                'funnel' => [
                    'signups' => (clone $users)->count(),
                    'verified' => (clone $users)->whereNotNull('email_verified_at')->count(),
                    'withChannel' => $tenantsWithChannel,
                    'paying' => $paying,
                ],
                'signups7' => (clone $users)->where('created_at', '>=', $since7)->count(),
                'signups30' => (clone $users)->where('created_at', '>=', $since30)->count(),
                'activeUsers7' => (clone $users)->where('last_login_at', '>=', $since7)->count(),
                'tenants' => DB::table('tenants')->count(),
                'orders30' => DB::table('orders')->where('placed_at', '>=', $since30)->count(),
                'dailySignups' => $this->dailySignups(30),
                'channels' => $this->channelBreakdown(),
                'plans' => $this->planBreakdown(),
                'revenue' => $this->monthlyRevenue(),
            ];
        });
    }

    /**
     * Son N günün günlük kayıt sayısı — boş günler 0 ile doldurulur
     * (grafikte eksik gün "veri yok" değil "kayıt yok" demek).
     *
     * @return list<array{date: string, count: int}>
     */
    private function dailySignups(int $days): array
    {
        $rows = DB::table('users')
            ->selectRaw('DATE(created_at) AS day, COUNT(*) AS n')
            ->where('created_at', '>=', now()->subDays($days - 1)->startOfDay())
            ->groupBy('day')
            ->pluck('n', 'day');

        $out = [];

        for ($i = $days - 1; $i >= 0; $i--) {
            $day = now()->subDays($i)->toDateString();
            $out[] = ['date' => $day, 'count' => (int) ($rows[$day] ?? 0)];
        }

        return $out;
    }

    /** @return list<array{code: string, name: string, total: int, healthy: int, unhealthy: int}> */
    private function channelBreakdown(): array
    {
        return DB::table('channel_connections as c')
            ->leftJoin('channel_types as t', 't.code', '=', 'c.channel_type_code')
            ->whereNotNull('c.connected_at')
            ->whereIn('c.status', ['active', 'pending'])
            ->groupBy('c.channel_type_code', 't.name')
            ->orderByDesc(DB::raw('COUNT(*)'))
            ->get([
                'c.channel_type_code as code',
                't.name as name',
                DB::raw('COUNT(*) as total'),
                DB::raw("SUM(CASE WHEN c.health_status = 'healthy' THEN 1 ELSE 0 END) as healthy"),
            ])
            ->map(fn ($r): array => [
                'code' => $r->code,
                'name' => $r->name ?? $r->code,
                'total' => (int) $r->total,
                'healthy' => (int) $r->healthy,
                'unhealthy' => (int) $r->total - (int) $r->healthy,
            ])->all();
    }

    /** @return list<array{code: string, name: string, isPublic: bool, tenants: int}> */
    private function planBreakdown(): array
    {
        return DB::table('plans as p')
            ->leftJoin('subscriptions as s', function ($join): void {
                $join->on('s.plan_code', '=', 'p.code')->whereIn('s.status', Subscription::ACTIVE_STATUSES);
            })
            ->groupBy('p.code', 'p.name', 'p.is_public', 'p.price_monthly')
            ->orderBy('p.price_monthly')
            ->get(['p.code', 'p.name', 'p.is_public', DB::raw('COUNT(s.id) as tenants')])
            ->map(fn ($r): array => [
                'code' => $r->code,
                'name' => $r->name,
                'isPublic' => (bool) $r->is_public,
                'tenants' => (int) $r->tenants,
            ])->all();
    }

    /**
     * Aylık tekrarlayan gelir TAHMİNİ — para birimine göre ayrı.
     *
     * Shopify'dan faturalanan abonelik USD fiyatıyla (`shopify_price_usd`),
     * diğerleri planın kendi fiyat ve birimiyle sayılır. Birimler TOPLANMAZ:
     * TL ile dolar aynı sayıya karışsaydı rakam anlamsızlaşırdı.
     *
     * @return array<string, string>
     */
    private function monthlyRevenue(): array
    {
        $rows = DB::table('subscriptions as s')
            ->join('plans as p', 'p.code', '=', 's.plan_code')
            ->whereIn('s.status', Subscription::ACTIVE_STATUSES)
            ->get(['s.provider', 'p.price_monthly', 'p.currency', 'p.shopify_price_usd']);

        $totals = [];

        foreach ($rows as $r) {
            [$amount, $currency] = $r->provider === 'shopify' && $r->shopify_price_usd !== null
                ? [(float) $r->shopify_price_usd, 'USD']
                : [(float) $r->price_monthly, (string) ($r->currency ?: 'TRY')];

            if ($amount <= 0) {
                continue;
            }

            $totals[$currency] = ($totals[$currency] ?? 0) + (int) round($amount * 100);
        }

        ksort($totals);

        return array_map(fn (int $minor): string => number_format($minor / 100, 2, '.', ''), $totals);
    }
}
