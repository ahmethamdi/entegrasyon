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
use App\Domain\Inventory\Models\InventoryMovement;
use App\Domain\Inventory\Support\MovementKey;
use App\Domain\Inventory\Support\OutboundQuantity;
use App\Domain\Messaging\Models\OutboxEvent;
use App\Support\Tenancy\Exceptions\MissingTenantContextException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\UuidV7;
use Tests\Concerns\AssertsLedgerIntegrity;
use Tests\TestCase;

/**
 * P0 testleri T2, T11, T12 — ApplyMovement doğruluk kuralları.
 *
 * Mimari Karar Dokümanı v2.2 · §18 · P0 test matrisi, §1 · Kararlar 04–07.
 *
 * T1 (eşzamanlılık) ayrı dosyadadır: gerçek paralel bağlantı gerektirir ve
 * RefreshDatabase'in transaction sarmalayıcısı ile birlikte çalışamaz.
 */
final class ApplyMovementTest extends TestCase
{
    use AssertsLedgerIntegrity;
    use RefreshDatabase;

    /**
     * T2 — sıfır stokta satış.
     *
     * Kanonik bakiye −1'e düşer ve OLDUĞU GİBİ saklanır; kanala giden yük
     * 0'a kırpılır. Kırpma yalnızca OutboundQuantity içinde yaşar.
     */
    #[Test]
    public function sale_at_zero_stock_goes_negative_canonically_and_zero_outbound(): void
    {
        [$tenant, $variant, $warehouseId] = $this->makeContext();

        $this->seedLevel($tenant, $warehouseId, $variant->id, onHand: 0);

        $movement = $this->asTenant($tenant, fn () => (new ApplyMovement)->run(
            warehouseId: $warehouseId,
            variantId: $variant->id,
            type: MovementType::SALE,
            quantity: 1,
            idempotencyKey: MovementKey::sale($this->uuid()),
            sourceType: 'order_line',
        ));

        $level = $this->levelFor($tenant, $warehouseId, $variant->id);

        // Kanonik durum: kırpılmamış.
        $this->assertSame(-1, $level->on_hand);
        $this->assertSame(-1, $level->available);
        $this->assertTrue($level->isOversold());

        // Ledger de kırpılmamış gerçek bakiyeyi taşır.
        $this->assertSame(-1, $movement->on_hand_after);
        $this->assertSame(-1, $movement->on_hand_delta);

        // Kanala giden yük kırpılır — ama hiçbir yerde saklanmaz.
        $this->assertSame(0, OutboundQuantity::forChannel($level));
        $this->assertDatabaseHas('inventory_levels', [
            'id' => $level->id,
            'on_hand' => -1,
        ]);

        $this->assertLedgerMatchesProjection($tenant->id, $warehouseId, $variant->id);
    }

    /**
     * T12 — SALE yetersiz stokta KABUL edilir ve fazla satış işaretlenir.
     *
     * Fazla satılan miktar negatif available'ın kendisidir; ayrı bir
     * oversold_qty sayacı yazılmaz.
     */
    #[Test]
    public function sale_with_insufficient_stock_is_accepted_and_marked_oversold(): void
    {
        [$tenant, $variant, $warehouseId] = $this->makeContext();

        $this->seedLevel($tenant, $warehouseId, $variant->id, onHand: 2);

        $this->asTenant($tenant, fn () => (new ApplyMovement)->run(
            warehouseId: $warehouseId,
            variantId: $variant->id,
            type: MovementType::SALE,
            quantity: 5,                       // eldekinden 3 fazla
            idempotencyKey: MovementKey::sale($this->uuid()),
            sourceType: 'order_line',
        ));

        $level = $this->levelFor($tenant, $warehouseId, $variant->id);

        $this->assertSame(-3, $level->on_hand);
        $this->assertSame(-3, $level->available);
        $this->assertTrue($level->isOversold());

        // Fazla satış miktarı = |available|. Ayrı sayaç kolonu YOK.
        $this->assertArrayNotHasKey('oversold_qty', $level->getAttributes());

        // Kısmi oversold indeksi bu satırı görmeli.
        $this->assertSame(1, $this->asSystem(fn () => DB::table('inventory_levels')
            ->where('tenant_id', $tenant->id)
            ->where('available', '<', 0)
            ->count()));

        $this->assertLedgerMatchesProjection($tenant->id, $warehouseId, $variant->id);
    }

