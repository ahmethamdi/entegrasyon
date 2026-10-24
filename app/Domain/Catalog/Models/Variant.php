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
 * @property string $kind `standard` | `bundle`
 * @property int $bundle_revision setin tanım sürümü (stok sürümüne eklenir)
 */
class Variant extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuidV7;

    public const KIND_STANDARD = 'standard';

    /**
     * Set / paket — kendi stoğu YOK, bileşenlerinden türetilir
     * (`variant_components`, `SellableStock`). Stok hareketi yazılamaz.
     */
    public const KIND_BUNDLE = 'bundle';

    protected $fillable = [
        'tenant_id',
        'product_id',
        'sku',
        'barcode',
        'price',
        'compare_at_price',
        'cost_price',
        'currency',
        'weight_grams',
        'status',
        'content_version',
        'kind',
        'bundle_revision',
    ];

    protected $attributes = [
        'kind' => self::KIND_STANDARD,
        'bundle_revision' => 0,
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'compare_at_price' => 'decimal:2',
            'cost_price' => 'decimal:2',
            'weight_grams' => 'integer',
            'content_version' => 'integer',
            'bundle_revision' => 'integer',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function isBundle(): bool
    {
        return $this->kind === self::KIND_BUNDLE;
    }

    /** Setin bileşenleri (yalnız set varyantında dolu). */
    public function components(): HasMany
    {
        return $this->hasMany(VariantComponent::class, 'bundle_variant_id');
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
