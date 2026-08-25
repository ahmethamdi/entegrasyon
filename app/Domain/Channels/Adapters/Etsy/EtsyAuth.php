<?php

declare(strict_types=1);

namespace App\Domain\Channels\Adapters\Etsy;

use RuntimeException;

/**
 * Etsy OAuth 2.0 + PKCE — projede İLK OAuth akışı.
 *
 * V3.0 · §11.2 · §19 (güvenlik · madde 2) · P0-10 · T-V3-24.
 *
 * ─────────────────────────────────────────────────────────────────────
 * BU SINIF SAF VE YAN ETKİSİZDİR
 * ─────────────────────────────────────────────────────────────────────
 * Ne oturuma yazar, ne kasaya, ne HTTP isteği atar. Yalnızca DEĞER üretir
 * ve DOĞRULAR. Sebep: PKCE'nin tüm kriptografik kararları (verifier
 * uzunluğu, challenge yöntemi, `state` karşılaştırması) tek yerde ve
 * veritabanı kurmadan sınanabilir olmalıdır — `CsvProductParser`'ın
 * ayrıştırma/yazma ayrımıyla aynı gerekçe.
 *
 * Oturuma yazmayı ve token isteğini ÇAĞIRAN yapar.
 *
 * ─────────────────────────────────────────────────────────────────────
 * ⚠️ `state` DOĞRULAMASI ZORUNLUDUR (P0-10)
 * ─────────────────────────────────────────────────────────────────────
 * Doğrulanmazsa saldırgan KENDİ yetkilendirme kodunu kurbanın oturumuna
 * enjekte eder ve **kurbanın kiracısına KENDİ mağazasını bağlar** —
 * CSRF'in OAuth'taki biçimi. O noktadan sonra kurbanın stoğu saldırganın
 * mağazasına akar.
 *
 * `state` oturumda saklanır, TEK KULLANIMLIKTIR ve karşılaştırma
 * `hash_equals` ile yapılır: `===` zamanlama sızdırır ve `==` PHP'nin tip
 * jonglörlüğüne açıktır.
 *
 * ─────────────────────────────────────────────────────────────────────
 * ⚠️ `code_verifier` KALICI DEPOYA YAZILMAZ
 * ─────────────────────────────────────────────────────────────────────
 * Tek kullanımlık bir sırdır ve yalnızca yetkilendirme ile token isteği
 * ARASINDA yaşar (§11.2). `channel_credentials`'a yazılsaydı kasada
 * hiçbir zaman geçerli olmayacak ölü bir sır birikirdi; `settings`'e
 * yazılsaydı ŞİFRESİZ bir kolona düşer ve panele Inertia prop'u olarak
 * giderdi.
 */
final class EtsyAuth
{
    /**
     * PKCE `code_verifier` uzunluğu — RFC 7636 aralığı 43–128 karakter.
     *
     * 43 SEÇİLMEZ: alt sınır, üretecin hiç payı olmadığı anlamına gelir ve
     * base64url dolgusu kırpıldığında sınırın ALTINA düşmek mümkündür.
     * 64 ham bayt → 86 karakter, aralığın ortasında güvenle durur.
     */
    private const VERIFIER_BYTES = 64;

    /** `state` için ham bayt — 32 bayt = 256 bit, tahmin edilemez. */
    private const STATE_BYTES = 32;

    /**
     * Etsy'nin istediği scope'lar (§11.2).
     *
     * ⚠️ SCOPE LİSTESİ DAR TUTULUR. Fazlası satıcıya gereksiz geniş bir
     * izin ekranı gösterir ve onay oranını düşürür; eksiği ise çağrı
     * anında 403 verir ve sebebi yetkilendirme ekranında DEĞİL aylar
     * sonra bir senkron hatasında görünür.
     *
     * `transactions_r` sipariş yoklaması, `listings_w` katalog ve stok
     * yazma, `shops_r` mağaza kimliği içindir.
     */
    public const SCOPES = ['listings_r', 'listings_w', 'transactions_r', 'shops_r', 'email_r'];

    /**
     * Yetkilendirme isteği için tek kullanımlık sırlar.
     *
     * ÇAĞIRAN bunları OTURUMA yazar ve satıcıyı `authorizeUrl()`'e
     * gönderir; callback döndüğünde ikisini de geri okur.
     *
     * @return array{state: string, code_verifier: string}
     */
    public static function newHandshake(): array
    {
        return [
            'state' => self::randomUrlSafe(self::STATE_BYTES),
            'code_verifier' => self::randomUrlSafe(self::VERIFIER_BYTES),
        ];
    }

