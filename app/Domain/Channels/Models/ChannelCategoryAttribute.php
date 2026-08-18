<?php

declare(strict_types=1);

namespace App\Domain\Channels\Models;

use App\Support\Uuid\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Kategorinin öznitelik tanımı — KİRACISIZ.
 *
 * Mimari Karar Dokümanı v2.2 · §4 · Mapping, §14 · zorunlu öznitelikler.
 *
 * Kategori gibi bu da KANALA aittir: "bu kategoride beden zorunludur"
 * bilgisi satıcıdan bağımsızdır. Satıcının kararı olan eşleştirme
 * (`attribute_mappings`) ayrı tablodadır ve kiracı başınadır.
 *
 * `is_required` §14'ün ön koşul kapısını besler: eksikse listing `blocked`
 * olur ve STOK AKIŞI ETKİLENMEZ.
 *
 * `is_variant_defining` ürünün kaç varyantla açılacağını belirler (beden,
 * renk); sıradan bir öznitelikten farklı ele alınır.
 *
 * @property string $id
 * @property string $channel_category_id
 * @property string $external_attribute_id
 * @property string $name
 * @property bool $is_required
 * @property bool $is_variant_defining
 * @property array<int, array<string, mixed>> $allowed_values
 */
class ChannelCategoryAttribute extends Model
{
    use HasUuidV7;

    protected $fillable = [
        'channel_category_id',
        'external_attribute_id',
        'name',
        'is_required',
        'is_variant_defining',
        'data_type',
        'allowed_values',
    ];

    protected function casts(): array
    {
        return [
            'is_required' => 'boolean',
            'is_variant_defining' => 'boolean',
            'allowed_values' => 'array',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ChannelCategory::class, 'channel_category_id');
    }
}
