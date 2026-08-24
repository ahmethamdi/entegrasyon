<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Enums\AuditAction;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\TenantUser;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Models\Warehouse;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Kiracı, sahip üyeliği ve varsayılan depo — tek transaction.
 *
 * Mimari Karar Dokümanı v2.2 · §19 · sınıf 5.
 *
 * "En az bir varsayılan depo bulunmalı" kuralının garantisi buradan gelir;
 * veritabanı kısıtıyla zorlanmaz (§4 · DDL Correction).
 */
final class CreateTenant
{
    public const DEFAULT_WAREHOUSE_CODE = 'default';

    public function run(
        string $name,
        User $owner,
        ?string $slug = null,
        string $currency = 'TRY',
        string $locale = 'tr',
        string $timezone = 'Europe/Istanbul',
    ): Tenant {
        return DB::transaction(function () use ($name, $owner, $slug, $currency, $locale, $timezone): Tenant {
            $tenant = Tenant::create([
                'name' => $name,
                'slug' => $slug ?? $this->uniqueSlug($name),
                'status' => 'active',
                'default_currency' => $currency,
                'default_locale' => $locale,
                'timezone' => $timezone,
            ]);

            TenantUser::create([
                'tenant_id' => $tenant->id,
                'user_id' => $owner->id,
                'role' => 'owner',
                'accepted_at' => now(),
            ]);

            // Varsayılan depo yalnızca bir kez yaratılır.
            //
            // İki koruma katmanı:
            //   1. firstOrCreate — aynı kod ikinci kez yaratılmaz
            //   2. warehouses_one_default_per_tenant kısmi tekil indeksi —
            //      is_default = true satırın ikinci kez eklenmesini engeller
            //
            // Kiracı yeni yaratıldığı için bağlam henüz kurulmamış olabilir;
            // tenant_id açıkça verilir ve sorgu sistem bağlamında çalışır.
            TenantContext::runAsSystem(function () use ($tenant): void {
                Warehouse::firstOrCreate(
                    [
                        'tenant_id' => $tenant->id,
                        'code' => self::DEFAULT_WAREHOUSE_CODE,
                    ],
                    [
                        'name' => 'Default Warehouse',
                        'is_default' => true,
                        'is_active' => true,
                        'priority' => 0,
                    ],
                );
            });

            // DENETİM KAYDI (§11) — hesabın doğum kaydı.
            //
            // Kiracı YENİ yaratıldı ve `TenantContext` henüz onu göstermiyor
            // olabilir (kayıt akışı bağlam kurmadan önce çalışır); kiracı
            // kimliği bu yüzden AÇIKÇA verilir. Verilmeseydi
            // `TenantContext::idOrFail()` istisna fırlatır, `RecordAuditLog`
            // onu yutar ve hesabın ilk kaydı SESSİZCE hiç yazılmazdı.
            //
            // Aktör de açıkça verilir: kayıt akışında kullanıcı henüz
            // oturum açmamıştır ve `auth()->id()` NULL döner — oysa hesabı
            // kimin açtığı denetimin ilk sorusudur.
            //
            // `new` ile değil KAPSAYICIDAN çözülür: `CreateTenant` pek çok
            // yerde `new CreateTenant` ile kuruluyor ve kurucuya bağımlılık
            // eklemek o çağrıların hepsini kırardı.
            app(RecordAuditLog::class)->run(
                action: AuditAction::TENANT_CREATED,
                subjectType: 'tenants',
                subjectId: $tenant->id,
                changes: ['name' => $name, 'slug' => $tenant->slug],
                userId: $owner->id,
                tenantId: $tenant->id,
            );

            return $tenant;
        });
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'tenant';
        $slug = $base;
        $suffix = 1;

        while (Tenant::where('slug', $slug)->exists()) {
            $slug = $base.'-'.(++$suffix);
        }

        return $slug;
    }
}
