<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\Actions\CreateProduct;
use App\Domain\Catalog\Exceptions\DuplicateSkuException;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Variant;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Enums\MovementType;
use App\Domain\Inventory\Models\InventoryLevel;
use App\Domain\Inventory\Models\InventoryMovement;
use App\Domain\Messaging\Models\OutboxEvent;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\UuidV7;
use Tests\Concerns\AssertsLedgerIntegrity;
use Tests\TestCase;

/**
 * Ürün oluşturma — §13 · faz 1.2 · "Panelde ürün oluşturma, düzenleme".
 *
 * Mimari Karar Dokümanı v2.2 · §13 · faz 1.2, §19 · "Panelde ürün oluştur
 * (SKU, fiyat, stok 10)".
 *
 * DEĞİŞMEZ KURAL — AÇILIŞ STOĞU LEDGER ÜZERİNDEN GİRER:
 *   `InventoryLevel` satırı DOĞRUDAN yazılmaz. Açılış stoğu bir IMPORT
 *   hareketidir; `on_hand = Σ on_hand_delta` eşitliği ürünün ilk anından
 *   itibaren korunur. Projeksiyona doğrudan yazmak eşitliği daha ürün
 *   yaratılırken bozar ve mutabakat sonsuza kadar sahte sürüklenme bulur.
 *
 * DEĞİŞMEZ KURAL — TEK TRANSACTION:
 *   Ürün, varyant ve açılış hareketi aynı commit'te yazılır. Ayrı olsalardı
 *   araya düşen bir hata stoksuz varyant veya varyantsız ürün bırakırdı.
 *
 * SKU KİRACI İÇİNDE TEKİLDİR (`UNIQUE(tenant_id, sku)`): iki kiracı aynı
 * SKU'yu kullanabilir, aynı kiracı kullanamaz.
 */
