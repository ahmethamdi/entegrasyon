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
use App\Domain\Inventory\Models\InventoryLevel;
use App\Domain\Inventory\Models\InventoryMovement;
use App\Domain\Inventory\Support\MovementKey;
use App\Domain\Messaging\Models\OutboxEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\UuidV7;
use Tests\Concerns\AssertsLedgerIntegrity;
use Tests\TestCase;

/**
 * Panelden stok düzeltme — fazla satışın "düzeltme yolu".
 *
 * Mimari Karar Dokümanı v2.2 · §17 · P0 · "Fazla satış ekranı — negatif
 * available kullanıcıya anlatılmalı; eksik miktar ve DÜZELTME YOLU
 * gösterilmeli", §13 · faz 1.2 · "panelde negatif stok uyarısı".
 *
 * DEĞİŞMEZ KURAL — DÜZELTME DE LEDGER ÜZERİNDEN GEÇER:
 *   Panel `inventory_levels` satırını DOĞRUDAN GÜNCELLEMEZ. Düzeltme bir
 *   MANUAL_ADJUSTMENT hareketidir; `on_hand = Σ on_hand_delta` eşitliği her
 *   koşulda korunur ve düzeltmeyi kimin ne zaman yaptığı ledger'da kalır.
 *
 * DEĞİŞMEZ KURAL — TEK KİLİT SORGUSU:
 *   Yazma yolu `LockInventoryRows` kullanır. Tek SKU'da bile: eşzamanlı bir
 *   sipariş alımıyla aynı satıra yazıldığında kilit sırası tutarlı olmalı.
 *
 * DEĞİŞMEZ KULLANIM — MANUAL_ADJUSTMENT EKLER:
 *   Yön hareket türünden gelir ve düzeltme sayım farkını EKLER. Eksiltme için
 *   MANUAL_ADJUSTMENT kullanılmaz; o iş uygun hareket türüyle yapılır.
 */
final class AdjustStockTest extends TestCase
{
    use AssertsLedgerIntegrity;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    // ─────────────────────────────────────────────────── erişim

    /** Misafir stok düzeltemez. */
    #[Test]
    public function guest_cannot_adjust_stock(): void
    {
        $this->post('/inventory/adjust')->assertRedirect('/login');
    }

    /** Başka kiracının varyantı düzeltilemez. */
    #[Test]
    public function cannot_adjust_another_tenants_variant(): void
    {
        [$tenantA, , $warehouseA] = $this->makeTenant('A');
        [, $userB] = $this->makeTenant('B');

        $variant = $this->stockedVariant($tenantA, $warehouseA, onHand: 5);

        $this->actingAs($userB)->post('/inventory/adjust', [
            'variant_id' => $variant->id,
            'quantity' => 3,
        ])->assertNotFound();

        // A'nın bakiyesi DEĞİŞMEDİ.
        $level = $this->asTenant($tenantA, fn () => InventoryLevel::query()
            ->where('variant_id', $variant->id)->firstOrFail());

        $this->assertSame(5, $level->on_hand);
    }

    // ─────────────────────────────────────────────────── düzeltme

    /**
     * FAZLA SATIŞ DÜZELTİLİR: negatif bakiye sıfıra çekilir.
     *
     * Eksik 2 adet; satıcı 2 adet ekler ve bakiye 0 olur. Ledger toplamı
     * projeksiyona eşit kalır.
     */
    #[Test]
    public function adjustment_corrects_an_oversold_balance(): void
    {
        [$tenant, $user, $warehouseId] = $this->makeTenant();

        $variant = $this->stockedVariant($tenant, $warehouseId, onHand: 0);
        $this->sell($tenant, $warehouseId, $variant, quantity: 2);

        $this->actingAs($user)->post('/inventory/adjust', [
            'variant_id' => $variant->id,
            'quantity' => 2,
            'note' => 'Sayım farkı',
        ])->assertRedirect();

        $level = $this->asTenant($tenant, fn () => InventoryLevel::query()
            ->where('variant_id', $variant->id)->firstOrFail());

        $this->assertSame(0, $level->on_hand, 'Fazla satış kapandı.');
        $this->assertSame(0, $level->available);
        $this->assertFalse($level->isOversold());

        $this->assertLedgerMatchesProjection($tenant->id, $warehouseId, $variant->id);
    }

