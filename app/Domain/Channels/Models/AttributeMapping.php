<?php

declare(strict_types=1);

namespace App\Domain\Channels\Models;

use App\Domain\Catalog\Models\OptionDefinition;
use App\Support\Tenancy\BelongsToTenant;
use App\Support\Uuid\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * İç seçenek tanımı → kanal özniteliği eşleştirmesi — KİRACIYA AİT.
 *
 * Mimari Karar Dokümanı v2.2 · §4 · Mapping, §14 · zorunlu öznitelikler.
 *
 * DEĞİŞMEZ KURAL — EŞLEŞTİRME KATEGORİ BAŞINADIR:
 *   Aynı "Beden" seçenek tanımı, kanalın elbise kategorisinde farklı bir
 *   `external_attribute_id` taşır, ayakkabı kategorisinde farklı. Anahtar
 *   `(kiracı, seçenek tanımı, kanal kategorisi)`; kategori anahtardan
 *   çıkarılsaydı satıcı tek bir eşleştirmeyle tüm kategorileri yanlış
 *   bağlar ve kanal doğrulama hatası dönerdi.
 *
 * @property string $id
 * @property string $option_definition_id
 * @property string $channel_category_id
 * @property string $external_attribute_id
 */
class AttributeMapping extends Model
{
    use BelongsToTenant;
    use HasUuidV7;

    protected $fillable = [
        'tenant_id',
        'option_definition_id',
        'channel_category_id',
        'external_attribute_id',
    ];

    public function optionDefinition(): BelongsTo
    {
        return $this->belongsTo(OptionDefinition::class, 'option_definition_id');
    }

    public function channelCategory(): BelongsTo
    {
        return $this->belongsTo(ChannelCategory::class, 'channel_category_id');
    }
}
