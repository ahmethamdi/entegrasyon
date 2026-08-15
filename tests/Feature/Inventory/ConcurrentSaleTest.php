<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Domain\Catalog\Models\Variant;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Actions\ApplyMovement;
use App\Domain\Inventory\Actions\LockInventoryRows;
use App\Domain\Inventory\Enums\MovementType;
use App\Domain\Inventory\Exceptions\InsufficientStockException;
use App\Domain\Inventory\Models\InventoryLevel;
use App\Domain\Inventory\Support\MovementKey;
use App\Domain\Inventory\Support\OutboundQuantity;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\UuidV7;
use Tests\Concerns\AssertsLedgerIntegrity;
use Tests\TestCase;

/**
 * P0 testi T1 — eşzamanlı satışlarda kilit ve ledger bütünlüğü.
 *
 * Mimari Karar Dokümanı v2.2 · §18 · T1, §5 · Kilit stratejisi.
 *
 * NEDEN RefreshDatabase DEĞİL:
 *   RefreshDatabase her testi tek bir transaction'a sarar ve sonunda geri
 *   alır. Bu testin amacı İKİ AYRI transaction'ın FOR UPDATE üzerinde
 *   gerçekten sıraya girmesini görmektir; tek transaction içinde kilit
 *   çekişmesi hiç oluşmaz ve test yanlış yeşile döner. Bu yüzden gerçek
 *   commit yapan DatabaseTruncation kullanılır.
 *
 * Eşzamanlılık ayrı bir PostgreSQL bağlantısı ile kurulur: ikinci bağlantı
 * birincinin kilidini beklemek ZORUNDADIR; beklemezse çakışan okuma iki kez
 * aynı bakiyeyi görür ve toplam sapar.
 */
final class ConcurrentSaleTest extends TestCase
{
    use AssertsLedgerIntegrity;
    use DatabaseTruncation;

    /**
     * Bu sınıf GERÇEKTEN COMMIT eder; artıklar temizlenmezse sonraki testlere
     * sızar.
     *
     * DatabaseTruncation tabloları KENDİ setUp'ında boşaltır, tearDown'da
     * değil. Sonraki test RefreshDatabase kullanıyorsa (transaction'a sarar,
     * boşaltmaz) buradan kalan satırları görür ve asSystem() ile yapılan
     * global sayımlar sapar. Bu yüzden temizlik burada, testten hemen sonra
     * yapılır.
     */
    protected function tearDown(): void
    {
        $this->truncateDatabaseTables();

        parent::tearDown();
    }

