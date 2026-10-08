<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Billing\Actions\AssignPlanManually;
use App\Domain\Billing\Actions\CreateCustomPlan;
use App\Domain\Billing\Enums\QuotaMetric;
use App\Domain\Billing\Models\Plan;
use App\Domain\Billing\Models\Subscription;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Support\PlatformStats;
use App\Http\Controllers\Controller;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Süper admin ekranları — platformu işletenin paneli.
 *
 * Rota `can:superAdmin` arkasında ve `tenant` ara katmanı YOKTUR: yönetici
 * tek bir kiracının içinde değil, hepsinin üstündedir. Bu yüzden her okuma
 * `runAsSystem()` içindedir; kiracı bağlamında koşsaydı listeler sessizce
 * tek kiracıya daralırdı.
 *
 * Yazan tek iş plan atamadır ve o da kiracının KENDİ bağlamında yapılır
 * (`AssignPlanManually` → `runFor`), denetim kaydı o kiracıya düşer.
 */
final class AdminController extends Controller
{
    public function dashboard(PlatformStats $stats): InertiaResponse
    {
        return Inertia::render('Admin/Dashboard', ['stats' => $stats->summary()]);
    }

    public function tenants(Request $request): InertiaResponse
    {
        $search = trim((string) $request->query('q', ''));

        $page = TenantContext::runAsSystem(function () use ($search) {
            $query = DB::table('tenants as t')
                ->select([
                    't.id', 't.name', 't.created_at',
                    DB::raw('(SELECT u.email FROM tenant_users tu JOIN users u ON u.id = tu.user_id WHERE tu.tenant_id = t.id ORDER BY tu.created_at LIMIT 1) AS owner_email'),
                    DB::raw('(SELECT MAX(u.last_login_at) FROM tenant_users tu JOIN users u ON u.id = tu.user_id WHERE tu.tenant_id = t.id) AS last_login_at'),
                    DB::raw('(SELECT COUNT(*) FROM channel_connections c WHERE c.tenant_id = t.id AND c.connected_at IS NOT NULL AND c.status IN (\'active\', \'pending\')) AS channels'),
                    DB::raw('(SELECT COUNT(*) FROM products p WHERE p.tenant_id = t.id) AS products'),
                    DB::raw('(SELECT COUNT(*) FROM orders o WHERE o.tenant_id = t.id AND o.placed_at >= NOW() - INTERVAL \'30 days\') AS orders30'),
                    DB::raw('(SELECT s.plan_code FROM subscriptions s WHERE s.tenant_id = t.id AND s.status IN (\'active\', \'trialing\') ORDER BY s.created_at DESC LIMIT 1) AS plan_code'),
                ])
                ->orderByDesc('t.created_at');

            if ($search !== '') {
                $like = '%'.mb_strtolower($search).'%';
                $query->where(fn ($q) => $q
                    ->whereRaw('LOWER(t.name) LIKE ?', [$like])
                    ->orWhereExists(fn ($e) => $e->from('tenant_users as tu')
                        ->join('users as u', 'u.id', '=', 'tu.user_id')
                        ->whereColumn('tu.tenant_id', 't.id')
                        ->whereRaw('LOWER(u.email) LIKE ?', [$like])));
            }

            return $query->paginate(25)->withQueryString();
        });

        $planNames = TenantContext::runAsSystem(fn () => Plan::query()->pluck('name', 'code'));

        return Inertia::render('Admin/Tenants', [
            'search' => $search,
            'tenants' => [
                'data' => collect($page->items())->map(fn ($t): array => [
                    'id' => $t->id,
                    'name' => $t->name,
                    'ownerEmail' => $t->owner_email,
                    'createdAt' => Carbon::parse($t->created_at)->toIso8601String(),
                    'lastLoginAt' => $t->last_login_at === null ? null : Carbon::parse($t->last_login_at)->toIso8601String(),
                    'channels' => (int) $t->channels,
                    'products' => (int) $t->products,
                    'orders30' => (int) $t->orders30,
                    'plan' => $t->plan_code === null ? null : ($planNames[$t->plan_code] ?? $t->plan_code),
                ])->all(),
                'total' => $page->total(),
                'currentPage' => $page->currentPage(),
                'lastPage' => $page->lastPage(),
            ],
        ]);
    }

