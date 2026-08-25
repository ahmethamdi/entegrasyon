<?php

declare(strict_types=1);

namespace Tests\Feature\Channels;

use App\Domain\Catalog\Models\Variant;
use App\Domain\Channels\Adapters\Shopify\ShopifyAdapter;
use App\Domain\Channels\Adapters\Shopify\ShopifyOrderNormalizer;
use App\Domain\Channels\Contracts\AdapterResult;
use App\Domain\Channels\Contracts\SupportsFulfillment;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Models\ChannelType;
use App\Domain\Channels\Support\ChannelHttpClient;
use App\Domain\Channels\Support\CredentialVault;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Models\InventoryMovement;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Messaging\Actions\IngestInboxMessage;
use App\Domain\Messaging\Models\InboxMessage;
use App\Domain\Orders\Actions\IngestChannelOrder;
use App\Domain\Orders\Models\Fulfillment;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Routing\OrderEventRouter;
use App\Domain\Orders\Support\IncomingOrder;
use App\Domain\Orders\Support\IncomingOrderLine;
use App\Domain\Sync\Support\NormalizedOrderEvent;
use App\Support\Logging\PayloadRedactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Shopify kargo — slice 1.8.
 *
 * V3.0 · §06.6 · §04 (capability matrisi) · v2.2 §4 · §7 · §13 · Faz 3.
 *
 * ─────────────────────────────────────────────────────────────────────
 * ⚠️ BU SLICE `UpdateFulfillment`'IN "DÜRÜST SINIR"INI KAPATIR
 * ─────────────────────────────────────────────────────────────────────
 * O sınıfın başlığı şöyle diyordu: "Hiçbir normalizer `fulfilled` tipi
 * ÜRETMİYOR... router dalını ve paket bazlı çıpayı sınayan bir davranış
 * testi YAZILAMAZ, çünkü o olayı üreten bir kaynak yok. Mutasyon bu iki
 * noktada hayatta kalır ve KALMALIDIR."
 *
 * Shopify `fulfillments/create` ve `fulfillments/update` konularını
 * GÖNDERİR — yani o kaynak ARTIK VAR ve sınır kapanır. Sınıf başlığındaki
 * talimat da bunu söylüyordu: "Kanal kargo bildirimi göndermeye
 * başladığında İLK İŞ normalizer'a `fulfilled` tipini ve
 * `payload['fulfillment']` bloğunu eklemektir."
 *
 * ⚠️ KARGO STOK HAREKETİ ÜRETMEZ (§4). Mal SATIŞTA zaten düşülmüştür;
 * hareket üretseydi aynı satış iki kez düşülür ve bakiye KALICI olarak
 * bozulurdu.
 */
final class ShopifyFulfillmentTest extends TestCase
{
    use RefreshDatabase;

    // ──────────────────────────────────────────────────── gelen kargo olayı

    /**
     * ⚠️ KARGO GÖVDESİNİN KÖKÜ FARKLIDIR — `order_id` OKUNUR, `id` DEĞİL.
     *
     * `fulfillments/create` webhook'unun gövdesi FULFILLMENT nesnesidir ve
     * `id` paketin KENDİ kimliğidir (iade gövdesindeki tuzağın aynısı).
     * `id` okunsaydı `OrderEventRouter::resolveOrder()` siparişi BULAMAZ,
     * yalnızca "eşleşmeyen sipariş" uyarısı yazar ve kargo bilgisi SESSİZCE
     * kaybolurdu — satıcı takip numarasını panelde hiç göremezdi.
     *
     * GERÇEK ÇALIŞTIRMADA BULUNDU: slice 1.7'den sonra normalizer bu
     * gövdede `7777` (paket kimliği) döndürüyordu.
     */
    #[Test]
    public function the_fulfillment_order_id_is_the_order_not_the_package(): void
    {
        $event = $this->normalizeFulfillment();

        $this->assertSame(
            '9001',
            $event?->externalOrderId,
            'Paketin kendi kimliği sipariş kimliği sanıldı — router siparişi '
            .'bulamaz ve kargo bilgisi sessizce kaybolur.',
        );
    }

