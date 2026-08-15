<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Models;

use App\Support\Tenancy\BelongsToTenant;
use App\Support\Uuid\HasUuidV7;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Varyant ↔ seçenek değeri bağı.
 *
 * Mimari Karar Dokümanı v2.2 · §4 · tablo 009.
 * JSONB yerine ilişkisel: attribute_value_mappings bir option_value_id'ye
 * bağlanmak zorundadır ve JSONB'de bu bağ kurulamaz.
 *
 * @property string $id
 */
class VariantOption extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuidV7;

    protected $fillable = [
        'tenant_id',
        'variant_id',
        'option_definition_id',
        'option_value_id',
    ];

    public function variant(): BelongsTo
    {
        return $this->belongsTo(Variant::class);
    }

    public function definition(): BelongsTo
    {
        return $this->belongsTo(OptionDefinition::class, 'option_definition_id');
    }

    public function value(): BelongsTo
    {
        return $this->belongsTo(OptionValue::class, 'option_value_id');
    }
}