    /**
     * T11 — RESERVATION yetersiz stokta REDDEDİLİR.
     *
     * Rezervasyon bizim kararımızdır ve kabul edilmeden doğrulanabilir.
     * Reddedildiğinde ne hareket ne projeksiyon değişikliği kalır.
     */
    #[Test]
    public function reservation_with_insufficient_stock_throws(): void
    {
        [$tenant, $variant, $warehouseId] = $this->makeContext();

        $this->seedLevel($tenant, $warehouseId, $variant->id, onHand: 2);

        $this->expectException(InsufficientStockException::class);

        try {
            $this->asTenant($tenant, fn () => (new ApplyMovement)->run(
                warehouseId: $warehouseId,
                variantId: $variant->id,
                type: MovementType::RESERVATION,
                quantity: 3,                   // available = 2
                idempotencyKey: MovementKey::reservation($this->uuid()),
                sourceType: 'reservation',
            ));
        } finally {
            $level = $this->levelFor($tenant, $warehouseId, $variant->id);

            // Hiçbir yan etki kalmamalı.
            $this->assertSame(2, $level->on_hand);
            $this->assertSame(0, $level->reserved);

            // Yalnızca açılış IMPORT'u kalmalı; reddedilen rezervasyon iz bırakmaz.
            $this->assertSame(1, $this->movementCount($tenant, $variant->id));

            $this->assertLedgerMatchesProjection($tenant->id, $warehouseId, $variant->id);
        }
    }

    #[Test]
    public function transfer_out_with_insufficient_stock_throws(): void
    {
        [$tenant, $variant, $warehouseId] = $this->makeContext();

        $this->seedLevel($tenant, $warehouseId, $variant->id, onHand: 1);

        $this->expectException(InsufficientStockException::class);

        $this->asTenant($tenant, fn () => (new ApplyMovement)->run(
            warehouseId: $warehouseId,
            variantId: $variant->id,
            type: MovementType::TRANSFER_OUT,
            quantity: 2,
            idempotencyKey: MovementKey::transferOut($this->uuid()),
            sourceType: 'transfer',
        ));
    }

    #[Test]
    public function reservation_within_available_stock_succeeds(): void
    {
        [$tenant, $variant, $warehouseId] = $this->makeContext();

        $this->seedLevel($tenant, $warehouseId, $variant->id, onHand: 5);

        $this->asTenant($tenant, fn () => (new ApplyMovement)->run(
            warehouseId: $warehouseId,
            variantId: $variant->id,
            type: MovementType::RESERVATION,
            quantity: 5,                       // tam sınırda — kabul
            idempotencyKey: MovementKey::reservation($this->uuid()),
            sourceType: 'reservation',
        ));

        $level = $this->levelFor($tenant, $warehouseId, $variant->id);

        $this->assertSame(5, $level->on_hand);   // rezervasyon on_hand'i taşımaz
        $this->assertSame(5, $level->reserved);
        $this->assertSame(0, $level->available);

        $this->assertLedgerMatchesProjection($tenant->id, $warehouseId, $variant->id);
    }

    /**
     * Rezervasyon available'a bakar, on_hand'e değil.
     *
     * on_hand = 5, reserved = 4 → available = 1. İkinci bir 2'lik rezervasyon
     * on_hand'e göre uygun görünür ama reddedilmelidir.
     */
    #[Test]
    public function reservation_checks_available_not_on_hand(): void
    {
        [$tenant, $variant, $warehouseId] = $this->makeContext();

        $this->seedLevel($tenant, $warehouseId, $variant->id, onHand: 5, reserved: 4);

        $this->expectException(InsufficientStockException::class);

        $this->asTenant($tenant, fn () => (new ApplyMovement)->run(
            warehouseId: $warehouseId,
            variantId: $variant->id,
            type: MovementType::RESERVATION,
            quantity: 2,
            idempotencyKey: MovementKey::reservation($this->uuid()),
            sourceType: 'reservation',
        ));
    }

