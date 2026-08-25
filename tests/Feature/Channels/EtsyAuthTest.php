<?php

declare(strict_types=1);

namespace Tests\Feature\Channels;

use App\Domain\Channels\Adapters\Etsy\EtsyAuth;
use App\Domain\Channels\Adapters\Etsy\EtsyEndpoints;
use App\Support\Logging\PayloadRedactor;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Etsy OAuth 2 + PKCE — slice 3.1.
 *
 * V3.0 · §11.2 · §19 (güvenlik · madde 2) · P0-10 · T-V3-24.
 *
 * ─────────────────────────────────────────────────────────────────────
 * VERİTABANI YOK ve bu BİLİNÇLİ
 * ─────────────────────────────────────────────────────────────────────
 * `EtsyAuth` saf ve yan etkisizdir; oturuma, kasaya veya ağa dokunmaz.
 * PKCE'nin kriptografik kararları (challenge yöntemi, base64url biçimi,
 * `state` karşılaştırması) veritabanı kurmadan sınanabilmelidir —
 * `CsvProductParser`'ın ayrıştırma/yazma ayrımıyla aynı gerekçe.
 */
final class EtsyAuthTest extends TestCase
{
    // ──────────────────────────────────────────────────────── PKCE · S256

    /**
     * ⚠️ CHALLENGE `S256`'DIR, `plain` DEĞİL.
     *
     * `plain` yöntemde challenge verifier'ın KENDİSİDİR; yetkilendirme
     * isteğini dinleyen biri verifier'ı doğrudan okur ve PKCE'nin
     * koruduğu tek şey ortadan kalkar.
     *
     * Beklenen değer RFC 7636'nın kendi örneğidir — kendi kodumuzla
     * üretilmiş bir beklenti, aynı hatayı iki tarafta birden yapardı.
     */
    #[Test]
    public function the_challenge_is_the_rfc_7636_s256_value(): void
    {
        $this->assertSame(
            'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM',
            EtsyAuth::challengeFor('dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk'),
            'Challenge RFC 7636 örneğiyle uyuşmuyor — hash ham bayt yerine '
            .'hex alınmış veya base64url yerine düz base64 kullanılmış olabilir.',
        );
    }

    /**
     * ⚠️ BASE64URL — DOLGU ATILIR, `+/` YERİNE `-_`.
     *
     * Düz base64 gönderilseydi `+` bir URL'de BOŞLUĞA çözülür ve
     * challenge sunucuda farklı okunurdu; `=` dolgusu da sorgu dizesinde
     * yeniden kodlanırdı.
     */
    #[Test]
    public function generated_secrets_are_url_safe(): void
    {
        $handshake = EtsyAuth::newHandshake();

        foreach (['state', 'code_verifier'] as $key) {
            $this->assertMatchesRegularExpression(
                '/^[A-Za-z0-9_-]+$/',
                $handshake[$key],
                "{$key} URL güvenli değil — `+`, `/` veya `=` taşıyor.",
            );
        }
    }

    /**
     * ⚠️ `code_verifier` RFC 7636'NIN 43–128 ARALIĞINDA OLMALIDIR.
     *
     * Alt sınırın altında kalan bir verifier'ı Etsy reddeder ve hata
     * ancak satıcı yetkilendirmeyi tamamladıktan SONRA görünür — yani en
     * geç fark edilen anda.
     */
    #[Test]
    public function the_verifier_length_is_inside_the_rfc_range(): void
    {
        $length = mb_strlen(EtsyAuth::newHandshake()['code_verifier']);

        $this->assertGreaterThanOrEqual(43, $length);
        $this->assertLessThanOrEqual(128, $length);
    }

    /** Her el sıkışma TAZE sır üretir — tekrar kullanılan sır PKCE'yi bozar. */
    #[Test]
    public function every_handshake_is_unique(): void
    {
        $first = EtsyAuth::newHandshake();
        $second = EtsyAuth::newHandshake();

        $this->assertNotSame($first['state'], $second['state']);
        $this->assertNotSame($first['code_verifier'], $second['code_verifier']);
    }

    // ─────────────────────────────────────────────── P0-10 · state doğrulama

    /**
     * ⚠️ EŞLEŞMEYEN `state` REDDEDİLİR — P0-10 · T-V3-24.
     *
     * Doğrulanmazsa saldırgan KENDİ yetkilendirme kodunu kurbanın
     * oturumuna enjekte eder ve kurbanın kiracısına KENDİ mağazasını
     * bağlar (CSRF'in OAuth'taki biçimi). O noktadan sonra kurbanın stoğu
     * saldırganın mağazasına akar.
     */
    #[Test]
    public function a_mismatched_state_is_rejected(): void
    {
        $this->assertFalse(EtsyAuth::stateMatches('beklenen', 'saldirgan'));
    }

    /** Eşleşen `state` kabul edilir — kapı meşru akışı engellemez. */
    #[Test]
    public function a_matching_state_is_accepted(): void
    {
        $this->assertTrue(EtsyAuth::stateMatches('ayni-deger', 'ayni-deger'));
    }

