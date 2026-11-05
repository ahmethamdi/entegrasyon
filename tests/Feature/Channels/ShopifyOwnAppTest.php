<?php

declare(strict_types=1);

namespace Tests\Feature\Channels;

use App\Domain\Billing\Support\ShopifyBilling;
use App\Domain\Channels\Adapters\Shopify\ShopifyAdapter;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Models\ChannelCredential;
use App\Domain\Channels\Models\ChannelType;
use App\Domain\Channels\Registry\AdapterRegistry;
use App\Domain\Channels\Support\CredentialVault;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use App\Support\Privacy\SealedJson;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Shopify — SATICININ KENDİ Dev Dashboard uygulaması (ADR-012, 5 Kas 2026).
 *
 * App Store reddi (Ref 140131, pazaryeri politikası) sonrası satıcı kendi
 * mağazasının organizasyonunda uygulama açar ve Client ID/Secret'ı formda
 * verir. Anahtar `client_credentials` ile alınır: 24 saat, yenileme anahtarı
 * YOK. Webhook'lar O uygulamanın sırrıyla imzalanır.
 *
 * 34Pazar uygulamasıyla (OAuth) kurulmuş bağlantılar AYNEN çalışmalı —
 * onların testleri `ShopifyOAuthFlowTest` / `ShopifyInstallFlowTest`'te.
 */
final class ShopifyOwnAppTest extends TestCase
{
    use RefreshDatabase;

    private const SHOP = 'magaza.myshopify.com';

    private const OWN_ID = 'satici-istemci-1';

    private const OWN_SECRET = 'satici-sirri-1';

    private const GLOBAL_SECRET = 'uygulama-sirri';

    protected function setUp(): void
    {
        parent::setUp();

        // 34Pazar uygulaması da tanımlı — satıcı uygulaması onunla KARIŞMAMALI.
        config()->set('services.shopify.client_id', 'istemci-123');
        config()->set('services.shopify.client_secret', self::GLOBAL_SECRET);
    }

    // ═════════════════════════════════════════════════════ bağlama