    /**
     * DÜZELTME LEDGER'A YAZILIR — projeksiyon doğrudan güncellenmez.
     *
     * Hareket türü MANUAL_ADJUSTMENT, notu ve kaynağı kayıtlıdır: düzeltmeyi
     * kimin neden yaptığı sonradan sorulabilir.
     */
    #[Test]
    public function adjustment_is_recorded_as_a_manual_movement(): void
    {
        [$tenant, $user, $warehouseId] = $this->makeTenant();

        $variant = $this->stockedVariant($tenant, $warehouseId, onHand: 1);

        $this->actingAs($user)->post('/inventory/adjust', [
            'variant_id' => $variant->id,
            'quantity' => 4,
            'note' => 'Depo sayımı',
        ])->assertRedirect();

        $movement = $this->asTenant($tenant, fn () => InventoryMovement::query()
            ->where('variant_id', $variant->id)
            ->where('type', MovementType::MANUAL_ADJUSTMENT->value)
            ->firstOrFail());

        $this->assertSame(4, $movement->on_hand_delta, 'Düzeltme EKLER.');
        $this->assertSame(0, $movement->reserved_delta);
        $this->assertSame(5, $movement->on_hand_after);
        $this->assertSame('Depo sayımı', $movement->note);

        $this->assertLedgerMatchesProjection($tenant->id, $warehouseId, $variant->id);
    }

    /**
     * DÜZELTME OUTBOX OLAYI ÜRETİR — kanala geri yazılmalı.
     *
     * Panelden yapılan düzeltme kanalda görünmezse satıcı "düzelttim ama
     * mağazada eski değer duruyor" der. Zincirin geri kalanı bunu taşır.
     */
    #[Test]
    public function adjustment_emits_an_outbox_event(): void
    {
        [$tenant, $user, $warehouseId] = $this->makeTenant();

        $variant = $this->stockedVariant($tenant, $warehouseId, onHand: 0);

        $before = $this->asSystem(fn (): int => OutboxEvent::query()->count());

        $this->actingAs($user)->post('/inventory/adjust', [
            'variant_id' => $variant->id,
            'quantity' => 3,
        ])->assertRedirect();

        $events = $this->asSystem(fn () => OutboxEvent::query()
            ->where('event_type', 'InventoryLevelChanged')
            ->get());

        $this->assertGreaterThan($before, $events->count(), 'Düzeltme olay yazmalı.');

        // Yük KANONİK değeri taşır; kırpma giden dönüşümde yapılır.
        $latest = $events->sortByDesc('created_at')->first();
        $this->assertSame(3, $latest->payload['on_hand']);
        $this->assertSame(3, $latest->payload['available']);
    }

    // Kilidin GERÇEKTEN alındığı bu sınıfta sınanamaz: `ApplyMovement` kilit
    // yokluğunda istisna ATMAZ (ön koşul olarak belgelenmiştir ve kilitsiz
    // tek-SKU çağrısında satırı kendisi yaratır). İşlevsel bir test bu yüzden
    // kilidi göremez ve mutasyon hayatta kalır. Gerçek kanıt ayrı bir
    // transaction ile kilit çekişmesi kurmaktır → `ConcurrentAdjustStockTest`.

    // ─────────────────────────────────────────────────── doğrulama

    /** Miktar pozitif olmalı — yön hareket türünden gelir. */
    #[Test]
    public function quantity_must_be_positive(): void
    {
        [$tenant, $user, $warehouseId] = $this->makeTenant();

        $variant = $this->stockedVariant($tenant, $warehouseId, onHand: 5);

        foreach ([0, -3] as $invalid) {
            $this->actingAs($user)->post('/inventory/adjust', [
                'variant_id' => $variant->id,
                'quantity' => $invalid,
            ])->assertSessionHasErrors('quantity');
        }

        $level = $this->asTenant($tenant, fn () => InventoryLevel::query()
            ->where('variant_id', $variant->id)->firstOrFail());

        $this->assertSame(5, $level->on_hand, 'Geçersiz istek bakiyeyi değiştirmemeli.');
    }