    /**
     * ⚠️ BOŞ `state` DAİMA REDDEDİLİR.
     *
     * Oturumda `state` yoksa (süresi dolmuş, farklı tarayıcı, saldırgan
     * doğrudan callback'e gelmiş) karşılaştırma `'' === ''` ile GEÇSEYDİ
     * doğrulama tamamen devre dışı kalırdı — kapının hiç olmamasından
     * farksız ama VAR SANILAN bir hâl. En tehlikeli biçim budur.
     */
    #[Test]
    public function an_empty_state_never_passes(): void
    {
        $this->assertFalse(EtsyAuth::stateMatches(null, null));
        $this->assertFalse(EtsyAuth::stateMatches('', ''));
        $this->assertFalse(EtsyAuth::stateMatches('beklenen', ''));
        $this->assertFalse(EtsyAuth::stateMatches('', 'saglanan'));
        $this->assertFalse(EtsyAuth::stateMatches(null, 'saglanan'));
    }

    // ──────────────────────────────────────────────────── yetkilendirme URL

    /**
     * ⚠️ YETKİLENDİRME ADRESİ `www.etsy.com`'DUR, `openapi.etsy.com` DEĞİL.
     *
     * Bu adres satıcının TARAYICISININ gittiği bir Etsy sayfasıdır. API
     * tabanına yazılsaydı satıcı bir JSON hatasıyla karşılaşır ve bağlama
     * akışı hiç başlamazdı.
     */
    #[Test]
    public function the_authorize_url_points_at_the_seller_facing_host(): void
    {
        $url = EtsyAuth::authorizeUrl(
            keystring: 'key-123',
            redirectUri: 'https://panel.test/channels/etsy/callback',
            state: 'st',
            codeVerifier: 'ver',
        );

        $this->assertStringStartsWith('https://www.etsy.com/oauth/connect?', $url);
        $this->assertStringNotContainsString('openapi.etsy.com', $url);
    }

    /**
     * ⚠️ URL CHALLENGE TAŞIR, VERIFIER TAŞIMAZ.
     *
     * Verifier sızarsa PKCE anlamsızlaşır: kodu çalan saldırgan onu
     * doğrudan token isteğinde kullanır. Yetkilendirme adresi tarayıcı
     * geçmişine, sunucu günlüklerine ve Referer başlığına düşer.
     */
    #[Test]
    public function the_authorize_url_carries_the_challenge_not_the_verifier(): void
    {
        $verifier = 'gizli-verifier-degeri';

        $url = EtsyAuth::authorizeUrl(
            keystring: 'key-123',
            redirectUri: 'https://panel.test/cb',
            state: 'st',
            codeVerifier: $verifier,
        );

        $this->assertStringNotContainsString(
            $verifier,
            $url,
            'code_verifier yetkilendirme adresine sızdı — PKCE anlamsızlaştı.',
        );
        $this->assertStringContainsString(
            'code_challenge='.EtsyAuth::challengeFor($verifier),
            $url,
        );
        $this->assertStringContainsString('code_challenge_method=S256', $url);
    }

    /** Scope listesi §11.2'de yazılı olanla birebir — dar tutulur. */
    #[Test]
    public function the_requested_scopes_match_the_document(): void
    {
        $this->assertSame(
            ['listings_r', 'listings_w', 'transactions_r', 'shops_r', 'email_r'],
            EtsyAuth::SCOPES,
        );
    }

    // ──────────────────────────────────────────────────────── token istekleri

    /** İlk takas `authorization_code` ve verifier TAŞIR. */
    #[Test]
    public function the_first_exchange_carries_the_verifier(): void
    {
        $body = EtsyAuth::tokenRequest('key-123', 'https://panel.test/cb', 'kod', 'ver');

        $this->assertSame('authorization_code', $body['grant_type']);
        $this->assertSame('ver', $body['code_verifier']);
        $this->assertSame('kod', $body['code']);
    }

    /**
     * ⚠️ YENİLEMEDE `code_verifier` GÖNDERİLMEZ.
     *
     * PKCE yalnızca İLK takasta anlamlıdır; yenilemede gönderilseydi Etsy
     * isteği reddeder ve token yenilenemezdi — 1 saatlik token'da bu,
     * bağlantının bir saat içinde ölmesi demektir.
     */
    #[Test]
    public function the_refresh_request_omits_the_verifier(): void
    {
        $body = EtsyAuth::refreshRequest('key-123', 'refresh-abc');

        $this->assertSame('refresh_token', $body['grant_type']);
        $this->assertSame('refresh-abc', $body['refresh_token']);
        $this->assertArrayNotHasKey('code_verifier', $body);
    }

    // ────────────────────────────────────────────────── token biçimi

    /** Access token'ın ön eki satıcının kullanıcı kimliğidir. */
    #[Test]
    public function the_user_id_is_read_from_the_token_prefix(): void
    {
        $this->assertSame('12345', EtsyAuth::userIdFromToken('12345.abcdefghij'));
    }

