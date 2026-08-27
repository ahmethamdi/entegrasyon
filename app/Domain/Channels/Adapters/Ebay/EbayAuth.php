<?php

declare(strict_types=1);

namespace App\Domain\Channels\Adapters\Ebay;

use RuntimeException;

/**
 * eBay OAuth 2.0 — projede İKİNCİ OAuth akışı.
 *
 * V3.0 · §13.3 · §20 · §24 (güvenlik · madde 2) · P0-10.
 *
 * ─────────────────────────────────────────────────────────────────────
 * BU SINIF SAF VE YAN ETKİSİZDİR
 * ─────────────────────────────────────────────────────────────────────
 * Ne oturuma yazar, ne kasaya, ne HTTP isteği atar — `EtsyAuth` ile aynı
 * ayrım. Kriptografik kararlar (`state` üretimi ve karşılaştırması,
 * Basic auth kodlaması) veritabanı kurmadan sınanabilir olmalıdır.
 *
 * ─────────────────────────────────────────────────────────────────────
 * ⚠️ ETSY'DEN KOPYALANAMAZ — DÖRT NOKTADA AYRILIR
 * ─────────────────────────────────────────────────────────────────────
 * Projede "aynı kural iki kanalda ters sonuç verebilir" kuralı defalarca
 * ısırdı (Trendyol `listPrice` ↔ Shopify `compareAtPrice`; Trendyol
 * "alanı gönderme" ↔ Etsy "alanı göndermemek SİLMEKTİR"). OAuth'ta da
 * aynısı geçerlidir:
 *
 * | Konu            | Etsy (§11.2)        | eBay (§13.3)                  |
 * |-----------------|---------------------|-------------------------------|
 * | PKCE            | ZORUNLU (S256)      | **YOK**                       |
 * | İstemci kimliği | `x-api-key` başlığı | **Basic auth** (id:secret)    |
 * | Access TTL      | 1 saat              | **2 saat**                    |
 * | Refresh TTL     | 90 gün (yenilenir)  | **18 ay — YENİLENMEZ**        |
 *
 * **PKCE YOKTUR ve bu bir eksiklik DEĞİLDİR.** PKCE, istemci sırrını
 * güvenle saklayamayan istemciler (mobil, SPA) içindir; eBay sunucu
 * tarafı akışında `client_secret` ZATEN gizlidir ve kanal PKCE
 * parametrelerini KABUL ETMEZ. Etsy'den kopyalanıp `code_challenge`
 * gönderilseydi istek reddedilirdi.
 *
 * **AMA `state` YİNE ZORUNLUDUR** (P0-10) — o, PKCE'den bağımsız bir
 * korumadır ve CSRF'i engeller.
 *
 * ─────────────────────────────────────────────────────────────────────
 * ⚠️ `state` DOĞRULAMASI ZORUNLUDUR (P0-10)
 * ─────────────────────────────────────────────────────────────────────
 * Doğrulanmazsa saldırgan KENDİ yetkilendirme kodunu kurbanın oturumuna
 * enjekte eder ve **kurbanın kiracısına KENDİ mağazasını bağlar** —
 * CSRF'in OAuth'taki biçimi. O noktadan sonra kurbanın stoğu saldırganın
 * mağazasına akar.
 *
 * ─────────────────────────────────────────────────────────────────────
 * ⚠️ REFRESH TOKEN 18 AY SONRA ÖLÜR ve YENİLENMEZ (§13.3 · §20)
 * ─────────────────────────────────────────────────────────────────────
 * Etsy'de her yenileme YENİ bir refresh token döndürür ve ömür sıfırlanır;
 * eBay'de refresh token SABİT ömürlüdür ve yenileme onu TAZELEMEZ. Yani
 * bağlantı, hiç hata vermeden çalışırken 18 ay sonra ölür ve satıcı
 * YENİDEN YETKİLENDİRMEK zorundadır. Panel bunu **30 gün kala** haber
 * verir (§20) — uyarmasaydık bağlantı bir sabah sessizce ölürdü.
 */
final class EbayAuth
{
    /** `state` için ham bayt — 32 bayt = 256 bit, tahmin edilemez. */
    private const STATE_BYTES = 32;

    /**
     * eBay'in istediği scope'lar (§13.3).
     *
     * ⚠️ SCOPE LİSTESİ DAR TUTULUR (`EtsyAuth::SCOPES` ile aynı gerekçe).
     * Fazlası satıcıya gereksiz geniş bir izin ekranı gösterir ve onay
     * oranını düşürür; eksiği ise çağrı anında 403 verir ve sebebi
     * yetkilendirme ekranında DEĞİL aylar sonra bir senkron hatasında
     * görünür.
     *
     * ⚠️ eBay SCOPE'LARI URL BİÇİMİNDEDİR, düz ad değil. Etsy'nin
     * `listings_r` gibi kısa adları kopyalanıp yazılsaydı kanal isteği
     * reddederdi.
     *
     * `sell.inventory` katalog ve stok yazma, `sell.account` politika ve
     * konum okuma, `sell.fulfillment` sipariş yoklaması içindir.
     */
    public const SCOPES = [
        'https://api.ebay.com/oauth/api_scope/sell.inventory',
        'https://api.ebay.com/oauth/api_scope/sell.account',
        'https://api.ebay.com/oauth/api_scope/sell.fulfillment',
    ];

