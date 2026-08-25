<?php

declare(strict_types=1);

namespace App\Domain\Channels\Support;

/**
 * Bu kanal PANELDEKİ bağlama formuyla bağlanabilir mi?
 *
 * ═════════════════════════════════════════════════════════════════════
 * ⚠️ "KANAL AÇIK" İLE "PANELDEN BAĞLANABİLİR" AYRI ŞEYLERDİR
 * ═════════════════════════════════════════════════════════════════════
 * `channel_types.is_active` kanalın satıcıya GÖRÜNÜP görünmediğini
 * söyler. Bu sınıf başka bir soruyu cevaplar: bağlama formu o kanalın
 * kimlik biçimini SORABİLİYOR mu?
 *
 * Form bugün TEK bir biçim biliyor — Woo/Trendyol'un basic-auth çifti
 * (`consumer_key` / `consumer_secret`). Etsy OAuth2 + PKCE ister
 * (`keystring` + `shop_id`, ve tarayıcı Etsy'ye YÖNLENDİRİLİR);
 * Shopify tek bir Admin API token'ı ister. İkisi de bu formdan
 * GİRİLEMEZ.
 *
 * ─────────────────────────────────────────────────────────────────────
 * ⚠️ KAPI OLMASAYDI NE OLURDU
 * ─────────────────────────────────────────────────────────────────────
 * Kanal listede görünür, satıcı seçer ve form ondan `ck_...` ister.
 * Etsy panelinde ÖYLE BİR ANAHTAR YOKTUR; satıcı bulamadığı için
 * rastgele bir değer girer, sağlık kontrolü 401 alır ve sebep
 * "anahtarın yanlış" gibi görünür — oysa anahtar yanlış değil, o
 * kanalda böyle bir anahtar HİÇ YOKTUR. Teşhis edilemez bir hata.
 *
 * Bu, projenin "aktif ama çalışmayan bağlantı en pahalı hata biçimidir"
 * kuralının FORM tarafındaki karşılığıdır.
 *
 * ─────────────────────────────────────────────────────────────────────
 * ⚠️ KAPI HEM PANELDE HEM SUNUCUDA UYGULANIR
 * ─────────────────────────────────────────────────────────────────────
 * Yalnızca formda gizlenseydi doğrudan POST atan bir istek Etsy
 * bağlantısını Woo anahtarlarıyla kasaya YAZARDI; satır `pending`
 * kalır ama kasada anlamsız bir sır durur ve satıcı bağlantıyı
 * "kurulmuş" sanardı. Liste TEK kaynaktır ve iki taraf da onu okur.
 *
 * ⚠️ BU SINIF GEÇİCİDİR. Bağlama formu kanal başına dallandırıldığında
 * (Shopify → tek token alanı, Etsy → OAuth yönlendirmesi) buradaki
 * satırlar TEKER TEKER SİLİNİR. Liste kısaldıkça iş bitmiş demektir;
 * boşaldığında sınıf da kaldırılır.
 */
final class PanelConnectSupport
{
    /**
     * Bağlama formunun HENÜZ soramadığı kimlik biçimleri.
     *
     * Anahtar kanal kodu, değer SATICIYA gösterilecek sebeptir. Sebep
     * metni "ne zaman gelecek" değil "şu an ne yapabilirsin" der:
     * satıcıya yapamayacağı bir şeyi beklemesini söylemek, hiç bilgi
     * vermemekten iyi değildir.
     */
    private const UNSUPPORTED = [
        'etsy' => 'Etsy bağlantısı Etsy üzerinden yetkilendirme (OAuth) ister '
            .'ve bu akış panele henüz eklenmedi. Anahtar girerek bağlanamazsın.',

        'shopify' => 'Shopify bağlantısı tek bir Admin API erişim anahtarı ister; '
            .'bu form iki parçalı anahtar soruyor ve Shopify için henüz uygun değil.',

        // ⚠️ Hepsiburada bu listede DEĞİLDİR ve olmamalıdır: onun kimlik
        // biçimi (basic auth çifti) bu formla UYUMLUDUR. O kanal
        // `is_active = false` ile kapalıdır çünkü UÇ NOKTALARI
        // doğrulanmadı — tamamen ayrı bir sebep ve ayrı bir kapı.
        // İkisi burada birleştirilseydi Hepsiburada'nın uç noktaları
        // doğrulandığında bu satır unutulur ve kanal sessizce
        // "bağlanamaz" kalırdı.
    ];

    public static function isConnectable(string $channelTypeCode): bool
    {
        return ! isset(self::UNSUPPORTED[$channelTypeCode]);
    }

    /** Bağlanamama sebebi — bağlanabilen kanalda `null`. */
    public static function reasonFor(string $channelTypeCode): ?string
    {
        return self::UNSUPPORTED[$channelTypeCode] ?? null;
    }
}
