<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Variant;
use App\Domain\Channels\Adapters\Trendyol\TrendyolAdapter;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Models\ChannelType;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Actions\ApplyMovement;
use App\Domain\Inventory\Enums\MovementType;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Orders\Actions\UpdateFulfillment;
use App\Domain\Orders\Actions\UpdateOrderSnapshot;
use App\Domain\Orders\Enums\OrderEventType;
use App\Domain\Orders\Models\Fulfillment;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\OrderEvent;
use App\Domain\Orders\Support\FulfillmentEvent;
use App\Domain\Orders\Support\OrderSnapshotEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\UuidV7;
use Tests\Concerns\AssertsLedgerIntegrity;
use Tests\TestCase;

/**
 * Sipariş anlık görüntüsü ve kargo — §13 · Faz 3.
 *
 * Mimari Karar Dokümanı v2.2 · §4 · orders + fulfillments, §6 · Yönlendirme,
 * §1 · Karar 24.
 *
 * KAPATILAN BOŞLUK: `OrderEventRouter` bugüne kadar `UPDATED` ve
 * `FULFILLED` olaylarını YALNIZCA LOG'LUYORDU. Faz 2'de sipariş yoklaması
 * yazıldıktan sonra bu boşluk CANLI hale geldi: Trendyol siparişi
 * `Shipped`'a geçtiğinde olay inbox'a yazılıyor, işleniyor ve sessizce
 * düşüyordu — panel siparişi sonsuza kadar "Created" gösterirdi.
 *
 * DEĞİŞMEZ KURAL — İKİSİ DE STOK HAREKETİ ÜRETMEZ (§4):
 *   Mal satışta ZATEN düşülmüştür. Kargo yalnızca teslim durumunu izler;
 *   güncelleme yalnızca anlık görüntüyü tazeler. Hareket üretselerdi aynı
 *   satış iki kez düşülür ve bakiye kalıcı olarak bozulurdu. Bu testler
 *   ledger'ı ÖNCE ve SONRA karşılaştırarak korur.
 *
 * DEĞİŞMEZ KURAL — SİPARİŞ KALEMLERİ GÜNCELLEMEDE DEĞİŞMEZ:
 *   Kalem değişikliği stok demektir ve stok yalnızca iptal/iade
 *   yollarından geçer. Güncelleme kalemlere dokunsaydı, kanalın gönderdiği
 *   bir kalem listesi sessizce stok tutarsızlığı üretirdi.
 */
final class OrderSnapshotAndFulfillmentTest extends TestCase
{
    use AssertsLedgerIntegrity;
    use RefreshDatabase;

    // ─────────────────────────────────────────── sipariş anlık görüntüsü

    /** Durum ve finansal durum tazelenir. */
    #[Test]
    public function an_update_refreshes_the_order_status(): void
    {
        [$tenant, $order] = $this->orderWithStock();

        $this->asTenant($tenant, fn () => app(UpdateOrderSnapshot::class)->run(
            new OrderSnapshotEvent(
                orderId: $order->id,
                externalRef: 'TY-1:Shipped',
                status: 'Shipped',
                financialStatus: 'paid',
                payload: ['type' => 'updated'],
            ),
        ));

        // HAM SATIR okunur: Eloquent kimlik haritası bayat nesne verebilir.
        $row = $this->rawOrder($tenant, $order->id);

        $this->assertSame('Shipped', $row->status);
        $this->assertSame('paid', $row->financial_status);
    }

    /**
     * GÜNCELLEME STOK HAREKETİ ÜRETMEZ — §4'ün açık kuralı.
     *
     * Mal satışta zaten düşülmüştür. Hareket üretseydi aynı satış iki kez
     * düşülür ve bakiye kalıcı bozulurdu.
     */
    #[Test]
    public function an_update_never_touches_stock(): void
    {
        [$tenant, $order, $variant] = $this->orderWithStock();

        $before = $this->movementCount($tenant);
        $available = $this->availableFor($tenant, $variant);

        $this->asTenant($tenant, fn () => app(UpdateOrderSnapshot::class)->run(
            new OrderSnapshotEvent(
                orderId: $order->id,
                externalRef: 'TY-1:Shipped',
                status: 'Shipped',
                payload: ['type' => 'updated'],
            ),
        ));

        $this->assertSame($before, $this->movementCount($tenant), 'Hareket sayısı DEĞİŞMEMELİ.');
        $this->assertSame($available, $this->availableFor($tenant, $variant));

        $this->assertLedgerMatchesProjection(
            $tenant->id,
            $this->warehouse($tenant)->id,
            $variant->id,
        );
    }