    /** İade ve iptal stoğu geri getirir; ledger toplamı korunur. */
    #[Test]
    public function return_after_oversold_sale_restores_balance(): void
    {
        [$tenant, $variant, $warehouseId] = $this->makeContext();

        $this->seedLevel($tenant, $warehouseId, $variant->id, onHand: 0);

        $this->asTenant($tenant, function () use ($variant, $warehouseId): void {
            (new ApplyMovement)->run(
                warehouseId: $warehouseId,
                variantId: $variant->id,
                type: MovementType::SALE,
                quantity: 2,
                idempotencyKey: MovementKey::sale($this->uuid()),
                sourceType: 'order_line',
            );

            (new ApplyMovement)->run(
                warehouseId: $warehouseId,
                variantId: $variant->id,
                type: MovementType::RETURN,
                quantity: 2,
                idempotencyKey: MovementKey::return($this->uuid()),
                sourceType: 'order_event',
            );
        });

        $level = $this->levelFor($tenant, $warehouseId, $variant->id);

        $this->assertSame(0, $level->on_hand);
        $this->assertFalse($level->isOversold());
        $this->assertSame(2, $this->movementCount($tenant, $variant->id));   // SALE + RETURN

        $this->assertLedgerMatchesProjection($tenant->id, $warehouseId, $variant->id);
    }

    /** RELEASE yalnızca reserved sütununu taşır. */
    #[Test]
    public function release_lowers_reserved_only(): void
    {
        [$tenant, $variant, $warehouseId] = $this->makeContext();

        $this->seedLevel($tenant, $warehouseId, $variant->id, onHand: 5, reserved: 3);

        $this->asTenant($tenant, fn () => (new ApplyMovement)->run(
            warehouseId: $warehouseId,
            variantId: $variant->id,
            type: MovementType::RELEASE,
            quantity: 3,
            idempotencyKey: MovementKey::release($this->uuid()),
            sourceType: 'reservation_event',
        ));

        $level = $this->levelFor($tenant, $warehouseId, $variant->id);

        $this->assertSame(5, $level->on_hand);
        $this->assertSame(0, $level->reserved);
        $this->assertSame(5, $level->available);

        $this->assertLedgerMatchesProjection($tenant->id, $warehouseId, $variant->id);
    }

    /** Aynı idempotency anahtarı ikinci kez uygulanmaz — stok iki kez düşmez. */
    #[Test]
    public function replaying_same_idempotency_key_does_not_double_apply(): void
    {
        [$tenant, $variant, $warehouseId] = $this->makeContext();

        $this->seedLevel($tenant, $warehouseId, $variant->id, onHand: 10);

        $key = MovementKey::sale($this->uuid());

        $first = $this->asTenant($tenant, fn () => (new ApplyMovement)->run(
            warehouseId: $warehouseId,
            variantId: $variant->id,
            type: MovementType::SALE,
            quantity: 3,
            idempotencyKey: $key,
            sourceType: 'order_line',
        ));

        $second = $this->asTenant($tenant, fn () => (new ApplyMovement)->run(
            warehouseId: $warehouseId,
            variantId: $variant->id,
            type: MovementType::SALE,
            quantity: 3,
            idempotencyKey: $key,             // aynı anahtar
            sourceType: 'order_line',
        ));

        $this->assertSame($first->id, $second->id, 'Tekrar oynatma mevcut hareketi döndürmeli.');

        // Açılış IMPORT'u + tek SALE. İkinci çağrı hareket YARATMAZ.
        $this->assertSame(2, $this->movementCount($tenant, $variant->id));
        $this->assertSame(7, $this->levelFor($tenant, $warehouseId, $variant->id)->on_hand);

        $this->assertLedgerMatchesProjection($tenant->id, $warehouseId, $variant->id);
    }

