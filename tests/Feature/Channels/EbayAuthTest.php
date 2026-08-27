<?php

declare(strict_types=1);

namespace Tests\Feature\Channels;

use App\Domain\Channels\Adapters\Ebay\EbayAuth;
use App\Domain\Channels\Adapters\Ebay\EbayEndpoints;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * eBay OAuth 2 — slice 4.1.
 *
 * V3.0 · §13.3 · §20 · §24 · P0-10.
 *
 * ─────────────────────────────────────────────────────────────────────
 * VERİTABANI YOK ve bu BİLİNÇLİ
 * ─────────────────────────────────────────────────────────────────────
 * `EbayAuth` saf ve yan etkisizdir; oturuma, kasaya veya ağa dokunmaz —
 * `EtsyAuthTest`'in aynı gerekçesi.
 *
 * ─────────────────────────────────────────────────────────────────────
 * BU TESTİN ASIL İŞİ: "ETSY'DEN KOPYALANMADI"YI KANITLAMAK
 * ─────────────────────────────────────────────────────────────────────
 * Projede "aynı kural iki kanalda ters sonuç verebilir" tuzağı defalarca
 * ısırdı. eBay'in OAuth'u Etsy'ninkine YÜZEYDE benzer ve tam bu yüzden
 * tehlikelidir. Aşağıdaki testlerin çoğu bir davranışı değil bir
 * AYRIMI korur.
 */
final class EbayAuthTest extends TestCase
{
    // ─────────────────────────────────────────── PKCE YOK — Etsy'den ayrım 1

    /**
     * ⚠️ eBay PKCE KULLANMAZ ve el sıkışma `code_verifier` ÜRETMEZ.
     *
     * Etsy'den kopyalanıp verifier üretilseydi oturuma hiçbir zaman
     * kullanılmayacak ölü bir sır yazılırdı; daha kötüsü, çağıran onu
     * `authorizeUrl()`'e geçirmeye çalışır ve o parametreyi kabul etmeyen
     * eBay isteği reddederdi.
     */
    #[Test]
    public function the_handshake_carries_only_state_and_no_pkce_verifier(): void
    {
        $handshake = EbayAuth::newHandshake();

        $this->assertSame(
            ['state'],
            array_keys($handshake),
            'eBay el sıkışması yalnızca `state` taşımalı — PKCE eBay\'de YOKTUR '
            .'(§13.3) ve `code_verifier` üretmek Etsy\'den kopyalandığının işaretidir.',
        );
    }

    /**
     * ⚠️ YETKİLENDİRME ADRESİ PKCE PARAMETRESİ TAŞIMAZ.
     *
     * Taşısaydı eBay isteği reddeder ve satıcı bağlanamadan geri dönerdi.
     */
    #[Test]
    public function the_authorize_url_carries_no_pkce_parameters(): void
    {
        $url = EbayAuth::authorizeUrl('client-123', 'RuName-abc', 'state-xyz');

        $this->assertStringNotContainsString('code_challenge', $url);
        $this->assertStringNotContainsString('code_challenge_method', $url);
    }

    // ──────────────────────────────── Basic auth — Etsy'den ayrım 2 ve 3

    /**
     * ⚠️ İSTEMCİ KİMLİĞİ BAŞLIKTA GİDER, GÖVDEDE DEĞİL.
     *
     * Etsy `client_id`'yi token gövdesine yazar. eBay onu `client_secret`
     * ile birlikte Basic auth başlığında bekler; gövdeye de yazılsaydı
     * istek reddedilirdi.
     */
    #[Test]
    public function the_token_request_body_carries_no_client_id(): void
    {
        $body = EbayAuth::tokenRequest('RuName-abc', 'auth-code');

        $this->assertArrayNotHasKey('client_id', $body);
        $this->assertArrayNotHasKey('client_secret', $body);
        $this->assertSame('authorization_code', $body['grant_type']);
    }

    /** Basic auth değeri RFC 7617 biçimindedir: `Basic base64(id:secret)`. */
    #[Test]
    public function the_basic_auth_header_is_base64_of_id_colon_secret(): void
    {
        $this->assertSame(
            'Basic '.base64_encode('my-app:my-secret'),
            EbayAuth::basicAuthHeader('my-app', 'my-secret'),
        );
    }

