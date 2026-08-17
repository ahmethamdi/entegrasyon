<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Domain\Catalog\Models\Variant;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Actions\AdjustStock;
use App\Domain\Inventory\Actions\ApplyMovement;
use App\Domain\Inventory\Actions\LockInventoryRows;
use App\Domain\Inventory\Enums\MovementType;
use App\Domain\Inventory\Support\MovementKey;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\UuidV7;
use Tests\Concerns\AssertsLedgerIntegrity;
use Tests\TestCase;

/**
 * Panelden yapılan düzeltme, eşzamanlı yazmada bakiyeyi kaybettirmiyor.
 *
 * Mimari Karar Dokümanı v2.2 · §5 · Kilit stratejisi, §17 · P0.
 *
 * NEDEN RefreshDatabase DEĞİL:
 *   RefreshDatabase her testi tek transaction'a sarar; tek transaction içinde
 *   kilit çekişmesi hiç oluşmaz ve test yanlış yeşile döner. Gerçek commit
 *   yapan DatabaseTruncation gerekir.
 *
 * DÜRÜST SINIR — `LockInventoryRows` ÇAĞRISI DAVRANIŞLA SINANAMAZ:
 *   Tek SKU'da o çağrıyı silmek hiçbir testi kırmaz ve bu testler de dahil
 *   hepsi yeşil kalır. Sebep yapısaldır: `ApplyMovement` zaten
 *   `UPDATE inventory_levels` yapar ve PostgreSQL bu satıra commit'e kadar
 *   tutulan bir satır kilidi koyar. İkinci bağlantı `FOR UPDATE` denediğinde
 *   o UPDATE kilidinde bloklanır — `LockInventoryRows` hiç çağrılmasa bile.
 *   Yani tek-SKU yolunda açık kilidin gözlenebilir bir etkisi YOKTUR.
 *
 *   Çağrı buna rağmen KALIR ve gerekçesi eşzamanlılık değil SIRALAMADIR:
 *   düzeltme sipariş alımıyla aynı satırlara yazar ve çok-SKU yolları kilidi
 *   `ORDER BY variant_id` ile alır. Aynı kapıdan geçmeyen bir yazıcı, çok
 *   kalemli bir iade ile ters sırada kilitlenip ABBA deadlock üretir (§6 ·
 *   düzeltme 11). Kuralı koruyan şey test değil, tüm yazma yollarının aynı
 *   action'ı kullanması disiplinidir.
 *
 *   Bu yüzden SAHTE TEST YAZILMADI. Aşağıdaki testler kilidin varlığını
 *   değil, gözlenebilir olanı doğrular: eşzamanlı yazmada bakiyenin
 *   kaybolmadığını ve ledger toplamının projeksiyona eşit kaldığını.
 */
final class ConcurrentAdjustStockTest extends TestCase
{
    use AssertsLedgerIntegrity;
    use DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    /**
     * Bu sınıf GERÇEKTEN COMMIT eder; artık sonraki testlere sızmasın.
     *
     * DatabaseTruncation kendi setUp'ında boşaltır, tearDown'da değil.
     */
    protected function tearDown(): void
    {
        $this->truncateDatabaseTables();

        parent::tearDown();
    }

    /**
     * Düzeltme tamamlanmadan ikinci bir yazıcı aynı satıra GİREMEZ.
     *
     * Yarım kalmış bir düzeltmenin üstüne ikinci bir yazma binerse biri
     * diğerinin okuduğu bakiyeyi görmez ve `on_hand` üzerine yazarak onu
     * kaybettirir. Bu testin doğruladığı şey satırın commit'e kadar dışarıya
     * kapalı olduğudur.
     *
     * (Bunu sağlayan mekanizma sınıf başlığındaki dürüst sınırda anlatıldı:
     * açık `FOR UPDATE` ile `ApplyMovement`'ın UPDATE'i aynı kilidi verir.
     * Test mekanizmayı değil GÖZLENEBİLİR sonucu sabitler.)
     */
    #[Test]
    public function a_second_writer_cannot_enter_before_the_adjustment_commits(): void
    {
        [$tenant, $variant, $warehouseId] = $this->makeContext(onHand: 5);

        $connectionB = $this->secondConnection();

        // Dış transaction: düzeltme burada çalışır ve COMMIT EDİLMEZ, böylece
        // aldığı kilit test süresince elde tutulur.
        DB::beginTransaction();

        TenantContext::runFor($tenant->id, fn () => app(AdjustStock::class)->run(
            warehouseId: $warehouseId,
            variantId: $variant->id,
            quantity: 3,
            note: 'Kilit sınaması',
        ));

        // İkinci bağlantı aynı satırı kilitlemeye çalışır ve zaman aşımına
        // uğramak ZORUNDADIR. lock_timeout bunu kanıta çevirir: beklemeden
        // geçerse kilit hiç alınmamış demektir.
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
        DB::rollBack();

        $this->assertTrue(
            $blocked,
            'Düzeltme commit etmeden ikinci yazıcı satıra girebildi; '.
            'eşzamanlı yazma bakiyeyi kaybettirir.',
        );
    }