    /** Bilinmeyen varyant 404. */
    #[Test]
    public function unknown_variant_is_not_found(): void
    {
        [, $user] = $this->makeTenant();

        $this->actingAs($user)->post('/inventory/adjust', [
            'variant_id' => (string) new UuidV7,
            'quantity' => 1,
        ])->assertNotFound();
    }

    /**
     * AYNI DÜZELTME İKİ KEZ GÖNDERİLİRSE İKİ HAREKET OLUR.
     *
     * Bu bilinçli: düzeltme kullanıcının açık eylemidir ve iki ayrı sayım
     * iki ayrı düzeltmedir. Sipariş idempotency'si dış olay kimliğine
     * dayanır; burada öyle bir kimlik YOKTUR ve uydurmak, satıcının ikinci
     * kez bilerek yaptığı düzeltmeyi sessizce yutardı.
     */
    #[Test]
    public function two_submissions_produce_two_movements(): void
    {
        [$tenant, $user, $warehouseId] = $this->makeTenant();

        $variant = $this->stockedVariant($tenant, $warehouseId, onHand: 0);

        foreach (range(1, 2) as $ignored) {
            $this->actingAs($user)->post('/inventory/adjust', [
                'variant_id' => $variant->id,
                'quantity' => 2,
            ])->assertRedirect();
        }

        $count = $this->asTenant($tenant, fn (): int => InventoryMovement::query()
            ->where('variant_id', $variant->id)
            ->where('type', MovementType::MANUAL_ADJUSTMENT->value)
            ->count());

        $this->assertSame(2, $count);

        $level = $this->asTenant($tenant, fn () => InventoryLevel::query()
            ->where('variant_id', $variant->id)->firstOrFail());

        $this->assertSame(4, $level->on_hand);

        $this->assertLedgerMatchesProjection($tenant->id, $warehouseId, $variant->id);
    }

    // ─────────────────────────────────────────────────── yardımcılar

    /** @return array{0: Tenant, 1: User, 2: string} */
    private function makeTenant(string $name = 'Düzeltme'): array
    {
        $user = User::factory()->create();
        $tenant = (new CreateTenant)->run(name: $name.' '.uniqid(), owner: $user);
        $warehouseId = $this->asTenant($tenant, fn () => $tenant->defaultWarehouse()->id);

        return [$tenant, $user, $warehouseId];
    }

    private function stockedVariant(Tenant $tenant, string $warehouseId, int $onHand): Variant
    {
        return $this->asTenant($tenant, function () use ($warehouseId, $onHand): Variant {
            $variant = Variant::factory()->create();

            if ($onHand > 0) {
                DB::transaction(function () use ($warehouseId, $variant, $onHand): void {
                    (new LockInventoryRows)->run($warehouseId, [$variant->id]);

                    (new ApplyMovement)->run(
                        warehouseId: $warehouseId,
                        variantId: $variant->id,
                        type: MovementType::IMPORT,
                        quantity: $onHand,
                        idempotencyKey: MovementKey::import((string) new UuidV7),
                        sourceType: 'import_row',
                    );
                });
            }

            return $variant;
        });
    }

    private function sell(Tenant $tenant, string $warehouseId, Variant $variant, int $quantity): void
    {
        $this->asTenant($tenant, fn () => DB::transaction(function () use ($warehouseId, $variant, $quantity): void {
            (new LockInventoryRows)->run($warehouseId, [$variant->id]);

            (new ApplyMovement)->run(
                warehouseId: $warehouseId,
                variantId: $variant->id,
                type: MovementType::SALE,
                quantity: $quantity,
                idempotencyKey: MovementKey::sale((string) new UuidV7),
                sourceType: 'order_line',
            );
        }));
    }
}
