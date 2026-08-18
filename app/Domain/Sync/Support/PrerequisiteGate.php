<?php

declare(strict_types=1);

namespace App\Domain\Sync\Support;

use App\Domain\Catalog\Models\Product;
use App\Domain\Channels\Contracts\SupportsTaxonomy;
use App\Domain\Channels\Models\AttributeMapping;
use App\Domain\Channels\Models\CategoryMapping;
use App\Domain\Channels\Models\ChannelCategoryAttribute;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Registry\AdapterRegistry;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Katalog aktarımının ön koşulunu kontrol eder.
 *
 * Mimari Karar Dokümanı v2.2 · §13 · Faz 2 ("Katalog aktarımı, ön koşul
 * kapısı, onay durumu takibi"), §14 · pazaryeri karmaşıklığı.
 *
 * DEĞİŞMEZ KURAL — KAPI STOK AKIŞINA DOKUNMAZ (§14'ün ANA TASARIM HEDEFİ):
 *   Bu sınıf hiçbir stok hareketi yazmaz, hiçbir bakiye güncellemez ve
 *   hiçbir outbox olayı üretmez. Eksik eşleştirme İÇERİK aktarımını
 *   durdurur; stok akışı listing'in nasıl oluştuğunu bilmez ve yalnızca
 *   `lifecycle_status = 'live'` kontrolü yapar. Pazaryerine özgü
 *   karmaşıklığın stok çekirdeğine dokunmaması bu maddenin varlık
 *   sebebidir.
 *
 * DEĞİŞMEZ KURAL — YETENEK `instanceof` İLE OKUNUR:
 *   `if ($code === 'trendyol')` YAZILMAZ. WooCommerce `SupportsTaxonomy`
 *   uygulamaz; orada kategori serbesttir ve kapı HİÇ çalışmaz. Doküman
 *   §14'teki örnek de tam olarak bu biçimdedir.
 *
 * NEDEN `Adapters/Trendyol/Catalog/` ALTINDA DEĞİL:
 *   §19'daki dizin ağacı bu sınıfı Trendyol'un altında listeliyor, ama
 *   davranışı kanaldan bağımsızdır: KİRACININ eşleştirme tablolarını okur
 *   ve ÇEKİRDEĞİN listing durumunu belirler. Trendyol'a özgü hiçbir alan
 *   veya uç nokta bilmez ve `SupportsTaxonomy` uygulayan HER kanalda
 *   çalışır. Adapter'ın altına konsaydı ikinci pazaryeri eklendiğinde
 *   ya kopyalanır ya da oradan çağrılırdı — ikisi de yanlış. §14'ün
 *   `instanceof SupportsTaxonomy` örneği de kapının çekirdekte durduğunu
 *   varsayar.
 *
 * EŞLEŞTİRME KİRACIYA AİTTİR: sorgular kiracı scope'u altında çalışır ve
 * başka satıcının kararı bu kapıyı AÇMAZ.
 */
final class PrerequisiteGate
{
    public function __construct(
        private readonly AdapterRegistry $registry,
    ) {}

    /**
     * Ürün bu kanala gönderilebilir mi.
     *
     * Kontrol ÜRÜN seviyesindedir: iç kategori üründe tutulur ve zorunlu
     * öznitelikler o kategoriye bağlıdır. Varyant başına ayrı sonuç
     * üretmek aynı cevabı N kez hesaplamak olurdu.
     */
    public function check(Product $product, ChannelConnection $connection): PrerequisiteResult
    {
        if (! $this->requiresTaxonomy($connection)) {
            return PrerequisiteResult::notApplicable();
        }

        $internalCategoryId = $product->internal_category_id;

        // İÇ KATEGORİ HİÇ ATANMAMIŞ: eşleştirilecek bir şey bile yok.
        // Sebep "eşleşme bulunamadı"dan AYRIDIR — öyle deseydik kullanıcıyı
        // eşleştirme ekranında hiç bulunmayan bir satırı aramaya
        // gönderirdik.
        if ($internalCategoryId === null || trim($internalCategoryId) === '') {
            return PrerequisiteResult::blocked(
                missingCategoryReason: sprintf(
                    '%s ürününe iç kategori atanmamış; ürünü düzenleyip iç kategori alanını doldurun.',
                    $product->sku,
                ),
            );
        }

        // Eşleştirme KİRACIYA aittir; global scope sorguya giriyor.
        $mapping = CategoryMapping::query()
            ->where('internal_category_id', $internalCategoryId)
            ->where('channel_type_code', $connection->channel_type_code)
            ->first();

        if ($mapping === null) {
            return PrerequisiteResult::blocked(
                missingCategoryReason: sprintf(
                    '"%s" iç kategorisi bu kanalda eşleştirilmemiş.',
                    $internalCategoryId,
                ),
            );
        }

        $missingAttributes = $this->missingRequiredAttributes($mapping->channel_category_id);

        if ($missingAttributes !== []) {
            return PrerequisiteResult::blocked(
                missingAttributes: $missingAttributes,
                channelCategoryId: $mapping->channel_category_id,
            );
        }

        return PrerequisiteResult::ok($mapping->channel_category_id);
    }

    /**
     * Eşleşmemiş ZORUNLU özniteliklerin ADLARI.
     *
     * Eşleştirme ekranıyla AYNI mantık: bu tek kaynak iki yerde
     * kullanılır, kopyalanmaz. İki ayrı yerde yazılsaydı biri
     * güncellendiğinde panel "hazır" derken kapı "eksik" der ve kullanıcı
     * neyi düzelteceğini bilemezdi.
     *
     * @return list<string>
     */
    public function missingRequiredAttributes(string $channelCategoryId): array
    {
        // Öznitelik TANIMI kanala aittir — kiracı scope'u yoktur.
        $required = ChannelCategoryAttribute::query()
            ->where('channel_category_id', $channelCategoryId)
            ->where('is_required', true)
            ->orderBy('name')
            ->get(['external_attribute_id', 'name']);

        if ($required->isEmpty()) {
            return [];
        }

        // Eşleştirme KİRACIYA aittir.
        $mapped = AttributeMapping::query()
            ->where('channel_category_id', $channelCategoryId)
            ->pluck('external_attribute_id')
            ->all();

        $missing = [];

        foreach ($required as $attribute) {
            if (! in_array($attribute->external_attribute_id, $mapped, strict: true)) {
                $missing[] = $attribute->name;
            }
        }

        return $missing;
    }

    /**
     * Kanal taksonomi taşıyor mu — yetenek TİP SİSTEMİNDEN okunur.
     *
     * Bozuk bir adapter sınıfı gönderimi 500'e düşürmemeli. Yetenek
     * çözülemezse kapı UYGULANMAZ ve ürün normal yoldan gider: bilinmeyene
     * karşı ENGELLEMEK, satıcının hiç ilgisi olmayan bir yapılandırma
     * hatası yüzünden tüm aktarımını durdurmak olurdu. Sebep sessizce
     * yutulmaz, günlüğe yazılır.
     */
    private function requiresTaxonomy(ChannelConnection $connection): bool
    {
        try {
            return $this->registry->for($connection) instanceof SupportsTaxonomy;
        } catch (Throwable $e) {
            Log::warning('prerequisite_gate.capability_unavailable', [
                'connection' => $connection->id,
                'channel_type' => $connection->channel_type_code,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