    /**
     * KALEMLER GÜNCELLEMEDE DEĞİŞMEZ.
     *
     * Kalem değişikliği stok demektir ve stok yalnızca iptal/iade
     * yollarından geçer.
     */
    #[Test]
    public function an_update_does_not_alter_order_lines(): void
    {
        [$tenant, $order] = $this->orderWithStock();

        $before = $this->asTenant($tenant, fn (): array => DB::table('order_lines')
            ->where('order_id', $order->id)
            ->orderBy('id')
            ->get(['id', 'quantity', 'variant_id'])
            ->map(fn ($r): array => (array) $r)
            ->all());

        $this->asTenant($tenant, fn () => app(UpdateOrderSnapshot::class)->run(
            new OrderSnapshotEvent(
                orderId: $order->id,
                externalRef: 'TY-1:Shipped',
                status: 'Shipped',
                payload: ['type' => 'updated', 'lines' => []],
            ),
        ));

        $after = $this->asTenant($tenant, fn (): array => DB::table('order_lines')
            ->where('order_id', $order->id)
            ->orderBy('id')
            ->get(['id', 'quantity', 'variant_id'])
            ->map(fn ($r): array => (array) $r)
            ->all());

        $this->assertSame($before, $after, 'Güncelleme kalemlere DOKUNMAMALI.');
    }

    /**
     * AYNI GÜNCELLEME İKİ KEZ GELİRSE TEK OLAY YAZILIR.
     *
     * Yoklama pencere örtüşmesi nedeniyle aynı durumu tekrar tekrar
     * görür. Her görüşte yeni bir olay satırı yazılsaydı `order_events`
     * denetim kaydı olarak işe yaramaz hale gelirdi.
     */
    #[Test]
    public function the_same_update_is_recorded_once(): void
    {
        [$tenant, $order] = $this->orderWithStock();

        $event = new OrderSnapshotEvent(
            orderId: $order->id,
            externalRef: 'TY-1:Shipped',
            status: 'Shipped',
            payload: ['type' => 'updated'],
        );

        $this->asTenant($tenant, function () use ($event): void {
            app(UpdateOrderSnapshot::class)->run($event);
            app(UpdateOrderSnapshot::class)->run($event);
        });

        $events = $this->asTenant($tenant, fn (): int => OrderEvent::query()
            ->where('order_id', $order->id)
            ->where('type', OrderEventType::UPDATED->value)
            ->count());

        $this->assertSame(1, $events, 'Aynı güncelleme ikinci olay satırı AÇMAZ.');
    }

    /**
     * BAYAT GÜNCELLEME YENİ DURUMU EZMEZ.
     *
     * Idempotency kapısının ASIL değeri burada görünür: aynı `external_ref`
     * ikinci kez geldiğinde olay ZATEN uygulanmıştır ve tekrarı bir
     * "yeniden uygula" değil, bir KOPYADIR.
     *
     * Yoklama pencere örtüşmesi nedeniyle `TY-1:Shipped` olayını tur tur
     * yeniden görür. Kapı olmasaydı araya giren `Delivered` her turda
     * `Shipped`'a geri EZİLİRDİ — sipariş kalıcı olarak geri sayardı ve
     * satıcı teslim edilmiş siparişi "kargoda" görürdü.
     */
    #[Test]
    public function a_replayed_update_does_not_overwrite_a_newer_status(): void
    {
        [$tenant, $order] = $this->orderWithStock();

        $shipped = new OrderSnapshotEvent(
            orderId: $order->id,
            externalRef: 'TY-1:Shipped',
            status: 'Shipped',
            payload: ['type' => 'updated'],
        );

        $this->asTenant($tenant, function () use ($shipped, $order): void {
            app(UpdateOrderSnapshot::class)->run($shipped);

            // Sipariş ilerledi: teslim edildi.
            app(UpdateOrderSnapshot::class)->run(new OrderSnapshotEvent(
                orderId: $order->id,
                externalRef: 'TY-1:Delivered',
                status: 'Delivered',
                payload: ['type' => 'updated'],
            ));

            // Yoklama eski olayı TEKRAR gördü.
            app(UpdateOrderSnapshot::class)->run($shipped);
        });

        $this->assertSame(
            'Delivered',
            $this->rawOrder($tenant, $order->id)->status,
            'Bayat tekrar yeni durumu GERİ ALMAMALI.',
        );
    }

