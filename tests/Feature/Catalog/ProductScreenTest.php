<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Variant;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Actions\ApplyMovement;
use App\Domain\Inventory\Actions\LockInventoryRows;
use App\Domain\Inventory\Enums\MovementType;
use App\Domain\Inventory\Models\InventoryMovement;
use App\Domain\Inventory\Support\MovementKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\UuidV7;
use Tests\Concerns\AssertsLedgerIntegrity;
use Tests\TestCase;

/**
 * Ürün yönetimi ekranları — §13 · faz 1.2 · "Panelde ürün oluşturma, düzenleme".
 *
 * Mimari Karar Dokümanı v2.2 · §13 · faz 1.2, §19.
 *
 * DEĞİŞMEZ KURAL — INERTIA'YA MODEL GÖNDERİLMEZ: yalnızca görünen alanlar.
 *
 * DEĞİŞMEZ KURAL — DÜZENLEME STOĞA DOKUNMAZ:
 *   Ürün başlığı/fiyatı değişince `inventory_levels` DEĞİŞMEZ. İçerik ve stok
 *   ayrı alanlardır (`listing_sync_states.domain`); başlık düzeltmesinin stok
 *   hareketi yaratması ledger'ı kirletir ve fazla satışı gizleyebilirdi.
 *
 * `content_version` düzenlemede ARTAR: senkron kapısı bu sürümden beslenir.
 */
final class ProductScreenTest extends TestCase
{
    use AssertsLedgerIntegrity;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    // ─────────────────────────────────────────────────── erişim

    /** Misafir ürün ekranlarını göremez. */
    #[Test]
    public function guest_cannot_reach_the_product_screens(): void
    {
        $this->get('/products')->assertRedirect('/login');
        $this->get('/products/create')->assertRedirect('/login');
        $this->post('/products')->assertRedirect('/login');
    }

    /** Başka kiracının ürünü listede GÖRÜNMEZ. */
    #[Test]
    public function products_of_other_tenants_are_not_visible(): void
    {
        [$tenantA, $userA] = $this->makeTenant('A');
        [$tenantB] = $this->makeTenant('B');

        $this->asTenant($tenantA, fn () => Product::factory()->create(['title' => 'Benim Ürünüm']));
        $this->asTenant($tenantB, fn () => Product::factory()->create(['title' => 'Başkasının']));

        $rows = $this->rows($this->actingAs($userA)->get('/products'));

        $this->assertCount(1, $rows);
        $this->assertSame('Benim Ürünüm', $rows[0]['title']);
    }

    /** Başka kiracının ürünü düzenlenemez. */
    #[Test]
    public function cannot_edit_another_tenants_product(): void
    {
        [$tenantA] = $this->makeTenant('A');
        [, $userB] = $this->makeTenant('B');

        $product = $this->asTenant($tenantA, fn () => Product::factory()->create());

        $this->actingAs($userB)->get("/products/{$product->id}/edit")->assertNotFound();

        $this->actingAs($userB)->put("/products/{$product->id}", [
            'title' => 'Ele geçirildi',
            'price' => 1,
        ])->assertNotFound();
    }

    // ─────────────────────────────────────────────────── liste

    /** Liste ürünü, varyant sayısını ve toplam stoğu gösterir. */
    #[Test]
    public function list_shows_product_with_variant_and_stock_summary(): void
    {
        [$tenant, $user, $warehouseId] = $this->makeTenant();

        $this->createViaPanel($user, [
            'sku' => 'LISTE-1',
            'title' => 'Listelenecek Ürün',
            'price' => 99.50,
            'opening_stock' => 7,
        ]);

        $rows = $this->rows($this->actingAs($user)->get('/products'));

        $this->assertCount(1, $rows);
        $this->assertSame('LISTE-1', $rows[0]['sku']);
        $this->assertSame('Listelenecek Ürün', $rows[0]['title']);
        $this->assertSame(1, $rows[0]['variantCount']);
        $this->assertSame(7, $rows[0]['totalOnHand']);
        $this->assertFalse($rows[0]['hasOversold']);
    }

