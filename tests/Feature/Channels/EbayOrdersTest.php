<?php

declare(strict_types=1);

namespace Tests\Feature\Channels;

use App\Domain\Catalog\Models\Variant;
use App\Domain\Channels\Adapters\Ebay\EbayAdapter;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Models\ChannelType;
use App\Domain\Channels\Support\ChannelHttpClient;
use App\Domain\Channels\Support\CredentialVault;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Actions\ApplyMovement;
use App\Domain\Inventory\Enums\MovementType;
use App\Domain\Inventory\Models\InventoryLevel;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Messaging\Jobs\ProcessInboxMessage;
use App\Domain\Messaging\Models\InboxMessage;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Support\PollChannelOrders;
use App\Domain\Sync\Support\NormalizedOrderEvent;
use App\Support\Logging\PayloadRedactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\AssertsLedgerIntegrity;
use Tests\TestCase;

/**
 * eBay sipariş yoklaması — slice 4.7 (`getOrders`).
 *
 * Etsy kalıbı: webhook yok, pencere `lastmodifieddate`, olay kimliği
 * durumu taşır (Karar 24) — aynı siparişin iptali YUTULMAZ.
 */
final class EbayOrdersTest extends TestCase
{
    use AssertsLedgerIntegrity;
    use RefreshDatabase;

    private const ORDERS_URL = 'https://api.ebay.com/sell/fulfillment/v1/order*';

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    /** ⚠️ AYNI SİPARİŞİN İPTALİ YUTULMAZ — kimlik durumu taşır. */
    #[Test]
    public function a_cancellation_of_the_same_order_is_not_swallowed(): void
    {
        [$tenant] = $this->connected();

        Http::fake([self::ORDERS_URL => Http::sequence()
            ->push(['orders' => [$this->order('12-1', 'PAID')], 'total' => 1], 200)
            ->push(['orders' => [$this->order('12-1', 'PAID', cancelled: true)], 'total' => 1], 200)]);

        app(PollChannelOrders::class)->run();
        app(PollChannelOrders::class)->run();

        $ids = $this->asTenant($tenant, fn (): array => InboxMessage::query()
            ->orderBy('external_event_id')->pluck('external_event_id')->all());

        $this->assertSame(['12-1:cancelled', '12-1:paid:not_started'], $ids);
    }

    /** Pencere `lastmodifieddate` filtresiyle ve resmî zaman biçimiyle gider. */
    #[Test]
    public function the_poll_asks_get_orders_with_a_last_modified_window(): void
    {
        $this->connected();

        Http::fake([self::ORDERS_URL => Http::response(['orders' => [], 'total' => 0], 200)]);

        app(PollChannelOrders::class)->run();

        Http::assertSent(fn (HttpRequest $r): bool => str_starts_with($r->url(), 'https://api.ebay.com/sell/fulfillment/v1/order?')
            && preg_match('/^lastmodifieddate:\[\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{3}Z\.\.\]$/', (string) ($r->data()['filter'] ?? '')) === 1
            && (int) ($r->data()['limit'] ?? 0) === 200
            && $r->hasHeader('Authorization', 'Bearer ebay-access'));
    }

    /** 500 yanıtı boş sayfa sayılmaz — inbox'a hiçbir şey yazılmaz, imleç ilerlemez. */
    #[Test]
    public function a_failed_poll_is_not_read_as_an_empty_page(): void
    {
        [$tenant] = $this->connected();

        Http::fake([self::ORDERS_URL => Http::response(['errors' => [['errorId' => 10001]]], 500)]);

        app(PollChannelOrders::class)->run();

        $this->assertSame(0, $this->asTenant($tenant, fn (): int => InboxMessage::query()->count()));
    }

    /** Ödenmiş, kargolanmamış sipariş `created`; iptal `cancelled`; tam iade `updated` + refunded. */
    #[Test]
    public function statuses_map_to_event_types(): void
    {
        $adapter = $this->adapter();

        $this->assertSame('created', $this->parse($adapter, $this->order('1', 'PAID'))->type);
        $this->assertSame('cancelled', $this->parse($adapter, $this->order('1', 'PAID', cancelled: true))->type);
        $this->assertSame('updated', $this->parse($adapter, $this->order('1', 'PAID', fulfillment: 'FULFILLED'))->type);

        $refund = $this->parse($adapter, $this->order('1', 'FULLY_REFUNDED'));
        $this->assertSame('updated', $refund->type);
        $this->assertSame('refunded', $refund->payload['financial_status']);
    }

    /** `lineItemCost` kalem toplamıdır; birim fiyat ondan bölünür, tutarlar nesneden okunur. */
    #[Test]
    public function lines_and_money_come_from_the_order(): void
    {
        $event = $this->parse($this->adapter(), $this->order('1', 'PAID'));

        $this->assertSame('EUR', $event->payload['currency']);
        $this->assertSame('44.80', $event->payload['grand_total']);
        $this->assertSame('4.90', $event->payload['shipping_total']);

        $line = $event->payload['lines'][0];
        $this->assertSame('TSH-M', $line['sku']);
        $this->assertSame(2, $line['quantity']);
        $this->assertSame('39.90', $line['line_total']);
        $this->assertSame('19.95', $line['unit_price']);
        $this->assertSame('3300011', $line['external_variant_id']);
    }