    /**
     * Yetkilendirme isteği için tek kullanımlık sır.
     *
     * ⚠️ ETSY'NİN AKSİNE TEK DEĞER DÖNER — `code_verifier` YOKTUR çünkü
     * eBay PKCE kullanmaz. Etsy'den kopyalanıp verifier de üretilseydi
     * oturuma hiçbir zaman kullanılmayacak ölü bir sır yazılırdı.
     *
     * ÇAĞIRAN bunu OTURUMA yazar ve satıcıyı `authorizeUrl()`'e gönderir;
     * callback döndüğünde geri okur.
     *
     * @return array{state: string}
     */
    public static function newHandshake(): array
    {
        return ['state' => self::randomUrlSafe(self::STATE_BYTES)];
    }

    /**
     * Satıcının tarayıcısının gideceği yetkilendirme adresi.
     *
     * @param  string  $clientId  Uygulamanın eBay App ID'si
     * @param  string  $redirectUri  eBay'de "RuName" olarak kayıtlı değer
     *
     * ⚠️ `redirect_uri` eBay'DE HAM ADRES DEĞİL "RuName"DİR. eBay
     * geliştirici panelinde tanımlanan takma addır ve gerçek adres orada
     * saklanır; ham adres gönderilseydi `invalid_request` alınırdı. Bu,
     * Etsy'den ayrılan BEŞİNCİ noktadır ve çağıran doğru değeri
     * geçmekten sorumludur.
     */
    public static function authorizeUrl(
        string $clientId,
        string $redirectUri,
        string $state,
        bool $sandbox = false,
    ): string {
        return EbayEndpoints::authorizeUrl($sandbox).'?'.http_build_query([
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'scope' => implode(' ', self::SCOPES),
            'state' => $state,
        ]);
    }

    /**
     * Callback'ten dönen `state` beklenenle aynı mı?
     *
     * ⚠️ `hash_equals` ZORUNLUDUR (P0-10) ve boş değer DAİMA reddedilir:
     * oturumda `state` yoksa (süresi dolmuş, farklı tarayıcı, saldırgan
     * doğrudan callback'e gelmiş) karşılaştırma `'' === ''` ile geçseydi
     * doğrulama TAMAMEN devre dışı kalırdı — kapının hiç olmamasından
     * FARKSIZ ama var sanılan bir hâl.
     */
    public static function stateMatches(?string $expected, ?string $provided): bool
    {
        if ($expected === null || $expected === '' || $provided === null || $provided === '') {
            return false;
        }

        return hash_equals($expected, $provided);
    }

    /**
     * Token isteğinin gövdesi — İLK alış.
     *
     * ⚠️ `client_id` GÖVDEDE YOKTUR — Etsy'den ayrılan ÜÇÜNCÜ nokta.
     * eBay istemci kimliğini `Authorization: Basic` BAŞLIĞINDA bekler
     * (`basicAuthHeader()`); gövdeye de yazılsaydı istek reddedilirdi.
     *
     * @return array<string, string>
     */
    public static function tokenRequest(string $redirectUri, string $code): array
    {
        return [
            'grant_type' => 'authorization_code',
            'redirect_uri' => $redirectUri,
            'code' => $code,
        ];
    }

    /**
     * Token isteğinin gövdesi — YENİLEME.
     *
     * ⚠️ `scope` GÖNDERİLİR ve bu Etsy'den ayrılan ALTINCI noktadır.
     * eBay yenilemede scope daraltmaya izin verir; GÖNDERİLMEZSE bazı
     * hesaplarda token DAR scope ile döner ve sonraki çağrılar 403 alır —
     * sebebi de "yetki yok" diye görünür, oysa yetki VARDI.
     *
     * @return array<string, string>
     */
    public static function refreshRequest(string $refreshToken): array
    {
        return [
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'scope' => implode(' ', self::SCOPES),
        ];
    }

    /**
     * `Authorization: Basic` başlığının DEĞERİ.
     *
     * ⚠️ eBay İSTEMCİ KİMLİĞİNİ BAŞLIKTA BEKLER — Etsy'den ayrılan İKİNCİ
     * nokta. Etsy `client_id`'yi gövdeye yazar ve uygulama kimliğini ayrı
     * bir `x-api-key` başlığında taşır; eBay ikisini tek bir Basic auth
     * çiftinde birleştirir.
     *
     * ⚠️ `ChannelHttpClient::BASIC_AUTH_KEY_PAIRS` KULLANILMAZ. O
     * mekanizma HER isteğe Basic auth ekler; eBay'de Basic auth YALNIZCA
     * token uç noktasına gider, diğer çağrılar `Bearer` taşır. İkisi
     * karıştırılsaydı ya token isteği kimliksiz gider ya da her API
     * çağrısı istemci sırrını gereksizce taşırdı.
     */
    public static function basicAuthHeader(string $clientId, string $clientSecret): string
    {
        if ($clientId === '' || $clientSecret === '') {
            throw new RuntimeException(
                'eBay istemci kimliği veya sırrı boş — istek kimliksiz gider ve '
                .'kanal 401 döner; `AUTHENTICATION` KALICI sayılır ve bağlantı '
                .'"anahtar yanlış" diyerek ölürdü (`97a7eb7` hata biçimi).'
            );
        }

        return 'Basic '.base64_encode($clientId.':'.$clientSecret);
    }

    private static function randomUrlSafe(int $bytes): string
    {
        return self::base64Url(random_bytes($bytes));
    }

    /**
     * base64url — dolgu atılır, `+/` yerine `-_`.
     *
     * `state` bir sorgu dizesinde taşınır; düz base64 üretilseydi `+` bir
     * URL'de BOŞLUĞA çözülür ve callback'te farklı okunurdu — `state`
     * eşleşmez ve MEŞRU bağlanma denemesi reddedilirdi.
     */
    private static function base64Url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
