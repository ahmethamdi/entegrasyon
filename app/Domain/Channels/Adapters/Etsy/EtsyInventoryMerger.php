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
     * Mevcut envanteri alır, YALNIZCA verilen kalemleri değiştirir ve TAM
     * gövdeyi döner.
     *
     * ⚠️ DÖNEN DİZİ GİRDİYLE AYNI SAYIDA `product` TAŞIR — HER ZAMAN.
     * Eksik bir eleman, o varyantın kanaldan silinmesi demektir.
     *
     * ⚠️ EŞLEŞMEYEN KALEME DOKUNULMAZ, ATILMAZ. Bizim yükümüzde olmayan
     * varyant kanalda ne ise O KALIR; değerini sıfırlamak veya çıkarmak,
     * satıcının bizden habersiz eklediği varyantı yok etmek olurdu.
     *
     * ⚠️ MİKTAR VE FİYAT BİRBİRİNİ KORUR — ÇİFT YÖNLÜ.
     * Etsy'nin offering nesnesi İKİSİNİ BİRDEN taşır ve gövdede eksik
     * bırakılan alanı kanal SIFIRLAR:
     *   · STOK turunda fiyat eksik kalsaydı → sessiz bir FİYAT sıfırlaması
     *     (§9'un "sessizce ezmek EN SIK ŞİKAYET" kuralının en ağır biçimi)
     *   · FİYAT turunda miktar eksik kalsaydı → sessiz bir STOK sıfırlaması
     *     ve ürün satışa KAPANIR — daha da ağırdır
     *
     * ⚠️ İKİ ANAHTAR BİLİNÇLİ OLARAK FARKLIDIR ve bu keyfi DEĞİL,
     * TAŞINAN VERİDEN gelir. `InventoryPushItem` `sku` taşır ve
     * `product_id` BİLMEZ; `PricePushBatch` kalemi `external_id`
     * (= `product_id`) taşır ve `sku` BİLMEZ. Fiyat SKU ile eşlenseydi
     * kalemin taşımadığı bir alan uydurulmak zorunda kalınırdı; üstelik
     * kanalda SKU BOŞ olabilir ve boş dize iki varyantı birden eşlerdi.
     *
     * @param  list<array<string, mixed>>  $products  Kanaldan OKUNAN tam envanter
     * @param  array<string, int>  $quantityBySku  Yalnızca DEĞİŞECEK miktarlar
     * @param  array<string, string>  $priceByProductId  Yalnızca DEĞİŞECEK fiyatlar
     * @return list<array<string, mixed>>
     */
    public static function merge(
        array $products,
        array $quantityBySku,
        array $priceByProductId = [],
    ): array {
        $merged = [];

        foreach ($products as $product) {
            if (! is_array($product)) {
                continue;
            }

            $sku = (string) ($product['sku'] ?? '');
            $productId = (string) ($product['product_id'] ?? '');

            $merged[] = self::rebuildProduct(
                $product,
                $quantityBySku[$sku] ?? null,
                $productId === '' ? null : ($priceByProductId[$productId] ?? null),
            );
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
    private static function rebuildProduct(
        array $product,
        ?int $newQuantity,
        ?string $newPrice = null,
    ): array {
        /** @var list<array<string, mixed>> $offerings */
        $offerings = $product['offerings'] ?? [];

        $rebuilt = [];

        foreach ($offerings as $offering) {
            if (! is_array($offering)) {
                continue;
            }

            $rebuilt[] = array_filter([
                // ⚠️ MİKTAR — yalnızca bizim STOK kalemimizse değişir,
                // aksi hâlde KANALDAKİ değer korunur. Fiyat turunda
                // `$newQuantity` daima null'dır ve korunan bu daldır:
                // sıfırlansaydı bir fiyat güncellemesi ürünü satışa
                // KAPATIRDI.
                'quantity' => $newQuantity ?? (int) ($offering['quantity'] ?? 0),

                // ⚠️ FİYAT — yalnızca bizim FİYAT kalemimizse değişir,
                // aksi hâlde KANALDAKİ değer korunur. Etsy yazma
                // gövdesinde fiyatı DÜZ SAYI bekler (okuma nesne döner);
                // dönüşüm burada yapılır. Eksik bırakılsaydı kanal
                // fiyatı sıfırlardı.
                'price' => $newPrice !== null
                    ? round((float) $newPrice, 2)
                    : self::priceValue($offering),

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
     * Bir `product`'ın gözlenen FİYATI — mutabakatın ve §9 çakışma
     * tespitinin girdisi.
     *
     * ⚠️ STRING DÖNER, float DEĞİL. Para float taşınmaz (§7): yuvarlama
     * kuruş kayması üretir ve `RemotePriceSnapshot` sözleşmesi string'tir.
     * Biçim `number_format(..., 2)` ile SABİTTİR — `"19.9"` ile `"19.90"`
     * metin olarak farklıdır ve her tur sahte çakışma üretirdi.
     *
     * ⚠️ FİYATI OLMAYAN OFFERING SIFIR OKUNMAZ, NULL DÖNER. `"0"`
     * yazılsaydı mutabakat "kanalda 0 TL" sanır ve `PRICE_CONFLICT`
     * açardı — satıcı VAR OLMAYAN bir fiyat için karar vermeye
     * zorlanırdı. Doğru sınıflandırma `REMOTE_MISSING`'dir (§10).
     *
     * ⚠️ İLK OFFERING OKUNUR — `quantityOf()` ile aynı gerekçe: bizim
     * modelimizde bir varyant TEK fiyat taşır.
     *
     * @param  array<string, mixed>  $product
     */
    public static function priceOf(array $product): ?string
    {
        /** @var list<array<string, mixed>> $offerings */
        $offerings = $product['offerings'] ?? [];

        foreach ($offerings as $offering) {
            if (! is_array($offering)) {
                continue;
            }

            $price = self::priceValue($offering);

            if ($price !== null) {
                return number_format((float) $price, 2, '.', '');
            }
        }

        return null;
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