    /** Projeksiyon satırı yoksa yaratılır — ilk hareket kaybolmaz. */
    #[Test]
    public function movement_creates_level_row_when_missing(): void
    {
        [$tenant, $variant, $warehouseId] = $this->makeContext();

        // seedLevel çağrılmadı: inventory_levels satırı yok.
        $this->asTenant($tenant, fn () => (new ApplyMovement)->run(
            warehouseId: $warehouseId,
            variantId: $variant->id,
            type: MovementType::IMPORT,
            quantity: 8,
            idempotencyKey: MovementKey::import($this->uuid()),
            sourceType: 'import_row',
        ));

        $this->assertSame(8, $this->levelFor($tenant, $warehouseId, $variant->id)->on_hand);
        $this->assertLedgerMatchesProjection($tenant->id, $warehouseId, $variant->id);
    }

    /** Hareket, projeksiyon ve outbox olayı aynı transaction'da yazılır. */
    #[Test]
    public function movement_projection_and_outbox_are_written_together(): void
    {
        [$tenant, $variant, $warehouseId] = $this->makeContext();

        $this->seedLevel($tenant, $warehouseId, $variant->id, onHand: 4);

        $versionBefore = $this->levelFor($tenant, $warehouseId, $variant->id)->version;

        $movement = $this->asTenant($tenant, fn () => (new ApplyMovement)->run(
            warehouseId: $warehouseId,
            variantId: $variant->id,
            type: MovementType::SALE,
            quantity: 1,
            idempotencyKey: MovementKey::sale($this->uuid()),
            sourceType: 'order_line',
        ));

        $level = $this->levelFor($tenant, $warehouseId, $variant->id);

        // Açılış hareketi de olay üretir; en son yazılan aranır.
        $event = $this->asSystem(fn () => OutboxEvent::query()
            ->where('tenant_id', $tenant->id)
            ->where('aggregate_type', 'inventory_level')
            ->where('aggregate_id', $level->id)
            ->orderByDesc('id')
            ->first());

        $this->assertNotNull($event, 'Stok değişimi outbox olayı üretmeli.');
        $this->assertSame('InventoryLevelChanged', $event->event_type);
        $this->assertNull($event->published_at, 'Yayınlama relay işidir, ApplyMovement değil.');

        // Olay yükü sürüm kapısı için gerekli alanları taşır.
        $this->assertSame($variant->id, $event->payload['variant_id']);
        $this->assertSame($warehouseId, $event->payload['warehouse_id']);
        $this->assertSame($level->version, $event->payload['version']);

        // Projeksiyon sürümü her harekette artar.
        $this->assertSame($versionBefore + 1, $level->version);
        $this->assertSame($movement->id, $level->last_movement_id);
    }

    /** Sürüm her harekette monoton artar — sürüm kapısının dayanağı. */
    #[Test]
    public function version_increments_monotonically(): void
    {
        [$tenant, $variant, $warehouseId] = $this->makeContext();

        $this->seedLevel($tenant, $warehouseId, $variant->id, onHand: 10);

        $versionBefore = $this->levelFor($tenant, $warehouseId, $variant->id)->version;

        $this->asTenant($tenant, function () use ($variant, $warehouseId): void {
            foreach (range(1, 3) as $ignored) {
                (new ApplyMovement)->run(
                    warehouseId: $warehouseId,
                    variantId: $variant->id,
                    type: MovementType::SALE,
                    quantity: 1,
                    idempotencyKey: MovementKey::sale($this->uuid()),
                    sourceType: 'order_line',
                );
            }
        });

        $this->assertSame(
            $versionBefore + 3,
            $this->levelFor($tenant, $warehouseId, $variant->id)->version,
        );
    }

    /** Sıfır ve negatif miktar reddedilir — yön hareket türünden gelir. */
    #[Test]
    public function non_positive_quantity_is_rejected(): void
    {
        [$tenant, $variant, $warehouseId] = $this->makeContext();

        $this->seedLevel($tenant, $warehouseId, $variant->id, onHand: 5);

        $this->expectException(\InvalidArgumentException::class);

        $this->asTenant($tenant, fn () => (new ApplyMovement)->run(
            warehouseId: $warehouseId,
            variantId: $variant->id,
            type: MovementType::SALE,
            quantity: 0,
            idempotencyKey: MovementKey::sale($this->uuid()),
            sourceType: 'order_line',
        ));
    }

