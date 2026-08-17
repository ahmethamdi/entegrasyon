<?php

declare(strict_types=1);

namespace App\Domain\Sync\Support;

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

        return new ListingPayload(
            listing: $listing,
            title: $product->title,
            description: $product->description,
            categoryId: $product->internal_category_id,
            version: $version,
        );
    }
}