    /**
     * BOŞ DURUM MEVCUT DEĞERİ EZMEZ.
     *
     * Kanal bazı olaylarda alanı hiç göndermez. Boş dize yazılsaydı panel
     * siparişi durumsuz gösterir ve satıcı neyin değiştiğini anlayamazdı —
     * üstelik bilgi GERİ ALINAMAZ, çünkü eski durum kaybolmuştur.
     */
    #[Test]
    public function a_missing_status_does_not_erase_the_existing_one(): void
    {
        [$tenant, $order] = $this->orderWithStock();

        $this->asTenant($tenant, fn () => app(UpdateOrderSnapshot::class)->run(
            new OrderSnapshotEvent(
                orderId: $order->id,
                externalRef: 'TY-1:Shipped',
                status: 'Shipped',
                payload: ['type' => 'updated'],
            ),
        ));

        // İkinci olay durumu HİÇ taşımıyor.
        $this->asTenant($tenant, fn () => app(UpdateOrderSnapshot::class)->run(
            new OrderSnapshotEvent(
                orderId: $order->id,
                externalRef: 'TY-1:Note',
                status: null,
                payload: ['type' => 'updated'],
            ),
        ));

        $this->assertSame('Shipped', $this->rawOrder($tenant, $order->id)->status);
    }

    // ────────────────────────────────────────────────────────── kargo

    /** Kargo bildirimi yazılır: taşıyıcı, takip numarası, durum. */
    #[Test]
    public function a_fulfillment_is_recorded(): void
    {
        [$tenant, $order] = $this->orderWithStock();

        $this->asTenant($tenant, fn () => app(UpdateFulfillment::class)->run(
            new FulfillmentEvent(
                orderId: $order->id,
                externalId: 'PKG-1',
                carrier: 'Yurtiçi',
                trackingNumber: 'YK123456',
                status: 'shipped',
                shippedAt: now()->toDateTimeImmutable(),
                payload: ['type' => 'fulfilled'],
            ),
        ));

        $fulfillment = $this->asTenant($tenant, fn (): Fulfillment => Fulfillment::query()
            ->where('order_id', $order->id)
            ->firstOrFail());

        $this->assertSame('PKG-1', $fulfillment->external_id);
        $this->assertSame('Yurtiçi', $fulfillment->carrier);
        $this->assertSame('YK123456', $fulfillment->tracking_number);
        $this->assertSame('shipped', $fulfillment->status);
        $this->assertNotNull($fulfillment->shipped_at);
    }

    /**
     * KARGO STOK HAREKETİ ÜRETMEZ — §4'ün açık kuralı.
     *
     * Mal satışta zaten düşülmüştür; kargo yalnızca teslim durumunu izler.
     */
    #[Test]
    public function a_fulfillment_never_touches_stock(): void
    {
        [$tenant, $order, $variant] = $this->orderWithStock();

        $before = $this->movementCount($tenant);
        $available = $this->availableFor($tenant, $variant);

        $this->asTenant($tenant, fn () => app(UpdateFulfillment::class)->run(
            new FulfillmentEvent(
                orderId: $order->id,
                externalId: 'PKG-1',
                status: 'shipped',
                payload: ['type' => 'fulfilled'],
            ),
        ));

        $this->assertSame($before, $this->movementCount($tenant), 'Hareket sayısı DEĞİŞMEMELİ.');
        $this->assertSame($available, $this->availableFor($tenant, $variant));

        $this->assertLedgerMatchesProjection(
            $tenant->id,
            $this->warehouse($tenant)->id,
            $variant->id,
        );
    }