final class CreateProductTest extends TestCase
{
    use AssertsLedgerIntegrity;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Açılış IMPORT hareketi outbox olayı yazar; relay bu testin konusu
        // değil ve sync sürücüde iş derhal çalışıp bağlamı temizlerdi.
        Queue::fake();
    }

    /**
     * Ürün, varyant ve açılış stoğu birlikte yaratılır.
     *
     * Dokümanın doğrulama ölçütü: "Panelde ürün oluştur (SKU, fiyat, stok 10)".
     */
    #[Test]
    public function creates_product_variant_and_opening_stock(): void
    {
        [$tenant, , $warehouseId] = $this->makeTenant();

        $product = $this->asTenant($tenant, fn (): Product => app(CreateProduct::class)->run(
            sku: 'KAZAK-001',
            title: 'Yün Kazak',
            price: 249.90,
            openingStock: 10,
            warehouseId: $warehouseId,
            description: 'Merinos yün',
            brand: 'Test Marka',
        ));

        $this->assertSame('KAZAK-001', $product->sku);
        $this->assertSame('Yün Kazak', $product->title);

        // Varsayılan varyant ürünün SKU'sunu taşır: tek varyantlı üründe
        // ayrı bir SKU istemek kullanıcıya gereksiz karar yüklüyordu.
        $variant = $this->asTenant($tenant, fn (): Variant => $product->variants()->firstOrFail());

        $this->assertSame('KAZAK-001', $variant->sku);
        $this->assertSame('249.90', $variant->price);

        // AÇILIŞ STOĞU LEDGER'DAN: IMPORT hareketi yazıldı.
        $movement = $this->asTenant($tenant, fn (): InventoryMovement => InventoryMovement::query()
            ->where('variant_id', $variant->id)
            ->where('type', MovementType::IMPORT->value)
            ->firstOrFail());

        $this->assertSame(10, $movement->on_hand_delta);
        $this->assertSame(10, $movement->on_hand_after);

        // Projeksiyon hareketten türedi.
        $level = $this->asTenant($tenant, fn (): InventoryLevel => InventoryLevel::query()
            ->where('variant_id', $variant->id)->firstOrFail());

        $this->assertSame(10, $level->on_hand);
        $this->assertSame(10, $level->available);

        $this->assertLedgerMatchesProjection($tenant->id, $warehouseId, $variant->id);
    }

    /**
     * AÇILIŞ STOĞU SIFIRSA HAREKET YAZILMAZ.
     *
     * `ApplyMovement` pozitif miktar bekler; sıfır için hareket açmak hem
     * istisna verir hem anlamsız bir ledger satırı üretir. Bakiye satırı da
     * yaratılmaz: ilk hareket onu yaratır.
     */
    #[Test]
    public function zero_opening_stock_writes_no_movement(): void
    {
        [$tenant, , $warehouseId] = $this->makeTenant();

        $product = $this->asTenant($tenant, fn (): Product => app(CreateProduct::class)->run(
            sku: 'STOKSUZ-1',
            title: 'Stoksuz Ürün',
            price: 10.00,
            openingStock: 0,
            warehouseId: $warehouseId,
        ));

        $variant = $this->asTenant($tenant, fn (): Variant => $product->variants()->firstOrFail());

        $this->assertSame(
            0,
            $this->asTenant($tenant, fn (): int => InventoryMovement::query()
                ->where('variant_id', $variant->id)->count()),
            'Sıfır açılış stoğu hareket yazmamalı.',
        );
    }

    /**
     * AÇILIŞ STOĞU OUTBOX OLAYI ÜRETİR.
     *
     * Ürün bir kanalda listelendiğinde stoğun gitmesi gerekir; olay
     * yazılmazsa ilk senkron hiç tetiklenmez.
     */
    #[Test]
    public function opening_stock_emits_an_outbox_event(): void
    {
        [$tenant, , $warehouseId] = $this->makeTenant();

        $this->asTenant($tenant, fn (): Product => app(CreateProduct::class)->run(
            sku: 'OLAY-1',
            title: 'Olay Ürünü',
            price: 5.00,
            openingStock: 3,
            warehouseId: $warehouseId,
        ));

        $event = $this->asSystem(fn (): OutboxEvent => OutboxEvent::query()
            ->where('event_type', 'InventoryLevelChanged')
            ->firstOrFail());

        $this->assertSame(3, $event->payload['on_hand']);
        $this->assertSame(3, $event->payload['available']);
    }

    /**
     * TEK TRANSACTION: hata olursa hiçbir şey kalmaz.
     *
     * Geçersiz depo kimliği verildiğinde hareket yazılamaz; ürün ve varyant
     * da geri alınmalıdır. Aksi halde panelde stoksuz, düzeltilemez bir ürün
     * kalırdı.
     */
    #[Test]
    public function failure_rolls_back_product_and_variant(): void
    {
        [$tenant] = $this->makeTenant();

        $before = $this->asTenant($tenant, fn (): int => Product::query()->count());

        try {
            $this->asTenant($tenant, fn () => app(CreateProduct::class)->run(
                sku: 'PATLAK-1',
                title: 'Patlayacak',
                price: 1.00,
                openingStock: 5,
                // Var olmayan depo: FK ihlali.
                warehouseId: (string) new UuidV7,
            ));
        } catch (\Throwable) {
            // beklenen
        }

        $this->assertSame(
            $before,
            $this->asTenant($tenant, fn (): int => Product::query()->count()),
            'Hata durumunda ürün geri alınmalı.',
        );
        $this->assertSame(
            0,
            $this->asTenant($tenant, fn (): int => Variant::query()->count()),
            'Hata durumunda varyant geri alınmalı.',
        );
    }

    /**
     * AYNI KİRACIDA AYNI SKU İKİ KEZ KULLANILAMAZ.
     *
     * `UNIQUE(tenant_id, sku)` bunu zorlar; action anlaşılır bir hataya
     * çevirir ki panel kullanıcıya ne olduğunu söyleyebilsin.
     */
    #[Test]
    public function duplicate_sku_within_a_tenant_is_rejected(): void
    {
        [$tenant, , $warehouseId] = $this->makeTenant();

        $create = fn () => $this->asTenant($tenant, fn () => app(CreateProduct::class)->run(
            sku: 'AYNI-1',
            title: 'İlk',
            price: 1.00,
            openingStock: 0,
            warehouseId: $warehouseId,
        ));

        $create();

        $this->expectException(DuplicateSkuException::class);

        $create();
    }

    /** İKİ FARKLI KİRACI AYNI SKU'YU KULLANABİLİR. */
    #[Test]
    public function the_same_sku_is_allowed_across_tenants(): void
    {
        [$tenantA, , $warehouseA] = $this->makeTenant('A');
        [$tenantB, , $warehouseB] = $this->makeTenant('B');

        foreach ([[$tenantA, $warehouseA], [$tenantB, $warehouseB]] as [$tenant, $warehouseId]) {
            $this->asTenant($tenant, fn () => app(CreateProduct::class)->run(
                sku: 'PAYLASILAN-1',
                title: 'Aynı SKU',
                price: 1.00,
                openingStock: 0,
                warehouseId: $warehouseId,
            ));
        }

        $this->assertSame(2, $this->asSystem(fn (): int => Product::query()->count()));
    }

    /** Kiracı bağlamı olmadan ürün yaratılamaz. */
    #[Test]
    public function creating_without_tenant_context_throws(): void
    {
        $this->assertFalse(TenantContext::hasTenant());

        $this->expectException(\Throwable::class);

        app(CreateProduct::class)->run(
            sku: 'BAGLAMSIZ-1',
            title: 'Bağlamsız',
            price: 1.00,
            openingStock: 0,
            warehouseId: (string) new UuidV7,
        );
    }

    // ─────────────────────────────────────────────────── yardımcılar

    /** @return array{0: Tenant, 1: User, 2: string} */
    private function makeTenant(string $name = 'Katalog'): array
    {
        $user = User::factory()->create();
        $tenant = (new CreateTenant)->run(name: $name.' '.uniqid(), owner: $user);
        $warehouseId = $this->asTenant($tenant, fn () => $tenant->defaultWarehouse()->id);

        return [$tenant, $user, $warehouseId];
    }
}