    /**
     * Düzeltme commit ettikten sonra okuyan BAYAT bakiye görmez.
     *
     * Kilit yalnızca yazmayı sıraya sokmakla kalmaz; commit'ten sonra sıraya
     * girmiş okuyucunun güncel değeri görmesi gerekir. Bayat değer görülürse
     * ikinci yazıcı onun üzerine yazar ve düzeltme kaybolur.
     */
    #[Test]
    public function a_reader_after_commit_sees_the_adjusted_balance(): void
    {
        [$tenant, $variant, $warehouseId] = $this->makeContext(onHand: 2);

        TenantContext::runFor($tenant->id, fn () => app(AdjustStock::class)->run(
            warehouseId: $warehouseId,
            variantId: $variant->id,
            quantity: 5,
            note: 'Sayım',
        ));

        $connectionB = $this->secondConnection();
        $connectionB->statement("SET lock_timeout = '5s'");
        $connectionB->beginTransaction();

        $row = $connectionB->selectOne(
            'SELECT on_hand, available FROM inventory_levels
              WHERE tenant_id = ? AND warehouse_id = ? AND variant_id = ?
                FOR UPDATE',
            [$tenant->id, $warehouseId, $variant->id],
        );

        $connectionB->rollBack();

        $this->assertSame(7, (int) $row->on_hand, 'Commit sonrası okuyucu 2 + 5 görmeli.');
        $this->assertSame(7, (int) $row->available);

        $this->assertLedgerMatchesProjection($tenant->id, $warehouseId, $variant->id);
    }

    /**
     * Eşzamanlı satış ve düzeltme birbirini KAYBETTİRMEZ.
     *
     * Sıfır stokta bir satış (bakiye −1), ardından iki adet düzeltme: sonuç
     * +1 olmalı ve ledger toplamı projeksiyona eşit kalmalı. İkisi de kilidi
     * aynı yoldan aldığı için sıraya girerler.
     */
    #[Test]
    public function sale_and_adjustment_serialise_without_losing_either(): void
    {
        [$tenant, $variant, $warehouseId] = $this->makeContext(onHand: 0);

        // Satış: sıfır stokta kabul edilir, bakiye −1.
        TenantContext::runFor($tenant->id, fn () => DB::transaction(function () use ($warehouseId, $variant): void {
            (new LockInventoryRows)->run($warehouseId, [$variant->id]);

            (new ApplyMovement)->run(
                warehouseId: $warehouseId,
                variantId: $variant->id,
                type: MovementType::SALE,
                quantity: 1,
                idempotencyKey: MovementKey::sale((string) new UuidV7),
                sourceType: 'order_line',
            );
        }));

        // Düzeltme: satıcı 2 adet ekler.
        TenantContext::runFor($tenant->id, fn () => app(AdjustStock::class)->run(
            warehouseId: $warehouseId,
            variantId: $variant->id,
            quantity: 2,
            note: 'Sayım farkı',
        ));

        $level = TenantContext::runFor($tenant->id, fn () => $variant->inventoryLevelFor($warehouseId));

        $this->assertSame(1, $level->on_hand, 'Satış ve düzeltme birlikte: -1 + 2 = 1.');
        $this->assertSame(1, $level->available);

        // TEK GERÇEK KAYNAK: ledger toplamı projeksiyona eşit.
        $this->assertLedgerMatchesProjection($tenant->id, $warehouseId, $variant->id);
    }

    // ─────────────────────────────────────────────────── yardımcılar

    private function secondConnection(): Connection
    {
        $name = 'pgsql_adjust_concurrent';

        config(['database.connections.'.$name => config('database.connections.pgsql')]);

        DB::purge($name);

        return DB::connection($name);
    }

    /** @return array{0: Tenant, 1: Variant, 2: string} */
    private function makeContext(int $onHand): array
    {
        $tenant = (new CreateTenant)->run(
            name: 'Eşzamanlı Düzeltme '.uniqid(),
            owner: User::factory()->create(),
        );

        $warehouseId = TenantContext::runFor($tenant->id, fn () => $tenant->defaultWarehouse()->id);
        $variant = TenantContext::runFor($tenant->id, fn () => Variant::factory()->create());

        // Açılış stoğu LEDGER üzerinden girer (IMPORT); projeksiyona doğrudan
        // yazmak on_hand = Σ on_hand_delta eşitliğini bozardı.
        if ($onHand > 0) {
            TenantContext::runFor($tenant->id, fn () => DB::transaction(function () use ($warehouseId, $variant, $onHand): void {
                (new LockInventoryRows)->run($warehouseId, [$variant->id]);

                (new ApplyMovement)->run(
                    warehouseId: $warehouseId,
                    variantId: $variant->id,
                    type: MovementType::IMPORT,
                    quantity: $onHand,
                    idempotencyKey: MovementKey::import((string) new UuidV7),
                    sourceType: 'import_row',
                );
            }));
        }

        return [$tenant, $variant, $warehouseId];
    }
}
