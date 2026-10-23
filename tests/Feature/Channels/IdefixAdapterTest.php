<?php

declare(strict_types=1);

namespace Tests\Feature\Channels;

use App\Domain\Channels\Adapters\Idefix\IdefixAdapter;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Models\ChannelType;
use App\Domain\Channels\Support\ChannelHttpClient;
use App\Domain\Channels\Support\CredentialVault;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\User;
use App\Domain\Messaging\Models\InboxMessage;
use App\Domain\Orders\Enums\OrderState;
use App\Domain\Sync\Enums\ErrorClass;
use App\Domain\Sync\Enums\SyncDomain;
use App\Domain\Sync\Support\InventoryPushBatch;
use App\Domain\Sync\Support\InventoryPushItem;
use App\Domain\Sync\Support\PricePushBatch;
use App\Support\Logging\PayloadRedactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * İdefix satıcı API'si — kimlik, içe aktarma, stok/fiyat, toplu iş sonucu,
 * sevkiyat (sipariş) ve iade.
 *
 * Gerçek hesapla SINANMADI (beta); istek/yanıt biçimleri developer.idefix.com
 * örneklerinden (24 Eki 2026).
 */
final class IdefixAdapterTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://merchantapi.idefix.com';

    /** Kimlik `X-API-KEY: base64(key:secret)`; Basic auth GİTMEZ. Satıcı kimliği yolda. */
    #[Test]
    public function requests_carry_the_vendor_token_and_vendor_id_in_the_path(): void
    {
        Http::fake([self::BASE.'/*' => Http::response(['batchRequestId' => 'b-1', 'items' => []], 200)]);

        $this->adapter()->pushInventory(new InventoryPushBatch('c', [new InventoryPushItem('l1', '8680000000011', 'SKU-1', 5, 1)]));

        Http::assertSent(fn (HttpRequest $r): bool => $r->method() === 'POST'
            && $r->url() === self::BASE.'/pim/catalog/4321/inventory-upload'
            && $r->header('X-API-KEY')[0] === base64_encode('key-123:secret-abc')
            && ! $r->hasHeader('Authorization'));
    }

    /**
     * ⚠️ Stok işi FİYAT TAŞIMAZ, fiyat işi STOK TAŞIMAZ (ikisi aynı uçta).
     * Bayat fiyat kampanyayı, bayat stok satılmış ürünü ezerdi.
     */
    #[Test]
    public function stock_and_price_payloads_never_carry_each_others_fields(): void
    {
        Http::fake([self::BASE.'/*' => Http::response(['batchRequestId' => 'b-1'], 200)]);
        $adapter = $this->adapter();

        $stock = $adapter->pushInventory(new InventoryPushBatch('c', [new InventoryPushItem('l1', '8680000000011', 'SKU-1', 7, 1)]));
        $price = $adapter->pushPrices(new PricePushBatch('c', [
            ['listing_id' => 'l1', 'external_id' => '8680000000011', 'price' => '149.90', 'compare_at_price' => '199.90', 'version' => 1],
            ['listing_id' => 'l2', 'external_id' => '8680000000028', 'price' => '99.00', 'compare_at_price' => '89.00', 'version' => 1],
        ]));

        $this->assertSame('b-1', $adapter->batchIdFrom($stock));
        $this->assertSame('b-1', $adapter->batchIdFrom($price));

        Http::assertSent(fn (HttpRequest $r): bool => $r['items'] === [['barcode' => '8680000000011', 'inventoryQuantity' => 7]]);
        Http::assertSent(fn (HttpRequest $r): bool => $r['items'] === [
            ['barcode' => '8680000000011', 'price' => 149.9, 'comparePrice' => 199.9],
            // Üstü çizili satıştan küçükse belgedeki gibi 0.
            ['barcode' => '8680000000028', 'price' => 99.0, 'comparePrice' => 0],
        ]);
    }

    /** Kimliksiz 200 "sıraya alındı" sayılmaz. */
    #[Test]
    public function an_upload_without_batch_id_is_not_a_success(): void
    {
        Http::fake([self::BASE.'/*' => Http::response(['items' => []], 200)]);

        $this->expectException(\RuntimeException::class);

        $this->adapter()->pushInventory(new InventoryPushBatch('c', [new InventoryPushItem('l1', '8680000000011', 'SKU-1', 1, 1)]));
    }

    /** Sonuç: `completed` başarılı, `decline` sebebiyle; tek `created` = sürüyor. */
    #[Test]
    public function batch_result_maps_completed_declined_and_pending_rows(): void
    {
        Http::fake([
            self::BASE.'/pim/catalog/4321/inventory-result/done' => Http::response(['status' => 'COMPLETED', 'items' => [
                ['barcode' => '111', 'status' => 'completed'],
                ['barcode' => '222', 'status' => 'decline', 'failureReasons' => 'PRODUCT_NOT_FOUND'],
            ]], 200),
            self::BASE.'/pim/catalog/4321/inventory-result/running' => Http::response(['status' => 'COMPLETED', 'items' => [
                ['barcode' => '111', 'status' => 'completed'],
                ['barcode' => '222', 'status' => 'created'],
            ]], 200),
            self::BASE.'/pim/catalog/4321/inventory-result/gone' => Http::response(['status' => 'FAILED', 'items' => [
                ['barcode' => '111', 'status' => 'decline', 'failureReasons' => 'BATCH_NOT_EXIST'],
            ]], 200),
        ]);

        $adapter = $this->adapter();
        $done = $adapter->fetchBatchStatus('done', SyncDomain::INVENTORY);

        $this->assertTrue($done->isCompleted());
        $this->assertSame('succeeded', $done->outcomeFor('111')[0]);
        [$outcome, $failure] = $done->outcomeFor('222');
        $this->assertSame('failed', $outcome);
        $this->assertStringContainsString('PRODUCT_NOT_FOUND', $failure->reason);

        $this->assertTrue($adapter->fetchBatchStatus('running', SyncDomain::INVENTORY)->isPending());
        $this->assertTrue($adapter->fetchBatchStatus('gone', SyncDomain::PRICE)->isExpired());
    }

    /**
     * İçe aktarma: kimlik barkod, SKU satıcı stok kodu; incelemedeki ürün
     * ALINMAZ. Kısa sayfa son sayfa SAYILMAZ (sayfa sınırı belgede yok).
     */
    #[Test]
    public function import_takes_sellable_products_and_reads_until_an_empty_page(): void
    {
        Http::fake([self::BASE.'/pim/pool/4321/list*' => Http::sequence()
            ->push(['products' => [
                $this->poolRow('8680000000011', 'ready_for_sale'),
                $this->poolRow('8680000000028', 'not_matched'),
            ]], 200)
            ->push(['products' => []], 200)]);

        $adapter = $this->adapter();
        $first = $adapter->fetchProductPage();

        $this->assertCount(1, $first->products);
        $this->assertSame('8680000000011', $first->products[0]->externalId);
        $this->assertSame('STK-8680000000011', $first->products[0]->sku);
        $this->assertSame('149.00', $first->products[0]->price);
        $this->assertSame('GRP-1', $first->products[0]->listingIdentity['external_parent_id']);
        $this->assertTrue($first->hasMore);
        $this->assertSame('2', $first->nextCursor);

        $this->assertFalse($adapter->fetchProductPage('2')->hasMore);
    }

    /**
     * Sevkiyat kalemleri (adet başına 1) BARKODDA toplanır; alıcı, adres ve
     * T.C. kimlik no kayda GİRMEZ. Pencere `lastUpdatedAt`, Türkiye saati.
     */
    #[Test]
    public function shipments_become_orders_grouped_by_barcode_without_personal_data(): void
    {
        Http::fake([
            self::BASE.'/oms/4321/list*' => Http::response($this->shipmentPage([
                $this->shipment(600001, 'shipment_ready', ['8680000000011', '8680000000011', '8680000000028']),
            ]), 200),
            self::BASE.'/oms/4321/claim-list*' => Http::response(['items' => [], 'pageCount' => 1], 200),
        ]);

        $adapter = $this->adapter();
        $page = $adapter->fetchOrders(Carbon::parse('2026-10-24 07:00:00', 'UTC'));

        $this->assertSame(['600001:created'], array_map(fn (array $o): ?string => $adapter->pollingEventIdFor($o), $page->orders));
        $this->assertSame('c:1', $page->nextCursor);
        Http::assertSent(fn (HttpRequest $r): bool => str_contains($r->url(), '/oms/4321/list')
            && str_contains(urldecode($r->url()), 'lastUpdatedAt=2026/10/24 10:00:00'));

        $encoded = (string) json_encode($page->orders[0]);
        $this->assertStringNotContainsString('52369854125', $encoded);
        $this->assertStringNotContainsString('Test adresidir', $encoded);

        $event = $adapter->parseOrderEvent($this->message($page->orders[0], '600001:created'));

        $this->assertSame('created', $event->type);
        $this->assertSame('600001', $event->externalOrderId);
        $this->assertSame('IDE-ORDER-1', $event->payload['external_number']);
        $this->assertCount(2, $event->payload['lines']);
        $this->assertSame(2, $event->payload['lines'][0]['quantity']);
        $this->assertSame('8680000000011', $event->payload['lines'][0]['external_variant_id']);
        $this->assertSame('99.80', $event->payload['lines'][0]['line_total']);
        $this->assertSame(OrderState::AWAITING_SHIPMENT, $adapter->orderStateFor($event));
    }

    /**
     * ⚠️ BÖLÜNEN SEVKİYAT İPTAL SAYILIR: yerine aynı kalemlerle yeni
     * sevkiyatlar gelir; eski açık kalsaydı stok İKİ KEZ düşerdi. Hiç
     * görülmemiş iptal/bölünmüş sevkiyat ise HİÇBİR kayıt üretmez.
     */
    #[Test]
    public function a_split_or_cancelled_shipment_cancels_a_known_order_and_ignores_an_unknown_one(): void
    {
        $adapter = $this->adapter();

        $this->asSystem(fn () => InboxMessage::query()->create([
            'tenant_id' => $adapter->connection()->tenant_id,
            'channel_connection_id' => $adapter->connection()->id,
            'source' => 'polling',
            'external_event_id' => '600001:created',
            'event_type' => 'order.polled',
            'payload' => [],
            'payload_hash' => sha1('600001'),
            'status' => 'processed',
            'received_at' => now(),
        ]));

        Http::fake([
            self::BASE.'/oms/4321/list*' => Http::response($this->shipmentPage([
                $this->shipment(600001, 'shipment_split', ['8680000000011']),
                $this->shipment(600002, 'shipment_cancelled', ['8680000000011']),
                $this->shipment(600003, 'shipment_ready', ['8680000000011']),
            ]), 200),
        ]);

        $page = $adapter->fetchOrders(Carbon::parse('2026-10-24 07:00:00'));
        $ids = array_map(fn (array $o): ?string => $adapter->pollingEventIdFor($o), $page->orders);

        $this->assertSame(['600001:cancel', '600003:created'], $ids);

        $event = $adapter->parseOrderEvent($this->message($page->orders[0], '600001:cancel'));
        $this->assertSame('cancelled', $event->type);
        $this->assertSame([['external_line_id' => '8680000000011', 'sku' => '8680000000011', 'quantity' => 1]], $event->payload['lines']);
        $this->assertSame(OrderState::CANCELLED, $adapter->orderStateFor($event));
    }

    /**
     * İade kalemi ana sipariş numarasını taşır; sevkiyat kalem kimliğiyle
     * bulunur. Yalnız `approved` iade stok geri getirir (olay kalem başına).
     */
    #[Test]
    public function approved_claims_are_mapped_back_to_their_shipment(): void
    {
        Http::fake([
            self::BASE.'/oms/4321/claim-list*' => Http::response(['pageCount' => 1, 'items' => [[
                'id' => 241,
                'orderNumber' => 'IDE-ORDER-1',
                'items' => [
                    ['id' => 262, 'orderLineId' => 10000001, 'barcode' => '8680000000011', 'state' => 'approved', 'approvedAt' => '2026-10-24T11:00:00+03:00'],
                    ['id' => 263, 'orderLineId' => 10000002, 'barcode' => '8680000000028', 'state' => 'waiting_vendor_approve'],
                ],
            ]]], 200),
            self::BASE.'/oms/4321/list*' => Http::response($this->shipmentPage([
                $this->shipment(600001, 'shipment_delivered', ['8680000000011', '8680000000028']),
            ]), 200),
        ]);

        $adapter = $this->adapter();
        $page = $adapter->fetchOrders(Carbon::parse('2026-10-24 07:00:00'), 'c:1');

        $this->assertSame(['600001:return:262'], array_map(fn (array $o): ?string => $adapter->pollingEventIdFor($o), $page->orders));
        $this->assertFalse($page->hasMore);
        Http::assertSent(fn (HttpRequest $r): bool => str_contains($r->url(), '/oms/4321/list') && str_contains($r->url(), 'orderNumber=IDE-ORDER-1'));

        $event = $adapter->parseOrderEvent($this->message($page->orders[0], '600001:return:262'));
        $this->assertSame('returned', $event->type);
        $this->assertSame('600001', $event->externalOrderId);
        $this->assertSame([['external_line_id' => '8680000000011', 'quantity' => 1]], $event->payload['lines']);
    }

    /** Yanlış anahtar 401 (`VENDOR_TOKEN_NOT_EXIST`) kalıcı kimlik hatasıdır; 5xx yükselir. */
    #[Test]
    public function errors_are_classified_and_server_errors_raised(): void
    {
        Http::fake([self::BASE.'/*' => Http::response(['message' => 'VENDOR_TOKEN_NOT_EXIST'], 401)]);
        $adapter = $this->adapter();

        try {
            $adapter->pushInventory(new InventoryPushBatch('c', [new InventoryPushItem('l1', '1', 'S', 1, 1)]));
            $this->fail('401 yükselmeliydi');
        } catch (RequestException $e) {
            $this->assertSame(ErrorClass::AUTHENTICATION, $adapter->classifyError($e));
        }

        $this->assertFalse($adapter->healthCheck()->healthy);
    }

    /** @return array<string, mixed> */
    private function poolRow(string $barcode, string $status): array
    {
        return [
            'barcode' => $barcode,
            'title' => 'Barbie Oyun Seti',
            'productMainId' => 'GRP-1',
            'inventoryQuantity' => 52,
            'vendorStockCode' => 'STK-'.$barcode,
            'description' => 'Açıklama',
            'price' => 149,
            'comparePrice' => 159,
            'images' => [['url' => 'https://cdn.example/1.jpg']],
            'status' => $status,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    private function shipmentPage(array $items): array
    {
        return ['totalCount' => count($items), 'pageCount' => 1, 'currentPage' => 1, 'limit' => 100, 'items' => $items];
    }

    /**
     * @param  list<string>  $barcodes
     * @return array<string, mixed>
     */
    private function shipment(int $id, string $status, array $barcodes): array
    {
        $address = ['fullName' => 'Derya Kaya', 'address1' => 'Test adresidir.', 'phone' => '5555555555', 'identificationNumber' => '52369854125'];
        $items = [];

        foreach ($barcodes as $i => $barcode) {
            $items[] = [
                'id' => 10000001 + $i,
                'productName' => 'Ürün '.$barcode,
                'barcode' => $barcode,
                'merchantSku' => $barcode,
                'price' => 54.9,
                'discountedTotalPrice' => 49.9,
                'currency' => 'TL',
            ];
        }

        return [
            'id' => $id,
            'orderNumber' => 'IDE-ORDER-1',
            'invoiceAddress' => $address,
            'shippingAddress' => $address,
            'customerContactName' => 'Derya Kaya',
            'customerContactMail' => '1000019@idefixcustomer.com',
            'discountedTotalPrice' => 49.9 * count($barcodes),
            'status' => $status,
            'orderDate' => '2026-10-24T09:24:25+03:00',
            'statusUpdatedAt' => '2026-10-24T10:24:25+03:00',
            'items' => $items,
        ];
    }

    /** @param array<string, mixed> $payload */
    private function message(array $payload, string $eventId): InboxMessage
    {
        $message = new InboxMessage;
        $message->payload = $payload;
        $message->external_event_id = $eventId;

        return $message;
    }

    private function adapter(): IdefixAdapter
    {
        $this->asSystem(fn (): ChannelType => ChannelType::query()->updateOrCreate(
            ['code' => 'idefix'],
            ['name' => 'İdefix', 'kind' => 'marketplace', 'adapter_class' => IdefixAdapter::class, 'supports_webhooks' => false, 'is_active' => true],
        ));

        $tenant = (new CreateTenant)->run(name: 'İdefix '.uniqid(), owner: User::factory()->create());

        return $this->asTenant($tenant, function (): IdefixAdapter {
            $connection = ChannelConnection::factory()->create([
                'channel_type_code' => 'idefix',
                'external_account_id' => '4321',
                'status' => 'active',
                'settings' => [IdefixAdapter::VENDOR_ID_KEY => '4321'],
            ]);

            app(CredentialVault::class)->store($connection, [
                IdefixAdapter::API_KEY_SECRET => 'key-123',
                IdefixAdapter::API_SECRET_SECRET => 'secret-abc',
            ]);

            return new IdefixAdapter(
                $connection,
                new ChannelHttpClient($connection, app(CredentialVault::class), app(PayloadRedactor::class)),
            );
        });
    }
}
