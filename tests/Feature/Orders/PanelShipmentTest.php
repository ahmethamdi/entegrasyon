<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Domain\Catalog\Models\Variant;
use App\Domain\Channels\Adapters\Shopify\ShopifyAdapter;
use App\Domain\Channels\Adapters\Trendyol\TrendyolAdapter;
use App\Domain\Channels\Adapters\WooCommerce\WooCommerceAdapter;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Models\ChannelType;
use App\Domain\Channels\Registry\AdapterRegistry;
use App\Domain\Channels\Support\CredentialVault;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Models\InventoryMovement;
use App\Domain\Orders\Actions\IngestChannelOrder;
use App\Domain\Orders\Actions\UpdateFulfillment;
use App\Domain\Orders\Jobs\PushFulfillment;
use App\Domain\Orders\Models\Fulfillment;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\OrderEvent;
use App\Domain\Orders\Support\FulfillmentEvent;
use App\Domain\Orders\Support\IncomingOrder;
use App\Domain\Orders\Support\IncomingOrderLine;
use App\Domain\Sync\Support\ChannelErrorText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Panelden kargo bildirimi — takip numarası TEK yerden girilir, siparişin
 * geldiği kanala gider.
 *
 * KAPATILAN BOŞLUK: `pushFulfillment` Shopify ve Woo'da yazılıydı ama hiçbir
 * akıştan çağrılmıyordu. Adapter testleri onu tek başına sınadığı için
 * eksik görünmedi; bu dosya PANEL → İŞ → ADAPTER zincirini birlikte sınar.
 */
final class PanelShipmentTest extends TestCase
{
    use RefreshDatabase;

    // ─────────────────────────────────────────────────── panel

    /** Kayıt yazılır, denetim olayı düşer, iş kuyruğa girer — stok hareketi YOK. */
    #[Test]
    public function shipping_from_the_panel_records_and_queues_the_push(): void
    {
        Queue::fake();
        [$tenant, $user, $order] = $this->orderOn('woocommerce');

        $movementsBefore = $this->asTenant($tenant, fn (): int => InventoryMovement::query()->count());

        $this->actingAs($user)
            ->post("/orders/{$order->id}/shipments", ['carrier' => ' Yurtiçi Kargo ', 'tracking_number' => ' YK123 '])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $row = $this->asTenant($tenant, fn (): Fulfillment => Fulfillment::query()->sole());

        $this->assertSame('Yurtiçi Kargo', $row->carrier);
        $this->assertSame('YK123', $row->tracking_number);
        $this->assertSame(Fulfillment::SOURCE_PANEL, $row->source);
        $this->assertSame(Fulfillment::PUSH_PENDING, $row->push_status);

        $this->assertTrue($this->asTenant($tenant, fn (): bool => OrderEvent::query()
            ->where('order_id', $order->id)->where('source', 'panel')->exists()));

        // Kargo stok hareketi ÜRETMEZ — mal satışta düşüldü.
        $this->assertSame($movementsBefore, $this->asTenant($tenant, fn (): int => InventoryMovement::query()->count()));

        Queue::assertPushedOn('orders:high', PushFulfillment::class, fn (PushFulfillment $job): bool => $job->fulfillmentId === $row->id);
    }

    /** Takip numarası zorunlu. */
    #[Test]
    public function tracking_number_is_required(): void
    {
        Queue::fake();
        [, $user, $order] = $this->orderOn('woocommerce');

        $this->actingAs($user)
            ->post("/orders/{$order->id}/shipments", ['carrier' => 'Aras'])
            ->assertSessionHasErrors('tracking_number');

        Queue::assertNothingPushed();
    }

    /** Kanal desteklemiyorsa (Trendyol) kayıt AÇILMAZ, ne yapılacağı söylenir. */
    #[Test]
    public function an_unsupported_channel_is_refused_without_a_row(): void
    {
        Queue::fake();
        [$tenant, $user, $order] = $this->orderOn('trendyol');

        $this->actingAs($user)
            ->post("/orders/{$order->id}/shipments", ['tracking_number' => 'TY1'])
            ->assertSessionHasErrors('tracking_number');

        $this->assertSame(0, $this->asTenant($tenant, fn (): int => Fulfillment::query()->count()));
        Queue::assertNothingPushed();

        $props = $this->actingAs($user)->get("/orders/{$order->id}")->viewData('page')['props']['order'];
        $this->assertFalse($props['canShip']);
    }

