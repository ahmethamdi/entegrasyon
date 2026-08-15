<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Models;

use App\Support\Tenancy\BelongsToTenant;
use App\Support\Uuid\HasUuidV7;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Kanonik ürün.
 *
 * Mimari Karar Dokümanı v2.2 · §4 · tablo 005.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $sku
 * @property int $content_version
 */
class Product extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuidV7;

    protected $fillable = [
        'tenant_id',
        'sku',
        'title',
        'description',
        'brand',
        'internal_category_id',
        'status',
        'content_version',
    ];

    protected function casts(): array
    {
        return [
            'content_version' => 'integer',
        ];
    }

    public function variants(): HasMany
    {
        return $this->hasMany(Variant::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class);
    }
}
