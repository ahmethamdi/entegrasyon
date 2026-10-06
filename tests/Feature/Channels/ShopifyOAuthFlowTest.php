<?php

declare(strict_types=1);

namespace Tests\Feature\Channels;

use App\Domain\Channels\Actions\RegisterChannelWebhooks;
use App\Domain\Channels\Adapters\Shopify\ShopifyAdapter;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Models\ChannelCredential;
use App\Domain\Channels\Models\ChannelType;
use App\Domain\Channels\Registry\AdapterRegistry;
use App\Domain\Channels\Support\CredentialVault;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\User;
use App\Domain\Messaging\Actions\IngestInboxMessage;
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
 * Shopify — 34Pazar uygulaması üzerinden bağlama (OAuth, 5 Eki 2026).
 *
 * Neden: 1 Ocak 2026'dan beri yeni "özel uygulama" açılamıyor → `shpat_`
 * formuyla yeni mağaza bağlanamıyordu. Yeni herkese açık uygulamada erişim
 * anahtarı 1 saat yaşar (`expiring=1`), yenileme anahtarı 90 gün.
 */
final class ShopifyOAuthFlowTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'uygulama-sirri';

    private const SHOP = 'magaza.myshopify.com';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.shopify.client_id', 'istemci-123');
        config()->set('services.shopify.client_secret', self::SECRET);
    }

    // ═════════════════════════════════════════════════════ geri dönüş

    #[Test]
    public function a_single_location_shop_is_connected_end_to_end(): void
    {
        [$user, $connection] = $this->pendingShop();
        $this->fakeShopify(locations: [['id' => 'gid://shopify/Location/1', 'name' => 'Ana depo', 'isActive' => true]]);

        $this->returnFromShopify($user, $connection)->assertRedirect(route('channels.index'));

        $fresh = $this->fresh($connection);
        $this->assertSame('active', $fresh->status);
        $this->assertSame('gid://shopify/Location/1', $fresh->settings[ShopifyAdapter::LOCATION_KEY]);

        $secrets = $this->secretsOf($fresh);
        $this->assertSame('erisim-1', $secrets['access_token']);
        $this->assertSame('shprt_yenileme-1', $secrets['refresh_token']);

        $credential = $this->credentialOf($fresh);
        $this->assertEqualsWithDelta(time() + 3600, $credential->expires_at->getTimestamp(), 30);
        $this->assertEqualsWithDelta(time() + 7_776_000, $credential->refresh_expires_at->getTimestamp(), 30);

        // Süresi dolan anahtar İSTENDİ — verilmezse süresiz anahtar döner ve
        // 1 Ocak 2027'de 401 ile ölür.
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/admin/oauth/access_token')
            && $r['expiring'] === '1' && $r['code'] === 'kod-1');

        // Sipariş + kaldırma webhook'ları kuruldu, alıcı adres bu bağlantı.
        $created = $this->createdWebhookTopics();
        $this->assertContains('ORDERS_CREATE', $created);
        $this->assertContains('APP_UNINSTALLED', $created);
        $this->assertTrue($this->fresh($connection)->settings['webhooks']['registered']);

        // Sırlar şifresiz `settings`'e SIZMAZ.
        $this->assertStringNotContainsString('erisim-1', json_encode($fresh->settings));
    }

    /**
     * ⚠️ ÇOK DEPOLU MAĞAZADA DEPO SESSİZCE SEÇİLMEZ (stok yanlış depoya
     * yazılırdı) — satıcı listeden seçer, seçince bağlantı tamamlanır.
     */
    #[Test]
    public function a_multi_location_shop_waits_for_the_seller_to_choose(): void
    {
        [$user, $connection] = $this->pendingShop();
        $this->fakeShopify(locations: [
            ['id' => 'gid://shopify/Location/1', 'name' => 'İstanbul', 'isActive' => true],
            ['id' => 'gid://shopify/Location/2', 'name' => 'Ankara', 'isActive' => true],
            ['id' => 'gid://shopify/Location/3', 'name' => 'Kapalı', 'isActive' => false],
        ]);

        $this->returnFromShopify($user, $connection);

        $fresh = $this->fresh($connection);
        $this->assertArrayNotHasKey(ShopifyAdapter::LOCATION_KEY, $fresh->settings);
        $this->assertSame('pending', $fresh->status);
        $this->assertCount(2, $fresh->settings['location_choices'], 'Kapalı depo listeye girmemeli.');
        $this->assertSame([], $this->createdWebhookTopics());

        // Panel seçimi gösteriyor.
        $this->actingAs($user)->get('/channels')->assertInertia(fn ($page) => $page
            ->where('connections.0.locationChoices.1.name', 'Ankara'));

        // Liste DIŞI kimlik reddedilir.
        $this->actingAs($user)
            ->post(route('channels.shopify.location', $connection->id), ['location' => 'gid://shopify/Location/999'])
            ->assertStatus(422);

        $this->actingAs($user)
            ->post(route('channels.shopify.location', $connection->id), ['location' => 'gid://shopify/Location/2'])
            ->assertRedirect(route('channels.index'));

        $fresh = $this->fresh($connection);
        $this->assertSame('gid://shopify/Location/2', $fresh->settings[ShopifyAdapter::LOCATION_KEY]);
        $this->assertSame('active', $fresh->status);
        $this->assertContains('ORDERS_CREATE', $this->createdWebhookTopics());
    }

    #[Test]
    public function a_forged_state_is_refused(): void
    {
        [$user, $connection] = $this->pendingShop();
        Http::fake();

        $this->returnFromShopify($user, $connection, state: 'baska-state');

        Http::assertNothingSent();
        $this->assertNull($this->credentialOf($connection));
    }

    #[Test]
    public function a_callback_not_signed_by_shopify_is_refused(): void
    {
        [$user, $connection] = $this->pendingShop();
        Http::fake();

        $this->returnFromShopify($user, $connection, secret: 'sahte-sir');

        Http::assertNothingSent();
        $this->assertNull($this->credentialOf($connection));
    }

    /**
     * ⚠️ BAŞKA MAĞAZANIN ONAYI KABUL EDİLMEZ — imza geçerli olsa bile.
     * Kabul edilseydi satıcı A, B'nin mağazasının anahtarını kendi
     * bağlantısına bağlar ve B'nin stoğunu yönetirdi.
     */
    #[Test]
    public function an_authorization_for_another_shop_is_refused(): void
    {
        [$user, $connection] = $this->pendingShop();
        Http::fake();

        $this->returnFromShopify($user, $connection, shop: 'baska-magaza.myshopify.com');

        Http::assertNothingSent();
        $this->assertNull($this->credentialOf($connection));
    }

    // ═════════════════════════════════════════════════════ anahtar ömrü

    #[Test]
    public function the_refresh_round_renews_the_hourly_token(): void
    {
        [, $connection] = $this->pendingShop();
        $this->storeTokens($connection, expiresIn: 600);

        Http::fake(['*/admin/oauth/access_token' => Http::response([
            'access_token' => 'erisim-2',
            'refresh_token' => 'shprt_yenileme-2',
            'expires_in' => 3600,
            'refresh_token_expires_in' => 7_776_000,
        ])]);

        $this->artisan('credentials:refresh')->assertSuccessful();

        Http::assertSent(fn (Request $r) => $r['grant_type'] === 'refresh_token'
            && $r['refresh_token'] === 'shprt_eski');

        $secrets = $this->secretsOf($connection);
        $this->assertSame('erisim-2', $secrets['access_token']);
        $this->assertSame('shprt_yenileme-2', $secrets['refresh_token']);
        $this->assertGreaterThan(time() + 3000, $this->credentialOf($connection)->expires_at->getTimestamp());
    }

    /**
     * ⚠️ WEBHOOK SIRRI YAZILIRKEN ANAHTARIN SÜRESİ SİLİNMEZ.
     *
     * `RegisterChannelWebhooks` kasaya `webhook_secret` eklerken `store()`'u
     * süresiz çağırıyordu: `expires_at` NULL olur, yenileme turu bağlantıyı
     * bir daha seçmez ve 1 saatlik anahtar SESSİZCE ölürdü.
     */
    #[Test]
    public function registering_webhooks_keeps_the_token_expiry(): void
    {
        [, $connection] = $this->pendingShop();
        $connection->forceFill(['settings' => [ShopifyAdapter::LOCATION_KEY => 'gid://shopify/Location/1']])->save();
        $this->storeTokens($connection, expiresIn: 3600);
        $before = $this->credentialOf($connection);

        $this->fakeShopify(locations: []);
        TenantContext::runAsSystem(fn () => app(RegisterChannelWebhooks::class)->run($connection));

        $after = $this->credentialOf($connection);
        $this->assertNotNull($after->expires_at);
        $this->assertSame($before->expires_at->getTimestamp(), $after->expires_at->getTimestamp());
        $this->assertSame($before->refresh_expires_at->getTimestamp(), $after->refresh_expires_at->getTimestamp());
    }

    /**
     * ⚠️ ROZET 1 SAATLİK ANAHTARA BAKMAZ — yenileme anahtarına bakar.
     * Bakarsa her Shopify bağlantısı sürekli "Yakında dolacak" görünürdü.
     */
    #[Test]
    public function the_badge_follows_the_refresh_token_not_the_hourly_token(): void
    {
        [$user, $connection] = $this->pendingShop();
        $this->storeTokens($connection, expiresIn: 3600);

        $this->actingAs($user)->get('/channels')->assertInertia(fn ($page) => $page
            ->where('connections.0.tokenStatus', 'valid'));
    }

    // ═════════════════════════════════════════════════════ webhook'lar

    #[Test]
    public function order_webhooks_are_verified_with_the_app_secret(): void
    {
        [, $connection] = $this->pendingShop();
        $adapter = TenantContext::runAsSystem(fn () => app(AdapterRegistry::class)->for($connection));
        $body = '{"id":1}';

        $this->assertTrue($adapter->verifyWebhookSignature($body, [
            'x-shopify-hmac-sha256' => [base64_encode(hash_hmac('sha256', $body, self::SECRET, true))],
        ]));
        $this->assertFalse($adapter->verifyWebhookSignature($body, [
            'x-shopify-hmac-sha256' => [base64_encode(hash_hmac('sha256', $body, 'sahte', true))],
        ]));
    }

    #[Test]
    public function compliance_webhooks_reject_a_bad_signature(): void
    {
        $this->compliance('customers/data_request', ['shop_domain' => self::SHOP], secret: 'sahte')
            ->assertStatus(401);

        $this->compliance('customers/data_request', ['shop_domain' => self::SHOP])
            ->assertStatus(200);
    }

    #[Test]
    public function customers_redact_clears_only_the_listed_orders(): void
    {
        [, $connection] = $this->pendingShop();
        $this->order($connection, '1001');
        $this->order($connection, '1002');

        $this->compliance('customers/redact', [
            'shop_domain' => self::SHOP,
            'orders_to_redact' => [1001],
        ])->assertStatus(200);

        $refs = DB::table('orders')->pluck('customer_ref', 'external_id');
        $this->assertNull($refs['1001']);
        $this->assertNotNull($refs['1002']);

        $payloads = DB::table('inbox_messages')->get()->keyBy(fn ($m) => SealedJson::open($m->payload)['id'] ?? 'x');
        $this->assertArrayHasKey('1002', $payloads->all());
        $this->assertArrayNotHasKey('1001', $payloads->all());
    }

    #[Test]
    public function shop_redact_clears_all_orders_and_closes_access(): void
    {
        [, $connection] = $this->pendingShop();
        $this->storeTokens($connection, expiresIn: 3600);
        $this->order($connection, '1001');

        $this->compliance('shop/redact', ['shop_domain' => self::SHOP])->assertStatus(200);

        $this->assertNull(DB::table('orders')->value('customer_ref'));
        $this->assertSame('inactive', $this->fresh($connection)->status);
        $this->assertNull($this->credentialOf($connection));
    }

    // ─────────────────────────────────────────────────────── yardımcılar

    /** @return array{0: User, 1: ChannelConnection} */
    private function pendingShop(): array
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

        $connection = $this->asTenant($tenant, fn (): ChannelConnection => ChannelConnection::factory()->create([
            'channel_type_code' => 'shopify',
            'external_account_id' => self::SHOP,
            'status' => 'pending',
            'settings' => [],
        ]));

        return [$user, $connection];
    }

    private function returnFromShopify(
        User $user,
        ChannelConnection $connection,
        string $state = 'state-1',
        string $secret = self::SECRET,
        string $shop = self::SHOP,
    ): TestResponse {
        $query = ['code' => 'kod-1', 'shop' => $shop, 'state' => $state, 'timestamp' => (string) time()];
        ksort($query);
        $query['hmac'] = hash_hmac('sha256', http_build_query($query, '', '&', PHP_QUERY_RFC3986), $secret);

        return $this->actingAs($user)
            ->withSession(['shopify.oauth.state' => 'state-1', 'shopify.oauth.connection' => $connection->id])
            ->get(route('channels.shopify.callback', $query));
    }

    /** @param list<array{id: string, name: string, isActive: bool}> $locations */
    private function fakeShopify(array $locations): void
    {
        Http::fake([
            '*/admin/oauth/access_token' => Http::response([
                'access_token' => 'erisim-1',
                'scope' => 'write_products',
                'expires_in' => 3600,
                'refresh_token' => 'shprt_yenileme-1',
                'refresh_token_expires_in' => 7_776_000,
            ]),
            '*/admin/api/*/graphql.json' => function (Request $r) use ($locations) {
                $q = (string) $r['query'];

                return Http::response(['data' => match (true) {
                    str_contains($q, 'ShopHealth') => ['shop' => ['id' => 'gid://shopify/Shop/1']],
                    str_contains($q, 'Locations') => ['locations' => ['nodes' => $locations]],
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

    private function storeTokens(ChannelConnection $connection, int $expiresIn): void
    {
        TenantContext::runAsSystem(fn () => app(CredentialVault::class)->store(
            $connection,
            ['access_token' => 'erisim-eski', 'refresh_token' => 'shprt_eski'],
            'write_products',
            new \DateTimeImmutable('@'.(time() + $expiresIn)),
            new \DateTimeImmutable('@'.(time() + 7_776_000)),
        ));
    }

    private function compliance(string $topic, array $body, string $secret = self::SECRET): TestResponse
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
            'customer_ref' => SealedJson::seal(['name' => 'Ayşe Yılmaz', 'email' => 'ayse@example.com']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Gerçek giriş yolu: `resource_id`'yi (redact'in aradığı kolon) o doldurur.
        app(IngestInboxMessage::class)->run(
            $connection,
            'webhook',
            'evt-'.$externalId,
            'orders/create',
            (string) json_encode(['id' => (int) $externalId, 'email' => 'ayse@example.com']),
        );
    }

    private function fresh(ChannelConnection $connection): ChannelConnection
    {
        return TenantContext::runAsSystem(fn () => ChannelConnection::query()->findOrFail($connection->id));
    }

    private function credentialOf(ChannelConnection $connection): ?ChannelCredential
    {
        return TenantContext::runAsSystem(fn () => $this->fresh($connection)->activeCredential()->first());
    }

    /** @return array<string, mixed> */
    private function secretsOf(ChannelConnection $connection): array
    {
        return TenantContext::runAsSystem(fn () => app(CredentialVault::class)->read($this->fresh($connection)));
    }
}
