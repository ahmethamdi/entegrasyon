<?php

declare(strict_types=1);

namespace App\Domain\Channels\Actions;

use App\Domain\Catalog\Models\OptionValue;
use App\Domain\Channels\Models\AttributeValueMapping;
use App\Domain\Channels\Models\ChannelCategoryAttribute;
use App\Support\Tenancy\TenantContext;
use InvalidArgumentException;

/**
 * İç seçenek değerini ("S") kanalın bir değerine ("SMALL") bağlar.
 *
 * Mimari Karar Dokümanı v2.2 · §4 · Mapping, §14 · zorunlu öznitelikler.
 *
 * DEĞİŞMEZ KURAL — DEĞER EŞLEŞTİRMESİ ÖZNİTELİK BAŞINADIR, KATEGORİ
 * BAŞINA DEĞİL:
 *   `SaveAttributeMapping`'in tersi ve fark bilinçli: kanalın öznitelik
 *   KİMLİĞİ kategoriye göre değişir, ama o özniteliğin değer LİSTESİ
 *   değişmez. Kategori de anahtara girseydi satıcı aynı "S → SMALL"
 *   kararını her kategori için yeniden vermek zorunda kalırdı.
 *
 * DEĞİŞMEZ KURAL — BOŞ İZİNLİ DEĞER LİSTESİ "HİÇBİRİ" DEMEK DEĞİLDİR:
 *   Serbest metin kabul eden öznitelikte liste boştur ve HER değer
 *   geçerlidir. Boş listeyi "hiçbir değer geçerli değil" diye yorumlamak
 *   satıcının o özniteliği asla eşleştirememesi demekti.
 *
 * DEĞİŞMEZ KURAL — LİSTE VARSA DIŞINA ÇIKILMAZ:
 *   Uydurma değer kanalda `VALIDATION` hatası verir ve o hata KALICIDIR;
 *   listing "düzeltilemez" damgasıyla ölür. Kaydederken yakalanır.
 *
 * ETİKET SAKLANIR: panelde `12345` yerine "SMALL" gösterilir. Kimlik
 * gösterilseydi satıcı ne seçtiğini anlayamaz ve yanlış değeri onaylardı.
 */
final class SaveAttributeValueMapping
{
    public function run(
        OptionValue $optionValue,
        string $externalAttributeId,
        string $externalValueId,
        ?string $externalValueLabel = null,
    ): AttributeValueMapping {
        $tenantId = TenantContext::idOrFail();

        $this->assertValueIsAllowed($externalAttributeId, $externalValueId);

        return AttributeValueMapping::query()->updateOrCreate(
            [
                'tenant_id' => $tenantId,
                'option_value_id' => $optionValue->id,
                'external_attribute_id' => $externalAttributeId,
            ],
            [
                'external_value_id' => $externalValueId,
                'external_value_label' => $externalValueLabel,
            ],
        );
    }

    /**
     * Değer, kanalın izin verdiği listede mi.
     *
     * Öznitelik tanımı KATEGORİ BAŞINA ayrı satırda saklanır ama aynı
     * `external_attribute_id` birden çok kategoride görünebilir; değer
     * listesi hepsinde aynıdır. Bu yüzden tanımlar `external_attribute_id`
     * üzerinden okunur ve izinli değerler BİRLEŞTİRİLİR — kategoriye göre
     * daraltmak, satıcının başka bir kategoride gördüğü geçerli değeri
     * reddetmek olurdu.
     *
     * Tanım hiç bulunamazsa doğrulama YAPILMAZ: taksonomi henüz
     * çekilmemiş olabilir ve bilinmeyene karşı reddetmek satıcıyı
     * bekletirdi. Eksiklik ön koşul kapısında zaten görünür.
     */
    private function assertValueIsAllowed(string $externalAttributeId, string $externalValueId): void
    {
        // Öznitelik tanımı KANALA aittir; kiracı scope'u yoktur.
        $definitions = ChannelCategoryAttribute::query()
            ->where('external_attribute_id', $externalAttributeId)
            ->get();

        if ($definitions->isEmpty()) {
            return;
        }

        $allowed = [];
        $anyListed = false;

        foreach ($definitions as $definition) {
            $values = $definition->allowed_values ?? [];

            if ($values === []) {
                // SERBEST METİN: bu öznitelik liste dayatmıyor.
                continue;
            }

            $anyListed = true;

            foreach ($values as $value) {
                // Kanal iki biçimde döndürebilir: {id, label} nesnesi veya
                // düz dize. İkisi de desteklenir; biçim varsayımı yapıp
                // sessizce boş listeye düşmek her değeri reddederdi.
                $allowed[] = is_array($value)
                    ? (string) ($value['id'] ?? $value['value'] ?? '')
                    : (string) $value;
            }
        }

        // Hiçbir tanım liste dayatmıyorsa serbest metindir.
        if (! $anyListed) {
            return;
        }

        if (! in_array($externalValueId, $allowed, strict: true)) {
            throw new InvalidArgumentException(sprintf(
                '"%s" değeri "%s" özniteliğinin izin verdiği değerler arasında değil.',
                $externalValueId,
                $externalAttributeId,
            ));
        }
    }
}
