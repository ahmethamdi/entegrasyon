<?php

declare(strict_types=1);

namespace App\Domain\Channels\Models;

use App\Support\Tenancy\BelongsToTenant;
use App\Support\Uuid\HasUuidV7;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * İç kategori → kanal kategorisi eşleştirmesi — KİRACIYA AİT.
 *
 * Mimari Karar Dokümanı v2.2 · §4 · Mapping, §13 · Faz 2, §14 · ön koşul.
 *
 * DEĞİŞMEZ KURAL — EŞLEŞTİRME KİRACIYA AİTTİR, TAKSONOMİNİN AKSİNE:
 *   `ChannelCategory` `BelongsToTenant` KULLANMAZ çünkü ağaç kanalın
 *   gerçeğidir. Bu model KULLANIR: hangi kategoriye açılacağı satıcının
 *   kararıdır ve iki satıcı farklı karar verebilir.
 *
 * DEĞİŞMEZ KURAL — SÜRÜM KOLONU BAYATLIĞI SORGULANABİLİR YAPAR:
 *   `taxonomy_version` FK'nın işaret ettiği satırdan da okunabilirdi, ama
 *   kolon olarak tutulması "hangi eşleştirmeler eski sürüme bakıyor"
 *   sorusunu tek indeksle cevaplar. Eşleştirme bayatladığında SİLİNMEZ,
 *   işaretlenir — satıcının emeği yok olmaz.
 *
 * @property string $id
 * @property string $internal_category_id
 * @property string $channel_type_code
 * @property string $channel_category_id
 * @property string $taxonomy_version
 * @property int $confidence
 * @property string $mapped_by
 * @property Carbon|null $verified_at
 */
class CategoryMapping extends Model
{
    use BelongsToTenant;
    use HasUuidV7;

    protected $fillable = [
        'tenant_id',
        'internal_category_id',
        'channel_type_code',
        'channel_category_id',
        'taxonomy_version',
        'confidence',
        'mapped_by',
        'verified_at',
    ];

    protected function casts(): array
    {
        return [
            'confidence' => 'integer',
            'verified_at' => 'datetime',
        ];
    }

    public function channelCategory(): BelongsTo
    {
        return $this->belongsTo(ChannelCategory::class, 'channel_category_id');
    }

    public function channelType(): BelongsTo
    {
        return $this->belongsTo(ChannelType::class, 'channel_type_code', 'code');
    }

    /**
     * Bayat eşleştirmeler — kanal yeni sürüm yayınlamış ama bu satır hâlâ
     * eskisine bakıyor.
     *
     * `category_mappings_version_idx` tam bu sorgu içindir.
     *
     * @param  Builder<CategoryMapping>  $query
     */
    public function scopeStaleFor(Builder $query, string $channelTypeCode, string $currentVersion): void
    {
        $query->where('channel_type_code', $channelTypeCode)
            ->where('taxonomy_version', '!=', $currentVersion);
    }
}
