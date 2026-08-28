<?php

declare(strict_types=1);

namespace Tests\Feature\Channels;

use App\Domain\Channels\Adapters\Ebay\EbayAdapter;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Models\ChannelType;
use App\Domain\Channels\Support\CredentialVault;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * eBay OAuth 2 bağlama akışı — slice 4.2 · GERÇEK HTTP YOLU.
 *
 * V3.0 · §13.3 · §24 · P0-10.
 *
 * ─────────────────────────────────────────────────────────────────────
 * `EbayAuthTest` SAF MANTIĞI SINAR, BU TEST AKIŞI SINAR
 * ─────────────────────────────────────────────────────────────────────
 * Orada `stateMatches()` doğrudan çağrılır; burada CALLBACK ROTASI
 * sürülür ve asıl soru şudur: doğrulama gerçekten ÇAĞRILIYOR MU ve
 * başarısızlıkta kimlik bilgisi gerçekten YAZILMIYOR MU? Saf mantık
 * kusursuz olup akışta hiç çağrılmasa test yine yeşil kalırdı.
 */
final class EbayOAuthFlowTest extends TestCase
{
    use RefreshDatabase;

    // ───────────────────────────────────────────── yetkilendirmeye gönderme

    /**
     * ⚠️ ADRES PKCE PARAMETRESİ TAŞIMAZ — Etsy'den ayrım.
     *
     * eBay `code_challenge`'ı KABUL ETMEZ; Etsy'den kopyalanıp
     * gönderilseydi istek reddedilir ve satıcı bağlanamadan geri dönerdi.
     */
    #[Test]
    public function the_seller_is_redirected_to_ebay_without_any_pkce_parameter(): void
    {
        [$user, $connection] = $this->connectedSeller();

        $response = $this->actingAs($user)
            ->post(route('channels.ebay.authorize', $connection->id));

        $response->assertRedirect();
        $response->assertSessionHas('ebay.oauth.state');

        $target = (string) $response->headers->get('Location');

        $this->assertStringStartsWith('https://auth.ebay.com/oauth2/authorize?', $target);
        $this->assertStringNotContainsString('code_challenge', $target);
    }

    /**
     * ⚠️ OTURUMDA `code_verifier` TUTULMAZ.
     *
     * Etsy'den kopyalanıp üretilseydi hiçbir zaman okunmayan ölü bir sır
     * oturuma yazılırdı.
     */
    #[Test]
    public function no_pkce_verifier_is_kept_in_the_session(): void
    {
        [$user, $connection] = $this->connectedSeller();

        $this->actingAs($user)
            ->post(route('channels.ebay.authorize', $connection->id));

        $this->assertNull(session('ebay.oauth.code_verifier'));
    }

    /**
     * ⚠️ `redirect_uri` RuName'DİR, HAM ADRES DEĞİL (§13.3).
     *
     * Ham callback adresi gönderilseydi eBay `invalid_request` döner ve
     * satıcı sebebini anlayamazdı.
     */
    #[Test]
    public function the_authorize_url_carries_the_runame_not_the_callback_url(): void
    {
        [$user, $connection] = $this->connectedSeller();

        $response = $this->actingAs($user)
            ->post(route('channels.ebay.authorize', $connection->id));

        $target = (string) $response->headers->get('Location');

        parse_str((string) parse_url($target, PHP_URL_QUERY), $query);

        $this->assertSame('Satici-App-PRD-abc-def', $query['redirect_uri']);
        $this->assertStringNotContainsString('channels/ebay/callback', $target);
    }

    /**
     * ⚠️ İSTEMCİ KİMLİĞİ YOKSA YÖNLENDİRME YAPILMAZ.
     *
     * Kimliksiz gidilseydi eBay "invalid_client" ekranı gösterir ve
     * satıcı sebebini ANLAYAMAZDI — oysa eksik olan bizdeki bir alandır.
     */
    #[Test]
    public function a_connection_without_a_client_id_is_not_redirected_to_ebay(): void
    {
        [$user, $connection] = $this->connectedSeller(clientId: null);

        $response = $this->actingAs($user)
            ->post(route('channels.ebay.authorize', $connection->id));

        $response->assertRedirect(route('channels.index'));
        $this->assertNull(session('ebay.oauth.state'));
    }

    // ─────────────────────────────────────────── P0-10 · state doğrulaması

    /**
     * ⚠️ EŞLEŞMEYEN `state` KİMLİK BİLGİSİ YAZDIRMAZ — P0-10.
     *
     * Doğrulanmazsa saldırgan KENDİ yetkilendirme kodunu kurbanın
     * oturumuna enjekte eder ve kurbanın kiracısına KENDİ mağazasını
     * bağlar; o noktadan sonra kurbanın stoğu saldırganın mağazasına akar.
     *
     * ⚠️ İDDİA "YÖNLENDİRİLDİ" DEĞİL "KASADA TOKEN YOK"TUR. Yönlendirme
     * iddiası takas gerçekleşse BİLE yeşil kalırdı.
     */
    #[Test]
    public function a_mismatched_state_never_stores_credentials(): void
    {
        [$user, $connection] = $this->connectedSeller();

        Http::fake(['*' => Http::response([
            'access_token' => 'saldirgan-access',
            'refresh_token' => 'saldirgan-refresh',
            'expires_in' => 7200,
        ], 200)]);

        $this->actingAs($user)
            ->withSession([
                'ebay.oauth.state' => 'gercek-state',
                'ebay.oauth.connection' => $connection->id,
            ])
            ->get(route('channels.ebay.callback', ['code' => 'kod', 'state' => 'sahte-state']));

        $secrets = $this->storedSecrets($connection);

        $this->assertArrayNotHasKey('access_token', $secrets ?? []);
        Http::assertNothingSent();
    }

