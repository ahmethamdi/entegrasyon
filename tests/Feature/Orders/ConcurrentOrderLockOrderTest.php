<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Domain\Catalog\Models\Variant;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Models\ChannelType;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Actions\ApplyMovement;
use App\Domain\Inventory\Actions\LockInventoryRows;
use App\Domain\Inventory\Enums\MovementType;
use App\Domain\Inventory\Support\MovementKey;
use App\Domain\Orders\Actions\ApplyOrderReturn;
use App\Domain\Orders\Actions\IngestChannelOrder;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Support\IncomingOrder;
use App\Domain\Orders\Support\IncomingOrderLine;
use App\Domain\Orders\Support\ReturnedLine;
use App\Domain\Orders\Support\ReturnEvent;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\UuidV7;
use Tests\Concerns\AssertsLedgerIntegrity;
use Tests\TestCase;

/**
 * P0 testi T9 — çok kalemli iade ile eşzamanlı sipariş, deadlock yok.
 *
 * Mimari Karar Dokümanı v2.2 · §18 · T9 (faz 1.6), §5 · Kilit stratejisi.
 *
 * SENARYO: iade olayı varyantları TERS sırada listeler, sipariş DÜZ sırada.
 * İki yol da LockInventoryRows kullandığı ve o da ORDER BY variant_id
 * uyguladığı için GERÇEK kilit sırası aynı olur ve ABBA deadlock oluşmaz.
 *
 * NEDEN RefreshDatabase DEĞİL: iki AYRI transaction'ın gerçekten çekişmesi
 * gerekiyor; tek transaction içinde deadlock hiç oluşmaz ve test yanlış
 * yeşile döner.
 */
final class ConcurrentOrderLockOrderTest extends TestCase
{
    use AssertsLedgerIntegrity;
    use DatabaseTruncation;

    /**
     * Bu sınıf GERÇEKTEN COMMIT eder; artıklar sonraki testlere sızmasın.
     *
     * DatabaseTruncation kendi setUp'ında boşaltır, tearDown'da değil.
     */
    protected function tearDown(): void
    {
        $this->truncateDatabaseTables();

        parent::tearDown();
    }

    /**
     * T9 — ters sıralı iade ile düz sıralı sipariş deadlock üretmez.
     *
     * A: iade [c, b, a] sırasıyla ister
     * B: sipariş [a, b, c] sırasıyla ister
     *
     * İkisi de en düşük variant_id'den başlayarak kilitlerse çekişme
     * serileşir; sıralama olmasaydı A c'yi B a'yı tutar ve birbirini
     * beklerdi (PostgreSQL 40P01).
     */
    #[Test]
    public function multiline_return_and_concurrent_order_do_not_deadlock(): void
    {
        [$tenant, $connection, $warehouseId, $variantIds] = $this->makeContext(stock: 10);

        sort($variantIds);
        [$a, $b, $c] = $variantIds;

        // Önce bir sipariş alınır ki iade edilecek satırlar var olsun.
        $order = $this->ingestOrder($tenant, $connection, $warehouseId, [$a, $b, $c], quantity: 2);

        $exceptions = [];

        // --- A: iade, TERS sırada ister --------------------------------------
        // Kilidi alır ve tutar; B aynı anda düz sırada girmeye çalışır.
        DB::beginTransaction();

        try {
            TenantContext::runFor($tenant->id, function () use ($warehouseId, $c, $b, $a): void {
                (new LockInventoryRows)->run($warehouseId, [$c, $b, $a]);
            });

            // --- B: ayrı bağlantı, DÜZ sırada, kısa lock_timeout -------------
            // A tüm satırları tuttuğu için B beklemeli ve zaman aşımına
            // uğramalı — ama DEADLOCK (40P01) almamalı.
            $connectionB = $this->secondConnection();
            $connectionB->statement("SET lock_timeout = '400ms'");
            $connectionB->beginTransaction();

            try {
                $connectionB->select(
                    'SELECT variant_id FROM inventory_levels
                      WHERE tenant_id = ? AND warehouse_id = ? AND variant_id = ANY(?)
                      ORDER BY variant_id
                        FOR UPDATE',
                    [$tenant->id, $warehouseId, '{'.implode(',', [$a, $b, $c]).'}'],
                );
            } catch (\Throwable $e) {
                $exceptions[] = $e;
            }

            $connectionB->rollBack();
        } finally {
            DB::rollBack();
        }

        // Zaman aşımı BEKLENİR (kilit gerçekten tutuluyor), deadlock BEKLENMEZ.
        $this->assertNotEmpty($exceptions, 'B hiç bloklanmadı — kilit alınmıyor olabilir.');

        $deadlocks = array_filter(
            $exceptions,
            static fn (\Throwable $e): bool => str_contains($e->getMessage(), '40P01')
                || str_contains(strtolower($e->getMessage()), 'deadlock'),
        );

        $this->assertEmpty($deadlocks, 'Deadlock oluştu — kilit sırası bozuk.');

        // --- Gerçek iş: iade ve sipariş sırayla tamamlanır -------------------
        $this->applyReturn($tenant, $order, [$c, $b, $a], quantity: 1);

        foreach ([$a, $b, $c] as $variantId) {
            $this->assertLedgerMatchesProjection($tenant->id, $warehouseId, $variantId);
        }
    }