    /**
     * FAZLA SATIŞ ÜRÜN LİSTESİNDE DE GÖRÜNÜR (§17 · P0).
     *
     * Satıcı ürün listesinde dolaşırken de eksikten haberdar olmalı; uyarıyı
     * yalnızca stok ekranına saklamak, ürüne bakan kullanıcıyı habersiz
     * bırakır.
     */
    #[Test]
    public function oversold_variant_is_flagged_in_the_product_list(): void
    {
        [$tenant, $user, $warehouseId] = $this->makeTenant();

        $this->createViaPanel($user, [
            'sku' => 'FAZLA-1',
            'title' => 'Fazla Satılan',
            'price' => 10.00,
            'opening_stock' => 0,
        ]);

        $variant = $this->asTenant($tenant, fn (): Variant => Variant::factory()->make()
            ->newQuery()->where('sku', 'FAZLA-1')->firstOrFail());

        $this->sell($tenant, $warehouseId, $variant->id, quantity: 2);

        $rows = $this->rows($this->actingAs($user)->get('/products'));

        $this->assertTrue($rows[0]['hasOversold'], 'Fazla satış ürün listesinde işaretlenmeli.');
        // KIRPMA YOK: toplam bakiye negatif gösterilir.
        $this->assertSame(-2, $rows[0]['totalOnHand']);
    }

    /**
     * STOK TOPLAMI BAŞKA KİRACININ VARYANTINI SAYMAZ.
     *
     * Toplam `DB::table()` üzerinden gider ve `DB::table()` Eloquent global
     * scope'una TABİ DEĞİLDİR — kiracı filtresi orada AÇIKÇA yazılmak
     * zorundadır. Yazılmazsa iki kiracının varyantları aynı ürün kimliğine
     * bağlandığında toplam karışır.
     *
     * Kurgu bunu görünür kılar: B kiracısı A'nın ÜRÜN kimliğine varyant
     * açıyor (FK kiracı sınırını zorlamıyor) ve o varyanta stok giriyor.
     * Filtre yoksa A'nın satırında B'nin stoğu görünür.
     */
    #[Test]
    public function stock_totals_never_include_another_tenants_variants(): void
    {
        [$tenantA, $userA, $warehouseA] = $this->makeTenant('A');
        [$tenantB, , $warehouseB] = $this->makeTenant('B');

        // A'nın ürünü: stoksuz.
        $this->createViaPanel($userA, [
            'sku' => 'CAPRAZ-1', 'title' => 'Çapraz Ürün', 'price' => 1, 'opening_stock' => 0,
        ]);

        $productA = $this->asTenant($tenantA, fn (): Product => Product::query()->firstOrFail());

        // B kiracısı A'nın ürün kimliğine varyant açıyor ve stok giriyor.
        $this->asTenant($tenantB, function () use ($productA, $tenantB, $warehouseB): void {
            $variant = Variant::query()->create([
                'tenant_id' => $tenantB->id,
                'product_id' => $productA->id,
                'sku' => 'B-VARYANT-1',
                'price' => 1,
                'currency' => 'TRY',
                'status' => 'active',
                'content_version' => 1,
            ]);

            DB::transaction(function () use ($warehouseB, $variant): void {
                (new LockInventoryRows)->run($warehouseB, [$variant->id]);

                (new ApplyMovement)->run(
                    warehouseId: $warehouseB,
                    variantId: $variant->id,
                    type: MovementType::IMPORT,
                    quantity: 99,
                    idempotencyKey: MovementKey::import(
                        (string) new UuidV7
                    ),
                    sourceType: 'import_row',
                );
            });
        });

        $rows = $this->rows($this->actingAs($userA)->get('/products'));

        $this->assertSame(
            1,
            $rows[0]['variantCount'],
            'Yalnızca A kiracısının varyantı sayılmalı.',
        );
        $this->assertSame(
            0,
            $rows[0]['totalOnHand'],
            'Başka kiracının stoğu toplama karışmamalı — DB::table() global scope\'a tabi değil.',
        );
    }