    #[Test]
    public function the_merchant_app_pair_connects_the_store_end_to_end(): void
    {
        [$user] = $this->tenant();
        $this->fakeShopify();

        $this->connect($user)->assertRedirect(route('channels.index'));

        $connection = $this->connectionOrFail();
        $this->assertSame('active', $connection->status);
        $this->assertSame('gid://shopify/Location/1', $connection->settings[ShopifyAdapter::LOCATION_KEY]);

        // Anahtar ÇİFTLE istendi: OAuth kodu yok, kapsam gönderilmedi,
        // 34Pazar uygulamasının kimliği KULLANILMADI.
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), self::SHOP.'/admin/oauth/access_token')
            && $r['grant_type'] === 'client_credentials'
            && $r['client_id'] === self::OWN_ID
            && $r['client_secret'] === self::OWN_SECRET
            && ! isset($r['scope']) && ! isset($r['code']));
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'access_token') && ($r['client_id'] ?? null) === 'istemci-123');
        $this->assertNull(session('shopify.oauth.state'));

        // Çift KORUNDU (sonraki yenileme onunla yapılır), anahtar 24 saat.
        $secrets = $this->secretsOf($connection);
        $this->assertSame('erisim-cc-1', $secrets['access_token']);
        $this->assertSame(self::OWN_ID, $secrets['client_id']);
        $this->assertSame(self::OWN_SECRET, $secrets['client_secret']);
        $this->assertArrayNotHasKey('refresh_token', $secrets);
        $this->assertEqualsWithDelta(time() + 86399, $this->credentialOf($connection)->expires_at->getTimestamp(), 30);

        // İlk bağlantı: stok + fiyat açık, ürün yayını kapalı (ADR-010 istisnası).
        $this->assertTrue($connection->stock_sync_enabled);
        $this->assertTrue($connection->price_sync_enabled);

        // Webhook'lar kuruldu; Shopify fatura konusu satıcı uygulamasında YOK.
        $topics = $this->createdWebhookTopics();
        $this->assertContains('ORDERS_CREATE', $topics);
        $this->assertContains('APP_UNINSTALLED', $topics);
        $this->assertNotContains('APP_SUBSCRIPTIONS_UPDATE', $topics);
    }

    /**
     * Shopify anahtar vermezse bağlantı `pending` kalır ve sağlık/webhook
     * adımları KOŞMAZ — koşsaydı kimliksiz gider ve sebebi ezerdi.
     */
    #[Test]
    public function a_rejected_pair_leaves_the_connection_pending_without_further_calls(): void
    {
        [$user] = $this->tenant();
        Http::fake([
            '*/admin/oauth/access_token' => Http::response(['error' => 'invalid_client'], 401),
            '*' => Http::response(['data' => []]),
        ]);

        $this->connect($user)
            ->assertRedirect(route('channels.index'))
            ->assertSessionHas('warning');

        $connection = $this->connectionOrFail();
        $this->assertSame('pending', $connection->status);
        $this->assertSame('unhealthy', $connection->health_status);
        $this->assertFalse((bool) $connection->stock_sync_enabled);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'graphql.json'));
    }

    /** Özel alan adı (magazam.com) anahtar isteğinde kullanılamaz; bağlantı açılmaz. */
    #[Test]
    public function a_custom_domain_is_rejected_before_anything_is_stored(): void
    {
        [$user] = $this->tenant();
        Http::fake();

        $this->connect($user, storeUrl: 'magazam.com')->assertSessionHasErrors('store_url');

        $this->assertNull($this->connection());
        Http::assertNothingSent();
    }

    // ═════════════════════════════════════════════════════ yenileme

    #[Test]
    public function refreshing_asks_again_with_the_pair_and_keeps_it(): void
    {
        [, $tenant] = $this->tenant();
        $connection = $this->ownAppConnection($tenant);
        Http::fake(['*/admin/oauth/access_token' => Http::response([
            'access_token' => 'erisim-cc-2', 'scope' => 'write_products', 'expires_in' => 86399,
        ])]);

        $fresh = $this->adapter($connection)->refreshCredentials();

        $this->assertSame('erisim-cc-2', $fresh->secrets['access_token']);
        $this->assertSame(self::OWN_ID, $fresh->secrets['client_id']);
        $this->assertSame(self::OWN_SECRET, $fresh->secrets['client_secret']);
        $this->assertArrayNotHasKey('refresh_token', $fresh->secrets);
        $this->assertNull($fresh->refreshExpiresAt);
        Http::assertSent(fn (Request $r) => $r['grant_type'] === 'client_credentials' && ! isset($r['refresh_token']));
    }

    // ═════════════════════════════════════════════════════ webhook imzası

    #[Test]
    public function an_own_app_connection_accepts_only_its_own_signature(): void
    {
        [, $tenant] = $this->tenant();
        $adapter = $this->adapter($this->ownAppConnection($tenant));
        $raw = '{"id":1}';

        $this->assertTrue($adapter->verifyWebhookSignature($raw, $this->signed($raw, self::OWN_SECRET)));

        // 34Pazar uygulamasının sırrı bu bağlantıda meşru hiçbir teslimi
        // imzalamaz — kabul edilseydi o sırla sahte sipariş yazılabilirdi.
        $this->assertFalse($adapter->verifyWebhookSignature($raw, $this->signed($raw, self::GLOBAL_SECRET)));
        $this->assertFalse($adapter->verifyWebhookSignature($raw, $this->signed($raw, 'baska-sir')));
    }

    /** 34Pazar uygulamasıyla kurulmuş bağlantı eskisi gibi global sırla doğrular. */
    #[Test]
    public function an_oauth_connection_still_verifies_with_the_app_secret(): void
    {
        [, $tenant] = $this->tenant();
        $connection = $this->connectionWith($tenant, ['access_token' => 'erisim', 'refresh_token' => 'shprt_x']);
        $adapter = $this->adapter($connection);
        $raw = '{"id":1}';

        $this->assertTrue($adapter->verifyWebhookSignature($raw, $this->signed($raw, self::GLOBAL_SECRET)));
        $this->assertFalse($adapter->verifyWebhookSignature($raw, $this->signed($raw, self::OWN_SECRET)));
        $this->assertContains('APP_SUBSCRIPTIONS_UPDATE', $adapter->webhookTopics());
    }

    // ═════════════════════════════════════════════════════ gizlilik webhook'u

    #[Test]
    public function shop_redact_signed_by_the_merchant_app_is_accepted_and_redacts(): void
    {
        [, $tenant] = $this->tenant();
        $connection = $this->ownAppConnection($tenant);
        $this->order($connection, '1001');

        $this->compliance('shop/redact', ['shop_domain' => self::SHOP], self::OWN_SECRET)->assertNoContent(200);

        $this->assertNull(DB::table('orders')->where('external_id', '1001')->value('customer_ref'));
    }

    /**
     * Mağaza adı imzasız gövdeden gelir ama sır seçmekten öte bir şey
     * yapmaz: başka mağazanın sırrıyla imzalanmış istek GEÇMEZ.
     */
    #[Test]
    public function compliance_rejects_a_signature_from_another_shops_app(): void
    {
        [, $tenant] = $this->tenant();
        $connection = $this->ownAppConnection($tenant);
        $this->order($connection, '1001');

        $this->compliance('shop/redact', ['shop_domain' => self::SHOP], 'baska-magazanin-sirri')->assertStatus(401);

        $this->assertNotNull(DB::table('orders')->where('external_id', '1001')->value('customer_ref'));
    }

    // ═════════════════════════════════════════════════════ faturalama

    /** Custom dağıtım Shopify faturasını kullanamaz → satıcı Stripe'a düşer. */
    #[Test]
    public function an_own_app_store_is_not_billed_through_shopify(): void
    {
        [, $tenant] = $this->tenant();
        $this->ownAppConnection($tenant, status: 'active');

        $this->assertNull($this->asTenant($tenant, fn () => app(ShopifyBilling::class)->connectionForCurrentTenant()));
    }

    #[Test]
    public function an_oauth_store_is_still_billed_through_shopify(): void
    {
        [, $tenant] = $this->tenant();
        $connection = $this->connectionWith($tenant, ['access_token' => 'erisim', 'refresh_token' => 'shprt_x'], status: 'active');

        $found = $this->asTenant($tenant, fn () => app(ShopifyBilling::class)->connectionForCurrentTenant());

        $this->assertSame($connection->id, $found?->id);
    }

    // ═════════════════════════════════════════════════════ yeniden izin

    /**
     * Satıcı uygulamalı bağlantının "yeniden izin ver" düğmesi 34Pazar
     * uygulamasının onay ekranına GİTMEZ — forma döner.
     */
    #[Test]
    public function reauthorizing_an_own_app_connection_returns_to_the_form(): void
    {
        [$user, $tenant] = $this->tenant();
        $connection = $this->ownAppConnection($tenant);

        $this->actingAs($user)
            ->post(route('channels.shopify.authorize', ['connection' => $connection->id]))
            ->assertRedirect(route('channels.create'))
            ->assertSessionHas('warning');

        $this->assertNull(session('shopify.oauth.state'));
    }

    // ═════════════════════════════════════════════════════ yardımcılar

    /** @return array{0: User, 1: Tenant} */
    private function tenant(): array
    {
        $this->asSystem(fn () => ChannelType::query()->updateOrCreate(['code' => 'shopify'], [
            'name' => 'Shopify',
            'kind' => 'storefront',
            'adapter_class' => ShopifyAdapter::class,
            'supports_webhooks' => true,
            'is_active' => true,
        ]));

        $user = User::factory()->create();
        $tenant = (new CreateTenant)->run(name: 'Shopify '.uniqid(), owner: $user);

        return [$user, $tenant];
    }

    private function connect(User $user, string $storeUrl = self::SHOP): TestResponse
    {
        return $this->actingAs($user)->post('/channels', [
            'channel_type_code' => 'shopify',
            'label' => 'Shopify Mağazam',
            'store_url' => $storeUrl,
            'client_id' => self::OWN_ID,
            'client_secret' => self::OWN_SECRET,
        ]);
    }

    private function ownAppConnection(Tenant $tenant, string $status = 'pending'): ChannelConnection
    {
        return $this->connectionWith($tenant, [
            'client_id' => self::OWN_ID,
            'client_secret' => self::OWN_SECRET,
            'access_token' => 'erisim-cc-1',
        ], $status);
    }

    /** @param array<string, string> $secrets */
    private function connectionWith(Tenant $tenant, array $secrets, string $status = 'pending'): ChannelConnection
    {
        $connection = $this->asTenant($tenant, fn (): ChannelConnection => ChannelConnection::factory()->create([
            'channel_type_code' => 'shopify',
            'external_account_id' => self::SHOP,
            'status' => $status,
            'settings' => [ShopifyAdapter::LOCATION_KEY => 'gid://shopify/Location/1'],
        ]));

        TenantContext::runAsSystem(fn () => app(CredentialVault::class)->store(
            $connection,
            $secrets,
            'write_products',
            new \DateTimeImmutable('@'.(time() + 86399)),
        ));

        return $connection;
    }

    private function fakeShopify(): void
    {
        Http::fake([
            '*/admin/oauth/access_token' => Http::response([
                'access_token' => 'erisim-cc-1',
                'scope' => 'write_products,write_inventory',
                'expires_in' => 86399,
            ]),
            '*/admin/api/*/graphql.json' => function (Request $r) {
                $q = (string) $r['query'];

                return Http::response(['data' => match (true) {
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

    /** @return list<string> */
    private function createdWebhookTopics(): array
    {
        return collect(Http::recorded())
            ->map(fn (array $pair) => $pair[0])
            ->filter(fn (Request $r) => str_contains((string) ($r['query'] ?? ''), 'WebhookCreate'))
            ->map(fn (Request $r) => $r['variables']['topic'])
            ->values()
            ->all();
    }

    /** @return array<string, list<string>> */
    private function signed(string $raw, string $secret): array
    {
        return ['x-shopify-hmac-sha256' => [base64_encode(hash_hmac('sha256', $raw, $secret, true))]];
    }

    private function compliance(string $topic, array $body, string $secret): TestResponse
    {
        $raw = json_encode($body, JSON_THROW_ON_ERROR);

        return $this->call('POST', '/webhooks/shopify/compliance', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_SHOPIFY_TOPIC' => $topic,
            'HTTP_X_SHOPIFY_HMAC_SHA256' => base64_encode(hash_hmac('sha256', $raw, $secret, true)),
        ], $raw);
    }

    private function order(ChannelConnection $connection, string $externalId): void
    {
        DB::table('orders')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $connection->tenant_id,
            'channel_connection_id' => $connection->id,
            'external_id' => $externalId,
            'customer_ref' => SealedJson::seal(['name' => 'Ayşe Yılmaz']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function adapter(ChannelConnection $connection): ShopifyAdapter
    {
        $adapter = TenantContext::runAsSystem(fn () => app(AdapterRegistry::class)->for(
            ChannelConnection::query()->findOrFail($connection->id)
        ));
        $this->assertInstanceOf(ShopifyAdapter::class, $adapter);

        return $adapter;
    }

    private function connection(): ?ChannelConnection
    {
        return TenantContext::runAsSystem(fn () => ChannelConnection::query()->where('channel_type_code', 'shopify')->first());
    }

    private function connectionOrFail(): ChannelConnection
    {
        $connection = $this->connection();
        $this->assertNotNull($connection);

        return $connection;
    }

    private function credentialOf(ChannelConnection $connection): ChannelCredential
    {
        return TenantContext::runAsSystem(fn () => ChannelConnection::query()->findOrFail($connection->id)->activeCredential()->firstOrFail());
    }

    /** @return array<string, mixed> */
    private function secretsOf(ChannelConnection $connection): array
    {
        return TenantContext::runAsSystem(fn () => app(CredentialVault::class)->read(ChannelConnection::query()->findOrFail($connection->id)));
    }
}
