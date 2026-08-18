<?php

declare(strict_types=1);

namespace App\Domain\Channels\Adapters\Trendyol\Catalog;

use App\Domain\Catalog\Models\VariantOption;
use App\Domain\Channels\Models\AttributeMapping;
use App\Domain\Channels\Models\AttributeValueMapping;
use App\Domain\Channels\Models\CategoryMapping;
use App\Domain\Channels\Models\ChannelCategory;
use App\Domain\Sync\Support\ListingPayload;
use RuntimeException;

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
    /**
     * @return array<string, mixed> Trendyol `products` uç noktasının kalem gövdesi
     */
    public function toChannelItem(ListingPayload $payload): array
    {
        $listing = $payload->listing;

        $listing->loadMissing(['variant.product']);

        $variant = $listing->variant;

        if ($variant === null) {
            throw new RuntimeException(
                "Listing {$listing->id} için varyant bulunamadı; Trendyol yükü kurulamaz."
            );
        }

        // BARKOD ZORUNLU: kimlik odur.
        $barcode = $variant->barcode ?? $variant->sku;

        if ($barcode === null || trim((string) $barcode) === '') {
            throw new RuntimeException(
                "Varyant {$variant->id} için barkod yok; Trendyol ürünü barkodla tanır ".
                've barkodsuz gönderim kanalda kimliksiz ürün yaratır.'
            );
        }

        $category = $this->channelCategory($payload);

        return [
            'barcode' => (string) $barcode,
            'title' => $payload->title,
            'description' => $payload->description ?? $payload->title,
            // Kanal SAYISAL kimlik bekler; ağaçtaki `external_id` odur.
            'categoryId' => (int) $category->external_id,
            'quantity' => 0,
            'stockCode' => $variant->sku,
            'listPrice' => (float) ($variant->compare_at_price ?? $variant->price),
            'salePrice' => (float) $variant->price,
            'currencyType' => $variant->currency,
            'attributes' => $this->attributes($variant->id, $category->id),
        ];
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
            throw new RuntimeException(
                "Listing {$payload->listing->id} için iç kategori atanmamış; ".
                'Trendyol kategorisiz ürün kabul etmez.'
            );
        }

        $mapping = CategoryMapping::query()
            ->where('internal_category_id', $internalCategoryId)
            ->where('channel_type_code', 'trendyol')
            ->first();

        if ($mapping === null) {
            throw new RuntimeException(
                "\"{$internalCategoryId}\" iç kategorisi Trendyol'da eşleştirilmemiş; ".
                'eşleştirme ekranından tamamlanmalı.'
            );
        }

        // Kategori KİRACISIZ okunur — ağaç kanalın gerçeğidir.
        $category = ChannelCategory::query()->find($mapping->channel_category_id);

        if ($category === null) {
            throw new RuntimeException(
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
     * @return list<array{attributeId: string, attributeValueId: string}>
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

            $out[] = [
                'attributeId' => $attributeMapping->external_attribute_id,
                'attributeValueId' => $valueMapping->external_value_id,
            ];
        }

        return $out;
    }
}