    /**
     * AYNI PAKET İKİNCİ SATIR AÇMAZ — DURUMU İLERLETİR.
     *
     * `(order_id, external_id)` tekildir. Paket önce `shipped`, sonra
     * `delivered` olur; ikinci olay YENİ satır açsaydı panelde tek kargo
     * iki kez görünür ve hangisinin güncel olduğu belirsiz kalırdı.
     */
    #[Test]
    public function the_same_package_advances_instead_of_duplicating(): void
    {
        [$tenant, $order] = $this->orderWithStock();

        $this->asTenant($tenant, function () use ($order): void {
            app(UpdateFulfillment::class)->run(new FulfillmentEvent(
                orderId: $order->id,
                externalId: 'PKG-1',
                status: 'shipped',
                shippedAt: now()->toDateTimeImmutable(),
                payload: ['type' => 'fulfilled'],
            ));

            app(UpdateFulfillment::class)->run(new FulfillmentEvent(
                orderId: $order->id,
                externalId: 'PKG-1',
                status: 'delivered',
                deliveredAt: now()->toDateTimeImmutable(),
                payload: ['type' => 'fulfilled'],
            ));
        });

        $rows = $this->asTenant($tenant, fn () => Fulfillment::query()
            ->where('order_id', $order->id)
            ->get());

        $this->assertCount(1, $rows, 'Aynı paket ikinci satır AÇMAZ.');
        $this->assertSame('delivered', $rows[0]->status);
        $this->assertNotNull($rows[0]->delivered_at);
        // İlk adımın bilgisi KAYBOLMAZ.
        $this->assertNotNull($rows[0]->shipped_at, 'Kargoya veriliş anı korunmalı.');
    }

    /**
     * ÇOK PAKETLİ SİPARİŞ AYRI SATIRLAR TAŞIR.
     *
     * Trendyol bir siparişi birden çok kargo paketine böler ve her paket
     * kendi durumunu taşır. Tek satıra sıkıştırılsaydı ikinci paket
     * birincinin durumunu ezer ve satıcı yarısı teslim olmuş bir siparişi
     * "tamamen teslim" sanırdı.
     */
    #[Test]
    public function multiple_packages_are_tracked_separately(): void
    {
        [$tenant, $order] = $this->orderWithStock();

        $this->asTenant($tenant, function () use ($order): void {
            app(UpdateFulfillment::class)->run(new FulfillmentEvent(
                orderId: $order->id, externalId: 'PKG-1', status: 'delivered',
                payload: ['type' => 'fulfilled'],
            ));

            app(UpdateFulfillment::class)->run(new FulfillmentEvent(
                orderId: $order->id, externalId: 'PKG-2', status: 'shipped',
                payload: ['type' => 'fulfilled'],
            ));
        });

        $rows = $this->asTenant($tenant, fn () => Fulfillment::query()
            ->where('order_id', $order->id)
            ->orderBy('external_id')
            ->get());

        $this->assertCount(2, $rows);
        $this->assertSame('delivered', $rows[0]->status);
        $this->assertSame('shipped', $rows[1]->status);
    }

    /**
     * PAKET KİMLİĞİ OLMAYAN KARGO DA KAYDEDİLİR.
     *
     * Kanal kimlik vermeyebilir. Bildirim düşürülseydi kargo bilgisi
     * tamamen kaybolurdu; tekillik kısıtı NULL'ları kapsamaz, bu yüzden
     * satır yazılabilir.
     */
    #[Test]
    public function a_fulfillment_without_an_external_id_is_still_recorded(): void
    {
        [$tenant, $order] = $this->orderWithStock();

        $this->asTenant($tenant, fn () => app(UpdateFulfillment::class)->run(
            new FulfillmentEvent(
                orderId: $order->id,
                externalId: null,
                trackingNumber: 'YK999',
                status: 'shipped',
                payload: ['type' => 'fulfilled'],
            ),
        ));

        $rows = $this->asTenant($tenant, fn (): int => Fulfillment::query()
            ->where('order_id', $order->id)
            ->count());

        $this->assertSame(1, $rows);
    }