    /**
     * ⚠️ BOŞ İSTEMCİ KİMLİĞİYLE İSTEK HİÇ ATILMAZ.
     *
     * `Basic base64(":")` gönderilseydi kanal 401 döner, `AUTHENTICATION`
     * KALICI sayılır ve bağlantı "anahtar yanlış" diyerek ölürdü — oysa
     * anahtar hiç GÖNDERİLMEMİŞTİR (`97a7eb7` hata biçimi, projede beş
     * kez ısırdı).
     */
    #[Test]
    public function an_empty_client_credential_throws_instead_of_sending_an_anonymous_request(): void
    {
        $this->expectException(RuntimeException::class);

        EbayAuth::basicAuthHeader('', 'my-secret');
    }

    #[Test]
    public function an_empty_client_secret_also_throws(): void
    {
        $this->expectException(RuntimeException::class);

        EbayAuth::basicAuthHeader('my-app', '');
    }

    // ────────────────────────────────────── scope — Etsy'den ayrım 4 ve 6

    /**
     * ⚠️ eBay SCOPE'LARI URL BİÇİMİNDEDİR, Etsy'nin kısa adları DEĞİL.
     *
     * `listings_r` gibi bir ad yazılsaydı kanal isteği reddederdi.
     */
    #[Test]
    public function scopes_are_absolute_urls(): void
    {
        foreach (EbayAuth::SCOPES as $scope) {
            $this->assertStringStartsWith(
                'https://api.ebay.com/oauth/api_scope/',
                $scope,
                "eBay scope'ları URL biçimindedir; `{$scope}` Etsy'nin kısa ad "
                .'biçiminde yazılmış olabilir.',
            );
        }
    }

    /**
     * ⚠️ YENİLEME İSTEĞİ SCOPE TAŞIR — Etsy'de taşımaz.
     *
     * Gönderilmezse bazı hesaplarda token DAR scope ile döner ve sonraki
     * çağrılar 403 alır; sebebi "yetki yok" diye görünür, oysa yetki
     * VARDI.
     */
    #[Test]
    public function the_refresh_request_carries_scope(): void
    {
        $body = EbayAuth::refreshRequest('refresh-token-abc');

        $this->assertSame('refresh_token', $body['grant_type']);
        $this->assertSame('refresh-token-abc', $body['refresh_token']);
        $this->assertSame(implode(' ', EbayAuth::SCOPES), $body['scope']);
    }

    /**
     * ⚠️ YENİLEMEDE `code_verifier` YOKTUR.
     *
     * Etsy'de de yoktu ama orada bunun sebebi "PKCE yalnızca ilk takasta
     * anlamlıdır"dı; burada sebep PKCE'nin HİÇ olmamasıdır. Sonuç aynı,
     * gerekçe farklı — ve test ikisini de korur.
     */
    #[Test]
    public function the_refresh_request_carries_no_verifier(): void
    {
        $this->assertArrayNotHasKey('code_verifier', EbayAuth::refreshRequest('r'));
    }

    // ───────────────────────────────────────────────── state · P0-10

    /**
     * ⚠️ BOŞ `state` DAİMA REDDEDİLİR (P0-10).
     *
     * Oturumda `state` yoksa karşılaştırma `'' === ''` ile geçseydi
     * doğrulama TAMAMEN devre dışı kalırdı — kapının hiç olmamasından
     * FARKSIZ ama var sanılan bir hâl. O noktadan sonra saldırgan kendi
     * mağazasını kurbanın kiracısına bağlar ve kurbanın stoğu oraya akar.
     */
    #[Test]
    public function an_empty_state_never_matches(): void
    {
        $this->assertFalse(EbayAuth::stateMatches('', ''));
        $this->assertFalse(EbayAuth::stateMatches(null, null));
        $this->assertFalse(EbayAuth::stateMatches('expected', ''));
        $this->assertFalse(EbayAuth::stateMatches('', 'provided'));
        $this->assertFalse(EbayAuth::stateMatches('expected', null));
        $this->assertFalse(EbayAuth::stateMatches(null, 'provided'));
    }

    #[Test]
    public function a_matching_state_passes_and_a_different_one_fails(): void
    {
        $this->assertTrue(EbayAuth::stateMatches('abc123', 'abc123'));
        $this->assertFalse(EbayAuth::stateMatches('abc123', 'abc124'));
    }

    /**
     * `state` URL güvenli olmalı: sorgu dizesinde taşınır ve düz base64
     * üretilseydi `+` bir URL'de BOŞLUĞA çözülür, callback'te farklı
     * okunur ve MEŞRU bağlanma denemesi reddedilirdi.
     */
    #[Test]
    public function the_generated_state_is_url_safe(): void
    {
        $this->assertMatchesRegularExpression(
            '/^[A-Za-z0-9_-]+$/',
            EbayAuth::newHandshake()['state'],
            'state URL güvenli değil — `+`, `/` veya `=` taşıyor.',
        );
    }

