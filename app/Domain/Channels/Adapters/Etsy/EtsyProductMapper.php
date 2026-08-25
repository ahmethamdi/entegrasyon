<?php

declare(strict_types=1);

namespace App\Domain\Channels\Adapters\Etsy;

use App\Domain\Sync\Support\ListingPayload;

/**
 * Kanonik listing → Etsy ilan gövdesi.
 *
 * V3.0 · §11.1 · §11.3 · v2.2 §7 · §19.
 *
 * ─────────────────────────────────────────────────────────────────────
 * ⚠️ ÜÇ SEVİYE, İKİSİ BİZDE YOK — ADLAR ÇAKIŞIYOR VE ANLAMLARI TERS
 * ─────────────────────────────────────────────────────────────────────
 *   Etsy Listing (listing_id)   → BİZİM ÜRÜNÜMÜZ  → external_parent_id
 *   Etsy Product (product_id)   → BİZİM VARYANTIMIZ → external_id
 *   Etsy Offering (offering_id) → fiyat/stok hedefi → channel_metadata
 *
 * Etsy'nin "Listing"i bizim ürünümüz, Etsy'nin "Product"ı bizim
 * varyantımızdır. Bu dönüşüm BURADA soğurulur ve ÇEKİRDEK MODEL DEĞİŞMEZ
 * (kullanıcının açık talebi): Etsy'nin variation modelini Core'a
 * zorlamak, altı kanalın beşinde anlamsız bir seviye açardı.
 *
 * ⚠️ `external_id` = `product_id`, `listing_id` DEĞİL. Bizde listing
 * satırı VARYANT BAŞINADIR (`UNIQUE(channel_connection_id, variant_id)`);
 * `listing_id` yazılsaydı üç varyantlı bir ürünün üç listing satırı AYNI
 * `external_id`'yi taşır ve `UNIQUE(channel_connection_id, external_id)`
 * kısıtı ikincisini REDDEDERDİ.
 */
final class EtsyProductMapper
{
    /** Offering kimliğinin `channel_metadata` içindeki yeri. */
    public const OFFERING_ID_KEY = 'offering_id';

    /**
     * İlan gövdesi — `POST/PATCH listings`.
     *
     * ⚠️ FİYAT VE STOK BU GÖVDEDE GİTMEZ. Etsy'de ikisi de ENVANTER
     * uç noktasında yaşar (§11.3) ve o çağrı ayrıdır. Buraya konsaydı
     * ilan yaratma anında bir fiyat yazılır, ardından envanter çağrısı
     * onu EZER ve iki gerçek kaynağı doğardı.
     *
     * @return array<string, mixed>
     */
    public static function toListingBody(ListingPayload $payload): array
    {
        $attributes = $payload->attributes;

        return array_filter([
            'title' => $payload->title,
            'description' => $payload->description,

            // ⚠️ TAKSONOMİ KİMLİĞİ YAPRAKTIR. Ara kategori gönderilirse
            // Etsy `VALIDATION` döner ve o hata KALICIDIR; ön koşul kapısı
            // bunu zaten eler ama gövdede de doğru alan kullanılmalıdır.
            'taxonomy_id' => $payload->categoryId === null
                ? null
                : (int) $payload->categoryId,

            // Etsy'nin zorunlu alanları — satıcı bunları eşleştirme
            // ekranından verir. UYDURULMAZ: varsayılan bir değer yazmak
            // (ör. "who_made => i_did") satıcı adına YASAL bir beyanda
            // bulunmak olurdu.
            'who_made' => $attributes['who_made'] ?? null,
            'when_made' => $attributes['when_made'] ?? null,
            'taxonomy_attributes' => $attributes['taxonomy_attributes'] ?? null,

            // Yeni ilan TASLAK doğar: `PushListing` canlı işaretini kanal
            // onayından SONRA yazar. `active` gönderilseydi ilan stok
            // yazılmadan yayına girer ve satıcı stoksuz ürün satardı.
            'state' => $attributes['state'] ?? 'draft',
        ], static fn (mixed $v): bool => $v !== null);
    }