    /**
     * ⚠️ `payload['fulfillment']` BLOĞU ROUTER'IN SÖZLEŞMESİDİR.
     *
     * `OrderEventRouter::handleFulfilled()` paket alanlarını YALNIZCA bu
     * bloktan okur. Blok yazılmasaydı `FulfillmentEvent` boş kimlik, boş
     * kargo firması ve boş takip numarasıyla kurulur; satır yazılır ama
     * İÇİ BOŞ olurdu ve satıcı "kargolandı" görüp takip edemezdi.
     */
    #[Test]
    public function the_payload_carries_the_fulfillment_block_the_router_reads(): void
    {
        $shipment = $this->normalizeFulfillment()?->payload['fulfillment'] ?? null;

        $this->assertIsArray($shipment);
        $this->assertSame('7777', $shipment['external_id'] ?? null);
        $this->assertSame('Yurtiçi Kargo', $shipment['carrier'] ?? null);
        $this->assertSame('TR123456789', $shipment['tracking_number'] ?? null);
    }

    /**
     * ⚠️ PAKET KİMLİĞİ ZORUNLUDUR — ÇOK PAKETLİ SİPARİŞİN ÇIPASI.
     *
     * Tekillik `(order_id, external_id)` üzerindedir. Kimlik taşınmasaydı
     * çok paketli siparişin ikinci paketi birincinin satırını EZER ve
     * satıcı yarısı teslim olmuş siparişi "tamamen teslim" sanırdı
     * (`UpdateFulfillment`'ın değişmez kuralı).
     */
    #[Test]
    public function two_packages_of_one_order_produce_two_rows(): void
    {
        [$tenant, $order] = $this->tenantWithOrder();

        $this->asTenant($tenant, function () use ($order): void {
            $this->routeFulfillment($order, externalId: 7777, tracking: 'TR-1');
            $this->routeFulfillment($order, externalId: 8888, tracking: 'TR-2');

            $rows = Fulfillment::query()->where('order_id', $order->id)->get();

            $this->assertCount(
                2,
                $rows,
                'İkinci paket birincinin satırını ezdi — yarısı teslim olmuş '
                .'sipariş "tamamen teslim" görünür.',
            );
            $this->assertEqualsCanonicalizing(
                ['TR-1', 'TR-2'],
                $rows->pluck('tracking_number')->all(),
            );

            // ⚠️ İKİ SATIR OLMASI TEK BAŞINA YETMEZ — KİMLİKLER DE
            // TAŞINMALIDIR. Blok kimliği hiç yazmasaydı iki satır YİNE
            // oluşurdu (tekillik kısıtı NULL'ları kapsamaz) ve sayım
            // testi YANLIŞ SEBEPLE yeşil kalırdı; o hâlde ikinci paketin
            // güncellemesi hangi satırı ilerleteceğini bilemezdi.
            // Mutasyonla bulundu.
            $this->assertEqualsCanonicalizing(
                ['7777', '8888'],
                $rows->pluck('external_id')->all(),
                'Paket kimlikleri taşınmadı — güncelleme hangi satırı '
                .'ilerleteceğini bilemez.',
            );
        });
    }

    /**
     * ⚠️ AYNI PAKETİN GÜNCELLEMESİ DURUMU İLERLETİR, YENİ SATIR AÇMAZ.
     *
     * `fulfillments/update` aynı `external_id` ile gelir. Yeni satır
     * açsaydı panelde tek kargo iki kez görünür ve hangisinin güncel
     * olduğu belirsiz kalırdı.
     */
    #[Test]
    public function an_update_to_the_same_package_advances_it_in_place(): void
    {
        [$tenant, $order] = $this->tenantWithOrder();

        $this->asTenant($tenant, function () use ($order): void {
            $this->routeFulfillment($order, externalId: 7777, status: 'success');
            $this->routeFulfillment(
                $order,
                externalId: 7777,
                status: 'delivered',
                topic: 'fulfillments/update',
                eventId: 'evt-f2',
            );

            $rows = Fulfillment::query()->where('order_id', $order->id)->get();

            $this->assertCount(1, $rows, 'Aynı paket ikinci satır açtı.');
            $this->assertSame('delivered', $rows->first()?->status);
        });
    }

