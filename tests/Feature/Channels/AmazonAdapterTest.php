<?php

declare(strict_types=1);

namespace Tests\Feature\Channels;

use App\Domain\Channels\Adapters\Amazon\AmazonAdapter;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Models\ChannelType;
use App\Domain\Channels\Support\ChannelHttpClient;
use App\Domain\Channels\Support\CredentialVault;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use App\Domain\Messaging\Models\InboxMessage;
use App\Domain\Orders\Models\Fulfillment;
use App\Domain\Orders\Models\Order;
use App\Domain\Sync\Models\SyncOperation;
use App\Domain\Sync\Support\InventoryPushBatch;
use App\Domain\Sync\Support\InventoryPushItem;
use App\Support\Logging\PayloadRedactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Amazon SP-API — Listings yamaları, Orders v0 yoklama, kargo onayı, yetki dönüşü.
 * Geliştirici kaydı yok (beta); biçimler resmî belgeden.
 */
final class AmazonAdapterTest extends TestCase
{
    use RefreshDatabase;

    private const EU = 'https://sellingpartnerapi-eu.amazon.com';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.amazon' => ['client_id' => 'amzn1.cid', 'client_secret' => 'sec', 'application_id' => 'amzn1.sp.app', 'draft' => true]]);
    }

    /** Stok yaması: doğru uç, `x-amz-access-token`, Bearer YOK; INVALID kaleme yazılır. */
    #[Test]
    public function inventory_is_patched_with_the_access_token_header(): void
    {
        Http::fake([
            self::EU.'/listings/2021-08-01/items/SELLER1/SKU-A*' => Http::response(['status' => 'ACCEPTED'], 200),
            self::EU.'/listings/2021-08-01/items/SELLER1/SKU-B*' => Http::response(['status' => 'INVALID', 'issues' => [['message' => 'bad']]], 200),
        ]);

        $op1 = new SyncOperation;
        $op1->id = 'op-1';
        $op2 = new SyncOperation;
        $op2->id = 'op-2';

        $result = $this->adapter()->pushInventory(new InventoryPushBatch('c', [
            new InventoryPushItem('l1', 'SKU-A', 'SKU-A', 4, 1),
            new InventoryPushItem('l2', 'SKU-B', 'SKU-B', 1, 1),
        ], [$op1, $op2]));

        $this->assertSame(['op-2'], array_keys($result->failedOperations));
        Http::assertSent(fn (HttpRequest $r): bool => $r->method() === 'PATCH'
            && $r->url() === self::EU.'/listings/2021-08-01/items/SELLER1/SKU-A?marketplaceIds=A1PA6795UKMFR9'
            && $r->hasHeader('x-amz-access-token', 'Atza|tok')
            && ! $r->hasHeader('Authorization')
            && $r['productType'] === 'PRODUCT'
            && $r['patches'][0]['path'] === '/attributes/fulfillment_availability'
            && $r['patches'][0]['value'][0]['quantity'] === 4);
    }

    /** Olay kimliği durumu taşır; iptal `cancelled`; alıcı verisi alınmaz. */
    #[Test]
    public function orders_are_polled_with_status_in_the_event_id(): void
    {
        Http::fake([
            self::EU.'/orders/v0/orders?*' => Http::response(['payload' => ['Orders' => [
                ['AmazonOrderId' => '305-1', 'OrderStatus' => 'Canceled', 'PurchaseDate' => '2026-10-20T10:00:00Z', 'BuyerInfo' => ['BuyerEmail' => 'x@y.z']],
            ]]], 200),
            self::EU.'/orders/v0/orders/305-1/orderItems' => Http::response(['payload' => ['OrderItems' => [
                ['OrderItemId' => 'oi1', 'SellerSKU' => 'SKU-A', 'QuantityOrdered' => 2, 'ItemPrice' => ['Amount' => '39.80', 'CurrencyCode' => 'EUR']],
            ]]], 200),
        ]);

        $adapter = $this->adapter();
        $page = $adapter->fetchOrders(Carbon::parse('2026-10-20 09:00:00'));

        $this->assertSame('305-1:Canceled', $adapter->pollingEventIdFor($page->orders[0]));
        $this->assertArrayNotHasKey('BuyerInfo', $page->orders[0]);
        Http::assertSent(fn (HttpRequest $r): bool => str_contains($r->url(), 'LastUpdatedAfter=2026-10-20T09%3A00%3A00Z'));

        $message = new InboxMessage;
        $message->payload = $page->orders[0];
        $event = $adapter->parseOrderEvent($message);

        $this->assertSame('cancelled', $event->type);
        $this->assertSame('19.90', $event->payload['lines'][0]['unit_price']);
    }

    /** Kargo onayı yalnız gönderilmemiş adetle gider. */
    #[Test]
    public function shipment_confirmation_sends_open_quantities(): void
    {
        Http::fake([
            self::EU.'/orders/v0/orders/305-2/orderItems' => Http::response(['payload' => ['OrderItems' => [
                ['OrderItemId' => 'oi1', 'QuantityOrdered' => 3, 'QuantityShipped' => 1],
                ['OrderItemId' => 'oi2', 'QuantityOrdered' => 1, 'QuantityShipped' => 1],
            ]]], 200),
            self::EU.'/orders/v0/orders/305-2/shipmentConfirmation' => Http::response('', 204),
        ]);

        $order = new Order;
        $order->external_id = '305-2';
        $fulfillment = new Fulfillment;
        $fulfillment->carrier = 'DHL';
        $fulfillment->tracking_number = 'JJD1';
        $fulfillment->shipped_at = Carbon::parse('2026-10-21 08:00:00');
        $fulfillment->setRelation('order', $order);

        $this->adapter()->pushFulfillment($fulfillment);

        Http::assertSent(fn (HttpRequest $r): bool => $r->url() === self::EU.'/orders/v0/orders/305-2/shipmentConfirmation'
            && $r['packageDetail']['orderItems'] === [['orderItemId' => 'oi1', 'quantity' => 2]]
            && $r['marketplaceId'] === 'A1PA6795UKMFR9');
    }

    /** Yenileme LWA'ya gider; yeni anahtar Bearer olmayan adla döner. */
    #[Test]
    public function credentials_are_refreshed_at_lwa(): void
    {
        Http::fake(['https://api.amazon.com/auth/o2/token' => Http::response(['access_token' => 'Atza|new', 'expires_in' => 3600], 200)]);

        $refreshed = $this->adapter()->refreshCredentials();

        $this->assertSame('Atza|new', $refreshed->secrets[AmazonAdapter::ACCESS_TOKEN_SECRET]);
        Http::assertSent(fn (HttpRequest $r): bool => $r['grant_type'] === 'refresh_token' && $r['refresh_token'] === 'Atzr|ref');
    }

    /** Yetki dönüşü satıcı kimliğini benimser; state tutmazsa hiçbir şey yazılmaz. */
    #[Test]
    public function the_callback_adopts_the_selling_partner(): void
    {
        [$tenant, $user] = $this->tenantWithType();
        $connection = $this->asTenant($tenant, fn (): ChannelConnection => ChannelConnection::factory()->create([
            'channel_type_code' => 'amazon',
            'external_account_id' => 'oauth-bekliyor:'.uniqid(),
            'status' => 'pending',
            'settings' => [AmazonAdapter::MARKETPLACE_KEY => 'de'],
        ]));

        Http::fake([
            'https://api.amazon.com/auth/o2/token' => Http::response(['access_token' => 'Atza|a', 'refresh_token' => 'Atzr|r', 'expires_in' => 3600], 200),
            self::EU.'/*' => Http::response(['payload' => []], 200),
        ]);

        $this->actingAs($user)->withSession(['amazon.oauth.state' => 'S', 'amazon.oauth.connection' => $connection->id])
            ->get('/channels/amazon/callback?state=X&selling_partner_id=A1B2&spapi_oauth_code=c')->assertRedirect('/channels');
        Http::assertNothingSent();

        $this->actingAs($user)->withSession(['amazon.oauth.state' => 'S', 'amazon.oauth.connection' => $connection->id])
            ->get('/channels/amazon/callback?state=S&selling_partner_id=A1B2&spapi_oauth_code=c')->assertRedirect('/channels');

        $fresh = $this->asTenant($tenant, fn () => $connection->fresh());
        $this->assertSame('A1B2', $fresh->external_account_id);
        $this->assertSame('A1B2', $fresh->settings[AmazonAdapter::SELLER_ID_KEY]);
        $this->assertSame('Atzr|r', $this->asTenant($tenant, fn () => app(CredentialVault::class)->read($fresh))['refresh_token']);
    }

    private function adapter(): AmazonAdapter
    {
        [$tenant] = $this->tenantWithType();

        return $this->asTenant($tenant, function (): AmazonAdapter {
            $connection = ChannelConnection::factory()->create([
                'channel_type_code' => 'amazon',
                'external_account_id' => 'SELLER1',
                'status' => 'active',
                'settings' => [AmazonAdapter::SELLER_ID_KEY => 'SELLER1', AmazonAdapter::MARKETPLACE_KEY => 'de'],
            ]);

            app(CredentialVault::class)->store($connection, [AmazonAdapter::ACCESS_TOKEN_SECRET => 'Atza|tok', 'refresh_token' => 'Atzr|ref']);

            return new AmazonAdapter($connection, new ChannelHttpClient($connection, app(CredentialVault::class), app(PayloadRedactor::class)));
        });
    }

    /** @return array{0: Tenant, 1: User} */
    private function tenantWithType(): array
    {
        $this->asSystem(fn (): ChannelType => ChannelType::query()->updateOrCreate(
            ['code' => 'amazon'],
            ['name' => 'Amazon', 'kind' => 'marketplace', 'adapter_class' => AmazonAdapter::class, 'supports_webhooks' => false, 'is_active' => true],
        ));

        $user = User::factory()->create();

        return [(new CreateTenant)->run(name: 'Amazon '.uniqid(), owner: $user), $user];
    }
}