    /** SKU ve başlık aranabilir. */
    #[Test]
    public function search_matches_sku_and_title(): void
    {
        [, $user] = $this->makeTenant();

        $this->createViaPanel($user, ['sku' => 'ARA-A1', 'title' => 'Kırmızı Kazak', 'price' => 1, 'opening_stock' => 0]);
        $this->createViaPanel($user, ['sku' => 'ARA-B2', 'title' => 'Mavi Tişört', 'price' => 1, 'opening_stock' => 0]);

        $bySku = $this->rows($this->actingAs($user)->get('/products?search=ARA-A1'));
        $this->assertCount(1, $bySku);
        $this->assertSame('ARA-A1', $bySku[0]['sku']);

        $byTitle = $this->rows($this->actingAs($user)->get('/products?search=tişört'));
        $this->assertCount(1, $byTitle);
        $this->assertSame('ARA-B2', $byTitle[0]['sku']);
    }

    // ─────────────────────────────────────────────────── yaratma

    /** Geçerli form ürünü açılış stoğuyla yaratır. */
    #[Test]
    public function valid_submission_creates_product_with_opening_stock(): void
    {
        [$tenant, $user, $warehouseId] = $this->makeTenant();

        $response = $this->actingAs($user)->post('/products', [
            'sku' => 'YENI-1',
            'title' => 'Yeni Ürün',
            'price' => 149.99,
            'opening_stock' => 12,
            'description' => 'Açıklama',
            'brand' => 'Marka',
        ]);

        $response->assertRedirect('/products');
        $response->assertSessionHas('success');

        $product = $this->asTenant($tenant, fn (): Product => Product::query()->firstOrFail());

        $this->assertSame('YENI-1', $product->sku);

        // Açılış stoğu LEDGER üzerinden girdi.
        $variant = $this->asTenant($tenant, fn (): Variant => $product->variants()->firstOrFail());

        $movement = $this->asTenant($tenant, fn (): InventoryMovement => InventoryMovement::query()
            ->where('variant_id', $variant->id)
            ->where('type', MovementType::IMPORT->value)
            ->firstOrFail());

        $this->assertSame(12, $movement->on_hand_delta);

        $this->assertLedgerMatchesProjection($tenant->id, $warehouseId, $variant->id);
    }

    /** Eksik alanlar doğrulama hatası verir; hiçbir şey yazılmaz. */
    #[Test]
    public function missing_fields_fail_validation(): void
    {
        [$tenant, $user] = $this->makeTenant();

        $this->actingAs($user)->post('/products', [])
            ->assertSessionHasErrors(['sku', 'title', 'price']);

        $this->assertSame(0, $this->asTenant($tenant, fn (): int => Product::query()->count()));
    }

    /** Negatif açılış stoğu reddedilir. */
    #[Test]
    public function negative_opening_stock_is_rejected(): void
    {
        [, $user] = $this->makeTenant();

        $this->actingAs($user)->post('/products', [
            'sku' => 'NEG-1',
            'title' => 'Negatif',
            'price' => 1,
            'opening_stock' => -5,
        ])->assertSessionHasErrors('opening_stock');
    }

    /** Aynı kiracıda yinelenen SKU alan hatası verir, 500 değil. */
    #[Test]
    public function duplicate_sku_shows_a_field_error(): void
    {
        [, $user] = $this->makeTenant();

        $payload = ['sku' => 'TEK-1', 'title' => 'İlk', 'price' => 1, 'opening_stock' => 0];

        $this->createViaPanel($user, $payload);

        $this->actingAs($user)->post('/products', $payload)
            ->assertSessionHasErrors('sku');
    }

    // ─────────────────────────────────────────────────── düzenleme