    /**
     * ⚠️ KARGO STOK HAREKETİ ÜRETMEZ (§4) — ledger snapshot'ıyla korunur.
     *
     * Mal SATIŞTA zaten düşülmüştür. Hareket üretseydi aynı satış iki kez
     * düşülür ve bakiye KALICI olarak bozulurdu.
     */
    #[Test]
    public function a_fulfillment_event_writes_no_inventory_movement(): void
    {
        [$tenant, $order] = $this->tenantWithOrder();

        $this->asTenant($tenant, function () use ($order): void {
            $before = InventoryMovement::query()->count();

            $this->routeFulfillment($order, externalId: 7777);

            $this->assertSame(
                $before,
                InventoryMovement::query()->count(),
                'Kargo stok hareketi üretti — aynı satış iki kez düşülür.',
            );
        });
    }

    /**
     * Kimliksiz bildirim de KAYDEDİLİR.
     *
     * Kanal kimlik vermeyebilir ve bildirimi düşürmek kargo bilgisini
     * tamamen kaybettirirdi (`UpdateFulfillment` kuralı).
     */
    #[Test]
    public function a_fulfillment_without_an_id_is_still_recorded(): void
    {
        [$tenant, $order] = $this->tenantWithOrder();

        $this->asTenant($tenant, function () use ($order): void {
            $this->routeFulfillment($order, externalId: null, tracking: 'TR-X');

            $this->assertSame(
                'TR-X',
                Fulfillment::query()->where('order_id', $order->id)->first()?->tracking_number,
            );
        });
    }

    /**
     * ⚠️ KARGO FİRMASI `tracking_company`'DEN OKUNUR.
     *
     * Shopify ayrıca `tracking_numbers` (çoğul dizi) ve `tracking_urls`
     * gönderir; kanonik model TEK takip numarası taşır ve ilki alınır.
     * Dizi olduğu gibi yazılsaydı kolon `["TR123"]` metnini taşır ve
     * satıcı takip sitesine yapıştırdığında hiçbir şey bulamazdı.
     */
    #[Test]
    public function the_tracking_number_falls_back_to_the_plural_field(): void
    {
        $event = $this->normalizeFulfillment([
            'tracking_number' => null,
            'tracking_numbers' => ['TR-COGUL-1', 'TR-COGUL-2'],
        ]);

        $this->assertSame(
            'TR-COGUL-1',
            $event?->payload['fulfillment']['tracking_number'] ?? null,
        );
    }

    // ─────────────────────────────────────────────────────── giden kargo

    /**
     * Kargo bildirimi kanala `fulfillmentCreateV2` ile gider.
     *
     * ⚠️ HEDEF `fulfillmentOrder`'DIR, SİPARİŞ DEĞİL. Shopify'ın modern
     * kargo modelinde sipariş, kargolanabilir parçalara (fulfillment
     * order) bölünür ve mutation onları ister. Sipariş gid'i verilseydi
     * Shopify `userErrors` döner ve o hata KALICIDIR.
     */
    #[Test]
    public function pushing_a_fulfillment_uses_the_fulfillment_order(): void
    {
        Http::fake([
            '*' => Http::sequence()
                ->push(['data' => ['order' => ['fulfillmentOrders' => ['nodes' => [
                    ['id' => 'gid://shopify/FulfillmentOrder/1', 'status' => 'OPEN'],
                ]]]]], 200)
                ->push(['data' => ['fulfillmentCreateV2' => [
                    'fulfillment' => ['id' => 'gid://shopify/Fulfillment/55', 'status' => 'SUCCESS'],
                    'userErrors' => [],
                ]]], 200),
        ]);

        $result = $this->pushFulfillment();

        $this->assertTrue($result->successful);

        Http::assertSent(function ($request): bool {
            $vars = $request->data()['variables'] ?? [];

            return ($vars['fulfillment']['lineItemsByFulfillmentOrder'][0]['fulfillmentOrderId'] ?? null)
                === 'gid://shopify/FulfillmentOrder/1';
        });
    }