    /**
     * Ters sıralı iade tek başına da doğru sonuç üretir.
     *
     * Kilit sırası bir eniyileme değil doğruluk kuralı olduğu için, sonucun
     * da doğru olduğu ayrıca kanıtlanır.
     */
    #[Test]
    public function multiline_return_restores_stock_for_every_line(): void
    {
        [$tenant, $connection, $warehouseId, $variantIds] = $this->makeContext(stock: 5);

        sort($variantIds);
        [$a, $b, $c] = $variantIds;

        $order = $this->ingestOrder($tenant, $connection, $warehouseId, [$a, $b, $c], quantity: 3);

        // Satış sonrası: 5 − 3 = 2
        foreach ([$a, $b, $c] as $variantId) {
            $this->assertSame(2, $this->onHand($tenant, $warehouseId, $variantId));
        }

        // TERS sırada iade — her kalem kendi hareketini almalı.
        $this->applyReturn($tenant, $order, [$c, $b, $a], quantity: 3);

        foreach ([$a, $b, $c] as $variantId) {
            $this->assertSame(
                5,
                $this->onHand($tenant, $warehouseId, $variantId),
                'Çok kalemli iadede bir kalem yutulmuş — anahtar satır kimliği taşımıyor olabilir.',
            );

            $this->assertLedgerMatchesProjection($tenant->id, $warehouseId, $variantId);
        }
    }

    // ---------------------------------------------------------------- yardımcılar

    private function secondConnection(): Connection
    {
        $name = 'pgsql_concurrent_orders';

        config(['database.connections.'.$name => config('database.connections.pgsql')]);

        DB::purge($name);

        return DB::connection($name);
    }

    /** @return array{0: Tenant, 1: ChannelConnection, 2: string, 3: list<string>} */
    private function makeContext(int $stock): array
    {
        $tenant = (new CreateTenant)->run(
            name: 'T9 '.uniqid(),
            owner: User::factory()->create(),
        );

        $warehouseId = TenantContext::runFor($tenant->id, fn () => $tenant->defaultWarehouse()->id);

        TenantContext::runAsSystem(fn () => ChannelType::query()->firstOrCreate(
            ['code' => 'woocommerce'],
            [
                'name' => 'WooCommerce',
                'kind' => 'storefront',
                'adapter_class' => 'App\\Domain\\Channels\\Adapters\\WooCommerceAdapter',
                'is_active' => true,
            ],
        ));

        $connection = TenantContext::runFor(
            $tenant->id,
            fn () => ChannelConnection::factory()->create(['channel_type_code' => 'woocommerce']),
        );

        $variantIds = TenantContext::runFor($tenant->id, fn () => collect(range(1, 3))
            ->map(fn () => Variant::factory()->create()->id)
            ->all());

        // Açılış stoğu LEDGER üzerinden girer.
        foreach ($variantIds as $variantId) {
            TenantContext::runFor($tenant->id, fn () => DB::transaction(function () use ($warehouseId, $variantId, $stock): void {
                (new LockInventoryRows)->run($warehouseId, [$variantId]);

                (new ApplyMovement)->run(
                    warehouseId: $warehouseId,
                    variantId: $variantId,
                    type: MovementType::IMPORT,
                    quantity: $stock,
                    idempotencyKey: MovementKey::import((string) new UuidV7),
                    sourceType: 'import_row',
                );
            }));
        }

        return [$tenant, $connection, $warehouseId, $variantIds];
    }

    /** @param list<string> $variantIds */
    private function ingestOrder(
        Tenant $tenant,
        ChannelConnection $connection,
        string $warehouseId,
        array $variantIds,
        int $quantity,
    ): Order {
        $lines = [];

        foreach ($variantIds as $index => $variantId) {
            $lines[] = new IncomingOrderLine(
                externalLineId: 'line-'.$index,
                sku: 'SKU-'.$index,
                title: 'Ürün '.$index,
                quantity: $quantity,
                variantId: $variantId,
            );
        }

        $incoming = new IncomingOrder(
            channelConnectionId: $connection->id,
            externalId: 'ORD-'.uniqid(),
            lines: $lines,
            placedAt: now(),
        );

        return TenantContext::runFor(
            $tenant->id,
            fn () => (new IngestChannelOrder)->run($incoming, $warehouseId),
        );
    }

    /** @param list<string> $variantIds İade sırası — kasten ters verilir. */
    private function applyReturn(
        Tenant $tenant,
        Order $order,
        array $variantIds,
        int $quantity,
    ): void {
        $returned = [];

        foreach ($variantIds as $variantId) {
            $line = TenantContext::runFor($tenant->id, fn () => $order->lines()
                ->where('variant_id', $variantId)
                ->firstOrFail());

            $returned[] = new ReturnedLine(
                orderLineId: $line->id,
                quantity: $quantity,
            );
        }

        $event = new ReturnEvent(
            orderId: $order->id,
            externalRef: 'RET-'.uniqid(),
            lines: $returned,
        );

        TenantContext::runFor($tenant->id, fn () => (new ApplyOrderReturn)->run($event));
    }

    private function onHand(Tenant $tenant, string $warehouseId, string $variantId): int
    {
        return (int) TenantContext::runAsSystem(fn () => DB::table('inventory_levels')
            ->where('tenant_id', $tenant->id)
            ->where('warehouse_id', $warehouseId)
            ->where('variant_id', $variantId)
            ->value('on_hand'));
    }
}
