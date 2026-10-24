<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Models;

use App\Support\Tenancy\BelongsToTenant;
use App\Support\Uuid\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Setin bir bileşeni — "bu set, şu varyanttan şu kadar içerir".
 *
 * Set stoğu bu satırlardan TÜRETİLİR (`SellableStock`); saklanmaz.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $bundle_variant_id
 * @property string $component_variant_id
 * @property int $quantity
 */
class VariantComponent extends Model
{
    use BelongsToTenant;
    use HasUuidV7;

    protected $fillable = [
        'tenant_id',
        'bundle_variant_id',
        'component_variant_id',
        'quantity',
    ];

    protected function casts(): array
    {
        return ['quantity' => 'integer'];
    }

    public function bundle(): BelongsTo
    {
        return $this->belongsTo(Variant::class, 'bundle_variant_id');
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(Variant::class, 'component_variant_id');
    }
}