    /** Takip bilgisi yüke konur — satıcının müşteriye vereceği veri budur. */
    #[Test]
    public function the_tracking_info_is_carried_in_the_push(): void
    {
        Http::fake([
            '*' => Http::sequence()
                ->push(['data' => ['order' => ['fulfillmentOrders' => ['nodes' => [
                    ['id' => 'gid://shopify/FulfillmentOrder/1', 'status' => 'OPEN'],
                ]]]]], 200)
                ->push(['data' => ['fulfillmentCreateV2' => [
                    'fulfillment' => ['id' => 'gid://shopify/Fulfillment/55', 'status' => 'SUCCESS'],
                    'userErrors' => [],
                ]]], 200),
        ]);

        $this->pushFulfillment();

        Http::assertSent(function ($request): bool {
            $info = $request->data()['variables']['fulfillment']['trackingInfo'] ?? null;

            if ($info === null) {
                return false;
            }

            return ($info['number'] ?? null) === 'TR123456789'
                && ($info['company'] ?? null) === 'Yurtiçi Kargo';
        });
    }

    /**
     * ⚠️ KARGOLANABİLİR PARÇA YOKSA İSTEK ATILMAZ.
     *
     * Sipariş zaten tamamen kargolanmışsa `fulfillmentOrders` boş döner.
     * Mutation yine de çağrılsaydı `userErrors` alır, o hata KALICIDIR
     * ve operasyon "düzeltilemez" damgasıyla ölürdü — oysa yapılacak bir
     * şey yoktur ve durum HATA DEĞİLDİR.
     */
    #[Test]
    public function nothing_to_fulfill_is_not_an_error(): void
    {
        Http::fake(['*' => Http::response([
            'data' => ['order' => ['fulfillmentOrders' => ['nodes' => []]]],
        ], 200)]);

        $result = $this->pushFulfillment();

        $this->assertTrue($result->successful);
        $this->assertTrue($result->data['already_fulfilled'] ?? false);
        Http::assertSentCount(1);
    }

    /** Siparişin kanal kimliği yoksa çağrı yapılmaz ve hata döner. */
    #[Test]
    public function a_fulfillment_without_an_order_identity_fails_without_calling(): void
    {
        Http::fake();

        $result = $this->pushFulfillment(orderExternalId: null);

        $this->assertTrue($result->failed());
        Http::assertNothingSent();
    }

    /**
     * ⚠️ KARGO FİRMASI LİSTESİ BOŞ DÖNER ve bu bir eksiklik DEĞİLDİR.
     *
     * Shopify sabit bir kargo firması listesi dayatmaz; `trackingInfo.company`
     * serbest metindir ve tanınan adlarda takip bağlantısını kendisi kurar.
     * Uydurma bir liste dönmek satıcıya olmayan bir kısıt gösterirdi
     * (Woo'daki kararın aynısı).
     */
    #[Test]
    public function the_carrier_list_is_empty_by_design(): void
    {
        $this->assertSame([], $this->adapter()->fetchCarriers());
    }

    /** Yetenek `instanceof` ile okunur. */
    #[Test]
    public function the_adapter_declares_the_fulfillment_capability(): void
    {
        $this->assertInstanceOf(SupportsFulfillment::class, $this->adapter());
    }

    // ────────────────────────────────────────────────────────── yardımcılar

    /** @param array<string, mixed> $overrides */
    private function normalizeFulfillment(array $overrides = []): ?NormalizedOrderEvent
    {
        $message = new InboxMessage;

        $message->event_type = 'fulfillments/create';
        $message->external_event_id = 'evt-f1';
        $message->payload = [...[
            'id' => 7777,
            'order_id' => 9001,
            'status' => 'success',
            'tracking_company' => 'Yurtiçi Kargo',
            'tracking_number' => 'TR123456789',
            'created_at' => '2026-08-25T14:00:00Z',
            'line_items' => [
                ['id' => 555, 'sku' => 'TSH-KIRMIZI-M', 'quantity' => 2, 'price' => '19.90'],
            ],
        ], ...$overrides];

        return ShopifyOrderNormalizer::normalize($message);
    }