    /** Kiracı bağlamı yokken stok yazılamaz — sessizce sızmaz. */
    #[Test]
    public function movement_without_tenant_context_throws(): void
    {
        [$tenant, $variant, $warehouseId] = $this->makeContext();

        $this->seedLevel($tenant, $warehouseId, $variant->id, onHand: 5);

        $this->expectException(MissingTenantContextException::class);

        (new ApplyMovement)->run(
            warehouseId: $warehouseId,
            variantId: $variant->id,
            type: MovementType::SALE,
            quantity: 1,
            idempotencyKey: MovementKey::sale($this->uuid()),
            sourceType: 'order_line',
        );
    }

    /**
     * ApplyMovement KENDİ KİLİDİNİ ALMAZ.
     *
     * Kilit ön koşuldur ve LockInventoryRows'ta tek sorguda alınır. Bu test
     * ApplyMovement'ın SELECT ... FOR UPDATE üretmediğini doğrular; aksi halde
     * çok-SKU yollarında ikinci bir kilit sırası doğar ve deadlock riski
     * geri gelir.
     */
    #[Test]
    public function apply_movement_does_not_acquire_its_own_lock(): void
    {
        [$tenant, $variant, $warehouseId] = $this->makeContext();

        $this->seedLevel($tenant, $warehouseId, $variant->id, onHand: 5);

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $this->asTenant($tenant, fn () => (new ApplyMovement)->run(
            warehouseId: $warehouseId,
            variantId: $variant->id,
            type: MovementType::SALE,
            quantity: 1,
            idempotencyKey: MovementKey::sale($this->uuid()),
            sourceType: 'order_line',
        ));

        $locking = array_values(array_filter(
            $queries,
            fn (string $sql): bool => str_contains(strtolower($sql), 'for update')
                || str_contains(strtolower($sql), 'for share'),
        ));

        $this->assertSame(
            [],
            $locking,
            'ApplyMovement kilit almamalı — kilit LockInventoryRows ön koşuludur. '.
            'Bulunan: '.implode(' | ', $locking),
        );
    }

    /**
     * LockInventoryRows TEK sorguda, variant_id sırasıyla kilitler.
     *
     * Sıralama deadlock önlemenin tek dayanağıdır: iki eşzamanlı sipariş aynı
     * iki SKU'yu ters sırada kilitlerse birbirini bekler.
     */
    #[Test]
    public function lock_inventory_rows_issues_single_ordered_query(): void
    {
        [$tenant, , $warehouseId] = $this->makeContext();

        $variantIds = $this->asTenant($tenant, fn () => collect(range(1, 3))
            ->map(fn () => Variant::factory()->create()->id)
            ->all());

        foreach ($variantIds as $variantId) {
            $this->seedLevel($tenant, $warehouseId, $variantId, onHand: 5);
        }

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $this->asTenant($tenant, fn () => DB::transaction(fn () => (new LockInventoryRows)->run(
            $warehouseId,
            // Kasten karışık sırada verilir.
            [$variantIds[2], $variantIds[0], $variantIds[1]],
        )));

        $locking = array_values(array_filter(
            $queries,
            fn (string $sql): bool => str_contains(strtolower($sql), 'for update'),
        ));

        $this->assertCount(
            1,
            $locking,
            'Kilit TEK sorguda alınmalı — satır başına sorgu deadlock penceresi açar.',
        );

        $sql = strtolower($locking[0]);

        $this->assertStringContainsString('order by', $sql);
        $this->assertStringContainsString('variant_id', $sql);
        $this->assertLessThan(
            strpos($sql, 'for update'),
            strpos($sql, 'order by'),
            'ORDER BY, FOR UPDATE\'ten önce gelmeli.',
        );
    }

    /** Kilit, eksik projeksiyon satırlarını da yaratır — sonra kilitler. */
    #[Test]
    public function lock_inventory_rows_creates_missing_levels_then_locks_them(): void
    {
        [$tenant, , $warehouseId] = $this->makeContext();

        $variantIds = $this->asTenant($tenant, fn () => collect(range(1, 2))
            ->map(fn () => Variant::factory()->create()->id)
            ->all());

        // Hiçbiri seed edilmedi.
        $levels = $this->asTenant($tenant, fn () => DB::transaction(
            fn () => (new LockInventoryRows)->run($warehouseId, $variantIds)
        ));

        $this->assertCount(2, $levels);

        foreach ($variantIds as $variantId) {
            $this->assertArrayHasKey($variantId, $levels);
            $this->assertSame(0, $levels[$variantId]->on_hand);
        }
    }

