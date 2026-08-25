<?php

declare(strict_types=1);

namespace App\Domain\Channels\Adapters\Etsy;

/**
 * Etsy envanter gövdesini BİRLEŞTİRİR — kardeş varyantları KORUYARAK.
 *
 * V3.0 · §11.3 · P0 (T-V3: "envanter yazma kardeş varyantları korur").
 *
 * ═════════════════════════════════════════════════════════════════════
 * ⚠️ BU SINIF PROJENİN EN TEHLİKELİ TEK KURALINI TAŞIR
 * ═════════════════════════════════════════════════════════════════════
 * Etsy'nin `PUT .../inventory` çağrısı KISMİ GÜNCELLEME DESTEKLEMEZ:
 * gövde neyi taşıyorsa ilanın envanteri O OLUR. Gönderilmeyen her
 * varyant KANALDAN SİLİNİR.
 *
 * Üç varyantlı bir üründe birinin stoğunu güncelleyen bir istek,
 * ötekilerin ikisini kaldırır. Sessizdir (kanal 200 döner), GERİ
 * ALINAMAZ ve satıcı bunu ancak siparişler kesilince fark eder.
 *
 * Bu yüzden bu sınıf SAF ve YAN ETKİSİZDİR: ağa, veritabanına ve kiracı
 * bağlamına dokunmaz. Kuralın kendisi veritabanı kurmadan, tek bir dizi
 * karşılaştırmasıyla sınanabilmelidir.
 *
 * ─────────────────────────────────────────────────────────────────────
 * ⚠️ "MUTLAK DEĞER" KURALI İHLAL EDİLMEZ
 * ─────────────────────────────────────────────────────────────────────
 * Gönderilen miktar hâlâ MUTLAKTIR; okunan şey BİZİM YAZMADIĞIMIZ
 * kardeş varyantlardır. Woo'da yük BİZİM gerçeğimizi taşır; Etsy'de yük
 * KANALIN gerçeğini de taşımak zorundadır.
 */
final class EtsyInventoryMerger
{
    /**
     * Mevcut envanteri alır, YALNIZCA verilen SKU'ların miktarını
     * değiştirir ve TAM gövdeyi döner.
     *
     * ⚠️ DÖNEN DİZİ GİRDİYLE AYNI SAYIDA `product` TAŞIR — HER ZAMAN.
     * Eksik bir eleman, o varyantın kanaldan silinmesi demektir.
     *
     * ⚠️ EŞLEŞMEYEN SKU'YA DOKUNULMAZ, ATILMAZ. Bizim yükümüzde olmayan
     * varyant kanalda ne ise O KALIR; miktarını sıfırlamak veya
     * çıkarmak, satıcının bizden habersiz eklediği varyantı yok etmek
     * olurdu.
     *
     * ⚠️ FİYAT DE KORUNUR. Etsy'nin offering nesnesi fiyatı da taşır ve
     * gövdede eksik bırakılırsa kanal onu sıfırlar; stok turu SESSİZCE
     * bir fiyat sıfırlaması yapardı (§9'un "sessizce ezmek EN SIK
     * ŞİKAYET" kuralının en ağır biçimi).
     *
     * @param  list<array<string, mixed>>  $products  Kanaldan OKUNAN tam envanter
     * @param  array<string, int>  $quantityBySku  Yalnızca DEĞİŞECEK miktarlar
     * @return list<array<string, mixed>>
     */
    public static function merge(array $products, array $quantityBySku): array
    {
        $merged = [];

        foreach ($products as $product) {
            if (! is_array($product)) {
                continue;
            }

            $sku = (string) ($product['sku'] ?? '');
            $newQuantity = $quantityBySku[$sku] ?? null;

            $merged[] = self::rebuildProduct($product, $newQuantity);
        }

        return $merged;
    }