    /** Gönderilmekte olan bildirim varken ikincisi reddedilir. */
    #[Test]
    public function a_second_shipment_is_refused_while_one_is_active(): void
    {
        Queue::fake();
        [$tenant, $user, $order] = $this->orderOn('woocommerce');

        $this->actingAs($user)->post("/orders/{$order->id}/shipments", ['tracking_number' => 'A1']);
        $this->actingAs($user)
            ->post("/orders/{$order->id}/shipments", ['tracking_number' => 'A2'])
            ->assertSessionHasErrors('tracking_number');

        $this->assertSame(1, $this->asTenant($tenant, fn (): int => Fulfillment::query()->count()));
    }

    /** Başka kiracının siparişine kargo yazılamaz. */
    #[Test]
    public function another_tenants_order_is_not_found(): void
    {
        Queue::fake();
        [, , $order] = $this->orderOn('woocommerce');
        [, $stranger] = $this->orderOn('woocommerce');

        $this->actingAs($stranger)
            ->post("/orders/{$order->id}/shipments", ['tracking_number' => 'X'])
            ->assertNotFound();

        Queue::assertNothingPushed();
    }

    /** Ekran kargo satırlarını ve gönderim durumunu taşır. */
    #[Test]
    public function the_order_screen_lists_shipments(): void
    {
        Queue::fake();
        [, $user, $order] = $this->orderOn('woocommerce');

        $this->actingAs($user)->post("/orders/{$order->id}/shipments", ['carrier' => 'DHL', 'tracking_number' => 'D1']);

        $props = $this->actingAs($user)->get("/orders/{$order->id}")->viewData('page')['props']['order'];

        $this->assertTrue($props['canShip']);
        $this->assertCount(1, $props['fulfillments']);
        $this->assertSame('D1', $props['fulfillments'][0]['trackingNumber']);
        $this->assertSame('pending', $props['fulfillments'][0]['pushStatus']);
        $this->assertArrayNotHasKey('tenant_id', $props['fulfillments'][0]);
    }

    // ─────────────────────────────────────────────────── iş → Woo

    /**
     * Woo: durum `completed` + MÜŞTERİ NOTU. Meta müşteriye görünmez;
     * numara müşteriye yalnız not yoluyla ulaşır.
     */
    #[Test]
    public function woo_push_completes_the_order_and_tells_the_customer(): void
    {
        Http::fake(['*' => Http::response(['id' => 1], 200)]);
        [$tenant, , $order, $row] = $this->shipped('woocommerce', carrier: 'Aras Kargo', tracking: 'AR777');

        $this->runJob($tenant, $row);

        Http::assertSent(fn (HttpRequest $r): bool => $r->method() === 'PUT'
            && str_ends_with($r->url(), "orders/{$order->external_id}")
            && $r['status'] === 'completed');

        Http::assertSent(fn (HttpRequest $r): bool => $r->method() === 'POST'
            && str_ends_with($r->url(), "orders/{$order->external_id}/notes")
            && $r['customer_note'] === true
            && str_contains($r['note'], 'AR777')
            && str_contains($r['note'], 'Aras Kargo'));

        $row = $this->asTenant($tenant, fn () => $row->fresh());
        $this->assertSame(Fulfillment::PUSH_SENT, $row->push_status);
        $this->assertNotNull($row->pushed_at);
        $this->assertNull($row->push_error);
    }

    /** Kalıcı hata (422): satır GÖNDERİLEMEDİ olur ve sebep görünür. */
    #[Test]
    public function a_permanent_rejection_marks_the_row_failed_with_a_reason(): void
    {
        Http::fake(['*' => Http::response(['message' => 'Geçersiz sipariş durumu'], 422)]);
        [$tenant, , , $row] = $this->shipped('woocommerce');

        $this->runJob($tenant, $row);

        $row = $this->asTenant($tenant, fn () => $row->fresh());
        $this->assertSame(Fulfillment::PUSH_FAILED, $row->push_status);
        $this->assertStringContainsString('Kanal bildirimi reddetti', (string) $row->push_error);
    }

    /** Geçici hata (500): satır BEKLİYOR kalır, son hata yazılır. */
    #[Test]
    public function a_transient_error_keeps_the_row_pending(): void
    {
        Http::fake(['*' => Http::response('boom', 500)]);
        [$tenant, , , $row] = $this->shipped('woocommerce');

        $this->runJob($tenant, $row);

        $row = $this->asTenant($tenant, fn () => $row->fresh());
        $this->assertSame(Fulfillment::PUSH_PENDING, $row->push_status);
        $this->assertSame(1, $row->push_attempts);
        $this->assertNotNull($row->push_error);
    }

