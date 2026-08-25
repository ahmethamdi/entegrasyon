<?php

declare(strict_types=1);

namespace Tests\Feature\Channels;

use App\Domain\Channels\Adapters\Etsy\EtsyAdapter;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Models\ChannelType;
use App\Domain\Channels\Support\CredentialVault;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Etsy OAuth 2 + PKCE bağlama akışı — slice 3.1 · GERÇEK HTTP YOLU.
 *
 * V3.0 · §11.2 · §19 (güvenlik · madde 2) · P0-10 · T-V3-24.
 *
 * ─────────────────────────────────────────────────────────────────────
 * `EtsyAuthTest` SAF MANTIĞI SINAR, BU TEST AKIŞI SINAR
 * ─────────────────────────────────────────────────────────────────────
 * Orada `stateMatches()` doğrudan çağrılır; burada CALLBACK ROTASI
 * sürülür ve asıl soru şudur: doğrulama gerçekten ÇAĞRILIYOR MU ve
 * başarısızlıkta kimlik bilgisi gerçekten YAZILMIYOR MU? Saf mantık
 * kusursuz olup akışta hiç çağrılmasa test yine yeşil kalırdı —
 * projenin "gerçek çalıştırma" kuralının tam olarak uyardığı boşluk.
 */
final class EtsyOAuthFlowTest extends TestCase
{
    use RefreshDatabase;

    // ───────────────────────────────────────────── yetkilendirmeye gönderme

    /**
     * Satıcı Etsy'nin yetkilendirme ekranına yönlendirilir ve tek
     * kullanımlık sırlar OTURUMA yazılır.
     */
    #[Test]
    public function the_seller_is_redirected_to_etsy_with_a_challenge(): void
    {
        [$user, $connection] = $this->connectedShop();

        $response = $this->actingAs($user)
            ->post(route('channels.etsy.authorize', $connection->id));

        $response->assertRedirect();
        $response->assertSessionHas('etsy.oauth.state');
        $response->assertSessionHas('etsy.oauth.code_verifier');

        $target = (string) $response->headers->get('Location');

        $this->assertStringStartsWith('https://www.etsy.com/oauth/connect?', $target);
        $this->assertStringContainsString('code_challenge_method=S256', $target);
    }

    /**
     * ⚠️ `code_verifier` YETKİLENDİRME ADRESİNE SIZMAZ.
     *
     * Sızsaydı PKCE anlamsızlaşırdı: kodu çalan saldırgan verifier'ı
     * doğrudan token isteğinde kullanır. O adres tarayıcı geçmişine,
     * sunucu günlüklerine ve Referer başlığına düşer.
     */
    #[Test]
    public function the_verifier_never_leaves_the_session(): void
    {
        [$user, $connection] = $this->connectedShop();

        $response = $this->actingAs($user)
            ->post(route('channels.etsy.authorize', $connection->id));

        $verifier = (string) session('etsy.oauth.code_verifier');
        $target = (string) $response->headers->get('Location');

        $this->assertNotSame('', $verifier);
        $this->assertStringNotContainsString(
            $verifier,
            $target,
            'code_verifier yetkilendirme adresine sızdı — PKCE anlamsızlaştı.',
        );
    }

    // ─────────────────────────────────────────── P0-10 · state doğrulaması

    /**
     * ⚠️ EŞLEŞMEYEN `state` KİMLİK BİLGİSİ YAZDIRMAZ — P0-10 · T-V3-24.
     *
     * Bu testin varlık sebebi: doğrulanmazsa saldırgan KENDİ
     * yetkilendirme kodunu kurbanın oturumuna enjekte eder ve kurbanın
     * kiracısına KENDİ mağazasını bağlar. O noktadan sonra kurbanın stoğu
     * saldırganın mağazasına akar.
     *
     * ⚠️ İDDİA "YÖNLENDİRİLDİ" DEĞİL "KASA BOŞ KALDI"DIR. Yönlendirme
     * iddiası, takas gerçekleşse BİLE yeşil kalırdı; ayırt edici işaret
     * kimlik bilgisinin YAZILMAMASIDIR. Ayrıca kanala HİÇ istek
     * atılmamalıdır.
     */
    #[Test]
    public function a_mismatched_state_never_stores_credentials(): void
    {
        [$user, $connection] = $this->connectedShop();

        Http::fake(['*' => Http::response([
            'access_token' => '12345.saldirgan',
            'refresh_token' => '12345.saldirgan-refresh',
            'expires_in' => 3600,
        ], 200)]);

        $this->actingAs($user)
            ->withSession([
                'etsy.oauth.state' => 'kurbanin-degeri',
                'etsy.oauth.code_verifier' => 'ver',
                'etsy.oauth.connection' => $connection->id,
            ])
            ->get(route('channels.etsy.callback', [
                'code' => 'saldirgan-kodu',
                'state' => 'SALDIRGANIN-DEGERI',
            ]))
            ->assertRedirect(route('channels.index'));

        Http::assertNothingSent();

        $this->assertNull(
            $this->storedSecrets($connection),
            'state uyuşmadığı hâlde kimlik bilgisi KASAYA YAZILDI — '
            .'saldırgan kurbanın kiracısına kendi mağazasını bağlayabilir.',
        );
    }

