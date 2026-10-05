<?php

declare(strict_types=1);

namespace Tests\Feature\Channels;

use App\Domain\Channels\Adapters\Shopify\ShopifyAdapter;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Models\ChannelType;
use App\Domain\Channels\Support\CredentialVault;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Notifications\VerifyEmailNotification;
use App\Http\Controllers\ShopifyInstallController;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Shopify'dan BAŞLAYAN kurulum (App URL) — App Store incelemesinin ilk
 * denediği yol. Kural: kurulumdan sonra uygulamanın hiçbir ekranından ÖNCE
 * OAuth; anahtar hesap açılana kadar şifreli oturumda bekler.
 */
final class ShopifyInstallFlowTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'uygulama-sirri';

    private const SHOP = 'magaza.myshopify.com';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.shopify.client_id', 'istemci-123');
        config()->set('services.shopify.client_secret', self::SECRET);

        $this->shopifyType(active: true);
    }

    // ═════════════════════════════════════════════════════ açılış (App URL)

    /** Geçerli istek HEMEN onay adresine gider — arada ekran yok. */
    #[Test]
    public function a_signed_launch_goes_straight_to_oauth(): void
    {
        $response = $this->get($this->signedUrl('/shopify', $this->launchQuery()));

        $target = (string) $response->headers->get('Location');
        $this->assertStringStartsWith('https://'.self::SHOP.'/admin/oauth/authorize?', $target);

        parse_str((string) parse_url($target, PHP_URL_QUERY), $params);
        $this->assertSame(route('shopify.install.callback'), $params['redirect_uri']);
        $this->assertSame('istemci-123', $params['client_id']);
        $this->assertSame(session('shopify.install.state'), $params['state']);
    }

    /** İmzasız, yanlış imzalı, bayat ya da sahte mağazalı istek reddedilir. */
    #[Test]
    public function an_unsigned_or_stale_launch_is_refused(): void
    {
        $this->get('/shopify?shop='.self::SHOP)->assertStatus(400);

        $this->get($this->signedUrl('/shopify', $this->launchQuery(), secret: 'baska-sir'))->assertStatus(400);

        $this->get($this->signedUrl('/shopify', $this->launchQuery(timestamp: time() - 2 * 86400)))->assertStatus(400);

        $this->get($this->signedUrl('/shopify', $this->launchQuery(shop: 'magaza.myshopify.com.saldirgan.example')))
            ->assertStatus(400);

        $this->assertNull(session('shopify.install.state'));
    }

    /** Shopify kanalı kapalıyken kurulum başlamaz. */
    #[Test]
    public function launch_is_unavailable_while_shopify_is_closed(): void
    {
        $this->shopifyType(active: false);

        $this->get($this->signedUrl('/shopify', $this->launchQuery()))->assertStatus(503);
    }

    // ═════════════════════════════════════════════════════ dönüş

    /**
     * Hesabı olmayan satıcı: anahtar alınır, kayıt sayfası mağaza bilgisiyle
     * dolu açılır; sayfaya ANAHTAR GİTMEZ.
     */
    #[Test]
    public function a_new_merchant_lands_on_a_prefilled_register_page(): void
    {
        $this->fakeShopify();

        $this->launch();
        $this->returnFromShopify()->assertRedirect(route('register'));

        $page = $this->get('/register')->assertOk()->viewData('page');
        $prefill = $page['props']['shopifyInstall'];

        $this->assertSame(['shop' => self::SHOP, 'name' => 'Atölye Nur', 'email' => 'sahip@atolye.example'], $prefill);
        $this->assertStringNotContainsString('erisim-1', json_encode($page));
    }

    /**
     * Kayıt → bağlantı OTOMATİK tamamlanır. Shopify'ın doğruladığı adresle
     * kaydolan doğrulama postası beklemez.
     */
    #[Test]
    public function registering_with_the_shop_email_completes_the_connection(): void
    {
        Notification::fake();
        $this->fakeShopify();

        $this->launch();
        $this->returnFromShopify();

        $this->post('/register', $this->registration('sahip@atolye.example'))
            ->assertRedirect(route('shopify.install.finish'));

        $user = User::query()->where('email', 'sahip@atolye.example')->firstOrFail();
        $this->assertTrue($user->hasVerifiedEmail(), 'Shopify e-postası doğrulanmış sayılmalı.');
        Notification::assertNotSentTo($user, VerifyEmailNotification::class);

        $this->get(route('shopify.install.finish'))->assertRedirect(route('channels.index'));

        $connection = $this->connectionOf($user);
        $this->assertSame('active', $connection->status);
        $this->assertSame('Atölye Nur', $connection->label);
        $this->assertSame('gid://shopify/Location/1', $connection->settings[ShopifyAdapter::LOCATION_KEY]);
        $this->assertSame('erisim-1', $this->secretsOf($connection)['access_token']);
        $this->assertTrue($connection->settings['webhooks']['registered']);

        // Tek kullanımlık: bekleyen kurulum oturumdan silindi.
        $this->assertNull(session(ShopifyInstallController::SESSION_PENDING));
    }

    /** Başka adresle kaydolan normal doğrulamadan geçer; kurulum bekler. */
    #[Test]
    public function registering_with_another_email_still_requires_verification(): void
    {
        Notification::fake();
        $this->fakeShopify();

        $this->launch();
        $this->returnFromShopify();

        $this->post('/register', $this->registration('baska@ornek.example'));

        $user = User::query()->where('email', 'baska@ornek.example')->firstOrFail();
        $this->assertFalse($user->hasVerifiedEmail());
        Notification::assertSentTo($user, VerifyEmailNotification::class);

        $this->get(route('shopify.install.finish'))->assertRedirect(route('verification.notice'));
        $this->assertNotNull(session(ShopifyInstallController::SESSION_PENDING), 'Doğrulamadan sonra devam edebilmeli.');
    }

    /** Giriş yapmış satıcı doğrudan bağlantıya gider. */
    #[Test]
    public function a_signed_in_merchant_is_connected_directly(): void
    {
        $this->fakeShopify();
        [$user] = $this->tenantUser();

        $this->actingAs($user);
        $this->launch();
        $this->returnFromShopify()->assertRedirect(route('shopify.install.finish'));
        $this->get(route('shopify.install.finish'))->assertRedirect(route('channels.index'));

        $this->assertSame('active', $this->connectionOf($user)->status);
    }

    /** Sahte state, imza ya da başka mağaza: anahtar istenmez, kurulum bekletilmez. */
    #[Test]
    public function a_forged_callback_is_refused(): void
    {
        $this->fakeShopify();

        $this->launch();
        $this->returnFromShopify(state: 'sahte')->assertRedirect(route('login'));

        $this->launch();
        $this->returnFromShopify(secret: 'baska-sir')->assertRedirect(route('login'));

        $this->launch();
        $this->returnFromShopify(shop: 'baska.myshopify.com')->assertRedirect(route('login'));

        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/admin/oauth/access_token'));
        $this->assertNull(session(ShopifyInstallController::SESSION_PENDING));
    }

    /** Başka kiracıya bağlı mağaza bu kiracıya bağlanmaz. */
    #[Test]
    public function a_shop_owned_by_another_tenant_is_not_taken_over(): void
    {
        $this->fakeShopify();

        [, $owner] = $this->tenantUser();
        $this->asTenant($owner, fn () => ChannelConnection::factory()->create([
            'channel_type_code' => 'shopify',
            'external_account_id' => self::SHOP,
            'status' => 'active',
        ]));

        [$intruder] = $this->tenantUser();
        $this->actingAs($intruder);
        $this->launch();
        $this->returnFromShopify();

        $this->get(route('shopify.install.finish'))->assertRedirect(route('channels.index'));

        $this->assertNull($this->connectionOf($intruder, orFail: false));
        $this->assertSame(1, TenantContext::runAsSystem(fn () => ChannelConnection::query()
            ->where('external_account_id', self::SHOP)->count()));
    }

    /** Bekleyen kurulum yoksa (süresi doldu, başka tarayıcı) panele yönlenir. */
    #[Test]
    public function finishing_without_a_pending_install_is_harmless(): void
    {
        [$user] = $this->tenantUser();

        $this->actingAs($user)->get(route('shopify.install.finish'))
            ->assertRedirect(route('channels.index'))
            ->assertSessionHas('success');
    }

    /** Bir günden eski bekleyen kurulum kullanılmaz (çalınmış oturum, unutulan sekme). */
    #[Test]
    public function a_pending_install_expires_after_a_day(): void
    {
        $this->fakeShopify();
        [$user] = $this->tenantUser();

        $this->actingAs($user);
        $this->launch();
        $this->returnFromShopify();

        $this->travel(25)->hours();

        $this->get(route('shopify.install.finish'))->assertRedirect(route('channels.index'));
        $this->assertNull($this->connectionOf($user, orFail: false));
    }

    // ═════════════════════════════════════════════════════ yardımcılar

    private function launch(): void
    {
        $this->get($this->signedUrl('/shopify', $this->launchQuery()))->assertRedirect();
    }

    private function returnFromShopify(string $state = '', string $secret = self::SECRET, string $shop = self::SHOP): TestResponse
    {
        $query = [
            'code' => 'kod-1',
            'shop' => $shop,
            'state' => $state !== '' ? $state : (string) session('shopify.install.state'),
            'timestamp' => (string) time(),
            'host' => base64_encode('admin.shopify.com/store/magaza'),
        ];

        return $this->get($this->signedUrl('/shopify/auth/callback', $query, $secret));
    }

    /** @return array<string, string> */
    private function launchQuery(string $shop = self::SHOP, ?int $timestamp = null): array
    {
        return [
            'shop' => $shop,
            'timestamp' => (string) ($timestamp ?? time()),
            // `host` base64 ve `=` içerebilir — imza HAM değer üzerinden.
            'host' => base64_encode('admin.shopify.com/store/magaza'),
        ];
    }

    /**
     * Shopify'ın imzası: parametreler ada göre sıralanır, HAM `k=v` `&` ile
     * birleşir, istemci sırrıyla HMAC-SHA256 (hex).
     *
     * @param  array<string, string>  $query
     */
    private function signedUrl(string $path, array $query, string $secret = self::SECRET): string
    {
        ksort($query);
        $message = implode('&', array_map(fn ($k, $v) => $k.'='.$v, array_keys($query), $query));
        $query['hmac'] = hash_hmac('sha256', $message, $secret);

        return $path.'?'.http_build_query($query);
    }

    /** @return array<string, string> */
    private function registration(string $email): array
    {
        return [
            'name' => 'Nur Yılmaz',
            'email' => $email,
            'company' => 'Atölye Nur',
            'password' => 'Guclu-Parola-2026!',
            'password_confirmation' => 'Guclu-Parola-2026!',
        ];
    }

    private function fakeShopify(): void
    {
        Http::fake([
            '*/admin/oauth/access_token' => Http::response([
                'access_token' => 'erisim-1',
                'scope' => 'write_products',
                'expires_in' => 3600,
                'refresh_token' => 'shprt_yenileme-1',
                'refresh_token_expires_in' => 7_776_000,
            ]),
            '*/admin/api/*/graphql.json' => function (Request $r) {
                $q = (string) $r['query'];

                return Http::response(['data' => match (true) {
                    str_contains($q, 'shop { name email }') => ['shop' => ['name' => 'Atölye Nur', 'email' => 'Sahip@Atolye.example']],
                    str_contains($q, 'ShopHealth') => ['shop' => ['id' => 'gid://shopify/Shop/1']],
                    str_contains($q, 'Locations') => ['locations' => ['nodes' => [
                        ['id' => 'gid://shopify/Location/1', 'name' => 'Ana depo', 'isActive' => true],
                    ]]],
                    str_contains($q, 'WebhookList') => ['webhookSubscriptions' => ['nodes' => []]],
                    str_contains($q, 'WebhookCreate') => ['webhookSubscriptionCreate' => [
                        'webhookSubscription' => ['id' => 'gid://shopify/WebhookSubscription/1'],
                        'userErrors' => [],
                    ]],
                    default => [],
                }]);
            },
        ]);
    }

    private function shopifyType(bool $active): void
    {
        $this->asSystem(fn () => ChannelType::query()->updateOrCreate(['code' => 'shopify'], [
            'name' => 'Shopify',
            'kind' => 'storefront',
            'adapter_class' => ShopifyAdapter::class,
            'supports_webhooks' => true,
            'is_active' => $active,
        ]));
    }

    /** @return array{0: User, 1: Tenant} */
    private function tenantUser(): array
    {
        $user = User::factory()->create();
        $tenant = (new CreateTenant)->run(name: 'Kiracı '.uniqid(), owner: $user);

        return [$user, $tenant];
    }

    private function connectionOf(User $user, bool $orFail = true): ?ChannelConnection
    {
        $tenantId = TenantContext::runAsSystem(fn () => $user->tenants()->firstOrFail()->id);

        $query = TenantContext::runAsSystem(fn () => ChannelConnection::query()
            ->where('tenant_id', $tenantId)
            ->where('external_account_id', self::SHOP));

        return $orFail
            ? TenantContext::runAsSystem(fn () => $query->firstOrFail())
            : TenantContext::runAsSystem(fn () => $query->first());
    }

    /** @return array<string, mixed> */
    private function secretsOf(ChannelConnection $connection): array
    {
        return TenantContext::runAsSystem(fn () => app(CredentialVault::class)->read($connection));
    }
}
