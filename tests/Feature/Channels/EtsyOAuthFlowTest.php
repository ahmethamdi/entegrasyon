<?php

declare(strict_types=1);

namespace Tests\Feature\Channels;

use App\Domain\Channels\Adapters\Etsy\EtsyAdapter;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Models\ChannelType;
use App\Domain\Channels\Support\ChannelConnectForm;
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
            '*' => Http::response(['user_id' => 12345, 'shop_id' => 777], 200),
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
            '*' => Http::response(['user_id' => 12345, 'shop_id' => 777], 200),
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
    // ───────────────────────────────── mağaza kimliği OAuth'tan (7 Eki 2026)

    /**
     * Satıcıya mağaza kimliği SORULMAZ: dönüşte `/users/me`'den okunur,
     * geçici hesap kimliğinin yerine yazılır. İstek `keystring:secret` taşır.
     */
    #[Test]
    public function the_shop_id_is_read_from_etsy_and_becomes_the_account(): void
    {
        [$user, $connection] = $this->pendingShop();

        $this->fakeEtsy(shopId: 555);
        $this->returnFromEtsy($user, $connection);

        $fresh = TenantContext::runAsSystem(fn () => ChannelConnection::query()->find($connection->id));

        $this->assertSame('555', $fresh->external_account_id);
        $this->assertSame('555', $fresh->settings[EtsyAdapter::SHOP_ID_KEY]);
        $this->assertSame('12345.yeni-access', $this->storedSecrets($connection)['access_token'] ?? null);
        Http::assertSent(static fn ($r): bool => str_contains($r->url(), '/users/me')
            && $r->hasHeader('x-api-key', 'key-abc:sir-xyz')
            && $r->hasHeader('Authorization', 'Bearer 12345.yeni-access'));
    }

    /** Mağaza başka kiracıya bağlıysa geçici bağlantı silinir, token yazılmaz. */
    #[Test]
    public function a_shop_owned_by_another_tenant_is_refused(): void
    {
        [, $other] = $this->connectedShop();
        TenantContext::runAsSystem(fn () => $other->forceFill(['external_account_id' => '555'])->save());

        [$user, $connection] = $this->pendingShop();

        $this->fakeEtsy(shopId: 555);
        $this->returnFromEtsy($user, $connection);

        $this->assertNull(TenantContext::runAsSystem(fn () => ChannelConnection::query()->find($connection->id)));
        $this->assertNull($this->storedSecrets($other), 'Başkasının bağlantısına token yazılmamalı.');
    }

    /**
     * Aynı kiracı mağazayı yeniden bağlarsa ikinci satır AÇILMAZ: token'lar
     * mevcut bağlantıya gider, geçici olan silinir.
     */
    #[Test]
    public function reconnecting_the_same_shop_reuses_the_existing_connection(): void
    {
        [$user, $pending] = $this->pendingShop();

        $existing = TenantContext::runAsSystem(fn () => ChannelConnection::factory()->create([
            'tenant_id' => $pending->tenant_id,
            'channel_type_code' => 'etsy',
            'external_account_id' => '555',
            'status' => 'active',
            'settings' => [EtsyAdapter::SHOP_ID_KEY => '555'],
        ]));

        $this->fakeEtsy(shopId: 555);
        $this->returnFromEtsy($user, $pending);

        $this->assertNull(TenantContext::runAsSystem(fn () => ChannelConnection::query()->find($pending->id)));
        $this->assertSame('12345.yeni-access', $this->storedSecrets($existing)['access_token'] ?? null);
    }

    /** Mağazası olmayan Etsy hesabı: geçici bağlantı silinir. */
    #[Test]
    public function an_account_without_a_shop_is_not_connected(): void
    {
        [$user, $connection] = $this->pendingShop();

        $this->fakeEtsy(shopId: null);
        $this->returnFromEtsy($user, $connection);

        $this->assertNull(TenantContext::runAsSystem(fn () => ChannelConnection::query()->find($connection->id)));
    }

    private function fakeEtsy(?int $shopId): void
    {
        Http::fake([
            '*/oauth/token' => Http::response(['access_token' => '12345.yeni-access', 'refresh_token' => '12345.yeni-refresh', 'expires_in' => 3600], 200),
            '*' => Http::response(array_filter(['user_id' => 12345, 'shop_id' => $shopId]), 200),
        ]);
    }

    private function returnFromEtsy(User $user, ChannelConnection $connection): void
    {
        $this->actingAs($user)
            ->withSession([
                'etsy.oauth.state' => 'ayni-deger',
                'etsy.oauth.code_verifier' => 'ver-123',
                'etsy.oauth.connection' => $connection->id,
            ])
            ->get(route('channels.etsy.callback', ['code' => 'yetki-kodu', 'state' => 'ayni-deger']))
            ->assertRedirect(route('channels.index'));
    }

    /** @return array{0: User, 1: ChannelConnection} */
    private function pendingShop(): array
    {
        [$user, $connection] = $this->connectedShop();

        TenantContext::runAsSystem(fn () => $connection->forceFill([
            'external_account_id' => ChannelConnectForm::PENDING_ACCOUNT_PREFIX.uniqid(),
            'settings' => [],
        ])->save());

        return [$user, $connection];
    }

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
