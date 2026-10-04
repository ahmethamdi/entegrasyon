<?php

declare(strict_types=1);

namespace App\Domain\Channels\Adapters\Trendyol\Catalog;

use App\Domain\Catalog\Models\ProductImage;
use App\Domain\Catalog\Models\Variant;
use App\Domain\Catalog\Models\VariantOption;
use App\Domain\Channels\Exceptions\ListingNotPublishable;
use App\Domain\Channels\Models\AttributeMapping;
use App\Domain\Channels\Models\AttributeValueMapping;
use App\Domain\Channels\Models\CategoryMapping;
use App\Domain\Channels\Models\ChannelCategory;
use App\Domain\Sync\Support\ListingPayload;
use Illuminate\Support\Facades\Storage;

/**
 * Kanonik içerik yükünü Trendyol'un ürün formatına çevirir.
 *
 * Mimari Karar Dokümanı v2.2 · §13 · Faz 2 ("Katalog aktarımı"), §14,
 * §19 · `Adapters/Trendyol/Catalog/ListingMapper`.
 *
 * DEĞİŞMEZ KURAL — ÇEVİRİ ADAPTER'IN İŞİDİR:
 *   Kanonik yük İÇ kategori adını taşır ("kadin-elbise"); Trendyol sayısal
 *   bir kategori kimliği bekler. Çekirdek kanalın kategori kimliklerini
 *   BİLMEZ ve bilmemelidir — bilseydi her yeni pazaryeri çekirdeği
 *   değiştirirdi.
 *
 * DEĞİŞMEZ KURAL — EŞLEŞTİRME YOKSA İSTİSNA:
 *   Ön koşul kapısı bunu zaten eler, ama mapper da kendini korur. İkinci
 *   savunma gereklidir: kapı yalnızca panelden gönderim yolunda çalışır,
 *   oysa bu sınıf mutabakat onarımından da çağrılabilir. Kategorisiz
 *   gönderim kanalda `VALIDATION` hatası verir ve o hata KALICIDIR —
 *   listing "düzeltilemez" damgasıyla ölürdü.
 *
 * DEĞİŞMEZ KURAL — BARKOD ZORUNLUDUR:
 *   Trendyol ürünü barkodla tanır ve `external_id` odur. Barkodsuz
 *   gönderim kimliksiz ürün yaratır; sonraki güncelleme onu bulamaz ve
 *   her turda KOPYA ürün açardı. Barkod yoksa varyantın SKU'su kullanılır
 *   — ikisi de yoksa gönderim durur.
 */
final class ListingMapper
{
    /** Trendyol: barkod başına en fazla 8 görsel. */
    public const MAX_IMAGES = 8;

