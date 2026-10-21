<?php

declare(strict_types=1);

namespace Tests\Feature\Channels;

use App\Domain\Channels\Adapters\Kaufland\KauflandAdapter;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Models\ChannelType;
use App\Domain\Channels\Support\ChannelHttpClient;
use App\Domain\Channels\Support\CredentialVault;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\User;
use App\Domain\Messaging\Models\InboxMessage;
use App\Domain\Orders\Models\Fulfillment;
use App\Domain\Orders\Models\Order;
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
 * Kaufland Seller API v2 — imza, içe aktarma, stok/fiyat, sipariş, kargo.
 *
 * Gerçek hesapla SINANMADI (beta); istek biçimleri resmî OpenAPI'ye göre.
 */
final class KauflandAdapterTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://sellerapi.kaufland.com/v2';

    /** ⚠️ İMZA tam adres + ham gövde + zaman damgasıyla; gövde imzalananın AYNISI gider. */
    #[Test]
    public function every_request_is_signed_over_the_exact_uri_and_body(): void
    {
        Http::fake([self::BASE.'/*' => Http::response(['data' => []], 200)]);

        $this->adapter()->pushPrices(new PricePushBatch('c', [
            ['listing_id' => 'l1', 'external_id' => '555', 'price' => '19.90', 'version' => 1],
        ]));

        Http::assertSent(function (HttpRequest $r): bool {
            $timestamp = $r->header('Shop-Timestamp')[0] ?? '';
            $expected = hash_hmac('sha256', implode("\n", ['PATCH', $r->url(), $r->body(), $timestamp]), 'secret-abc');

            return $r->method() === 'PATCH'
                && $r->url() === self::BASE.'/units/555?storefront=de'
                && $r->body() === '{"listing_price":1990}'
                && $r->header('Shop-Client-Key')[0] === 'client-123'
                && $r->header('Shop-Signature')[0] === $expected
                && $r->header('User-Agent')[0] === '34Pazar'
                && ! $r->hasHeader('Authorization');
        });
    }

    /** Stok MUTLAK `amount`; 4xx kaleme yazılır, parti düşmez. */
    #[Test]
    public function inventory_is_patched_per_unit_and_rejections_are_partial(): void
    {
        Http::fake([
            self::BASE.'/units/1*' => Http::response(['data' => []], 200),
            self::BASE.'/units/2*' => Http::response(['message' => 'unit not found'], 404),
        ]);

        $result = $this->adapter()->pushInventory(new InventoryPushBatch('c', [
            new InventoryPushItem('l1', '1', 'SKU-1', 7, 1),
            new InventoryPushItem('l2', '2', 'SKU-2', 0, 1),
        ]));

        $this->assertTrue($result->successful);
        $this->assertSame(1, $result->data['pushed']);
        Http::assertSent(fn (HttpRequest $r): bool => str_contains($r->url(), '/units/1?') && $r->body() === '{"amount":7}');
    }

    /** 5xx kaleme yazılmaz — yükselir ve parti yeniden denenir. */
    #[Test]
    public function a_server_error_is_raised_not_swallowed(): void
    {
        Http::fake([self::BASE.'/*' => Http::response('', 503)]);

        $this->expectException(RequestException::class);

        $this->adapter()->pushInventory(new InventoryPushBatch('c', [new InventoryPushItem('l1', '1', 'SKU-1', 3, 1)]));
    }

    /** İçe aktarma: kimlik `id_unit`, SKU `id_offer`, fiyat kuruştan. */
    #[Test]
    public function units_are_imported_with_offer_as_sku_and_cents_as_price(): void
    {
        Http::fake([self::BASE.'/units*' => Http::response([
            'data' => [[
                'id_unit' => 9001, 'id_offer' => 'TSH-M', 'id_product' => 77, 'listing_price' => 1990, 'amount' => 4, 'is_live' => true,
                'product' => ['title' => 'Tişört M', 'eans' => ['4006381333931'], 'main_picture' => 'https://img.kaufland/x.jpg'],
            ]],
            'pagination' => ['offset' => 0, 'limit' => 100, 'total' => 1],
        ], 200)]);

        $page = $this->adapter()->fetchProductPage();
        $product = $page->products[0];

        $this->assertSame('9001', $product->externalId);
        $this->assertSame('TSH-M', $product->sku);
        $this->assertSame('19.90', $product->price);
        $this->assertSame(4, $product->quantity);
        $this->assertSame('4006381333931', $product->barcode);
        $this->assertFalse($page->hasMore);
    }

    /**
     * ⚠️ BİRİMLER SİPARİŞTE TOPLANIR: aynı `id_order` için TEK "created";
     * iptal birim başına ayrı olaydır (kimlik birime bağlı).
     */
    #[Test]
    public function order_units_are_grouped_and_cancellations_are_separate_events(): void
    {
        Http::fake([self::BASE.'/order-units*' => Http::response([
            'data' => [
                $this->unit(1, 'MPAB1', 'TSH-M', 'need_to_be_sent'),
                $this->unit(2, 'MPAB1', 'TSH-M', 'need_to_be_sent'),
                $this->unit(3, 'MPAB1', 'TSH-L', 'cancelled'),
            ],
            'pagination' => ['offset' => 0, 'limit' => 100, 'total' => 3],
        ], 200)]);

        $adapter = $this->adapter();
        $page = $adapter->fetchOrders(Carbon::parse('2026-10-20 10:00:00'));
        $ids = array_map(fn (array $o): ?string => $adapter->pollingEventIdFor($o), $page->orders);

        $this->assertSame(['MPAB1:created', 'MPAB1:cancel:3'], $ids);

        Http::assertSent(fn (HttpRequest $r): bool => str_contains($r->url(), 'ts_updated_from_iso=2026-10-20T10%3A00%3A00Z'));

        $message = new InboxMessage;
        $message->payload = $page->orders[0];
        $message->external_event_id = 'MPAB1:created';
        $event = $adapter->parseOrderEvent($message);

        $this->assertSame('created', $event->type);
        $this->assertSame('open', $event->payload['status']);
        $this->assertSame('EUR', $event->payload['currency']);
        // 2 × 19,90 + 1 × 21,00 (iptal ayrıca düşülür) ; kargo 3 × 2,00
        $this->assertSame('60.80', $event->payload['subtotal']);
        $this->assertSame('6.00', $event->payload['shipping_total']);
        $this->assertSame(2, $event->payload['lines'][0]['quantity']);
        $this->assertSame('19.90', $event->payload['lines'][0]['unit_price']);
        $this->assertArrayNotHasKey('buyer', $page->orders[0]['units'][0]);
    }

    /** Kargo yalnız açık birimlere gider; gönderilmiş birime ikinci istek yok. */
    #[Test]
    public function fulfillment_is_sent_for_open_units_only(): void
    {
        Http::fake([
            self::BASE.'/orders/MPAB1' => Http::response(['data' => ['id_order' => 'MPAB1', 'order_units' => [
                $this->unit(1, 'MPAB1', 'TSH-M', 'need_to_be_sent'),
                $this->unit(2, 'MPAB1', 'TSH-M', 'sent'),
            ]]], 200),
            self::BASE.'/order-units/*' => Http::response('', 204),
        ]);

        $order = new Order;
        $order->external_id = 'MPAB1';
        $fulfillment = new Fulfillment;
        $fulfillment->carrier = 'dhl';
        $fulfillment->tracking_number = '00340434';
        $fulfillment->setRelation('order', $order);

        $result = $this->adapter()->pushFulfillment($fulfillment);

        $this->assertSame(1, $result->data['units']);
        Http::assertSent(fn (HttpRequest $r): bool => $r->method() === 'PATCH'
            && $r->url() === self::BASE.'/order-units/1/send'
            && $r['carrier_code'] === 'DHL'
            && $r['tracking_numbers'] === '00340434');
        Http::assertNotSent(fn (HttpRequest $r): bool => $r->url() === self::BASE.'/order-units/2/send');
    }

    /** @return array<string, mixed> */
    private function unit(int $id, string $order, string $offer, string $status): array
    {
        return [
            'id_order_unit' => $id,
            'id_order' => $order,
            'id_offer' => $offer,
            'id_unit' => 9000 + $id,
            'status' => $status,
            'price' => $offer === 'TSH-M' ? 1990 : 2100,
            'shipping_rate' => 200,
            'currency' => 'EUR',
            'ts_created_iso' => '2026-10-20T10:05:00Z',
            'ts_updated_iso' => '2026-10-20T10:06:00Z',
            'buyer' => ['email' => 'kisisel@example.com'],
            'shipping_address' => ['street' => 'Gizli 1'],
            'product' => ['title' => 'Tişört'],
        ];
    }

    private function adapter(): KauflandAdapter
    {
        $this->asSystem(fn (): ChannelType => ChannelType::query()->updateOrCreate(
            ['code' => 'kaufland'],
            ['name' => 'Kaufland', 'kind' => 'marketplace', 'adapter_class' => KauflandAdapter::class, 'supports_webhooks' => false, 'is_active' => true],
        ));

        $tenant = (new CreateTenant)->run(name: 'Kaufland '.uniqid(), owner: User::factory()->create());

        return $this->asTenant($tenant, function (): KauflandAdapter {
            $connection = ChannelConnection::factory()->create([
                'channel_type_code' => 'kaufland',
                'external_account_id' => 'kaufland-'.uniqid(),
                'status' => 'active',
                'settings' => [KauflandAdapter::STOREFRONT_KEY => 'de'],
            ]);

            app(CredentialVault::class)->store($connection, [
                KauflandAdapter::CLIENT_KEY_SECRET => 'client-123',
                KauflandAdapter::SECRET_KEY_SECRET => 'secret-abc',
            ]);

            return new KauflandAdapter(
                $connection,
                new ChannelHttpClient($connection, app(CredentialVault::class), app(PayloadRedactor::class)),
            );
        });
    }
}
