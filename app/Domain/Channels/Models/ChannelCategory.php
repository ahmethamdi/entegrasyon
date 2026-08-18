<?php

declare(strict_types=1);

namespace App\Domain\Channels\Models;

use App\Support\Uuid\HasUuidV7;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Kanalın kategori ağacındaki bir düğüm — KİRACISIZ.
 *
 * Mimari Karar Dokümanı v2.2 · §4 · Mapping, §14 · Trendyol.
 *
 * DEĞİŞMEZ KURAL — TAKSONOMİ KANALA AİTTİR, SATICIYA DEĞİL:
 *   `BelongsToTenant` KULLANILMAZ ve `tenant_id` kolonu YOKTUR. Trendyol'un
 *   kategori ağacı tüm satıcılar için aynıdır; kiracı başına kopyalansaydı
 *   aynı ağaç yüzlerce kez saklanır ve her kiracı ayrı ayrı çekmek zorunda
 *   kalırdı. Kiracıya ait olan EŞLEŞTİRMEDİR (`category_mappings`).
 *
 * DEĞİŞMEZ KURAL — SÜRÜM TEKİLLİĞİN PARÇASIDIR:
 *   Aynı `external_id` farklı `taxonomy_version` altında ayrı satırdır.
 *   Yeni sürüm eskiyi SİLMEZ; eşleştirmeler eski sürüme bağlıdır.
 *
 * @property string $id
 * @property string $channel_type_code
 * @property string $taxonomy_version
 * @property string $external_id
 * @property string|null $parent_external_id
 * @property string $name
 * @property string|null $path
 * @property bool $is_leaf
 */
class ChannelCategory extends Model
{
    use HasUuidV7;

    protected $fillable = [
        'channel_type_code',
        'taxonomy_version',
        'external_id',
        'parent_external_id',
        'name',
        'path',
        'is_leaf',
        'attributes_fetched_at',
    ];

    protected function casts(): array
    {
        return [
            'is_leaf' => 'boolean',
            'attributes_fetched_at' => 'datetime',
        ];
    }

    public function channelType(): BelongsTo
    {
        return $this->belongsTo(ChannelType::class, 'channel_type_code', 'code');
    }

    public function attributeDefinitions(): HasMany
    {
        return $this->hasMany(ChannelCategoryAttribute::class, 'channel_category_id');
    }

    /**
     * Yalnızca yapraklar — ürün ancak yaprağa açılır.
     *
     * `channel_categories_leaf_idx` kısmi indeksi tam bu sorgu içindir.
     *
     * @param  Builder<ChannelCategory>  $query
     */
    public function scopeLeaves(Builder $query): void
    {
        $query->where('is_leaf', true);
    }

    /**
     * Belirli bir sürümün ağacı.
     *
     * @param  Builder<ChannelCategory>  $query
     */
    public function scopeForVersion(Builder $query, string $channelTypeCode, string $version): void
    {
        $query->where('channel_type_code', $channelTypeCode)
            ->where('taxonomy_version', $version);
    }
}
