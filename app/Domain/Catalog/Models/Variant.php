<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Models;

use App\Domain\Inventory\Models\InventoryLevel;
use App\Domain\Inventory\Models\InventoryMovement;
use App\Support\Tenancy\BelongsToTenant;
use App\Support\Uuid\HasUuidV7;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Satılabilir birim. Stok her zaman varyant seviyesinde tutulur.
 *
 * Mimari Karar Dokümanı v2.2 · §4 · tablo 006.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $sku
 * @property string|null $barcode
 * @property int $content_version
 */
class Variant extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuidV7;

    protected $fillable = [
        'tenant_id',
        'product_id',
        'sku',
        'barcode',
        'price',
        'compare_at_price',
        'currency',
        'weight_grams',
        'status',
        'content_version',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'compare_at_price' => 'decimal:2',
            'weight_grams' => 'integer',
            'content_version' => 'integer',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function options(): HasMany
    {
        return $this->hasMany(VariantOption::class);
    }

    public function inventoryLevels(): HasMany
    {
        return $this->hasMany(InventoryLevel::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class);
    }

    /** Belirli bir depodaki bakiye satırı. */
    public function inventoryLevelFor(string $warehouseId): ?InventoryLevel
    {
        return $this->inventoryLevels()
            ->where('warehouse_id', $warehouseId)
            ->first();
    }
}
