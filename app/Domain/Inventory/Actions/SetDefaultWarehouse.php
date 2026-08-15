<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Actions;

use App\Domain\Inventory\Exceptions\InactiveWarehouseException;
use App\Domain\Inventory\Models\Warehouse;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Varsayılan depo değişimi — tek transaction, iki adım.
 *
 * Mimari Karar Dokümanı v2.2 · §5 · Varsayılan depo değişimi.
 *
 * warehouses_one_default_per_tenant kısmi tekil indeksi ertelenemez
 * (PostgreSQL'de DEFERRABLE yalnızca tablo kısıtlarına uygulanır). Bu yüzden
 * önce mevcut varsayılan düşürülür, sonra yenisi kaldırılır: aradaki anda
 * indeks hiçbir zaman ihlal edilmez.
 *
 * Hata durumunda transaction geri alınır ve eski varsayılan korunur.
 */
final class SetDefaultWarehouse
{
    public function run(string $tenantId, string $newWarehouseId): Warehouse
    {
        return TenantContext::runFor($tenantId, function () use ($tenantId, $newWarehouseId): Warehouse {
            return DB::transaction(function () use ($tenantId, $newWarehouseId): Warehouse {

                // (1) Hedef depo bu kiracıya mı ait?
                //     Global scope zaten filtreliyor; tenant_id ayrıca açıkça
                //     doğrulanır — çapraz kiracı yazma kapatılır.
                //     Bulunamazsa ModelNotFoundException fırlatılır.
                $warehouse = Warehouse::query()
                    ->where('id', $newWarehouseId)
                    ->where('tenant_id', $tenantId)
                    ->lockForUpdate()
                    ->firstOrFail();

                if (! $warehouse->is_active) {
                    throw InactiveWarehouseException::cannotBeDefault($warehouse->id);
                }

                if ($warehouse->is_default) {
                    return $warehouse;                 // zaten varsayılan
                }

                // (2) Mevcut varsayılanı düşür — indeks bu adımdan sonra boş.
                Warehouse::query()
                    ->where('tenant_id', $tenantId)
                    ->where('is_default', true)
                    ->update(['is_default' => false, 'updated_at' => now()]);

                // (3) Yenisini kaldır — indeks yeniden tek satırla dolar.
                $warehouse->forceFill(['is_default' => true])->save();

                return $warehouse->refresh();
            });
        });
    }
}
