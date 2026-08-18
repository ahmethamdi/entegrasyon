<?php

declare(strict_types=1);

namespace App\Domain\Channels\Models;

use App\Domain\Catalog\Models\OptionValue;
use App\Support\Tenancy\BelongsToTenant;
use App\Support\Uuid\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * İç seçenek değeri → kanal değeri eşleştirmesi — KİRACIYA AİT.
 *
 * Mimari Karar Dokümanı v2.2 · §4 · Mapping, §14 · zorunlu öznitelikler.
 *
 * DEĞİŞMEZ KURAL — DEĞER EŞLEŞTİRMESİ ÖZNİTELİK BAŞINADIR, KATEGORİ
 * BAŞINA DEĞİL:
 *   Kanalın "Beden" özniteliği tek bir değer listesi taşır ve o liste
 *   kategoriden bağımsızdır. Kategori de anahtara girseydi satıcı aynı
 *   "S → SMALL" kararını her kategori için yeniden vermek zorunda kalırdı.
 *   Bu, `AttributeMapping`'in tersidir ve fark bilinçlidir: öznitelik
 *   KİMLİĞİ kategoriye göre değişir, değer LİSTESİ değişmez.
 *
 * `external_value_label` NEDEN VAR: panelde kimlik yerine etiket gösterilir.
 * Satıcı `12345` kodundan ne seçtiğini anlayamaz ve yanlış değeri onaylar.
 *
 * @property string $id
 * @property string $option_value_id
 * @property string $external_attribute_id
 * @property string $external_value_id
 * @property string|null $external_value_label
 */
class AttributeValueMapping extends Model
{
    use BelongsToTenant;
    use HasUuidV7;

    protected $fillable = [
        'tenant_id',
        'option_value_id',
        'external_attribute_id',
        'external_value_id',
        'external_value_label',
    ];

    public function optionValue(): BelongsTo
    {
        return $this->belongsTo(OptionValue::class, 'option_value_id');
    }
}