    /**
     * ⚠️ OTURUMDA `state` YOKSA DA REDDEDİLİR.
     *
     * `'' === ''` ile geçilseydi doğrulama TAMAMEN devre dışı kalırdı —
     * kapının hiç olmamasından farksız ama VAR SANILAN bir hâl.
     */
    #[Test]
    public function a_callback_without_a_session_state_is_rejected(): void
    {
        [$user, $connection] = $this->connectedSeller();

        Http::fake(['*' => Http::response(['access_token' => 'x'], 200)]);

        $this->actingAs($user)
            ->withSession(['ebay.oauth.connection' => $connection->id])
            ->get(route('channels.ebay.callback', ['code' => 'kod', 'state' => '']));

        $this->assertArrayNotHasKey('access_token', $this->storedSecrets($connection) ?? []);
        Http::assertNothingSent();
    }

    // ───────────────────────────────────────────────────── başarılı takas

    /**
     * Geçerli callback token'ları kasaya yazar.
     *
     * ⚠️ İSTEMCİ ÇİFTİ DE KORUNUR: `client_id`/`client_secret`
     * yazılmasaydı token yenileme SONRAKİ turda kimliksiz kalır ve
     * bağlantı iki saat sonra sessizce ölürdü.
     */
    #[Test]
    public function a_valid_callback_stores_the_tokens_and_keeps_the_client_pair(): void
    {
        [$user, $connection] = $this->connectedSeller();

        Http::fake(['*' => Http::response([
            'access_token' => 'yeni-access',
            'refresh_token' => 'yeni-refresh',
            'expires_in' => 7200,
        ], 200)]);

        $this->actingAs($user)
            ->withSession([
                'ebay.oauth.state' => 'dogru-state',
                'ebay.oauth.connection' => $connection->id,
            ])
            ->get(route('channels.ebay.callback', ['code' => 'kod', 'state' => 'dogru-state']));

        $secrets = $this->storedSecrets($connection);

        $this->assertSame('yeni-access', $secrets['access_token'] ?? null);
        $this->assertSame('yeni-refresh', $secrets['refresh_token'] ?? null);
        $this->assertSame('app-id', $secrets['client_id'] ?? null);
        $this->assertSame('cert-id', $secrets['client_secret'] ?? null);
    }

    /**
     * ⚠️ TAKAS `Basic` KİMLİKLE ve FORM-ENCODED GİDER — İKİSİ DE ZORUNLU.
     *
     * İstemci kimliği gövdeye yazılsaydı ya da gövde JSON gitseydi eBay
     * alanları HİÇ okumaz, `invalid_request` döner ve sebebi gövdede
     * görünmezdi.
     */
    #[Test]
    public function the_exchange_uses_basic_auth_and_a_form_encoded_body(): void
    {
        [$user, $connection] = $this->connectedSeller();

        Http::fake(['*' => Http::response([
            'access_token' => 'a',
            'refresh_token' => 'r',
            'expires_in' => 7200,
        ], 200)]);

        $this->actingAs($user)
            ->withSession([
                'ebay.oauth.state' => 'dogru-state',
                'ebay.oauth.connection' => $connection->id,
            ])
            ->get(route('channels.ebay.callback', ['code' => 'kod', 'state' => 'dogru-state']));

        Http::assertSent(static function ($request): bool {
            $contentType = (string) ($request->header('Content-Type')[0] ?? '');

            return $request->hasHeader('Authorization', 'Basic '.base64_encode('app-id:cert-id'))
                && str_contains($contentType, 'application/x-www-form-urlencoded')
                && str_contains($request->body(), 'grant_type=authorization_code')
                // İstemci kimliği GÖVDEDE olmamalı — başlıkta gider.
                && ! str_contains($request->body(), 'client_id');
        });
    }

    /**
     * ⚠️ REFRESH TOKEN GELMEZSE HİÇBİR ŞEY YAZILMAZ.
     *
     * eBay ilk takasta onu DAİMA döner. Sessizce kabul edilseydi
     * bağlantı iki saat sonra ölür ve satıcı sebebini bulamazdı —
     * yazmamak, yarım bir kimlikle "bağlandı" demekten iyidir.
     */
    #[Test]
    public function a_response_without_a_refresh_token_stores_nothing(): void
    {
        [$user, $connection] = $this->connectedSeller();

        Http::fake(['*' => Http::response([
            'access_token' => 'yalniz-access',
            'expires_in' => 7200,
        ], 200)]);

        $this->actingAs($user)
            ->withSession([
                'ebay.oauth.state' => 'dogru-state',
                'ebay.oauth.connection' => $connection->id,
            ])
            ->get(route('channels.ebay.callback', ['code' => 'kod', 'state' => 'dogru-state']));

        $this->assertArrayNotHasKey('access_token', $this->storedSecrets($connection) ?? []);
    }