    /**
     * ⚠️ OTURUMDA `state` YOKSA DA REDDEDİLİR.
     *
     * Saldırgan callback'e DOĞRUDAN gelebilir; oturumda beklenen değer
     * olmaz. `'' === ''` ile geçilseydi doğrulama tamamen devre dışı
     * kalırdı — kapının hiç olmamasından farksız ama VAR SANILAN bir hâl.
     */
    #[Test]
    public function a_callback_without_a_session_state_is_rejected(): void
    {
        [$user, $connection] = $this->connectedShop();

        Http::fake();

        $this->actingAs($user)
            ->get(route('channels.etsy.callback', [
                'code' => 'kod',
                'state' => 'herhangi',
            ]))
            ->assertRedirect(route('channels.index'));

        Http::assertNothingSent();
        $this->assertNull($this->storedSecrets($connection));
    }

    // ──────────────────────────────────────────────────── mutlu yol

    /**
     * Eşleşen `state` ile kod token'a takas edilir ve KASAYA yazılır.
     *
     * ⚠️ TOKEN `settings`'E DEĞİL KASAYA GİDER (§19 · madde 3).
     * `settings` şifresizdir ve panele Inertia prop'u olarak gider;
     * refresh token oraya yazılsaydı 90 günlük bir sır tarayıcıda
     * görünürdü.
     */
    #[Test]
    public function a_valid_callback_stores_the_tokens_in_the_vault(): void
    {
        [$user, $connection] = $this->connectedShop();

        Http::fake([
            '*/oauth/token' => Http::response([
                'access_token' => '12345.yeni-access',
                'refresh_token' => '12345.yeni-refresh',
                'expires_in' => 3600,
            ], 200),
            '*' => Http::response(['user_id' => 12345], 200),
        ]);

        $this->actingAs($user)
            ->withSession([
                'etsy.oauth.state' => 'ayni-deger',
                'etsy.oauth.code_verifier' => 'ver-123',
                'etsy.oauth.connection' => $connection->id,
            ])
            ->get(route('channels.etsy.callback', [
                'code' => 'yetki-kodu',
                'state' => 'ayni-deger',
            ]))
            ->assertRedirect(route('channels.index'));

        $secrets = $this->storedSecrets($connection);

        $this->assertNotNull($secrets);
        $this->assertSame('12345.yeni-access', $secrets['access_token']);
        $this->assertSame('12345.yeni-refresh', $secrets['refresh_token']);

        // `settings` ŞİFRESİZDİR — oraya sır YAZILMAZ.
        $settings = TenantContext::runAsSystem(
            fn () => ChannelConnection::query()->find($connection->id)->settings
        );

        $this->assertArrayNotHasKey('access_token', $settings);
        $this->assertArrayNotHasKey('refresh_token', $settings);
    }

    /**
     * ⚠️ TAKAS `code_verifier` GÖNDERİR — PKCE'nin ikinci yarısı.
     *
     * Gönderilmeseydi Etsy isteği reddeder ve bağlama akışı satıcının
     * onayından SONRA, sebebi görünmeden başarısız olurdu.
     */
    #[Test]
    public function the_exchange_sends_the_verifier(): void
    {
        [$user, $connection] = $this->connectedShop();

        Http::fake([
            '*/oauth/token' => Http::response([
                'access_token' => '12345.access',
                'refresh_token' => '12345.refresh',
                'expires_in' => 3600,
            ], 200),
            '*' => Http::response(['user_id' => 12345], 200),
        ]);

        $this->actingAs($user)
            ->withSession([
                'etsy.oauth.state' => 'st',
                'etsy.oauth.code_verifier' => 'ver-gizli',
                'etsy.oauth.connection' => $connection->id,
            ])
            ->get(route('channels.etsy.callback', ['code' => 'kod', 'state' => 'st']));

        Http::assertSent(function ($request): bool {
            if (! str_contains($request->url(), '/oauth/token')) {
                return false;
            }

            $body = $request->data();

            return ($body['grant_type'] ?? null) === 'authorization_code'
                && ($body['code_verifier'] ?? null) === 'ver-gizli';
        });
    }