    /**
     * Tek `product` nesnesini yeniden kurar.
     *
     * ⚠️ ETSY YAZMA GÖVDESİNDE OKUMA-ÖZEL ALANLARI KABUL ETMEZ.
     * `product_id` ve `offering_id` kanalın ürettiği kimliklerdir;
     * gövdede geri gönderilirlerse Etsy `VALIDATION` döner ve o hata
     * KALICIDIR. Bu yüzden yalnızca YAZILABİLİR alanlar taşınır.
     *
     * @param  array<string, mixed>  $product
     * @return array<string, mixed>
     */
    private static function rebuildProduct(array $product, ?int $newQuantity): array
    {
        /** @var list<array<string, mixed>> $offerings */
        $offerings = $product['offerings'] ?? [];

        $rebuilt = [];

        foreach ($offerings as $offering) {
            if (! is_array($offering)) {
                continue;
            }

            $rebuilt[] = array_filter([
                // MİKTAR — yalnızca bizim kalemimizse değişir.
                'quantity' => $newQuantity ?? (int) ($offering['quantity'] ?? 0),

                // ⚠️ FİYAT KORUNUR. Etsy yazma gövdesinde fiyatı DÜZ
                // SAYI bekler (okuma nesne döner); dönüşüm burada
                // yapılır. Eksik bırakılsaydı kanal fiyatı sıfırlardı.
                'price' => self::priceValue($offering),

                // `is_enabled` varyantın satışa açık olup olmadığıdır ve
                // SATICININ kararıdır — korunur.
                'is_enabled' => (bool) ($offering['is_enabled'] ?? true),
            ], static fn (mixed $v): bool => $v !== null);
        }

        return array_filter([
            'sku' => (string) ($product['sku'] ?? ''),
            // ⚠️ VARYANT ÖZELLİKLERİ (beden/renk) KORUNUR. Atılsaydı çok
            // varyantlı ürün tek varyanta ÇÖKER ve ötekiler silinirdi.
            'property_values' => $product['property_values'] ?? null,
            'offerings' => $rebuilt,
        ], static fn (mixed $v): bool => $v !== null);
    }

    /**
     * Offering fiyatını YAZMA biçiminde döner.
     *
     * Etsy OKUMADA nesne verir (`{amount, divisor, currency_code}`) ama
     * YAZMADA düz sayı bekler. Nesne geri gönderilseydi `VALIDATION`
     * alınırdı; ham `amount` gönderilseydi 19.90 TL **1990 TL** olurdu.
     *
     * @param  array<string, mixed>  $offering
     */
    private static function priceValue(array $offering): float|int|null
    {
        $price = $offering['price'] ?? null;

        if (is_array($price)) {
            $amount = $price['amount'] ?? null;
            $divisor = $price['divisor'] ?? null;

            if (! is_numeric($amount) || ! is_numeric($divisor) || (float) $divisor == 0.0) {
                return null;
            }

            return round((float) $amount / (float) $divisor, 2);
        }

        return is_numeric($price) ? (float) $price : null;
    }

    /**
     * Bir `product`'ın gözlenen stok miktarı — mutabakatın girdisi.
     *
     * ⚠️ OFFERING'LER TOPLANMAZ, İLKİ OKUNUR. Bizim modelimizde bir
     * varyant TEK bir stok değeri taşır; Etsy teorik olarak birden çok
     * offering destekler ama tek fiyat/tek stok kullanımında dizi tek
     * elemanlıdır. Toplansaydı çok offering'li bir üründe miktar
     * OLDUĞUNDAN BÜYÜK okunur ve mutabakat sahte sürüklenme raporlardı.
     *
     * @param  array<string, mixed>  $product
     */
    public static function quantityOf(array $product): ?int
    {
        /** @var list<array<string, mixed>> $offerings */
        $offerings = $product['offerings'] ?? [];

        foreach ($offerings as $offering) {
            if (is_array($offering) && isset($offering['quantity'])) {
                return (int) $offering['quantity'];
            }
        }

        return null;
    }
}
