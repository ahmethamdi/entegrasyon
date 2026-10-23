<?php

declare(strict_types=1);

namespace App\Domain\Sync\Support;

use App\Domain\Catalog\Models\ProductChannelContent;
use App\Domain\Sync\Models\Listing;
use RuntimeException;

/**
 * Kanonik modelden içerik yükü kurar.
 *
 * Mimari Karar Dokümanı v2.2 · §7 · ListingPayload, §8.
 *
 * DEĞİŞMEZ KURAL — BU SINIF GRUPLAMA YAPMAZ:
 *   Stok yükünün aksine içerik yükü LISTING BAŞINADIR. Woo'nun ürün uç
 *   noktası tekil çalışır ve içerik gövdesi stok kalemi gibi birleştirilemez;
 *   her operasyon kendi çağrısını yapar. InventoryBatchBuilder'ın karşılığı
 *   değildir, ListingPayload üreticisidir.
 *
 * DEĞİŞMEZ KURAL — KANONİK MODEL OTORİTEDİR:
 *   Başlık, açıklama ve kategori ürün satırından okunur; kanal formatına
 *   çeviriyi ADAPTER'ın Mapper'ı yapar. Bu sınıf "şunu gönder" der, "şu
 *   JSON'u gönder" demez.
 *
 * KANALA ÖZGÜ METİN (S8): satıcı bir kanal türü için başlık/açıklama
 * onayladıysa (`product_channel_contents`) o kanalın yükü onu taşır; yoksa
 * kanonik metin gider. Kategori her zaman kanoniktir.
 */
final class ListingPayloadBuilder
{
    /**
     * @param  int  $version  Operasyonun iş sürümü — yükte taşınır ama hash'e girmez
     */
    public function build(Listing $listing, int $version): ListingPayload
    {
        // Yük varyantın ürününü okur; ilişkiler tek seferde yüklenir, yoksa
        // her alan erişimi ayrı sorgu açar.
        $listing->loadMissing(['variant.product']);

        $variant = $listing->variant;
        $product = $variant?->product;

        if ($product === null) {
            throw new RuntimeException(
                "Listing {$listing->id} için kanonik ürün bulunamadı; ".
                'içerik yükü kurulamaz.'
            );
        }

        $channelCode = $listing->connection?->channel_type_code;

        $override = $channelCode === null ? null : ProductChannelContent::query()
            ->where('product_id', $product->id)
            ->where('channel_type_code', $channelCode)
            ->first();

        return new ListingPayload(
            listing: $listing,
            title: $override !== null ? $override->title : $product->title,
            description: $override !== null ? $override->description : $product->description,
            categoryId: $product->internal_category_id,
            version: $version,
        );
    }
}