    /**
     * `code_verifier` → `code_challenge` (S256).
     *
     * ⚠️ YÖNTEM `S256`'DIR, `plain` DEĞİL. `plain` challenge'ı verifier'ın
     * KENDİSİ yapar; yetkilendirme isteğini dinleyen biri verifier'ı
     * doğrudan okur ve PKCE'nin koruduğu tek şey ortadan kalkar.
     *
     * Hash HAM bayt olarak alınır (`binary: true`) ve base64url'e
     * çevrilir. Hex alınsaydı challenge Etsy'nin beklediği değerden
     * farklı olur ve token isteği "invalid_grant" ile reddedilirdi.
     */
    public static function challengeFor(string $verifier): string
    {
        return self::base64Url(hash('sha256', $verifier, binary: true));
    }

    /**
     * Satıcının tarayıcısının gideceği yetkilendirme adresi.
     *
     * @param  string  $keystring  Uygulamanın Etsy anahtarı (`x-api-key`)
     */
    public static function authorizeUrl(
        string $keystring,
        string $redirectUri,
        string $state,
        string $codeVerifier,
    ): string {
        return EtsyEndpoints::AUTHORIZE_URL.'?'.http_build_query([
            'response_type' => 'code',
            'client_id' => $keystring,
            'redirect_uri' => $redirectUri,
            'scope' => implode(' ', self::SCOPES),
            'state' => $state,
            'code_challenge' => self::challengeFor($codeVerifier),
            'code_challenge_method' => 'S256',
        ]);
    }

    /**
     * Callback'ten dönen `state` beklenenle aynı mı?
     *
     * ⚠️ `hash_equals` ZORUNLUDUR (P0-10) ve boş değer DAİMA reddedilir:
     * oturumda `state` yoksa (süresi dolmuş, farklı tarayıcı, saldırgan
     * doğrudan callback'e gelmiş) karşılaştırma `'' === ''` ile geçseydi
     * doğrulama tamamen devre dışı kalırdı — kapının hiç olmamasından
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
     * @return array<string, string>
     */
    public static function tokenRequest(
        string $keystring,
        string $redirectUri,
        string $code,
        string $codeVerifier,
    ): array {
        return [
            'grant_type' => 'authorization_code',
            'client_id' => $keystring,
            'redirect_uri' => $redirectUri,
            'code' => $code,
            'code_verifier' => $codeVerifier,
        ];
    }

    /**
     * Token isteğinin gövdesi — YENİLEME.
     *
     * ⚠️ `code_verifier` YOKTUR ve olmamalıdır: PKCE yalnızca ilk
     * takasta anlamlıdır. Yenilemede gönderilseydi Etsy isteği reddederdi.
     *
     * @return array<string, string>
     */
    public static function refreshRequest(string $keystring, string $refreshToken): array
    {
        return [
            'grant_type' => 'refresh_token',
            'client_id' => $keystring,
            'refresh_token' => $refreshToken,
        ];
    }

    /**
     * Etsy'nin `user_id` biçimi: `{user_id}.{rastgele}`.
     *
     * Access token'ın ÖN EKİ satıcının kullanıcı kimliğidir. Mağaza
     * kimliği (`shop_id`) ayrı bir çağrıyla alınır ama kullanıcı kimliği
     * token'ın kendisinden okunabilir ve sağlık kontrolü bunu doğrular.
     *
     * ⚠️ TOKEN'IN KENDİSİ DÖNMEZ, yalnızca ön ek. Tamamı dönseydi bu
     * metodun her çağrısı bir sır sızıntısı riski taşırdı.
     */
    public static function userIdFromToken(string $accessToken): string
    {
        $prefix = strtok($accessToken, '.');

        if ($prefix === false || $prefix === '' || ! ctype_digit($prefix)) {
            throw new RuntimeException(
                'Etsy access token beklenen `{user_id}.{rastgele}` biçiminde değil.'
            );
        }

        return $prefix;
    }

    private static function randomUrlSafe(int $bytes): string
    {
        return self::base64Url(random_bytes($bytes));
    }

    /**
     * base64url — RFC 7636'nın istediği biçim.
     *
     * Dolgu (`=`) ATILIR ve `+/` yerine `-_` kullanılır. Düz base64
     * gönderilseydi `+` bir URL'de BOŞLUĞA çözülür ve challenge sunucuda
     * farklı okunurdu.
     */
    private static function base64Url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
