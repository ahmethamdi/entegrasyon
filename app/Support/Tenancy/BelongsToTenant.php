<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use App\Domain\Identity\Models\Tenant;
use App\Support\Tenancy\Exceptions\MissingTenantContextException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Kiracı kapsamını framework seviyesinde zorunlu kılar.
 *
 * Mimari Karar Dokümanı v2.2 · §11 · Karar 28.
 *
 * - Global scope her sorguya tenant_id filtresi ekler.
 * - Bağlam yoksa ve sistem bağlamı da yoksa istisna fırlatılır (fail-closed).
 * - create() sırasında tenant_id otomatik doldurulur.
 *
 * Bu trait'i kullanan modelin tablosunda tenant_id kolonu bulunmak zorundadır.
 */
trait BelongsToTenant
{
    protected static function bootBelongsToTenant(): void
    {
        static::addGlobalScope('tenant', function (Builder $builder): void {
            $tenantId = TenantContext::id();

            if ($tenantId !== null) {
                $builder->where(
                    $builder->getModel()->qualifyColumn('tenant_id'),
                    $tenantId
                );

                return;
            }

            // Bağlam yok. Sistem bağlamı açıkça istenmişse kapsamsız devam et,
            // aksi halde sessiz sızıntı yerine istisna fırlat.
            if (! TenantContext::isSystemContext()) {
                throw MissingTenantContextException::forQuery(static::class);
            }
        });

        static::creating(function ($model): void {
            if ($model->getAttribute('tenant_id') === null) {
                $model->setAttribute('tenant_id', TenantContext::idOrFail());
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