    /**
     * ⚠️ EL SIKIŞMA SIRRI TEK KULLANIMLIKTIR.
     *
     * Oturumda kalsaydı çalınmış bir `state` ikinci kez denenebilirdi.
     */
    #[Test]
    public function the_handshake_state_is_consumed(): void
    {
        [$user, $connection] = $this->connectedSeller();

        Http::fake(['*' => Http::response([
            'access_token' => 'a',
            'refresh_token' => 'r',
            'expires_in' => 7200,
        ], 200)]);

        $this->actingAs($user)
            ->withSession([
                'ebay.oauth.state' => 'dogru-state',
                'ebay.oauth.connection' => $connection->id,
            ])
            ->get(route('channels.ebay.callback', ['code' => 'kod', 'state' => 'dogru-state']));

        $this->assertNull(session('ebay.oauth.state'));
        $this->assertNull(session('ebay.oauth.connection'));
    }

    /** Başarısız takas hiçbir şey yazmaz. */
    #[Test]
    public function a_failed_exchange_stores_nothing(): void
    {
        [$user, $connection] = $this->connectedSeller();

        Http::fake(['*' => Http::response(['error' => 'invalid_grant'], 400)]);

        $this->actingAs($user)
            ->withSession([
                'ebay.oauth.state' => 'dogru-state',
                'ebay.oauth.connection' => $connection->id,
            ])
            ->get(route('channels.ebay.callback', ['code' => 'kod', 'state' => 'dogru-state']));

        $this->assertArrayNotHasKey('access_token', $this->storedSecrets($connection) ?? []);
    }

    /**
     * ⚠️ BAŞKA KİRACININ BAĞLANTISI YETKİLENDİRİLEMEZ.
     *
     * Kapsamlı sorgu YETKİLENDİRMEDİR: başka kiracının bağlantı
     * kimliğini adres çubuğuna yazan biri onu BULAMAZ.
     */
    #[Test]
    public function another_tenants_connection_cannot_be_authorized(): void
    {
        [, $connection] = $this->connectedSeller();

        $intruder = User::factory()->create();
        (new CreateTenant)->run(name: 'Yabanci '.uniqid(), owner: $intruder);

        $response = $this->actingAs($intruder)
            ->post(route('channels.ebay.authorize', $connection->id));

        $response->assertRedirect(route('channels.index'));
        $this->assertNull(session('ebay.oauth.state'));
    }

    // ──────────────────────────────────────────────────────── yardımcılar

    /** @return array<string, mixed>|null */
    private function storedSecrets(ChannelConnection $connection): ?array
    {
        return TenantContext::runAsSystem(function () use ($connection): ?array {
            $fresh = ChannelConnection::query()->find($connection->id);

            if ($fresh?->activeCredential()->first() === null) {
                return null;
            }

            return app(CredentialVault::class)->read($fresh);
        });
    }

    /** @return array{0: User, 1: ChannelConnection} */
    private function connectedSeller(?string $clientId = 'app-id'): array
    {
        $this->asSystem(fn (): ChannelType => ChannelType::query()->updateOrCreate(
            ['code' => 'ebay'],
            [
                'name' => 'eBay',
                'kind' => 'marketplace',
                'adapter_class' => EbayAdapter::class,
                'supports_webhooks' => false,
                'is_active' => false,
            ],
        ));

        $user = User::factory()->create();
        $tenant = (new CreateTenant)->run(name: 'eBay '.uniqid(), owner: $user);

        $connection = $this->asTenant($tenant, function () use ($clientId): ChannelConnection {
            $connection = ChannelConnection::factory()->create([
                'channel_type_code' => 'ebay',
                'external_account_id' => 'ebay-'.uniqid(),
                'status' => 'pending',
                // ⚠️ `settings` ŞİFRESİZDİR — yalnızca YAPILANDIRMA.
                'settings' => [
                    EbayAdapter::RU_NAME_KEY => 'Satici-App-PRD-abc-def',
                    EbayAdapter::MARKETPLACE_ID_KEY => 'EBAY_DE',
                    EbayAdapter::MERCHANT_LOCATION_KEY => 'WAREHOUSE-1',
                    EbayAdapter::FULFILLMENT_POLICY_KEY => 'FP-1',
                    EbayAdapter::PAYMENT_POLICY_KEY => 'PP-1',
                    EbayAdapter::RETURN_POLICY_KEY => 'RP-1',
                ],
            ]);

            // İstemci çifti KASADADIR (Etsy'nin keystring'inin aksine).
            app(CredentialVault::class)->store($connection, array_filter([
                'client_id' => $clientId,
                'client_secret' => 'cert-id',
            ], static fn (mixed $v): bool => $v !== null));

            return $connection;
        });

        return [$user, $connection];
    }
}
