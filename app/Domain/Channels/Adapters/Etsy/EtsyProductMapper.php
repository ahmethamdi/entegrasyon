<?php

declare(strict_types=1);

namespace App\Domain\Channels\Adapters\Etsy;

use App\Domain\Sync\Support\ListingPayload;
use App\Domain\Sync\Support\RemoteProduct;

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
     * Taslak ilan gövdesi — `POST shops/{id}/listings` (form biçiminde).
     *
     * Zorunlular (Etsy OAS): quantity, title, description, price, who_made,
     * when_made, taxonomy_id; fizikselde shipping_profile_id. `state` bu uç
     * noktada YOKTUR — Etsy her zaman taslak yaratır.
     *
     * Açıklama boşsa başlık yazılır: Etsy boş açıklamayı reddeder.
     *
     * @return array<string, scalar>
     */
    public static function toDraftBody(
        ListingPayload $payload,
        int $taxonomyId,
        string $whoMade,
        string $whenMade,
        string $price,
        string $shippingProfileId,
        ?string $readinessStateId = null,
    ): array {
        return array_filter([
            'title' => self::title($payload->title),
            'description' => self::description($payload),
            'quantity' => 1,
            'price' => round((float) $price, 2),
            'who_made' => $whoMade,
            'when_made' => $whenMade,
            'taxonomy_id' => $taxonomyId,
            'type' => 'physical',
            'shipping_profile_id' => (int) $shippingProfileId,
            'readiness_state_id' => $readinessStateId === null ? null : (int) $readinessStateId,
        ], static fn (mixed $v): bool => $v !== null);
    }

    /**
     * Güncelleme gövdesi — `PATCH shops/{id}/listings/{id}` (form biçiminde).
     *
     * ⚠️ DURUM (`state`) TAŞIMAZ: yayındaki ilan içerik güncellemesiyle
     * satıştan düşmemeli. Kategori yalnız eşleştirme varsa gider.
     *
     * @return array<string, scalar>
     */
    public static function toUpdateBody(ListingPayload $payload, ?int $taxonomyId): array
    {
        return array_filter([
            'title' => self::title($payload->title),
            'description' => self::description($payload),
            'taxonomy_id' => $taxonomyId,
        ], static fn (mixed $v): bool => $v !== null);
    }

    /** Etsy başlığı en fazla 140 karakter. */
    private static function title(string $title): string
    {
        return mb_substr(trim($title), 0, 140);
    }

    private static function description(ListingPayload $payload): string
    {
        $text = trim(strip_tags((string) $payload->description));

        return $text !== '' ? $text : trim($payload->title);
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
     * İçe aktarma: Etsy ilanı → varyant başına `RemoteProduct`.
     *
     * Ad = ilan başlığı + varyant özellikleri ("Kupa — Kırmızı / L"): aynı
     * ilanın varyantları aynı adla gelseydi panelde ayırt edilemezdi.
     *
     * SKU BOŞSA NULL geçilir: içe aktarma kanal adresi bilinen ürüne kendi
     * SKU'sunu üretir. Stok yazımı `product_id` ile eşlendiği için üretilen
     * SKU'nun kanalda olmaması yazmayı bozmaz (`EtsyInventoryMerger::merge`).
     *
     * @param  array<string, mixed>  $listing  `includes=Images,Inventory` ile okunmuş ilan
     * @return list<RemoteProduct>
     */
    public static function toRemoteProducts(array $listing): array
    {
        $listingId = (string) $listing['listing_id'];
        $text = static function (mixed $value): ?string {
            $value = is_scalar($value) ? trim(html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5)) : '';

            return $value === '' ? null : $value;
        };

        $images = [];
        $sorted = array_values(array_filter((array) ($listing['images'] ?? []), 'is_array'));
        usort($sorted, static fn (array $a, array $b): int => (int) ($a['rank'] ?? 0) <=> (int) ($b['rank'] ?? 0));

        foreach ($sorted as $image) {
            $url = (string) ($image['url_fullxfull'] ?? $image['url_570xN'] ?? '');

            if (str_starts_with($url, 'https://')) {
                $images[] = $url;
            }
        }

        $title = $text($listing['title'] ?? null) ?? "#{$listingId}";
        $out = [];

        foreach ((array) ($listing['inventory']['products'] ?? []) as $product) {
            if (! is_array($product) || ! isset($product['product_id']) || ($product['is_deleted'] ?? false) === true) {
                continue;
            }

            $offering = null;

            foreach ((array) ($product['offerings'] ?? []) as $candidate) {
                if (is_array($candidate) && ($candidate['is_deleted'] ?? false) !== true) {
                    $offering = $candidate;

                    break;
                }
            }

            // Kapalı seçenek Etsy'de satılmıyor — alınmaz (adapter notu).
            if ($offering === null || ($offering['is_enabled'] ?? true) === false) {
                continue;
            }

            $values = [];

            foreach ((array) ($product['property_values'] ?? []) as $property) {
                foreach ((array) (is_array($property) ? ($property['values'] ?? []) : []) as $value) {
                    if (($value = $text($value)) !== null) {
                        $values[] = $value;
                    }
                }
            }

            $price = is_array($offering['price'] ?? null) ? $offering['price'] : null;
            $sku = $text($product['sku'] ?? null);

            $out[] = new RemoteProduct(
                externalId: (string) $product['product_id'],
                sku: $sku,
                title: $values === [] ? $title : $title.' — '.implode(' / ', $values),
                price: $price === null ? null : self::money($price),
                quantity: (int) ($offering['quantity'] ?? 0),
                description: $text($listing['description'] ?? null),
                status: $text($listing['state'] ?? null),
                images: $images,
                raw: ['listing_id' => $listingId, 'product' => $product],
                listingIdentity: array_filter([
                    'external_id' => (string) $product['product_id'],
                    'external_parent_id' => $listingId,
                    'external_url' => $text($listing['url'] ?? null),
                    'channel_metadata' => isset($offering['offering_id'])
                        ? [self::OFFERING_ID_KEY => (string) $offering['offering_id']]
                        : null,
                ], static fn (mixed $v): bool => $v !== null),
                currency: $price === null ? null : $text($price['currency_code'] ?? null),
            );
        }

        return $out;
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
