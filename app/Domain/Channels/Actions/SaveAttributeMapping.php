<?php

declare(strict_types=1);

namespace App\Domain\Channels\Actions;

use App\Domain\Catalog\Models\OptionDefinition;
use App\Domain\Channels\Models\AttributeMapping;
use App\Domain\Channels\Models\ChannelCategory;
use App\Domain\Channels\Models\ChannelCategoryAttribute;
use App\Support\Tenancy\TenantContext;
use InvalidArgumentException;

/**
 * İç seçenek tanımını ("Beden") kanalın bir özniteliğine bağlar.
 *
 * Mimari Karar Dokümanı v2.2 · §4 · Mapping, §14 · zorunlu öznitelikler.
 *
 * DEĞİŞMEZ KURAL — EŞLEŞTİRME KATEGORİ BAŞINADIR:
 *   Aynı "Beden" tanımı kanalın elbise kategorisinde farklı bir
 *   `external_attribute_id` taşır, ayakkabıda farklı. Anahtar
 *   `(kiracı, seçenek tanımı, kanal kategorisi)`.
 *
 * DEĞİŞMEZ KURAL — ÖZNİTELİK KATEGORİDE GERÇEKTEN VAR OLMALIDIR:
 *   Uydurma bir kimlik kanalda doğrulama hatası verir, `VALIDATION`
 *   KALICI sayılır ve listing "düzeltilemez" damgasıyla ölür. Oysa hata
 *   bizim tarafımızdadır ve kaydederken yakalanabilir.
 *
 * ÖZNİTELİK TANIMI KİRACISIZ OKUNUR: `ChannelCategoryAttribute` kanala
 * aittir ve global scope taşımaz — doğrulama sorgusu kiracı bağlamından
 * bağımsız çalışır.
 */
final class SaveAttributeMapping
{
    public function run(
        OptionDefinition $optionDefinition,
        ChannelCategory $channelCategory,
        string $externalAttributeId,
    ): AttributeMapping {
        $tenantId = TenantContext::idOrFail();

        $exists = ChannelCategoryAttribute::query()
            ->where('channel_category_id', $channelCategory->id)
            ->where('external_attribute_id', $externalAttributeId)
            ->exists();

        if (! $exists) {
            throw new InvalidArgumentException(sprintf(
                '"%s" özniteliği %s kategorisinde bulunmuyor.',
                $externalAttributeId,
                $channelCategory->path ?? $channelCategory->name,
            ));
        }

        return AttributeMapping::query()->updateOrCreate(
            [
                'tenant_id' => $tenantId,
                'option_definition_id' => $optionDefinition->id,
                'channel_category_id' => $channelCategory->id,
            ],
            ['external_attribute_id' => $externalAttributeId],
        );
    }
}
