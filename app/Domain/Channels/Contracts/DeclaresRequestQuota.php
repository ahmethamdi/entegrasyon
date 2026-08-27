<?php

declare(strict_types=1);

namespace App\Domain\Channels\Contracts;

/**
 * Kanalın GÜNLÜK istek tavanı ve token yenileme uç noktası.
 *
 * V3.0 · §25 (üç yeni metrik) · §21 (Etsy'nin günlük kotası).
 *
 * ─────────────────────────────────────────────────────────────────────
 * NEDEN AYRI BİR YETENEK ARAYÜZÜ DEĞİL
 * ─────────────────────────────────────────────────────────────────────
 * `Supports*` arayüzleri bir kanalın YAPABİLDİĞİ işleri anlatır ve
 * panelde sekme açar (§05). Bunlar bir iş DEĞİL, kanalın ölçülmesi için
 * gereken iki OLGUDUR; `instanceof` ile sorulup panelde gösterilecek bir
 * şey yoktur. Bu yüzden yetenek arayüzü değil, `ChannelAdapter`'a
 * varsayılanla eklenen bir özelliktir.
 *
 * Varsayılanlar "bilmiyorum" der ve bu BİLİNÇLİDİR:
 *   • `dailyRequestQuota()` NULL → metrik HİÇ ölçülmez (§25'in "kanal
 *     kota bilgisi vermiyorsa satır hiç yazılmaz" kuralı). Sıfır
 *     dönseydi sıfıra bölme; büyük bir sayı uydurulsaydı grafik "her şey
 *     mükemmel" derdi.
 *   • `tokenEndpointFragment()` NULL → o kanalda token yenileme YOKTUR
 *     (Woo/Trendyol kalıcı anahtar taşır) ve yenileme hatası da olamaz.
 *
 * ─────────────────────────────────────────────────────────────────────
 * ⚠️ TAVAN ÇEKİRDEĞE GÖMÜLMEZ
 * ─────────────────────────────────────────────────────────────────────
 * `CaptureMetrics` içinde `if ($channel === 'etsy') return 10_000;`
 * yazılsaydı her yeni kanal o satırı uzatırdı ve biri eklemeyi unutunca
 * kanal SESSİZCE ölçülmez olurdu — yeteneklerin `instanceof` ile
 * okunması ve `if ($channel === '...')` yasağının kota karşılığı.
 */
trait DeclaresRequestQuota
{
    /**
     * Günlük istek tavanı — YOKSA `null`.
     *
     * Kanalın kendi belgelediği sayıdır ve HESAP başınadır. Uydurulmaz:
     * doğrulanmamış bir tavan, gerçek olmayan bir yüzde üretir ve satıcı
     * var olmayan bir sınıra göre karar verir.
     */
    public function dailyRequestQuota(): ?int
    {
        return null;
    }

    /**
     * Token yenileme uç noktasını tanıtan yol parçası — YOKSA `null`.
     *
     * `token_refresh_failures` metriği `api_calls`'tan TÜRETİLİR ve o
     * tablo kanala giden HER çağrıyı taşır. Süzülmeseydi başarısız bir
     * stok itmesi "token yenilenemedi" sayılır, satıcıya yeniden
     * yetkilendirme yaptırılır ve gerçek sorun hiç görünmezdi.
     *
     * Parça ADAPTER'DAN gelir çünkü yol kanalın şeklidir: Etsy
     * `/v3/public/oauth/token`, eBay bambaşka bir adres kullanır.
     * Çekirdekte tutulsaydı her yeni kanalda o desen listesi uzar ve
     * biri eklenmeyi unuturdu (`pollingEventIdFor` kararının aynısı).
     */
    public function tokenEndpointFragment(): ?string
    {
        return null;
    }
}
