<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Models;

use App\Support\Tenancy\BelongsToTenant;
use App\Support\Uuid\HasUuidV7;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Seçenek değeri — "Kırmızı", "L".
 *
 * Mimari Karar Dokümanı v2.2 · §4 · tablo 008.
 *
 * @property string $id
 * @property string $value
 */
class OptionValue extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuidV7;

    protected $fillable = ['tenant_id', 'option_definition_id', 'value', 'position'];

    protected function casts(): array
    {
        return ['position' => 'integer'];
    }

    public function definition(): BelongsTo
    {
        return $this->belongsTo(OptionDefinition::class, 'option_definition_id');
    }
}