    /**
     * ⚠️ BEKLENMEYEN BİÇİM İSTİSNA FIRLATIR, SESSİZCE GEÇMEZ.
     *
     * Geçseydi kullanıcı kimliği boş veya çöp bir değerle yazılır ve
     * sağlık kontrolü "doğrulandı" derken aslında hiçbir şey
     * doğrulamamış olurdu.
     */
    #[Test]
    public function a_malformed_token_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);

        EtsyAuth::userIdFromToken('bicimsiz-token');
    }

    // ───────────────────────────────────────────────────────── uç noktalar

    /**
     * ⚠️ DOLDURULMAMIŞ YER TUTUCU İSTİSNA FIRLATIR (§05).
     *
     * Geçseydi istek literal `{shop_id}` içeren bir adrese gider ve
     * 404'ün sebebi hiçbir yerde görünmezdi.
     */
    #[Test]
    public function an_unfilled_placeholder_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        EtsyEndpoints::url(EtsyEndpoints::SHOP_RECEIPTS);
    }

    /** Bilinmeyen yer tutucu da sessizce yok sayılmaz. */
    #[Test]
    public function an_unknown_placeholder_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        EtsyEndpoints::url(EtsyEndpoints::SHOP_RECEIPTS, ['bilinmeyen' => '1']);
    }

    /**
     * ⚠️ ENVANTER YOLU `listing_id` İLE ADRESLENİR, `product_id` İLE DEĞİL.
     *
     * Bizim `external_id`'miz `product_id` taşır ve `external_parent_id`
     * `listing_id` taşır (§11.1). Envanter çağrısında okunacak alan
     * EBEVEYNDİR; karıştırılırsa istek var olmayan bir ilana gider.
     */
    #[Test]
    public function the_inventory_path_is_addressed_by_listing_id(): void
    {
        $this->assertSame(
            'https://openapi.etsy.com/v3/application/listings/9001/inventory',
            EtsyEndpoints::url(EtsyEndpoints::LISTING_INVENTORY, ['listing_id' => 9001]),
        );
    }

    /** Yer tutucu ADIYLA doldurulur — konumla değil. */
    #[Test]
    public function placeholders_are_filled_by_name(): void
    {
        $this->assertSame(
            'https://openapi.etsy.com/v3/application/shops/777/receipts',
            EtsyEndpoints::url(EtsyEndpoints::SHOP_RECEIPTS, ['shop_id' => '777']),
        );
    }

    // ──────────────────────────────────────────── §19 · sır maskeleme

    /**
     * ⚠️ YENİ KİMLİK ANAHTARLARI MASKELENİR (§19 · P1-8).
     *
     * `x-api-key` Etsy'nin UYGULAMA anahtarını taşır ve `code_verifier`
     * PKCE'nin tek kullanımlık sırrıdır. Maskelenmezlerse bir istek
     * gövdesi olarak `api_calls`'a düşer ve oradan üçüncü taraf günlük
     * toplayıcıya gider.
     *
     * İDDİA BEKLENEN METİNLEDİR: davranış testi yazan ve okuyan aynı
     * listeyi kullandığı için mutasyon ikisini BİRLİKTE kaydırır ve
     * sessizce yeşil kalırdı ("yazan ve okuyan aynı yardımcı" kuralı).
     */
    #[Test]
    public function the_new_identity_keys_are_redacted(): void
    {
        $redacted = app(PayloadRedactor::class)->redact([
            'x-api-key' => 'etsy-uygulama-anahtari-uzun',
            'code_verifier' => 'pkce-tek-kullanimlik-sir',
            'shop_id' => '777',
        ]);

        $this->assertSame(PayloadRedactor::REDACTED, $redacted['x-api-key']);
        $this->assertSame(PayloadRedactor::REDACTED, $redacted['code_verifier']);

        // KİMLİK MASKELENMEZ — maskelenseydi günlükler teşhis edilemez
        // hâle gelirdi (§19 · madde 4: kimlik ≠ sır).
        $this->assertSame('777', $redacted['shop_id']);
    }

    /**
     * ⚠️ `dontFlash` LİSTESİ DE GENİŞLETİLİR (§19).
     *
     * Doğrulama hatasında girdi oturuma flash edilir ve
     * `SESSION_DRIVER=database` altında ŞİFRESİZ bir tabloya düşer.
     * Liste `bootstrap/app.php` içindedir ve dosyanın KENDİSİ okunur:
     * `config()` gibi etkin bir değer olmadığı için davranışla dolaylı
     * sınamak, listeyi hiç çalışmayan bir yoldan doğrulamak olurdu.
     */
    #[Test]
    public function the_oauth_secrets_are_in_the_dont_flash_list(): void
    {
        $source = (string) file_get_contents(base_path('bootstrap/app.php'));

        foreach (['code_verifier', 'etsy_keystring'] as $key) {
            $this->assertStringContainsString(
                "'{$key}'",
                $source,
                "`{$key}` dontFlash listesinde YOK — doğrulama hatasında "
                .'şifresiz oturum tablosuna düşer.',
            );
        }
    }
}
