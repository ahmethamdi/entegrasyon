<?php

declare(strict_types=1);

namespace Tests\Feature\Channels;

use App\Domain\Channels\Adapters\Otto\OttoAdapter;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Models\ChannelType;
use App\Domain\Channels\Support\ChannelHttpClient;
use App\Domain\Channels\Support\CredentialVault;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use App\Domain\Messaging\Models\InboxMessage;
use App\Domain\Sync\Models\SyncOperation;
use App\Domain\Sync\Support\InventoryPushBatch;
use App\Domain\Sync\Support\InventoryPushItem;
use App\Domain\Sync\Support\PricePushBatch;
use App\Support\Logging\PayloadRedactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * OTTO Market — kurulum anahtarı, stok/fiyat, sipariş yoklaması, kurulum bağlama.
 * Gerçek hesapla SINANMADI (beta); biçimler resmî OpenAPI'den.
 */
final class OttoAdapterTest extends TestCase
{
    use RefreshDatabase;

    private const HOST = 'https://api.otto.market';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.otto' => ['client_id' => 'cid', 'client_secret' => 'csecret', 'app_id' => 'app-1', 'sandbox' => false]]);
    }

    /** Kurulum anahtarı iki adımda üretilir ve önbellekten yeniden kullanılır. */
    #[Test]
    public function the_installation_token_is_minted_once_and_cached(): void
    {
        $this->fakeTokens([self::HOST.'/v1/availability/quantities*' => Http::response('', 200)]);

        $adapter = $this->adapter();
        $adapter->healthCheck();
        $adapter->healthCheck();

        Http::assertSent(fn (HttpRequest $r): bool => $r->url() === self::HOST.'/oauth2/token'
            && $r['grant_type'] === 'client_credentials' && $r['scope'] === 'developer');
        Http::assertSent(fn (HttpRequest $r): bool => $r->url() === self::HOST.'/v1/apps/app-1/installations/inst-9/accessToken'
            && $r->hasHeader('Authorization', 'Bearer dev-token') && $r['scope'] === 'products orders availability');
        Http::assertSent(fn (HttpRequest $r): bool => str_starts_with($r->url(), self::HOST.'/v1/availability/quantities')
            && $r->hasHeader('Authorization', 'Bearer inst-token'));
        $this->assertCount(1, array_filter(Http::recorded()->all(), fn (array $p): bool => $p[0]->url() === self::HOST.'/oauth2/token'));
    }

    /** Stok `[{sku, quantity}]`; 207'deki hatalı SKU kaleme yazılır. */
    #[Test]
    public function quantities_are_posted_and_multi_status_is_partial(): void
    {
        $this->fakeTokens([self::HOST.'/v1/availability/quantities' => Http::response([
            'results' => [['sku' => 'A', 'quantity' => 5]],
            'errors' => [['code' => '207', 'detail' => 'Error: SKU is unknown for SKU: B', 'logref' => 'B']],
        ], 207)]);

        $op1 = new SyncOperation;
        $op1->id = 'op-1';
        $op2 = new SyncOperation;
        $op2->id = 'op-2';

        $result = $this->adapter()->pushInventory(new InventoryPushBatch('c', [
            new InventoryPushItem('l1', 'A', 'A', 5, 1),
            new InventoryPushItem('l2', 'B', 'B', 0, 1),
        ], [$op1, $op2]));

        Http::assertSent(fn (HttpRequest $r): bool => $r->url() === self::HOST.'/v1/availability/quantities'
            && $r->data() === [['sku' => 'A', 'quantity' => 5], ['sku' => 'B', 'quantity' => 0]]);
        $this->assertTrue($result->successful);
        $this->assertSame(['op-2'], array_keys($result->failedOperations));
    }

    /** Fiyat standardPrice EUR olarak gider. */
    #[Test]
    public function prices_are_posted_as_standard_price(): void
    {
        $this->fakeTokens([self::HOST.'/v5/products/prices' => Http::response(['state' => 'pending', 'links' => [['rel' => 'self', 'href' => '/v5/products/update-tasks/x']]], 202)]);

        $result = $this->adapter()->pushPrices(new PricePushBatch('c', [['listing_id' => 'l', 'external_id' => 'A', 'price' => '19.9', 'version' => 1]]));

        $this->assertSame('/v5/products/update-tasks/x', $result->data['task']);
        Http::assertSent(fn (HttpRequest $r): bool => $r->url() === self::HOST.'/v5/products/prices'
            && $r->data() === [['sku' => 'A', 'standardPrice' => ['amount' => 19.9, 'currency' => 'EUR']]]);
    }

    /** Sipariş başına tek "created", iptal kalem başına; imleç `next` bağlantısından. */
    #[Test]
    public function orders_are_grouped_with_item_level_cancellations_and_cursor(): void
    {
        $this->fakeTokens([self::HOST.'/v4/orders*' => Http::response([
            'resources' => [[
                'salesOrderId' => 'so-1', 'orderNumber' => 'ABC123', 'orderDate' => '2026-10-20T10:00:00Z',
                'deliveryAddress' => ['email' => 'gizli@example.com'],
                'positionItems' => [
                    ['positionItemId' => 'p1', 'fulfillmentStatus' => 'PROCESSABLE', 'itemValueGrossPrice' => ['amount' => 19.9, 'currency' => 'EUR'], 'product' => ['sku' => 'A', 'productTitle' => 'Tişört']],
                    ['positionItemId' => 'p2', 'fulfillmentStatus' => 'PROCESSABLE', 'itemValueGrossPrice' => ['amount' => 19.9, 'currency' => 'EUR'], 'product' => ['sku' => 'A', 'productTitle' => 'Tişört']],
                    ['positionItemId' => 'p3', 'fulfillmentStatus' => 'CANCELLED_BY_MARKETPLACE', 'itemValueGrossPrice' => ['amount' => 5, 'currency' => 'EUR'], 'product' => ['sku' => 'B']],
                ],
            ]],
            'links' => [['rel' => 'next', 'href' => '/v4/orders?nextcursor=CUR1']],
        ], 200)]);

        $adapter = $this->adapter();
        $page = $adapter->fetchOrders(Carbon::parse('2026-10-20 09:00:00'));

        $this->assertSame(['so-1:created', 'so-1:cancel:p3'], array_map(fn (array $o): ?string => $adapter->pollingEventIdFor($o), $page->orders));
        $this->assertSame('CUR1', $page->nextCursor);
        $this->assertArrayNotHasKey('deliveryAddress', $page->orders[0]);
        Http::assertSent(fn (HttpRequest $r): bool => str_contains($r->url(), 'fromDate=2026-10-20T09%3A00%3A00Z'));

        $message = new InboxMessage;
        $message->payload = $page->orders[0];
        $message->external_event_id = 'so-1:created';
        $event = $adapter->parseOrderEvent($message);

        $this->assertSame('ABC123', $event->payload['external_number']);
        $this->assertSame('PROCESSABLE', $event->payload['status']);
        $this->assertSame(2, $event->payload['lines'][0]['quantity']);
        $this->assertSame('39.80', $event->payload['lines'][0]['line_total']);
    }

    /** Kurulum kimliği yoksa istek atılmaz. */
    #[Test]
    public function nothing_is_sent_without_an_installation(): void
    {
        Http::fake();

        $result = $this->adapter(installation: null)->healthCheck();

        $this->assertFalse($result->healthy);
        Http::assertNothingSent();
    }

    /** OAuth dönüşü kurulum kimliğini bağlantıya yazar; `state` tutmazsa hiçbir şey olmaz. */
    #[Test]
    public function the_callback_adopts_the_installation_id(): void
    {
        [$tenant, $user, $connection] = $this->pendingConnection();

        $this->fakeTokens([
            self::HOST.'/v1/apps/app-1/installation' => Http::response(['installationId' => 'inst-42'], 200),
            self::HOST.'/v1/availability/quantities*' => Http::response('', 200),
        ], userToken: true);

        $this->actingAs($user)
            ->withSession(['otto.oauth.state' => 'S1', 'otto.oauth.connection' => $connection->id])
            ->get('/channels/otto/callback?state=WRONG&code=c')
            ->assertRedirect('/channels');
        $this->assertStringStartsWith('oauth-bekliyor:', (string) $this->asTenant($tenant, fn () => $connection->fresh()->external_account_id));

        $this->actingAs($user)
            ->withSession(['otto.oauth.state' => 'S1', 'otto.oauth.connection' => $connection->id])
            ->get('/channels/otto/callback?state=S1&code=c')
            ->assertRedirect('/channels');

        $fresh = $this->asTenant($tenant, fn () => $connection->fresh());
        $this->assertSame('inst-42', $fresh->external_account_id);
        $this->assertSame('inst-42', $fresh->settings[OttoAdapter::INSTALLATION_ID_KEY]);
        Http::assertSent(fn (HttpRequest $r): bool => $r->url() === self::HOST.'/oauth2/token' && $r['grant_type'] === 'authorization_code');
    }

    /** @param array<string, mixed> $extra */
    private function fakeTokens(array $extra, bool $userToken = false): void
    {
        Http::fake([
            self::HOST.'/oauth2/token' => function (HttpRequest $r) use ($userToken) {
                return Http::response(['access_token' => $userToken && $r['grant_type'] === 'authorization_code' ? 'user-token' : 'dev-token', 'expires_in' => 300], 200);
            },
            self::HOST.'/v1/apps/app-1/installations/*/accessToken' => Http::response(['access_token' => 'inst-token', 'expires_in' => 1800], 200),
            ...$extra,
        ]);
    }

    private function adapter(?string $installation = 'inst-9'): OttoAdapter
    {
        [$tenant] = $this->tenantWithType();

        return $this->asTenant($tenant, function () use ($installation): OttoAdapter {
            $connection = ChannelConnection::factory()->create([
                'channel_type_code' => 'otto',
                'external_account_id' => 'otto-'.uniqid(),
                'status' => 'active',
                'settings' => $installation === null ? [] : [OttoAdapter::INSTALLATION_ID_KEY => $installation],
            ]);

            return new OttoAdapter($connection, new ChannelHttpClient($connection, app(CredentialVault::class), app(PayloadRedactor::class)));
        });
    }

    /** @return array{0: Tenant, 1: User, 2: ChannelConnection} */
    private function pendingConnection(): array
    {
        [$tenant, $user] = $this->tenantWithType();

        $connection = $this->asTenant($tenant, fn (): ChannelConnection => ChannelConnection::factory()->create([
            'channel_type_code' => 'otto',
            'external_account_id' => 'oauth-bekliyor:'.uniqid(),
            'status' => 'pending',
            'settings' => [],
        ]));

        return [$tenant, $user, $connection];
    }

    /** @return array{0: Tenant, 1: User} */
    private function tenantWithType(): array
    {
        $this->asSystem(fn (): ChannelType => ChannelType::query()->updateOrCreate(
            ['code' => 'otto'],
            ['name' => 'OTTO Market', 'kind' => 'marketplace', 'adapter_class' => OttoAdapter::class, 'supports_webhooks' => false, 'is_active' => true],
        ));

        $user = User::factory()->create();
        $tenant = (new CreateTenant)->run(name: 'OTTO '.uniqid(), owner: $user);

        return [$tenant, $user];
    }
}
