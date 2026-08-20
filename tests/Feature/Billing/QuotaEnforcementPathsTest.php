<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Domain\Billing\Models\Plan;
use App\Domain\Billing\Models\Subscription;
use App\Domain\Catalog\Models\Product;
use App\Domain\Channels\Adapters\WooCommerce\WooCommerceAdapter;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Models\ChannelType;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Actions\ApplyMovement;
use App\Domain\Inventory\Enums\MovementType;
use App\Domain\Inventory\Models\InventoryLevel;
use App\Domain\Inventory\Models\Warehouse;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\AssertsLedgerIntegrity;
use Tests\TestCase;

/**
 * Kota GERÇEK yollarda uygulanıyor mu?
 *
 * Mimari Karar Dokümanı v2.2 · §13 · Faz 4 · kota.
 *
 * BU TESTİN VARLIK SEBEBİ: `EnforceQuota` doğru çalışsa bile hiçbir
 * yerden ÇAĞRILMIYORSA kota diye bir şey YOKTUR. Bu projede aynı boşluk
 * daha önce gerçekten yaşandı — `pushPrices` adapter'da yazılmıştı ama
 * ÇEKİRDEKTE ÇAĞIRANI YOKTU ve fiyat hiç gitmiyordu. Bu dosya çağrının
 * varlığını sınar, mantığını değil.
 *
 * DEĞİŞMEZ KURAL — KOTA STOK VE SİPARİŞ AKIŞINA DOKUNMAZ:
 *   Kotası dolu bir kiracının stoğu güncellenmeye DEVAM EDER. Sipariş
 *   ASLA reddedilmez (pazaryeri onu kabul etmiştir; bu otoriter
 *   gerçektir) ve ödeme sorunu yüzünden stok bozmak, çözdüğünden büyük
 *   zarar verir. §14'ün ön koşul kapısının stok akışına dokunmama
 *   kuralıyla AYNI tasarım hedefi ve BURADA DA snapshot ile korunur.
 */
final class QuotaEnforcementPathsTest extends TestCase
{
    use AssertsLedgerIntegrity;
    use RefreshDatabase;

    // ---------------------------------------------------------------- ürün

    /** Kota dolu kiracı panelden ürün EKLEYEMEZ ve alan hatası görür. */
    #[Test]
    public function the_product_screen_blocks_creation_when_the_quota_is_full(): void
    {
        [$tenant, $user] = $this->tenantOnPlan(['products' => 1]);

        $this->productsFor($tenant, 1);

        $response = $this->actingAs($user)->post('/products', [
            'sku' => 'YENI-1',
            'title' => 'Yeni Ürün',
            'price' => '99.90',
            'opening_stock' => 5,
        ]);

        // 500 DEĞİL, alan hatası — `DuplicateSkuException` ile aynı kalıp.
        $response->assertSessionHasErrors('sku');

        $this->assertSame(1, TenantContext::runAsSystem(
            fn (): int => Product::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count(),
        ), 'Kota dolu iken ürün YARATILMAMALI.');
    }

    /** Kotası olan kiracı normal şekilde ürün ekleyebilir. */
    #[Test]
    public function the_product_screen_allows_creation_below_the_quota(): void
    {
        [$tenant, $user] = $this->tenantOnPlan(['products' => 5]);

        $this->actingAs($user)->post('/products', [
            'sku' => 'YENI-1',
            'title' => 'Yeni Ürün',
            'price' => '99.90',
            'opening_stock' => 5,
        ])->assertRedirect('/products');

        $this->assertSame(1, TenantContext::runAsSystem(
            fn (): int => Product::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count(),
        ));
    }

    // ---------------------------------------------------------------- kanal