    /** Her el sıkışma YENİ bir `state` üretir — tekrar kullanılamaz. */
    #[Test]
    public function each_handshake_produces_a_fresh_state(): void
    {
        $this->assertNotSame(
            EbayAuth::newHandshake()['state'],
            EbayAuth::newHandshake()['state'],
        );
    }

    // ──────────────────────────────────────────────── yetkilendirme adresi

    /**
     * Yetkilendirme adresi API tabanı DEĞİLDİR — satıcının gördüğü bir
     * eBay sayfasıdır. API tabanına yazılsaydı satıcı bir JSON hatasıyla
     * karşılaşır ve bağlama akışı hiç başlamazdı.
     */
    #[Test]
    public function the_authorize_url_is_not_the_api_host(): void
    {
        $url = EbayAuth::authorizeUrl('client-123', 'RuName-abc', 'state-xyz');

        $this->assertStringStartsWith('https://auth.ebay.com/oauth2/authorize?', $url);
        $this->assertStringNotContainsString('api.ebay.com/oauth2', $url);
    }

    /**
     * ⚠️ SANDBOX AYRI ANA BİLGİSAYARDIR (§13.3).
     *
     * Üretim adresine gönderilseydi sandbox anahtarı 401 alır ve satıcı
     * test ortamında hiç bağlanamazdı.
     */
    #[Test]
    public function the_sandbox_flag_switches_the_authorize_host(): void
    {
        $url = EbayAuth::authorizeUrl('client-123', 'RuName-abc', 'state-xyz', sandbox: true);

        $this->assertStringStartsWith('https://auth.sandbox.ebay.com/oauth2/authorize?', $url);
    }

    /** Adres, çağırana verilen `state` ve scope kümesini taşır. */
    #[Test]
    public function the_authorize_url_carries_state_and_all_scopes(): void
    {
        $url = EbayAuth::authorizeUrl('client-123', 'RuName-abc', 'state-xyz');

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $this->assertSame('code', $query['response_type']);
        $this->assertSame('client-123', $query['client_id']);
        $this->assertSame('RuName-abc', $query['redirect_uri']);
        $this->assertSame('state-xyz', $query['state']);
        $this->assertSame(implode(' ', EbayAuth::SCOPES), $query['scope']);
    }

    // ───────────────────────────────────────────────────── uç noktalar

    /**
     * ⚠️ DOLDURULMAMIŞ YER TUTUCU İSTİSNA FIRLATIR.
     *
     * Geçseydi istek literal `{offerId}` içeren bir adrese gider, 404
     * alınır ve sebebi hiçbir yerde görünmezdi — mutabakat yolunda 404
     * "ilan silinmiş" demektir ve var olan bir ilan `REMOTE_MISSING`
     * sayılırdı.
     */
    #[Test]
    public function an_unfilled_placeholder_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        EbayEndpoints::url(EbayEndpoints::OFFER_PUBLISH);
    }

    /**
     * ⚠️ YER TUTUCU ADIYLA DOLDURULUR, KONUMLA DEĞİL.
     *
     * Konumla eşleştirme sıra değişince sessizce yanlış değeri yazar ve
     * istek BAŞKA bir kaynağa giderdi.
     */
    #[Test]
    public function placeholders_are_filled_by_name(): void
    {
        $this->assertSame(
            'https://api.ebay.com/sell/inventory/v1/offer/8912345/publish',
            EbayEndpoints::url(EbayEndpoints::OFFER_PUBLISH, ['offerId' => '8912345']),
        );
    }

    /** SKU yol üzerinde taşınır ve URL kodlaması yapılır. */
    #[Test]
    public function the_sku_is_url_encoded_in_the_path(): void
    {
        $this->assertSame(
            'https://api.ebay.com/sell/inventory/v1/inventory_item/TSH%2FKIRMIZI',
            EbayEndpoints::url(EbayEndpoints::INVENTORY_ITEM, ['sku' => 'TSH/KIRMIZI']),
        );
    }

    /** Sandbox bayrağı API tabanını da değiştirir. */
    #[Test]
    public function the_sandbox_flag_switches_the_api_host(): void
    {
        $this->assertSame(
            'https://api.sandbox.ebay.com/sell/account/v1/privilege',
            EbayEndpoints::url(EbayEndpoints::PRIVILEGE, sandbox: true),
        );
    }
}
