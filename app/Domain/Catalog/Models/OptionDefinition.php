<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Models;

use App\Support\Tenancy\BelongsToTenant;
use App\Support\Uuid\HasUuidV7;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Seçenek tanımı — "Renk", "Beden".
 *
 * Mimari Karar Dokümanı v2.2 · §4 · tablo 007.
 * İlişkisel tutulur: kanal öznitelik eşleştirmesi buna bağlanır.
 *
 * @property string $id
 * @property string $name
 */
class OptionDefinition extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuidV7;

    protected $fillable = ['tenant_id', 'name', 'position'];

    protected function casts(): array
    {
        return ['position' => 'integer'];
    }

    public function values(): HasMany
    {
        return $this->hasMany(OptionValue::class);
    }
}