    /**
     * ⚠️ SIRLAR TEK KULLANIMLIKTIR — doğrulama sonucu ne olursa olsun
     * oturumdan SİLİNİR.
     *
     * Silinmeseydi çalınmış bir `state` ikinci kez denenebilirdi.
     */
    #[Test]
    public function the_handshake_secrets_are_consumed(): void
    {
        [$user, $connection] = $this->connectedShop();

        Http::fake(['*' => Http::response(['hata' => 'x'], 400)]);

        $this->actingAs($user)
            ->withSession([
                'etsy.oauth.state' => 'st',
                'etsy.oauth.code_verifier' => 'ver',
                'etsy.oauth.connection' => $connection->id,
            ])
            ->get(route('channels.etsy.callback', ['code' => 'kod', 'state' => 'st']))
            ->assertSessionMissing('etsy.oauth.state')
            ->assertSessionMissing('etsy.oauth.code_verifier');
    }

    /**
     * ⚠️ TAKAS BAŞARISIZSA KİMLİK BİLGİSİ YAZILMAZ.
     *
     * Yazılsaydı bağlantı yarım bir kimlikle `active` olur ve her çağrı
     * 401 alırdı — "aktif ama çalışmayan bağlantı en pahalı hata
     * biçimidir" (`ConnectChannel`).
     */
    #[Test]
    public function a_failed_exchange_stores_nothing(): void
    {
        [$user, $connection] = $this->connectedShop();

        Http::fake(['*' => Http::response(['error' => 'invalid_grant'], 400)]);

        $this->actingAs($user)
            ->withSession([
                'etsy.oauth.state' => 'st',
                'etsy.oauth.code_verifier' => 'ver',
                'etsy.oauth.connection' => $connection->id,
            ])
            ->get(route('channels.etsy.callback', ['code' => 'kod', 'state' => 'st']))
            ->assertRedirect(route('channels.index'));

        $this->assertNull($this->storedSecrets($connection));
    }

    // ──────────────────────────────────────────────────────── izolasyon

    /**
     * ⚠️ BAŞKA KİRACININ BAĞLANTISI YETKİLENDİRİLEMEZ.
     *
     * Bağlantı KİRACI KAPSAMINDA aranır; kapsam yetkilendirmenin
     * kendisidir. Kapsamsız aransaydı adres çubuğuna başka kiracının
     * bağlantı kimliğini yazan biri o mağazayı kendi oturumundan
     * yetkilendirebilirdi.
     */
    #[Test]
    public function another_tenants_connection_cannot_be_authorized(): void
    {
        [, $victimConnection] = $this->connectedShop();
        [$attacker] = $this->connectedShop();

        Http::fake();

        $this->actingAs($attacker)
            ->post(route('channels.etsy.authorize', $victimConnection->id))
            ->assertRedirect(route('channels.index'));

        Http::assertNothingSent();
    }

    // ────────────────────────────────────────────────────────── yardımcılar

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
    private function connectedShop(): array
    {
        $this->asSystem(fn (): ChannelType => ChannelType::query()->updateOrCreate(
            ['code' => 'etsy'],
            [
                'name' => 'Etsy',
                'kind' => 'marketplace',
                'adapter_class' => EtsyAdapter::class,
                'supports_webhooks' => false,
                'is_active' => false,
            ],
        ));

        $user = User::factory()->create();
        $tenant = (new CreateTenant)->run(name: 'Etsy '.uniqid(), owner: $user);

        $connection = $this->asTenant($tenant, fn (): ChannelConnection => ChannelConnection::factory()->create([
            'channel_type_code' => 'etsy',
            'external_account_id' => 'etsy-'.uniqid(),
            'status' => 'pending',
            'settings' => [
                // Keystring KİMLİKTİR, sır DEĞİL (§19 · madde 4).
                EtsyAdapter::KEYSTRING_KEY => 'key-abc',
                EtsyAdapter::SHOP_ID_KEY => '777',
            ],
        ]));

        return [$user, $connection];
    }

    private function tenantFor(User $user): Tenant
    {
        return $user->tenants()->firstOrFail();
    }
}