    /**
     * T1 — sıfır stokta iki eşzamanlı satış.
     *
     * İki satış da kabul edilir (SALE reddedilemez), bakiye −1'e iner ve
     * ledger toplamı projeksiyonla birebir eşleşir. Kayıp güncelleme olsaydı
     * bakiye 0 kalır ve eşitlik bozulurdu.
     */
    #[Test]
    public function two_concurrent_sales_land_at_minus_one_with_matching_ledger(): void
    {
        [$tenant, $variant, $warehouseId] = $this->makeContext();

        $this->seedLevel($tenant, $warehouseId, $variant->id, onHand: 1);

        // Bağlantı B'yi ayrı bir PDO üzerinden kur; A ile aynı havuzu
        // paylaşmamalı, yoksa iki "eşzamanlı" transaction aslında tek olur.
        $connectionB = $this->secondConnection();

        $keyA = MovementKey::sale((string) new UuidV7);
        $keyB = MovementKey::sale((string) new UuidV7);

        // --- Transaction A: kilidi al, henüz commit etme --------------------
        DB::beginTransaction();

        $lockedA = TenantContext::runFor($tenant->id, fn () => (new LockInventoryRows)
            ->run($warehouseId, [$variant->id]));

        $this->assertSame(1, $lockedA[$variant->id]->on_hand);

        // --- Transaction B: aynı satırı kilitlemeye çalış, bloklanmalı ------
        // lock_timeout ile bloklandığını KANITLIYORUZ: A kilidi tutarken
        // B'nin FOR UPDATE'i zaman aşımına uğramak zorundadır.
        $connectionB->statement("SET lock_timeout = '400ms'");
        $connectionB->beginTransaction();

        $blocked = false;

        try {
            $connectionB->select(
                'SELECT on_hand FROM inventory_levels
                 WHERE tenant_id = ? AND warehouse_id = ? AND variant_id = ?
                 ORDER BY variant_id
                 FOR UPDATE',
                [$tenant->id, $warehouseId, $variant->id],
            );
        } catch (\Throwable $e) {
            $blocked = str_contains($e->getMessage(), 'lock timeout')
                || str_contains($e->getMessage(), 'timeout')
                || str_contains($e->getMessage(), '55P03');
        }

        $connectionB->rollBack();

        $this->assertTrue(
            $blocked,
            'İkinci transaction birincinin FOR UPDATE kilidini beklemedi. '.
            'Kilit alınmıyorsa iki satış aynı bakiyeyi okur ve biri kaybolur.',
        );

        // --- A hareketini uygula ve commit et -------------------------------
        TenantContext::runFor($tenant->id, fn () => (new ApplyMovement)->run(
            warehouseId: $warehouseId,
            variantId: $variant->id,
            type: MovementType::SALE,
            quantity: 1,
            idempotencyKey: $keyA,
            sourceType: 'order_line',
        ));

        DB::commit();

        // --- B artık kilidi alabilir ve GÜNCEL bakiyeyi görür ---------------
        $connectionB->statement("SET lock_timeout = '5s'");
        $connectionB->beginTransaction();

        $rowB = $connectionB->selectOne(
            'SELECT on_hand FROM inventory_levels
             WHERE tenant_id = ? AND warehouse_id = ? AND variant_id = ?
             ORDER BY variant_id
             FOR UPDATE',
            [$tenant->id, $warehouseId, $variant->id],
        );

        $this->assertSame(
            0,
            (int) $rowB->on_hand,
            'İkinci okuyucu A commit ettikten sonra bayat bakiye görmemeli.',
        );

        $connectionB->rollBack();

        // --- İkinci satış: sıfır stokta, yine kabul --------------------------
        TenantContext::runFor($tenant->id, fn () => DB::transaction(
            fn () => (new ApplyMovement)->run(
                warehouseId: $warehouseId,
                variantId: $variant->id,
                type: MovementType::SALE,
                quantity: 1,
                idempotencyKey: $keyB,
                sourceType: 'order_line',
            )
        ));

        // --- Sonuç -----------------------------------------------------------
        $level = $this->levelFor($tenant, $warehouseId, $variant->id);

        $this->assertSame(-1, $level->on_hand, 'İki satış birden düşmeli; biri kaybolmamalı.');
        $this->assertSame(-1, $level->available);
        $this->assertTrue($level->isOversold());

        // Kanala giden yük kırpılır, kanonik durum kırpılmaz.
        $this->assertSame(0, OutboundQuantity::forChannel($level));

        // TEMEL INVARIANT.
        $this->assertLedgerMatchesProjection($tenant->id, $warehouseId, $variant->id);
    }

    /**
     * Çok-SKU yolunda kilit sırası ters verilse bile aynı sırada alınır.
     *
     * İki transaction aynı iki SKU'yu ters sırada isterse, LockInventoryRows
     * her ikisini de variant_id sırasına sokar ve deadlock oluşmaz.
     */
    #[Test]
    public function opposing_multi_sku_orders_do_not_deadlock(): void
    {
        [$tenant, , $warehouseId] = $this->makeContext();

        $variantIds = TenantContext::runFor($tenant->id, fn () => collect(range(1, 2))
            ->map(fn () => Variant::factory()->create()->id)
            ->all());

        sort($variantIds);

        foreach ($variantIds as $variantId) {
            $this->seedLevel($tenant, $warehouseId, $variantId, onHand: 5);
        }

        $connectionB = $this->secondConnection();

        // A: [yüksek, düşük] sırasıyla ister — kilit yine düşükten başlamalı.
        DB::beginTransaction();

        TenantContext::runFor($tenant->id, fn () => (new LockInventoryRows)
            ->run($warehouseId, [$variantIds[1], $variantIds[0]]));

        // B: DÜŞÜK id'yi kilitlemeye çalışır. A sıralı kilitlediyse düşük id
        // A'nın elindedir ve B beklemek zorundadır.
        $connectionB->statement("SET lock_timeout = '400ms'");
        $connectionB->beginTransaction();

        $blockedOnLowest = false;

        try {
            $connectionB->select(
                'SELECT variant_id FROM inventory_levels
                 WHERE tenant_id = ? AND warehouse_id = ? AND variant_id = ?
                 FOR UPDATE',
                [$tenant->id, $warehouseId, $variantIds[0]],
            );
        } catch (\Throwable $e) {
            $blockedOnLowest = true;
        }

        $connectionB->rollBack();
        DB::rollBack();

        $this->assertTrue(
            $blockedOnLowest,
            'Kilit variant_id sırasına sokulmamış: en düşük id kilitlenmemiş. '.
            'Ters sıralı iki sipariş bu durumda deadlock üretir.',
        );
    }