    /** Kanal kotası dolu kiracı yeni bağlantı EKLEYEMEZ. */
    #[Test]
    public function the_channel_screen_blocks_a_new_connection_when_the_quota_is_full(): void
    {
        [$tenant, $user] = $this->tenantOnPlan(['channels' => 1]);

        $this->connectionsFor($tenant, 1);

        $response = $this->actingAs($user)->post('/channels', [
            'channel_type_code' => 'woocommerce',
            'label' => 'İkinci Mağaza',
            'store_url' => 'ikinci.example.com',
            'consumer_key' => 'ck_test',
            'consumer_secret' => 'cs_test',
        ]);

        $response->assertSessionHasErrors();

        $this->assertSame(1, TenantContext::runAsSystem(
            fn (): int => ChannelConnection::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)->count(),
        ), 'Kota dolu iken bağlantı YARATILMAMALI.');
    }

    /**
     * KOTA DOLU OLSA BİLE VAR OLAN MAĞAZA YENİDEN BAĞLANABİLİR.
     *
     * `ConnectChannel` aynı hesabı `firstOrNew` ile YENİDEN KULLANIR —
     * bu, anahtar yenileme akışıdır ve yeni bağlantı EKLEMEZ.
     * Engellenseydi kotası dolu bir satıcı, süresi dolmuş anahtarını
     * güncelleyemez ve kanalı kalıcı olarak ölürdü.
     */
    #[Test]
    public function refreshing_the_keys_of_an_existing_store_is_never_blocked(): void
    {
        [$tenant, $user] = $this->tenantOnPlan(['channels' => 1]);

        $this->connectionsFor($tenant, 1);

        $existing = TenantContext::runAsSystem(
            fn (): ChannelConnection => ChannelConnection::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)->firstOrFail(),
        );

        $response = $this->actingAs($user)->post('/channels', [
            'channel_type_code' => 'woocommerce',
            'label' => 'Aynı Mağaza',
            'store_url' => $existing->external_account_id,
            'consumer_key' => 'ck_yeni',
            'consumer_secret' => 'cs_yeni',
        ]);

        $response->assertSessionHasNoErrors();

        $this->assertSame(1, TenantContext::runAsSystem(
            fn (): int => ChannelConnection::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)->count(),
        ), 'Yeniden bağlama ikinci satır AÇMAMALI.');
    }

    // ---------------------------------------------------------------- dokunmadığı yerler

    /**
     * KOTA DOLU OLSA BİLE STOK AKIŞI ÇALIŞIR.
     *
     * §14'ün "kapı stok akışına dokunmaz" kuralının kota karşılığı.
     * Kırılırsa faturalama çekirdeğe sızmış demektir.
     */
    #[Test]
    public function a_full_quota_never_blocks_stock_movement(): void
    {
        [$tenant] = $this->tenantOnPlan(['products' => 1]);

        $this->productsFor($tenant, 3);   // kota AŞILMIŞ durumda

        TenantContext::set($tenant->id);

        $variant = Product::query()->with('variants')->first()->variants->first();
        $warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();

        $before = $this->levelFor($variant->id);

        app(ApplyMovement::class)->run(
            warehouseId: $warehouse->id,
            variantId: $variant->id,
            type: MovementType::SALE,
            quantity: 2,
            idempotencyKey: 'quota-test-sale-1',
            sourceType: 'test',
        );

        $after = $this->levelFor($variant->id);

        $this->assertSame(
            $before - 2,
            $after,
            'Kota dolu olsa da satış stoğu düşürmeli.',
        );

        $this->assertLedgerMatchesProjection($tenant->id, $warehouse->id, $variant->id);
    }

    // ---------------------------------------------------------------- yardımcılar

    /**
     * @param  array<string, int|null>  $limits
     * @return array{0: Tenant, 1: User}
     */
    private function tenantOnPlan(array $limits): array
    {
        $user = User::factory()->create();
        $tenant = (new CreateTenant)->run(name: 'Kota Yolu', owner: $user);

        Plan::firstOrCreate(
            ['code' => 'free'],
            ['name' => 'Ücretsiz', 'price_monthly' => 0, 'limits' => ['products' => 1]],
        );

        $plan = Plan::create([
            'code' => 'test-plan',
            'name' => 'Test',
            'price_monthly' => 99,
            'limits' => $limits,
        ]);

        TenantContext::runAsSystem(fn () => Subscription::create([
            'tenant_id' => $tenant->id,
            'plan_code' => $plan->code,
            'status' => 'active',
            'started_at' => now(),
        ]));

        return [$tenant, $user];
    }

    /**
     * `runAsSystem` DEĞİL `set` KULLANILIR: varyant fabrikası `tenant_id`
     * yazmıyor ve sistem bağlamında yazma "bağlam yok" istisnası
     * fırlatırdı. Bağlam kurulunca `BelongsToTenant` kolonu kendisi
     * dolduruyor.
     */
    private function productsFor(Tenant $tenant, int $count): void
    {
        TenantContext::runFor($tenant->id, function () use ($tenant, $count): void {
            Product::factory()->count($count)->hasVariants(1)->create([
                'tenant_id' => $tenant->id,
            ]);
        });
    }

    private function connectionsFor(Tenant $tenant, int $count): void
    {
        TenantContext::runAsSystem(function () use ($tenant, $count): void {
            ChannelType::query()->updateOrCreate(
                ['code' => 'woocommerce'],
                [
                    'name' => 'WooCommerce',
                    'kind' => 'marketplace',
                    'adapter_class' => WooCommerceAdapter::class,
                    'is_active' => true,
                ],
            );

            ChannelConnection::factory()->count($count)->create(['tenant_id' => $tenant->id]);
        });
    }

    private function levelFor(string $variantId): int
    {
        return (int) InventoryLevel::query()
            ->where('variant_id', $variantId)
            ->value('on_hand');
    }
}