    /**
     * Kargo olayını GERÇEK router üzerinden geçirir.
     *
     * Normalizer'ı doğrudan çağırmak yetmez: bu slice'ın kapattığı sınır
     * tam olarak ROUTER DALIYDI ve onu atlayan bir test o dalı sınamazdı.
     */
    private function routeFulfillment(
        Order $order,
        ?int $externalId,
        string $status = 'success',
        string $tracking = 'TR123456789',
        string $topic = 'fulfillments/create',
        string $eventId = 'evt-f1',
    ): void {
        $connection = ChannelConnection::query()
            ->findOrFail($order->channel_connection_id);

        // Gelen hat GERÇEK yolundan kurulur (`IngestInboxMessage`): satırı
        // elle yazmak tekilleştirme ve kiracı alanlarını uydurmak olurdu.
        $message = app(IngestInboxMessage::class)->run(
            connection: $connection,
            source: 'webhook',
            externalEventId: $eventId.'-'.($externalId ?? 'null'),
            eventType: $topic,
            payload: (string) json_encode(array_filter([
                'id' => $externalId,
                'order_id' => (int) $order->external_id,
                'status' => $status,
                'tracking_company' => 'Yurtiçi Kargo',
                'tracking_number' => $tracking,
            ], static fn (mixed $v): bool => $v !== null)),
            signatureValid: true,
        );

        app(OrderEventRouter::class)->route($message);
    }

    /**
     * Kiracı + GERÇEK ALIM YOLUNDAN geçmiş bir sipariş.
     *
     * Order satırı ELLE YAZILMAZ (`OrderScreenTest`'in kuralı): elle
     * yazmak `stock_status` gibi alanları uydurmak demektir ve test gerçek
     * veriyi değil kendi varsayımını doğrular.
     *
     * @return array{0: Tenant, 1: Order}
     */
    private function tenantWithOrder(): array
    {
        $tenant = $this->makeTenant();

        $order = $this->asTenant($tenant, function (): Order {
            $connection = $this->connection();
            $variant = Variant::factory()->create(['sku' => 'TSH-KIRMIZI-M']);

            $warehouseId = (string) Warehouse::query()
                ->where('is_default', true)
                ->value('id');

            (new IngestChannelOrder)->run(
                new IncomingOrder(
                    channelConnectionId: $connection->id,
                    externalId: '9001',
                    lines: [new IncomingOrderLine(
                        externalLineId: '555',
                        sku: 'TSH-KIRMIZI-M',
                        title: 'Tişört',
                        quantity: 2,
                        variantId: $variant->id,
                        unitPrice: '19.90',
                        lineTotal: '39.80',
                    )],
                    grandTotal: '39.80',
                ),
                $warehouseId,
            );

            return Order::query()->where('external_id', '9001')->firstOrFail();
        });

        return [$tenant, $order];
    }

    private function pushFulfillment(
        ?string $orderExternalId = '9001',
    ): AdapterResult {
        $tenant = $this->makeTenant();

        return $this->asTenant($tenant, function () use ($orderExternalId) {
            $connection = $this->connection();

            $order = new Order;
            $order->external_id = $orderExternalId;

            $fulfillment = new Fulfillment;
            $fulfillment->carrier = 'Yurtiçi Kargo';
            $fulfillment->tracking_number = 'TR123456789';
            $fulfillment->setRelation('order', $order);

            return $this->adapterFor($connection)->pushFulfillment($fulfillment);
        });
    }

    private function adapter(): ShopifyAdapter
    {
        $tenant = $this->makeTenant();

        return $this->asTenant($tenant, fn (): ShopifyAdapter => $this->adapterFor($this->connection()));
    }

    private function connection(): ChannelConnection
    {
        $connection = ChannelConnection::factory()->create([
            'channel_type_code' => 'shopify',
            'external_account_id' => 'magaza-'.uniqid().'.myshopify.com',
            'settings' => ['location_gid' => 'gid://shopify/Location/12'],
        ]);

        app(CredentialVault::class)->store($connection, ['access_token' => 'shpat_test']);

        return $connection;
    }

    private function adapterFor(ChannelConnection $connection): ShopifyAdapter
    {
        return new ShopifyAdapter(
            $connection,
            new ChannelHttpClient(
                $connection,
                app(CredentialVault::class),
                app(PayloadRedactor::class),
            ),
        );
    }

    private function makeTenant(): Tenant
    {
        $this->asSystem(fn (): ChannelType => ChannelType::query()->updateOrCreate(
            ['code' => 'shopify'],
            [
                'name' => 'Shopify',
                'kind' => 'storefront',
                'adapter_class' => ShopifyAdapter::class,
                'supports_webhooks' => true,
                'is_active' => false,
            ],
        ));

        return (new CreateTenant)->run(
            name: 'Shopify Kargo '.uniqid(),
            owner: User::factory()->create(),
        );
    }
}