    /** Kargo olayı da `order_events` denetim kaydına düşer. */
    #[Test]
    public function a_fulfillment_writes_an_audit_event(): void
    {
        [$tenant, $order] = $this->orderWithStock();

        $this->asTenant($tenant, fn () => app(UpdateFulfillment::class)->run(
            new FulfillmentEvent(
                orderId: $order->id,
                externalId: 'PKG-1',
                status: 'shipped',
                payload: ['type' => 'fulfilled'],
            ),
        ));

        $events = $this->asTenant($tenant, fn (): int => OrderEvent::query()
            ->where('order_id', $order->id)
            ->where('type', OrderEventType::FULFILLED->value)
            ->count());

        $this->assertSame(1, $events);
    }

    // ──────────────────────────────────────────────────────── yardımcı

    private function rawOrder(Tenant $tenant, string $orderId): object
    {
        return $this->asTenant($tenant, fn () => DB::table('orders')
            ->where('tenant_id', $tenant->id)
            ->where('id', $orderId)
            ->first());
    }

    private function movementCount(Tenant $tenant): int
    {
        return (int) $this->asTenant($tenant, fn () => DB::table('inventory_movements')
            ->where('tenant_id', $tenant->id)
            ->count());
    }

    private function availableFor(Tenant $tenant, Variant $variant): int
    {
        return (int) $this->asTenant($tenant, fn () => DB::table('inventory_levels')
            ->where('tenant_id', $tenant->id)
            ->where('variant_id', $variant->id)
            ->value('available'));
    }

    private function warehouse(Tenant $tenant): Warehouse
    {
        return $this->asTenant($tenant, fn (): Warehouse => Warehouse::query()
            ->where('is_default', true)
            ->firstOrFail());
    }

    /**
     * Stoğu düşülmüş gerçek bir sipariş kurar.
     *
     * @return array{0: Tenant, 1: Order, 2: Variant}
     */
    private function orderWithStock(): array
    {
        $user = User::factory()->create();
        $tenant = (new CreateTenant)->run(name: 'Kargo '.uniqid(), owner: $user);

        $this->asSystem(fn () => ChannelType::query()->updateOrCreate(
            ['code' => 'trendyol'],
            [
                'name' => 'Trendyol',
                'kind' => 'marketplace',
                'adapter_class' => TrendyolAdapter::class,
                'is_active' => true,
            ],
        ));

        return $this->asTenant($tenant, function () use ($tenant): array {
            $connection = ChannelConnection::factory()->create([
                'channel_type_code' => 'trendyol',
                'external_account_id' => 'acc-'.uniqid(),
                'status' => 'active',
            ]);

            $product = Product::factory()->create();
            $variant = Variant::factory()->create([
                'product_id' => $product->id,
                'sku' => 'BARKOD-A',
            ]);

            // Açılış stoğu LEDGER üzerinden.
            app(ApplyMovement::class)->run(
                warehouseId: $this->warehouse($tenant)->id,
                variantId: $variant->id,
                type: MovementType::IMPORT,
                quantity: 10,
                idempotencyKey: 'import:'.$variant->id,
                sourceType: 'test',
            );

            $order = Order::query()->create([
                'tenant_id' => $tenant->id,
                'channel_connection_id' => $connection->id,
                'external_id' => 'TY-1',
                'status' => 'Created',
                'currency' => 'TRY',
                'subtotal' => '100.00',
                'shipping_total' => '0.00',
                'tax_total' => '0.00',
                'grand_total' => '100.00',
            ]);

            DB::table('order_lines')->insert([
                'id' => UuidV7::generate(),
                'tenant_id' => $tenant->id,
                'order_id' => $order->id,
                'variant_id' => $variant->id,
                'external_line_id' => '9001',
                'sku' => 'BARKOD-A',
                'title' => 'Tişört',
                'quantity' => 2,
                'unit_price' => '50.00',
                'line_total' => '100.00',
                'stock_status' => 'applied',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return [$tenant, $order, $variant];
        });
    }
}