    /** Uçtan uca: yoklanan sipariş stoğu gerçekten düşürür. */
    #[Test]
    public function a_polled_order_actually_reduces_stock(): void
    {
        [$tenant] = $this->connected();

        $variant = $this->asTenant($tenant, fn (): Variant => Variant::factory()->create(['sku' => 'TSH-M']));
        $warehouseId = (string) $this->asTenant($tenant, fn () => Warehouse::query()->where('is_default', true)->value('id'));

        $this->asTenant($tenant, fn () => app(ApplyMovement::class)->run(
            warehouseId: $warehouseId,
            variantId: $variant->id,
            type: MovementType::IMPORT,
            quantity: 10,
            idempotencyKey: 'import:'.$variant->id,
            sourceType: 'test',
        ));

        Http::fake([self::ORDERS_URL => Http::response(['orders' => [$this->order('12-9', 'PAID')], 'total' => 1], 200)]);

        $this->assertSame(1, app(PollChannelOrders::class)->run());

        $message = $this->asTenant($tenant, fn (): InboxMessage => InboxMessage::query()->firstOrFail());
        $this->asTenant($tenant, fn () => (new ProcessInboxMessage($tenant->id, $message->id))->handle());

        $order = $this->asTenant($tenant, fn (): Order => Order::query()->firstOrFail());
        $this->assertSame('12-9', $order->external_id);

        $level = $this->asTenant($tenant, fn () => InventoryLevel::query()->where('variant_id', $variant->id)->firstOrFail());
        $this->assertSame(8, (int) $level->on_hand);

        $this->assertLedgerMatchesProjection($tenant->id, $warehouseId, $variant->id);
    }

    // ──────────────────────────────────────────────────────── yardımcılar

    private function parse(EbayAdapter $adapter, array $order): NormalizedOrderEvent
    {
        $message = new InboxMessage;
        $message->payload = $order;
        $message->external_event_id = $adapter->pollingEventIdFor($order);

        $event = $adapter->parseOrderEvent($message);
        $this->assertNotNull($event);

        return $event;
    }

    /** @return array<string, mixed> */
    private function order(string $id, string $payment, bool $cancelled = false, string $fulfillment = 'NOT_STARTED'): array
    {
        return [
            'orderId' => $id,
            'creationDate' => now()->addMinute()->utc()->format('Y-m-d\TH:i:s.v\Z'),
            'lastModifiedDate' => now()->utc()->format('Y-m-d\TH:i:s.v\Z'),
            'orderPaymentStatus' => $payment,
            'orderFulfillmentStatus' => $fulfillment,
            'cancelStatus' => ['cancelState' => $cancelled ? 'CANCELED' : 'NONE_REQUESTED'],
            'buyer' => ['username' => 'alici_123'],
            'pricingSummary' => [
                'priceSubtotal' => ['value' => '39.90', 'currency' => 'EUR'],
                'deliveryCost' => ['value' => '4.90', 'currency' => 'EUR'],
                'total' => ['value' => '44.80', 'currency' => 'EUR'],
            ],
            'lineItems' => [[
                'lineItemId' => '10001',
                'legacyItemId' => '3300011',
                'sku' => 'TSH-M',
                'title' => 'Tişört M',
                'quantity' => 2,
                'lineItemCost' => ['value' => '39.90', 'currency' => 'EUR'],
                'lineItemFulfillmentStatus' => $fulfillment,
            ]],
        ];
    }

    private function adapter(): EbayAdapter
    {
        [$tenant, $connection] = $this->connected();

        return $this->asTenant($tenant, fn (): EbayAdapter => new EbayAdapter(
            $connection,
            new ChannelHttpClient($connection, app(CredentialVault::class), app(PayloadRedactor::class)),
        ));
    }

    /** @return array{0: Tenant, 1: ChannelConnection} */
    private function connected(): array
    {
        if ($this->cached !== null) {
            return $this->cached;
        }

        $this->asSystem(fn (): ChannelType => ChannelType::query()->updateOrCreate(
            ['code' => 'ebay'],
            ['name' => 'eBay', 'kind' => 'marketplace', 'adapter_class' => EbayAdapter::class, 'supports_webhooks' => false, 'is_active' => false],
        ));

        $tenant = (new CreateTenant)->run(name: 'eBay Sipariş '.uniqid(), owner: User::factory()->create());

        $connection = $this->asTenant($tenant, function (): ChannelConnection {
            $connection = ChannelConnection::factory()->create([
                'channel_type_code' => 'ebay',
                'external_account_id' => 'ebay-seller-'.uniqid(),
                'status' => 'active',
                'settings' => ['marketplace_id' => 'EBAY_DE'],
            ]);

            app(CredentialVault::class)->store($connection, [
                'client_id' => 'app-id',
                'client_secret' => 'cert-id',
                'access_token' => 'ebay-access',
                'refresh_token' => 'ebay-refresh',
            ]);

            return $connection;
        });

        return $this->cached = [$tenant, $connection];
    }

    /** @var array{0: Tenant, 1: ChannelConnection}|null */
    private ?array $cached = null;
}