    /**
     * DÜZENLEME STOĞA DOKUNMAZ.
     *
     * İçerik ve stok ayrı alanlardır. Başlık düzeltmesinin stok hareketi
     * yaratması ledger'ı kirletir; `content_version` artar ama bakiye ve
     * hareket sayısı DEĞİŞMEZ.
     */
    #[Test]
    public function editing_content_does_not_touch_stock(): void
    {
        [$tenant, $user, $warehouseId] = $this->makeTenant();

        $this->createViaPanel($user, [
            'sku' => 'DUZENLE-1',
            'title' => 'Eski Başlık',
            'price' => 50.00,
            'opening_stock' => 8,
        ]);

        $product = $this->asTenant($tenant, fn (): Product => Product::query()->firstOrFail());
        $variant = $this->asTenant($tenant, fn (): Variant => $product->variants()->firstOrFail());

        $versionBefore = $product->content_version;
        $movementsBefore = $this->asTenant($tenant, fn (): int => InventoryMovement::query()
            ->where('variant_id', $variant->id)->count());

        $this->actingAs($user)->put("/products/{$product->id}", [
            'title' => 'Yeni Başlık',
            'price' => 75.00,
            'description' => 'Güncellendi',
            'status' => 'active',
        ])->assertRedirect();

        $fresh = $this->asTenant($tenant, fn (): Product => $product->fresh());

        $this->assertSame('Yeni Başlık', $fresh->title);
        $this->assertGreaterThan(
            $versionBefore,
            $fresh->content_version,
            'İçerik sürümü artmalı — senkron kapısı bundan beslenir.',
        );

        // STOK DEĞİŞMEDİ.
        $this->assertSame(
            $movementsBefore,
            $this->asTenant($tenant, fn (): int => InventoryMovement::query()
                ->where('variant_id', $variant->id)->count()),
            'İçerik düzenlemesi stok hareketi yaratmamalı.',
        );

        $freshVariant = $this->asTenant($tenant, fn (): Variant => $variant->fresh());
        $this->assertSame('75.00', $freshVariant->price, 'Fiyat varyanta da yazılmalı.');

        $this->assertLedgerMatchesProjection($tenant->id, $warehouseId, $variant->id);
    }

    /** Düzenleme ekranı ürünü ve varyantını gösterir. */
    #[Test]
    public function edit_screen_shows_the_product(): void
    {
        [$tenant, $user] = $this->makeTenant();

        $this->createViaPanel($user, [
            'sku' => 'EKRAN-1', 'title' => 'Ekran Ürünü', 'price' => 33.00, 'opening_stock' => 2,
        ]);

        $product = $this->asTenant($tenant, fn (): Product => Product::query()->firstOrFail());

        $response = $this->actingAs($user)->get("/products/{$product->id}/edit");

        $response->assertOk();

        $props = $response->viewData('page')['props']['product'];

        $this->assertSame('EKRAN-1', $props['sku']);
        $this->assertSame('Ekran Ürünü', $props['title']);
        $this->assertSame(2, $props['totalOnHand']);
    }

    // ─────────────────────────────────────────────────── yardımcılar

    /** @return array<int, array<string, mixed>> */
    private function rows(TestResponse $response): array
    {
        $response->assertOk();

        return $response->viewData('page')['props']['rows'];
    }

    /** @param  array<string, mixed>  $payload */
    private function createViaPanel(User $user, array $payload): void
    {
        $this->actingAs($user)->post('/products', $payload)->assertSessionHasNoErrors();
    }

    private function sell(Tenant $tenant, string $warehouseId, string $variantId, int $quantity): void
    {
        $this->asTenant($tenant, fn () => DB::transaction(
            function () use ($warehouseId, $variantId, $quantity): void {
                (new LockInventoryRows)->run($warehouseId, [$variantId]);

                (new ApplyMovement)->run(
                    warehouseId: $warehouseId,
                    variantId: $variantId,
                    type: MovementType::SALE,
                    quantity: $quantity,
                    idempotencyKey: MovementKey::sale(
                        (string) new UuidV7
                    ),
                    sourceType: 'order_line',
                );
            }
        ));
    }

    /** @return array{0: Tenant, 1: User, 2: string} */
    private function makeTenant(string $name = 'Ürün'): array
    {
        $user = User::factory()->create();
        $tenant = (new CreateTenant)->run(name: $name.' '.uniqid(), owner: $user);
        $warehouseId = $this->asTenant($tenant, fn () => $tenant->defaultWarehouse()->id);

        return [$tenant, $user, $warehouseId];
    }
}