    /**
     * Kilit transaction dışında alınamaz — FOR UPDATE anlamsız olurdu.
     *
     * NOT: RefreshDatabase her testi bir transaction'a sarar, bu yüzden test
     * gövdesinde transactionLevel() hiçbir zaman 0 olmaz. Guard, ayrı ve
     * sarmalanmamış bir bağlantı üzerinden doğrulanır.
     */
    #[Test]
    public function lock_inventory_rows_requires_a_transaction(): void
    {
        [$tenant, $variant, $warehouseId] = $this->makeContext();

        $name = 'pgsql_unwrapped';
        config(['database.connections.'.$name => config('database.connections.pgsql')]);
        DB::purge($name);

        $original = config('database.default');
        config(['database.default' => $name]);

        try {
            $this->assertSame(0, DB::connection($name)->transactionLevel());

            $this->expectException(\LogicException::class);

            $this->asTenant($tenant, fn () => (new LockInventoryRows)->run(
                $warehouseId,
                [$variant->id],
            ));
        } finally {
            config(['database.default' => $original]);
            DB::purge($name);
        }
    }

    // ---------------------------------------------------------------- yardımcılar

    /** @return array{0: Tenant, 1: Variant, 2: string} */
    private function makeContext(): array
    {
        $tenant = (new CreateTenant)->run(
            name: 'Stok Hareketi '.uniqid(),
            owner: User::factory()->create(),
        );

        $warehouseId = $this->asTenant($tenant, fn () => $tenant->defaultWarehouse()->id);
        $variant = $this->asTenant($tenant, fn () => Variant::factory()->create());

        return [$tenant, $variant, $warehouseId];
    }

    /**
     * Açılış stoğunu LEDGER ÜZERİNDEN kurar.
     *
     * Projeksiyona doğrudan yazmak invariantı daha başlangıçta bozardı:
     * on_hand = Σ on_hand_delta eşitliği açılış bakiyesi için de geçerlidir.
     * Bu yüzden stok IMPORT, rezerve ise RESERVATION hareketiyle girer —
     * tıpkı üretimde olduğu gibi.
     */
    private function seedLevel(
        Tenant $tenant,
        string $warehouseId,
        string $variantId,
        int $onHand = 0,
        int $reserved = 0,
    ): InventoryLevel {
        $this->asTenant($tenant, function () use ($warehouseId, $variantId, $onHand, $reserved): void {
            // Sıfır açılışta bile projeksiyon satırı var olsun — üretimde de
            // ilk kilit satırı yaratır.
            DB::transaction(fn () => (new LockInventoryRows)->run($warehouseId, [$variantId]));

            if ($onHand !== 0) {
                (new ApplyMovement)->run(
                    warehouseId: $warehouseId,
                    variantId: $variantId,
                    type: MovementType::IMPORT,
                    quantity: $onHand,
                    idempotencyKey: MovementKey::import($this->uuid()),
                    sourceType: 'import_row',
                );
            }

            if ($reserved !== 0) {
                (new ApplyMovement)->run(
                    warehouseId: $warehouseId,
                    variantId: $variantId,
                    type: MovementType::RESERVATION,
                    quantity: $reserved,
                    idempotencyKey: MovementKey::reservation($this->uuid()),
                    sourceType: 'reservation',
                );
            }
        });

        return $this->levelFor($tenant, $warehouseId, $variantId);
    }

    private function levelFor(Tenant $tenant, string $warehouseId, string $variantId): InventoryLevel
    {
        return $this->asTenant($tenant, fn () => InventoryLevel::query()
            ->where('warehouse_id', $warehouseId)
            ->where('variant_id', $variantId)
            ->firstOrFail());
    }

    private function movementCount(Tenant $tenant, string $variantId): int
    {
        return $this->asTenant($tenant, fn () => InventoryMovement::query()
            ->where('variant_id', $variantId)
            ->count());
    }

    private function uuid(): string
    {
        return (string) new UuidV7;
    }
}