    /** Gönderilmiş satır ikinci kez gitmez (kuyruk tekrarı). */
    #[Test]
    public function a_sent_row_is_not_pushed_again(): void
    {
        Http::fake(['*' => Http::response(['id' => 1], 200)]);
        [$tenant, , , $row] = $this->shipped('woocommerce');

        $this->runJob($tenant, $row);
        $this->runJob($tenant, $row);

        Http::assertSentCount(2); // PUT + not, TEK kez
    }

    /** Başarısız satır düzeltilip yeniden denenir; bütçe sıfırlanır. */
    #[Test]
    public function a_failed_row_can_be_corrected_and_retried(): void
    {
        Http::fake(['*' => Http::response(['message' => 'hayır'], 422)]);
        [$tenant, $user, $order, $row] = $this->shipped('woocommerce', tracking: 'YANLIS');
        $this->runJob($tenant, $row);

        Queue::fake();

        $this->actingAs($user)
            ->post("/orders/{$order->id}/shipments/{$row->id}/retry", ['tracking_number' => 'DOGRU'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $row = $this->asTenant($tenant, fn () => $row->fresh());
        $this->assertSame('DOGRU', $row->tracking_number);
        $this->assertSame(Fulfillment::PUSH_PENDING, $row->push_status);
        $this->assertSame(0, $row->push_attempts);
        $this->assertNull($row->push_error);
        Queue::assertPushedOn('orders:high', PushFulfillment::class);
    }

    /** Başarısız OLMAYAN satır yeniden denenemez. */
    #[Test]
    public function only_failed_rows_can_be_retried(): void
    {
        [, $user, $order, $row] = $this->shipped('woocommerce');
        Queue::fake();

        $this->actingAs($user)
            ->post("/orders/{$order->id}/shipments/{$row->id}/retry")
            ->assertNotFound();

        Queue::assertNothingPushed();
    }

    // ─────────────────────────────────────────────────── iş → Shopify

    /**
     * ⚠️ SİPARİŞ KİMLİĞİ SAYISAL KAYITLI, SORGU GID İSTER — ve dönen paket
     * kimliği webhook biçiminde (sayısal) yazılır ki kanal yankısı AYNI
     * satırı bulsun, ikinci satır açmasın.
     */
    #[Test]
    public function shopify_push_uses_the_order_gid_and_the_echo_lands_on_the_same_row(): void
    {
        Http::fake(['*' => Http::sequence()
            ->push(['data' => ['order' => ['fulfillmentOrders' => ['nodes' => [
                ['id' => 'gid://shopify/FulfillmentOrder/1', 'status' => 'OPEN'],
            ]]]]], 200)
            ->push(['data' => ['fulfillmentCreateV2' => [
                'fulfillment' => ['id' => 'gid://shopify/Fulfillment/55', 'status' => 'SUCCESS'],
                'userErrors' => [],
            ]]], 200),
        ]);
        [$tenant, , $order, $row] = $this->shipped('shopify', tracking: 'UPS1');

        $this->runJob($tenant, $row);

        Http::assertSent(fn (HttpRequest $r): bool => ($r->data()['variables']['id'] ?? null) === 'gid://shopify/Order/9001');

        $row = $this->asTenant($tenant, fn () => $row->fresh());
        $this->assertSame(Fulfillment::PUSH_SENT, $row->push_status);
        $this->assertSame('55', $row->external_id);

        // Shopify'ın `fulfillments/create` yankısı.
        $this->asTenant($tenant, fn () => app(UpdateFulfillment::class)->run(new FulfillmentEvent(
            orderId: $order->id, externalId: '55', trackingNumber: 'UPS1', status: 'success',
        )));

        $this->assertSame(1, $this->asTenant($tenant, fn (): int => Fulfillment::query()->count()));
    }

    /**
     * ⚠️ YANKI İŞTEN ÖNCE GELDİYSE: o satır zaten vardır. Kimliği bizimkine
     * yazmak tekillik kısıtına çarpar ve BAŞARILI gönderim "başarısız"
     * görünürdü; gönderim bilgisi yankı satırına taşınır.
     */
    #[Test]
    public function an_echo_that_arrived_first_is_merged_not_duplicated(): void
    {
        Http::fake(['*' => Http::sequence()
            ->push(['data' => ['order' => ['fulfillmentOrders' => ['nodes' => [
                ['id' => 'gid://shopify/FulfillmentOrder/1', 'status' => 'OPEN'],
            ]]]]], 200)
            ->push(['data' => ['fulfillmentCreateV2' => [
                'fulfillment' => ['id' => 'gid://shopify/Fulfillment/55', 'status' => 'SUCCESS'],
                'userErrors' => [],
            ]]], 200),
        ]);
        [$tenant, , $order, $row] = $this->shipped('shopify', tracking: 'UPS1');

        $this->asTenant($tenant, fn () => app(UpdateFulfillment::class)->run(new FulfillmentEvent(
            orderId: $order->id, externalId: '55', trackingNumber: 'UPS1', status: 'success',
        )));

        $this->runJob($tenant, $row);

        $rows = $this->asTenant($tenant, fn () => Fulfillment::query()->get());
        $this->assertCount(1, $rows);
        $this->assertSame('55', $rows[0]->external_id);
        $this->assertSame(Fulfillment::PUSH_SENT, $rows[0]->push_status);
        $this->assertSame(Fulfillment::SOURCE_PANEL, $rows[0]->source);
    }

    // ─────────────────────────────────────────────────── yardımcılar

    private function runJob(Tenant $tenant, Fulfillment $row): void
    {
        (new PushFulfillment($row->id, $tenant->id))->handle(
            app(AdapterRegistry::class),
            app(ChannelErrorText::class),
        );
    }

    /** @return array{0: Tenant, 1: User, 2: Order, 3: Fulfillment} */
    private function shipped(string $channel, ?string $carrier = 'Yurtiçi Kargo', string $tracking = 'YK1'): array
    {
        [$tenant, $user, $order] = $this->orderOn($channel);

        // İş burada SAHTE kuyruğa düşer; testler onu `runJob` ile elle koşar.
        Queue::fake();
        $this->actingAs($user)->post("/orders/{$order->id}/shipments", ['carrier' => $carrier, 'tracking_number' => $tracking]);

        $row = $this->asTenant($tenant, fn (): Fulfillment => Fulfillment::query()->sole());

        return [$tenant, $user, $order, $row];
    }

    /** @return array{0: Tenant, 1: User, 2: Order} */
    private function orderOn(string $channel): array
    {
        $adapters = [
            'woocommerce' => WooCommerceAdapter::class,
            'shopify' => ShopifyAdapter::class,
            'trendyol' => TrendyolAdapter::class,
        ];

        $this->asSystem(fn () => ChannelType::query()->updateOrCreate(
            ['code' => $channel],
            [
                'name' => ucfirst($channel),
                'kind' => 'store',
                'adapter_class' => $adapters[$channel],
                'is_active' => true,
                'rate_limit_profile' => ['requests_per_second' => 50, 'burst_capacity' => 50],
            ],
        ));

        $user = User::factory()->create();
        $tenant = (new CreateTenant)->run(name: 'Kargo '.uniqid(), owner: $user);

        $order = $this->asTenant($tenant, function () use ($tenant, $channel): Order {
            $connection = ChannelConnection::factory()->create([
                'channel_type_code' => $channel,
                'external_account_id' => match ($channel) {
                    'shopify' => 'magaza-'.uniqid().'.myshopify.com',
                    'trendyol' => (string) random_int(100000, 999999),
                    default => 'kargo-'.uniqid().'.example.com',
                },
                'settings' => match ($channel) {
                    'shopify' => ['location_gid' => 'gid://shopify/Location/12'],
                    'woocommerce' => ['base_url' => 'https://kargo.example.com/wp-json/wc/v3/'],
                    default => [],
                },
            ]);

            app(CredentialVault::class)->store($connection, match ($channel) {
                'shopify' => ['access_token' => 'shpat_test_kargo_123456'],
                'woocommerce' => ['consumer_key' => 'ck_kargo_test_1234567890', 'consumer_secret' => 'cs_kargo_test_1234567890'],
                default => ['api_key' => 'k_kargo_123456', 'api_secret' => 's_kargo_123456'],
            });

            $variant = Variant::factory()->create(['sku' => 'KARGO-'.uniqid()]);

            (new IngestChannelOrder)->run(
                new IncomingOrder(
                    channelConnectionId: $connection->id,
                    externalId: '9001',
                    lines: [new IncomingOrderLine(
                        externalLineId: '1',
                        sku: $variant->sku,
                        title: 'Ürün',
                        quantity: 1,
                        variantId: $variant->id,
                        unitPrice: '10.00',
                        lineTotal: '10.00',
                    )],
                    grandTotal: '10.00',
                ),
                $tenant->defaultWarehouse()->id,
            );

            return Order::query()->where('external_id', '9001')->sole();
        });

        return [$tenant, $user, $order];
    }
}