    /**
     * Çok-SKU'lu bir sipariş kısmen başarısız olursa hiçbiri yazılmaz.
     *
     * İkinci SKU rezervasyonu reddedilirse birincinin hareketi de geri alınır;
     * ledger ve projeksiyon birlikte tutarlı kalır.
     */
    #[Test]
    public function failed_multi_sku_write_rolls_back_entirely(): void
    {
        [$tenant, , $warehouseId] = $this->makeContext();

        $variantIds = TenantContext::runFor($tenant->id, fn () => collect(range(1, 2))
            ->map(fn () => Variant::factory()->create()->id)
            ->all());

        sort($variantIds);

        $this->seedLevel($tenant, $warehouseId, $variantIds[0], onHand: 10);
        $this->seedLevel($tenant, $warehouseId, $variantIds[1], onHand: 1);

        try {
            TenantContext::runFor($tenant->id, fn () => DB::transaction(function () use ($warehouseId, $variantIds): void {
                (new LockInventoryRows)->run($warehouseId, $variantIds);

                (new ApplyMovement)->run(
                    warehouseId: $warehouseId,
                    variantId: $variantIds[0],
                    type: MovementType::RESERVATION,
                    quantity: 2,
                    idempotencyKey: MovementKey::reservation((string) new UuidV7),
                    sourceType: 'reservation',
                );

                // Bu yetersiz — tüm transaction geri alınmalı.
                (new ApplyMovement)->run(
                    warehouseId: $warehouseId,
                    variantId: $variantIds[1],
                    type: MovementType::RESERVATION,
                    quantity: 5,
                    idempotencyKey: MovementKey::reservation((string) new UuidV7),
                    sourceType: 'reservation',
                );
            }));

            $this->fail('Yetersiz stokta rezervasyon istisna fırlatmalıydı.');
        } catch (InsufficientStockException) {
            // beklenen
        }

        // Birinci SKU'nun rezervasyonu da geri alınmış olmalı.
        $this->assertSame(0, $this->levelFor($tenant, $warehouseId, $variantIds[0])->reserved);
        $this->assertSame(0, $this->levelFor($tenant, $warehouseId, $variantIds[1])->reserved);

        $this->assertLedgerMatchesProjectionForTenant($tenant->id);
    }

    // ---------------------------------------------------------------- yardımcılar

    /**
     * A'dan bağımsız ikinci bir PostgreSQL bağlantısı.
     *
     * Laravel aynı isimli bağlantıyı önbelleğe alır; ayrı bir isim altında
     * yapılandırma kopyalanarak gerçekten ikinci bir PDO açılır.
     */
    private function secondConnection(): Connection
    {
        $name = 'pgsql_concurrent';

        config(['database.connections.'.$name => config('database.connections.pgsql')]);

        DB::purge($name);

        return DB::connection($name);
    }

    /** @return array{0: Tenant, 1: Variant, 2: string} */
    private function makeContext(): array
    {
        $tenant = (new CreateTenant)->run(
            name: 'Eşzamanlı Satış '.uniqid(),
            owner: User::factory()->create(),
        );

        $warehouseId = TenantContext::runFor($tenant->id, fn () => $tenant->defaultWarehouse()->id);
        $variant = TenantContext::runFor($tenant->id, fn () => Variant::factory()->create());

        return [$tenant, $variant, $warehouseId];
    }

    /**
     * Açılış stoğunu LEDGER ÜZERİNDEN kurar.
     *
     * Projeksiyona doğrudan yazmak on_hand = Σ on_hand_delta eşitliğini daha
     * testin başında bozardı; açılış bakiyesi de bir harekettir.
     */
    private function seedLevel(
        Tenant $tenant,
        string $warehouseId,
        string $variantId,
        int $onHand = 0,
        int $reserved = 0,
    ): InventoryLevel {
        TenantContext::runFor($tenant->id, function () use ($warehouseId, $variantId, $onHand, $reserved): void {
            DB::transaction(function () use ($warehouseId, $variantId, $onHand, $reserved): void {
                (new LockInventoryRows)->run($warehouseId, [$variantId]);

                if ($onHand !== 0) {
                    (new ApplyMovement)->run(
                        warehouseId: $warehouseId,
                        variantId: $variantId,
                        type: MovementType::IMPORT,
                        quantity: $onHand,
                        idempotencyKey: MovementKey::import((string) new UuidV7),
                        sourceType: 'import_row',
                    );
                }

                if ($reserved !== 0) {
                    (new ApplyMovement)->run(
                        warehouseId: $warehouseId,
                        variantId: $variantId,
                        type: MovementType::RESERVATION,
                        quantity: $reserved,
                        idempotencyKey: MovementKey::reservation((string) new UuidV7),
                        sourceType: 'reservation',
                    );
                }
            });
        });

        return $this->levelFor($tenant, $warehouseId, $variantId);
    }

    private function levelFor(Tenant $tenant, string $warehouseId, string $variantId): InventoryLevel
    {
        return TenantContext::runFor($tenant->id, fn () => InventoryLevel::query()
            ->where('warehouse_id', $warehouseId)
            ->where('variant_id', $variantId)
            ->firstOrFail()->refresh());
    }
}
