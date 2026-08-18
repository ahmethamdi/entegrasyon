<?php

declare(strict_types=1);

namespace App\Domain\Channels\Actions;

use App\Domain\Channels\Models\CategoryMapping;
use App\Domain\Channels\Models\ChannelCategory;
use App\Support\Tenancy\TenantContext;
use InvalidArgumentException;

/**
 * İç kategoriyi kanalın bir kategorisine bağlar.
 *
 * Mimari Karar Dokümanı v2.2 · §13 · Faz 2 ("Kategori ve öznitelik
 * eşleştirme arayüzü"), §4 · Mapping, §14 · ön koşul kapısı.
 *
 * DEĞİŞMEZ KURAL — EŞLEŞTİRME KİRACIYA AİTTİR:
 *   Taksonomi `runAsSystem()` altında KİRACISIZ yazılır; bu action tam
 *   tersine kiracı bağlamı ZORUNLU kılar. Bağlamsız çağrı sessizce
 *   kiracısız bir satır açsaydı o eşleştirme hiçbir satıcıya görünmez ve
 *   ön koşul kapısı "eksik" demeye devam ederdi.
 *
 * DEĞİŞMEZ KURAL — YALNIZCA YAPRAĞA EŞLENİR:
 *   Ara kategoriye ürün açılamaz. Reddetmeseydik satıcı eşleştirmeyi
 *   tamamlanmış sanır, ön koşul kapısından geçer ve hata ancak kanala
 *   gönderildiğinde — kalıcı hata olarak — ortaya çıkardı. Hatayı
 *   kaydederken yakalamak sonra yakalamaktan ucuzdur.
 *
 * DEĞİŞMEZ KURAL — İKİNCİ SATIR AÇILMAZ:
 *   `UNIQUE(tenant_id, internal_category_id, channel_type_code)`. Yeniden
 *   eşleştirme var olan satırı GÜNCELLER; iki satır ürünün hangi
 *   kategoriye açılacağını belirsiz bırakırdı.
 *
 * SÜRÜM SEÇİLEN KATEGORİDEN OKUNUR, "güncel sürüm" diye ayrıca
 * sorgulanmaz: satıcı ekranda GÖRDÜĞÜ ağaçtan seçti ve karar o ağaca
 * aittir. Arada yeni sürüm yayınlansaydı, sorgulayan bir yazım satıcının
 * hiç görmediği bir sürümü damgalardı.
 */
final class SaveCategoryMapping
{
    public function run(
        string $internalCategoryId,
        ChannelCategory $channelCategory,
        string $mappedBy = 'user',
        int $confidence = 100,
    ): CategoryMapping {
        // Bağlam ZORUNLU: kiracısız eşleştirme kimseye görünmez.
        $tenantId = TenantContext::idOrFail();

        if (! $channelCategory->is_leaf) {
            throw new InvalidArgumentException(sprintf(
                '"%s" bir ara kategoridir; ürün yalnızca alt kategorisi olmayan bir kategoriye açılabilir.',
                $channelCategory->path ?? $channelCategory->name,
            ));
        }

        return CategoryMapping::query()->updateOrCreate(
            [
                'tenant_id' => $tenantId,
                'internal_category_id' => $internalCategoryId,
                'channel_type_code' => $channelCategory->channel_type_code,
            ],
            [
                'channel_category_id' => $channelCategory->id,
                // Sürüm SEÇİLEN kategoriden gelir; "en güncel sürüm"
                // ayrıca sorgulanmaz (yukarıdaki gerekçe).
                'taxonomy_version' => $channelCategory->taxonomy_version,
                'confidence' => $confidence,
                'mapped_by' => $mappedBy,
                // Elle yapılan eşleştirme DOĞRULANMIŞ doğar: satıcı bizzat
                // seçti ve ayrıca onaylatmak gereksiz iş yüküdür. Otomatik
                // öneri (`mapped_by = 'auto'`) doğrulanmamış kalır ve
                // panelde ayrı gösterilir.
                'verified_at' => $mappedBy === 'user' ? now() : null,
            ],
        );
    }
}