    public function showTenant(string $tenant): InertiaResponse
    {
        return Inertia::render('Admin/TenantShow', TenantContext::runAsSystem(function () use ($tenant): array {
            $model = Tenant::query()->findOrFail($tenant);

            $users = DB::table('tenant_users as tu')
                ->join('users as u', 'u.id', '=', 'tu.user_id')
                ->where('tu.tenant_id', $model->id)
                ->orderBy('tu.created_at')
                ->get(['u.name', 'u.email', 'u.email_verified_at', 'u.last_login_at', 'tu.role'])
                ->map(fn ($u): array => [
                    'name' => $u->name,
                    'email' => $u->email,
                    'verified' => $u->email_verified_at !== null,
                    'role' => $u->role,
                    'lastLoginAt' => $u->last_login_at === null ? null : Carbon::parse($u->last_login_at)->toIso8601String(),
                ])->all();

            $connections = DB::table('channel_connections as c')
                ->leftJoin('channel_types as t', 't.code', '=', 'c.channel_type_code')
                ->where('c.tenant_id', $model->id)
                ->orderBy('c.created_at')
                ->get(['c.label', 'c.channel_type_code', 't.name as type_name', 'c.status', 'c.health_status', 'c.last_error', 'c.connected_at'])
                ->map(fn ($c): array => [
                    'label' => $c->label,
                    'channel' => $c->type_name ?? $c->channel_type_code,
                    'status' => $c->status,
                    'health' => $c->health_status,
                    'lastError' => $c->last_error === null ? null : mb_substr((string) $c->last_error, 0, 300),
                    'connected' => $c->connected_at !== null,
                ])->all();

            $subscriptions = Subscription::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $model->id)
                ->orderByDesc('created_at')
                ->limit(10)
                ->get()
                ->map(fn (Subscription $s): array => [
                    'plan' => $s->plan_code,
                    'status' => $s->status,
                    'provider' => $s->provider,
                    'startedAt' => $s->started_at?->toIso8601String(),
                    'endsAt' => $s->current_period_end?->toIso8601String(),
                ])->all();

            return [
                'tenant' => [
                    'id' => $model->id,
                    'name' => $model->name,
                    'createdAt' => $model->created_at?->toIso8601String(),
                ],
                'users' => $users,
                'connections' => $connections,
                'subscriptions' => $subscriptions,
                'usage' => [
                    'products' => DB::table('products')->where('tenant_id', $model->id)->count(),
                    'channels' => DB::table('channel_connections')->where('tenant_id', $model->id)->whereNotNull('connected_at')->whereIn('status', ['active', 'pending'])->count(),
                    'orders30' => DB::table('orders')->where('tenant_id', $model->id)->where('placed_at', '>=', now()->subDays(30))->count(),
                ],
                'plans' => $this->planOptions(),
            ];
        }));
    }

    public function assignPlan(Request $request, string $tenant, AssignPlanManually $assign): RedirectResponse
    {
        $validated = $request->validate([
            'plan_code' => ['required', 'string'],
            'ends_at' => ['nullable', 'date', 'after:today'],
        ]);

        [$model, $plan] = TenantContext::runAsSystem(fn (): array => [
            Tenant::query()->findOrFail($tenant),
            Plan::query()->findOrFail($validated['plan_code']),
        ]);

        $assign->run(
            $model,
            $plan,
            empty($validated['ends_at']) ? null : Carbon::parse($validated['ends_at'])->endOfDay(),
            $request->user()?->id,
        );

        return redirect("/admin/tenants/{$model->id}")->with('success', __(':plan planı :tenant için atandı.', [
            'plan' => $plan->name,
            'tenant' => $model->name,
        ]));
    }

    public function plans(): InertiaResponse
    {
        return Inertia::render('Admin/Plans', ['plans' => $this->planOptions(withCounts: true)]);
    }

    public function storePlan(Request $request, CreateCustomPlan $create): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'price_monthly' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'currency' => ['required', 'in:TRY,EUR,USD'],
            'max_products' => ['nullable', 'integer', 'min:1'],
            'max_channels' => ['nullable', 'integer', 'min:1'],
        ]);

        $plan = $create->run(
            $validated['name'],
            (string) $validated['price_monthly'],
            $validated['currency'],
            isset($validated['max_products']) ? (int) $validated['max_products'] : null,
            isset($validated['max_channels']) ? (int) $validated['max_channels'] : null,
        );

        return redirect('/admin/plans')->with('success', __(':plan oluşturuldu. Müşteri sayfasından atayabilirsin.', ['plan' => $plan->name]));
    }

    /** @return list<array<string, mixed>> */
    private function planOptions(bool $withCounts = false): array
    {
        return TenantContext::runAsSystem(function () use ($withCounts): array {
            $counts = $withCounts
                ? Subscription::query()->withoutGlobalScopes()
                    ->whereIn('status', Subscription::ACTIVE_STATUSES)
                    ->groupBy('plan_code')
                    ->pluck(DB::raw('COUNT(*)'), 'plan_code')
                : collect();

            return Plan::query()->orderBy('is_public', 'desc')->orderBy('price_monthly')->get()
                ->map(fn (Plan $p): array => [
                    'code' => $p->code,
                    'name' => $p->name,
                    'price' => (string) $p->price_monthly,
                    'currency' => $p->currency,
                    'isPublic' => $p->is_public,
                    'maxProducts' => $p->limitFor(QuotaMetric::PRODUCTS),
                    'maxChannels' => $p->limitFor(QuotaMetric::CHANNELS),
                    'tenants' => (int) ($counts[$p->code] ?? 0),
                ])->all();
        });
    }
}
