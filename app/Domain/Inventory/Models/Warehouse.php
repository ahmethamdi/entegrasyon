<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Models;

use App\Support\Tenancy\BelongsToTenant;
use App\Support\Uuid\HasUuidV7;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Depo.
 *
 * Mimari Karar Dokümanı v2.2 · §4 · tablo 004.
 *
 * Kiracı başına en fazla BİR varsayılan depo — kısmi tekil indeks
 * warehouses_one_default_per_tenant tarafından garanti edilir.
 * Varsayılan değişimi SetDefaultWarehouse action'ı ile yapılır.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $code
 * @property bool $is_default
 * @property bool $is_active
 */
class Warehouse extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuidV7;

    protected $fillable = [
        'tenant_id',
        'code',
        'name',
        'is_default',
        'is_active',
        'priority',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'priority' => 'integer',
        ];
    }

    public function inventoryLevels(): HasMany
    {
        return $this->hasMany(InventoryLevel::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class);
    }
}