    /**
     * Ürün yaratma (`v2/products`) ve onaysız ürün güncelleme
     * (`products/unapproved-bulk-update`) kalemi — ikisi AYNI gövdeyi
     * ister ve ürünü barkodla tanır.
     *
     * V2'DE ZORUNLU ALANLAR (A11 ④b): `productMainId`, `brandId`, `images`,
     * `vatRate`, `dimensionalWeight`. V1 eşleyicisi bunların HİÇBİRİNİ
     * göndermiyordu ve her yaratma kanalda reddediliyordu; red toplu işin
     * sonucundaydı ve kimse okumadığı için ürün sonsuza dek "beklemede"
     * görünüyordu. `currencyType` V2'de yoktur.
     *
     * @return array<string, mixed>
     */
    public function toChannelItem(ListingPayload $payload, ListingContext $context): array
    {
        $variant = $this->variant($payload);
        $category = $this->channelCategory($payload);

        return array_filter([
            'barcode' => $this->barcode($variant),
            'title' => $payload->title,
            'description' => $payload->description ?? $payload->title,
            // Aynı ürünün varyantlarını Trendyol bu kimlikle GRUPLAR;
            // varyant başına farklı olsaydı her beden ayrı ürün açılırdı.
            'productMainId' => (string) $variant->product->sku,
            'brandId' => $context->brandId,
            // Kanal SAYISAL kimlik bekler; ağaçtaki `external_id` odur.
            'categoryId' => (int) $category->external_id,
            // Stok AYRI uç noktadan itilir (mutlak değer kuralı); yaratmada
            // sıfırla açılır ki onaydan önce satılmasın.
            'quantity' => 0,
            'stockCode' => $variant->sku,
            'dimensionalWeight' => $context->dimensionalWeight,
            'listPrice' => (float) ($variant->compare_at_price ?? $variant->price),
            'salePrice' => (float) $variant->price,
            'vatRate' => $context->vatRate,
            'shipmentAddressId' => $context->shipmentAddressId,
            'returningAddressId' => $context->returningAddressId,
            'images' => array_map(static fn (string $url): array => ['url' => $url], $this->imageUrls($variant)),
            'attributes' => $this->attributes($variant->id, $category->id),
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * Onaylı ürün İÇERİK güncellemesi (`products/content-bulk-update`).
     *
     * Onaylı üründe başlık, açıklama, görsel ve öznitelik YALNIZCA bu uç
     * noktadan değişir ve ürün `contentId` ile tanınır (barkod değil).
     * V1 kodu güncellemeyi yaratma uç noktasına yolluyordu.
     *
     * @return array<string, mixed>
     */
    public function toContentUpdate(ListingPayload $payload, int $contentId): array
    {
        $variant = $this->variant($payload);
        $category = $this->channelCategory($payload);

        return [
            'contentId' => $contentId,
            'title' => $payload->title,
            'description' => $payload->description ?? $payload->title,
            'images' => array_map(static fn (string $url): array => ['url' => $url], $this->imageUrls($variant)),
            'attributes' => $this->attributes($variant->id, $category->id),
        ];
    }

    /**
     * Onaylı ürün VARYANT güncellemesi (`products/variant-bulk-update`).
     * Fiyat ve stok buradan DEĞİŞMEZ — onların kendi uç noktası var.
     *
     * @return array<string, mixed>
     */
    public function toVariantUpdate(ListingPayload $payload, ListingContext $context): array
    {
        $variant = $this->variant($payload);

        return array_filter([
            'barcode' => $this->barcode($variant),
            'stockCode' => $variant->sku,
            'vatRate' => $context->vatRate,
            'dimensionalWeight' => $context->dimensionalWeight,
            'shipmentAddressId' => $context->shipmentAddressId,
            'returningAddressId' => $context->returningAddressId,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /** Ürünün marka ADI — kimliği adapter kanaldan arar. */
    public function brandName(ListingPayload $payload): string
    {
        $brand = trim((string) $this->variant($payload)->product->brand);

        if ($brand === '') {
            throw new ListingNotPublishable(sprintf(
                '%s ürününün markası boş; Trendyol markasız ürün kabul etmez. Ürünü düzenleyip marka girin.',
                $this->variant($payload)->product->sku,
            ));
        }

        return $brand;
    }

    private function variant(ListingPayload $payload): Variant
    {
        $listing = $payload->listing;

        $listing->loadMissing(['variant.product']);

        $variant = $listing->variant;

        if ($variant === null || $variant->product === null) {
            throw new ListingNotPublishable(
                "Listing {$listing->id} için varyant bulunamadı; Trendyol yükü kurulamaz."
            );
        }

        return $variant;
    }

    /** BARKOD ZORUNLU: kimlik odur. Yoksa SKU kullanılır. */
    private function barcode(Variant $variant): string
    {
        $barcode = $variant->barcode ?? $variant->sku;

        if ($barcode === null || trim((string) $barcode) === '') {
            throw new ListingNotPublishable(
                "Varyant {$variant->sku} için barkod yok; Trendyol ürünü barkodla tanır ".
                've barkodsuz gönderim kanalda kimliksiz ürün yaratır.'
            );
        }

        return (string) $barcode;
    }

    /**
     * Gönderilecek görsel adresleri — HTTPS, sıralı, en fazla 8.
     *
     * Varyanta bağlı görseller ÖNCE gelir (kırmızı tişörtün ilk görseli
     * kırmızı olmalı), sonra ürünün ortak görselleri. Başka varyanta ait
     * görsel GÖNDERİLMEZ.
     *
     * ⚠️ GÖRSELSİZ ÜRÜN DURUR. Trendyol en az bir görsel ister; boş liste
     * gönderilseydi red toplu işin sonucunda kalırdı. HTTPS olmayan adres
     * de atlanır: kanal onu indirmez.
     *
     * @return list<string>
     */
    private function imageUrls(Variant $variant): array
    {
        $images = ProductImage::query()
            ->where('product_id', $variant->product_id)
            ->where(fn ($q) => $q->whereNull('variant_id')->orWhere('variant_id', $variant->id))
            // Satıcı bu görseli Trendyol'dan hariç tuttuysa gitmez (A15).
            ->forChannel('trendyol')
            ->orderByRaw('CASE WHEN variant_id IS NULL THEN 1 ELSE 0 END')
            ->orderBy('position')
            ->get(['storage_path']);

        $urls = [];

        foreach ($images as $image) {
            $url = $this->publicUrl((string) $image->storage_path);

            if ($url !== null) {
                $urls[] = $url;
            }
        }

        $urls = array_values(array_unique($urls));

        if ($urls === []) {
            throw new ListingNotPublishable(sprintf(
                '%s ürününün HTTPS görseli yok; Trendyol en az bir görsel ister.',
                $variant->product->sku,
            ));
        }

        return array_slice($urls, 0, self::MAX_IMAGES);
    }

    /** Kayıtlı yol zaten tam adresse o, değilse genel diskteki adresi. */
    private function publicUrl(string $path): ?string
    {
        $url = preg_match('#^https?://#i', $path) === 1
            ? $path
            : Storage::disk('public')->url($path);

        return str_starts_with(strtolower($url), 'https://') ? $url : null;
    }

    /**
     * İç kategoriyi kanalın kategorisine çevirir.
     *
     * Eşleştirme KİRACIYA aittir ve sorgu kiracı scope'u altında çalışır.
     */
    private function channelCategory(ListingPayload $payload): ChannelCategory
    {
        $internalCategoryId = $payload->categoryId;

        if ($internalCategoryId === null || trim($internalCategoryId) === '') {
            throw new ListingNotPublishable(
                "Listing {$payload->listing->id} için iç kategori atanmamış; ".
                'Trendyol kategorisiz ürün kabul etmez.'
            );
        }

        $mapping = CategoryMapping::query()
            ->where('internal_category_id', $internalCategoryId)
            ->where('channel_type_code', 'trendyol')
            ->first();

        if ($mapping === null) {
            throw new ListingNotPublishable(
                "\"{$internalCategoryId}\" iç kategorisi Trendyol'da eşleştirilmemiş; ".
                'eşleştirme ekranından tamamlanmalı.'
            );
        }

        // Kategori KİRACISIZ okunur — ağaç kanalın gerçeğidir.
        $category = ChannelCategory::query()->find($mapping->channel_category_id);

        if ($category === null) {
            throw new ListingNotPublishable(
                "Eşleştirmenin işaret ettiği kategori bulunamadı: {$mapping->channel_category_id}."
            );
        }

        return $category;
    }

    /**
     * Varyantın seçeneklerini kanalın öznitelik kimliklerine çevirir.
     *
     * ÇEVRİLEMEYEN SEÇENEK SESSİZCE ATLANIR — ama bu bir kayıp değildir:
     * ZORUNLU özniteliklerin eksikliği ön koşul kapısında zaten
     * yakalanmıştır ve buraya gelen yük onları taşır. İsteğe bağlı bir
     * seçeneğin eşleştirilmemiş olması gönderimi durdurmamalı: satıcı
     * "Kumaş" özniteliğini eşleştirmediği için tüm kataloğunun
     * gitmemesi orantısız olurdu.
     *
     * @return list<array{attributeId: int|string, attributeValueId: int|string}>
     */
    private function attributes(string $variantId, string $channelCategoryId): array
    {
        // Varyantın seçenekleri: option_definition_id → option_value_id.
        $variantOptions = VariantOption::query()
            ->where('variant_id', $variantId)
            ->get(['option_definition_id', 'option_value_id']);

        if ($variantOptions->isEmpty()) {
            return [];
        }

        // Seçenek tanımı → kanal özniteliği (KATEGORİ başına).
        $attributeMappings = AttributeMapping::query()
            ->where('channel_category_id', $channelCategoryId)
            ->whereIn('option_definition_id', $variantOptions->pluck('option_definition_id')->all())
            ->get()
            ->keyBy('option_definition_id');

        if ($attributeMappings->isEmpty()) {
            return [];
        }

        // Seçenek değeri → kanal değeri (ÖZNİTELİK başına, kategori YOK).
        $valueMappings = AttributeValueMapping::query()
            ->whereIn('option_value_id', $variantOptions->pluck('option_value_id')->all())
            ->get();

        $out = [];

        foreach ($variantOptions as $option) {
            $attributeMapping = $attributeMappings->get($option->option_definition_id);

            if ($attributeMapping === null) {
                continue;
            }

            $valueMapping = $valueMappings
                ->where('option_value_id', $option->option_value_id)
                ->where('external_attribute_id', $attributeMapping->external_attribute_id)
                ->first();

            if ($valueMapping === null) {
                continue;
            }

            // Kanal SAYI bekler; kimlikler bizde metin saklanır. Yalnızca
            // rakamsa çevrilir — `(int)` sayısal olmayan kimliği SESSİZCE
            // 0'a düşürür ve ürün yanlış öznitelikle giderdi.
            $out[] = [
                'attributeId' => self::numericId($attributeMapping->external_attribute_id),
                'attributeValueId' => self::numericId($valueMapping->external_value_id),
            ];
        }

        return $out;
    }

    private static function numericId(mixed $id): int|string
    {
        $id = (string) $id;

        return ctype_digit($id) ? (int) $id : $id;
    }
}