    /**
     * Kanal yanıtından KİMLİK üçlüsü.
     *
     * ⚠️ ÜÇÜ BİRDEN OKUNUR. `offering_id` burada okunmasaydı her stok ve
     * fiyat itmesi önce envanteri okumak için EK BİR İSTEK gerektirirdi
     * ve Etsy'de kota GERÇEK bir tavandır (§21: 10.000 istek/gün).
     *
     * `channel_metadata` BİRLEŞTİRİLİR, EZİLMEZ (`PushListing::
     * adoptRemoteIdentity`) — bu dizi yalnızca yeni değerleri taşır.
     *
     * @param  array<string, mixed>  $listing  Etsy ilan gövdesi
     * @param  string|null  $sku  Hangi varyantın aranacağı
     * @return array<string, mixed>
     */
    public static function toIdentityResult(array $listing, ?string $sku = null): array
    {
        $listingId = isset($listing['listing_id']) ? (string) $listing['listing_id'] : null;

        $identity = array_filter([
            // Etsy'nin LISTING'i bizim ÜRÜNÜMÜZ.
            'external_parent_id' => $listingId,
        ], static fn (mixed $v): bool => $v !== null);

        $product = self::findProduct($listing, $sku);

        if ($product === null) {
            return $identity;
        }

        // Etsy'nin PRODUCT'ı bizim VARYANTIMIZ.
        if (isset($product['product_id'])) {
            $identity['external_id'] = (string) $product['product_id'];
        }

        $offeringId = self::firstOfferingId($product);

        if ($offeringId !== null) {
            $identity['channel_metadata'] = [self::OFFERING_ID_KEY => $offeringId];
        }

        return $identity;
    }

    /**
     * Envanter gövdesindeki ilgili `product`'ı bulur.
     *
     * ⚠️ SKU İLE ARANIR, KONUMLA DEĞİL. İlk eleman alınsaydı çok
     * varyantlı bir üründe BAŞKA varyantın kimliği yazılır ve o listing
     * satırı sonsuza kadar YANLIŞ varyantı güncellerdi — sessiz ve
     * satıcının fark etmesi imkânsız.
     *
     * SKU verilmediğinde tek varyantlı ürün varsayımıyla ilk eleman
     * alınır; bu yalnızca kanal SKU döndürmediğinde geçerlidir.
     *
     * @param  array<string, mixed>  $listing
     * @return array<string, mixed>|null
     */
    private static function findProduct(array $listing, ?string $sku): ?array
    {
        /** @var list<array<string, mixed>> $products */
        $products = $listing['inventory']['products']
            ?? $listing['products']
            ?? [];

        if ($products === []) {
            return null;
        }

        if ($sku === null || $sku === '') {
            return is_array($products[0] ?? null) ? $products[0] : null;
        }

        foreach ($products as $product) {
            if (is_array($product) && (string) ($product['sku'] ?? '') === $sku) {
                return $product;
            }
        }

        // ⚠️ EŞLEŞME YOKSA `null` DÖNER, İLK ELEMANA DÜŞMEZ. Düşseydi
        // yanlış varyantın kimliği yazılır ve hata sessizce kalıcılaşırdı.
        return null;
    }

    /**
     * Bir product'ın ilk offering kimliği — fiyat/stok yazma hedefi.
     *
     * @param  array<string, mixed>  $product
     */
    private static function firstOfferingId(array $product): ?string
    {
        /** @var list<array<string, mixed>> $offerings */
        $offerings = $product['offerings'] ?? [];

        foreach ($offerings as $offering) {
            if (is_array($offering) && isset($offering['offering_id'])) {
                return (string) $offering['offering_id'];
            }
        }

        return null;
    }

    /**
     * Kanal ilanından `RemoteListing` için ham alanlar.
     *
     * ⚠️ FİYAT ETSY'DE NESNEDİR: `{amount, divisor, currency_code}`.
     * `amount` KURUŞ ÖLÇEĞİNDEDİR ve `divisor`'a bölünmelidir; ham
     * `amount` okunsaydı 19.90 TL kanalda 1990 TL görünür ve mutabakat
     * her turda SAHTE bir fiyat çakışması raporlardı.
     *
     * @param  array<string, mixed>  $money
     */
    public static function money(array $money): ?string
    {
        $amount = $money['amount'] ?? null;
        $divisor = $money['divisor'] ?? null;

        if (! is_numeric($amount) || ! is_numeric($divisor) || (float) $divisor == 0.0) {
            return null;
        }

        // Para STRING taşınır — float dönüşümü kuruş kayması üretir (§7).
        return number_format((float) $amount / (float) $divisor, 2, '.', '');
    }
}
