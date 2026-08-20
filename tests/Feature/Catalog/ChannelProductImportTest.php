<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\Actions\ImportProductsFromChannel;
use App\Domain\Catalog\Models\Product;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Models\ChannelType;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Models\InventoryLevel;
use App\Domain\Inventory\Models\InventoryMovement;
use App\Domain\Sync\Support\RemoteProduct;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\AssertsLedgerIntegrity;
use Tests\Support\Channels\ProgrammableCatalogAdapter;
use Tests\Support\Channels\ProgrammableImportAdapter;
use Tests\TestCase;

/**
 * Kanaldan ürün çekme — §13 · Faz 3 · madde 5.
 *
 * Bu testlerin koruduğu asıl kural: içe aktarma STOK YAZMAZ. Kanaldaki
 * stok değeri bayat olabilir ve var olan ürüne uygulanırsa satılmış mallar
 * geri gelir; sessiz, geri alınamaz ve fazla satışa yol açar.
 */
final class ChannelProductImportTest extends TestCase
{
    use AssertsLedgerIntegrity;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        ProgrammableImportAdapter::reset();
        ProgrammableCatalogAdapter::reset();
    }

    // ---------------------------------------------------------------- yazma

    #[Test]
    public function it_creates_products_from_the_channel_catalog(): void
    {
        [$tenant, $connection] = $this->makeConnection();

        ProgrammableImportAdapter::returns('woocommerce', [
            $this->remote(sku: 'K-1', title: 'Kanal Ürünü', price: '25.50', quantity: 7),
        ]);

        $result = $this->import($tenant, $connection);

        $this->assertSame(1, $result->created);
        $this->assertSame(0, $result->updated);

        $product = $this->asTenant($tenant, fn () => Product::query()->where('sku', 'K-1')->firstOrFail());

        $this->assertSame('Kanal Ürünü', $product->title);
    }

    /**
     * AÇILIŞ STOĞU LEDGER ÜZERİNDEN GİRER.
     *
     * `InventoryLevel` doğrudan yazılsaydı `on_hand = Σ on_hand_delta`
     * eşitliği ürün yaratılırken bozulurdu — 500 ürünlük bir katalogda
     * 500 bozuk bakiye ve mutabakatın bulacağı 500 SAHTE sürüklenme.
     */
    #[Test]
    public function opening_stock_enters_through_the_ledger(): void
    {
        [$tenant, $connection] = $this->makeConnection();

        ProgrammableImportAdapter::returns('woocommerce', [
            $this->remote(sku: 'K-2', quantity: 9),
        ]);

        $this->import($tenant, $connection);

        $this->asTenant($tenant, function (): void {
            $variant = Product::query()->where('sku', 'K-2')->firstOrFail()->variants()->firstOrFail();

            $movements = InventoryMovement::query()->where('variant_id', $variant->id)->get();

            $this->assertCount(1, $movements, 'Açılış stoğu TEK hareketle girmeli.');
            $this->assertSame(9, (int) $movements->first()->on_hand_delta);

            $level = InventoryLevel::query()->where('variant_id', $variant->id)->firstOrFail();
            $this->assertSame(9, (int) $level->on_hand);
        });

        $this->assertLedgerMatchesProjectionForTenant($tenant->id);
    }

    // ------------------------------------------------- EN KRİTİK KURAL

    /**
     * VAR OLAN SKU'DA KANALIN STOĞU UYGULANMAZ.
     *
     * Bu maddenin en tehlikeli hatasıdır. Kanaldaki değer bayattır: biz
     * henüz göndermemiş ya da kanal uygulamamış olabilir. Uygulansaydı
     * SATILMIŞ mallar bir içe aktarma turuyla geri gelir ve bakiye kalıcı
     * olarak bozulurdu.
     */
    #[Test]
    public function importing_an_existing_sku_never_writes_stock(): void
    {
        [$tenant, $connection] = $this->makeConnection();

        // Bizde 3 adet var; kanal 99 diyor.
        ProgrammableImportAdapter::returns('woocommerce', [
            $this->remote(sku: 'K-3', title: 'İlk', price: '10.00', quantity: 3),
        ]);
        $this->import($tenant, $connection);

        $before = $this->asTenant($tenant, function (): array {
            $variant = Product::query()->where('sku', 'K-3')->firstOrFail()->variants()->firstOrFail();

            return [
                'on_hand' => (int) InventoryLevel::query()->where('variant_id', $variant->id)->firstOrFail()->on_hand,
                'movements' => InventoryMovement::query()->where('variant_id', $variant->id)->count(),
            ];
        });

        ProgrammableImportAdapter::returns('woocommerce', [
            $this->remote(sku: 'K-3', title: 'Güncellendi', price: '12.00', quantity: 99),
        ]);

        $result = $this->import($tenant, $connection);

        $this->assertSame(0, $result->created);
        $this->assertSame(1, $result->updated);

        $after = $this->asTenant($tenant, function (): array {
            $product = Product::query()->where('sku', 'K-3')->firstOrFail();
            $variant = $product->variants()->firstOrFail();

            return [
                'title' => $product->title,
                'on_hand' => (int) InventoryLevel::query()->where('variant_id', $variant->id)->firstOrFail()->on_hand,
                'movements' => InventoryMovement::query()->where('variant_id', $variant->id)->count(),
            ];
        });

        $this->assertSame('Güncellendi', $after['title'], 'İçerik güncellenmeli.');
        $this->assertSame(
            $before['on_hand'],
            $after['on_hand'],
            'KANALIN STOĞU UYGULANMAMALI — satılmış mal geri gelirdi.',
        );
        $this->assertSame(
            $before['movements'],
            $after['movements'],
            'Güncelleme HİÇ hareket üretmemeli.',
        );

        $this->assertLedgerMatchesProjectionForTenant($tenant->id);
    }

    /**
     * İç kategori SATICININ kararıdır ve kanaldan gelen veride karşılığı
     * yoktur; ezilseydi her tur eşleştirmeleri sessizce koparırdı.
     */
    #[Test]
    public function importing_does_not_clear_the_internal_category(): void
    {
        [$tenant, $connection] = $this->makeConnection();

        ProgrammableImportAdapter::returns('woocommerce', [$this->remote(sku: 'K-4')]);
        $this->import($tenant, $connection);

        $this->asTenant($tenant, function (): void {
            Product::query()->where('sku', 'K-4')->firstOrFail()
                ->forceFill(['internal_category_id' => 'tisort'])->save();
        });

        ProgrammableImportAdapter::returns('woocommerce', [$this->remote(sku: 'K-4', title: 'Yeni ad')]);
        $this->import($tenant, $connection);

        $this->assertSame(
            'tisort',
            $this->asTenant($tenant, fn () => Product::query()->where('sku', 'K-4')->firstOrFail()->internal_category_id),
            'Eşleştirmenin çıpası korunmalı.',
        );
    }

    /**
     * Kanal fiyat göndermediyse kanonik fiyat KORUNUR — 0'a düşseydi o
     * fiyat sonraki senkronda tüm kanallara yayılırdı.
     */
    #[Test]
    public function a_missing_remote_price_does_not_zero_the_canonical_price(): void
    {
        [$tenant, $connection] = $this->makeConnection();

        ProgrammableImportAdapter::returns('woocommerce', [$this->remote(sku: 'K-5', price: '49.90')]);
        $this->import($tenant, $connection);

        ProgrammableImportAdapter::returns('woocommerce', [$this->remote(sku: 'K-5', price: null)]);
        $this->import($tenant, $connection);

        $price = $this->asTenant(
            $tenant,
            fn () => Product::query()->where('sku', 'K-5')->firstOrFail()->variants()->firstOrFail()->price,
        );

        $this->assertSame('49.90', (string) $price, 'Fiyat sıfırlanmamalı.');
    }

    // ---------------------------------------------------------------- ayıklama

    /**
     * SKU'suz ürün ATLANIR ama SAYILIR ve SEBEBİYLE raporlanır — sessizce
     * düşseydi satıcı eksiğin nedenini hiçbir yerde bulamazdı.
     */
    #[Test]
    public function products_without_a_sku_are_skipped_and_reported(): void
    {
        [$tenant, $connection] = $this->makeConnection();

        ProgrammableImportAdapter::returns('woocommerce', [
            $this->remote(sku: 'K-6'),
            $this->remote(sku: null, title: 'SKU yok'),
        ]);

        $result = $this->import($tenant, $connection);

        $this->assertSame(1, $result->created);
        $this->assertSame(1, $result->skipped);
        $this->assertCount(1, $result->errors);
        $this->assertStringContainsString('SKU yok', $result->errors[0]['message']);
    }

    // ---------------------------------------------------------------- sayfalama

    #[Test]
    public function it_follows_the_cursor_across_pages(): void
    {
        [$tenant, $connection] = $this->makeConnection();

        ProgrammableImportAdapter::returnsPages('woocommerce', [
            [$this->remote(sku: 'S-1')],
            [$this->remote(sku: 'S-2')],
            [$this->remote(sku: 'S-3')],
        ]);

        $result = $this->import($tenant, $connection);

        $this->assertSame(3, $result->created);
        $this->assertSame(
            [null, '2', '3'],
            ProgrammableImportAdapter::cursorsFor('woocommerce'),
            'İmleç zinciri takip edilmeli; ilk çağrı null ile başlar.',
        );
    }

    /**
     * ÜST SINIR EMNİYETTİR: `hasMore` sonsuza kadar `true` dönen bozuk bir
     * kanalda tur asla bitmezdi. Sınıra takılan tur kullanıcıya SÖYLER.
     */
    #[Test]
    public function the_page_cap_stops_an_endless_channel_and_says_so(): void
    {
        [$tenant, $connection] = $this->makeConnection();

        ProgrammableImportAdapter::maxPages('woocommerce', 3);
        ProgrammableImportAdapter::returnsEndlessly('woocommerce', [$this->remote(sku: 'SONSUZ')]);

        $result = $this->import($tenant, $connection);

        $this->assertCount(
            3,
            ProgrammableImportAdapter::cursorsFor('woocommerce'),
            'Üst sınır kadar sayfa okunmalı, daha fazla değil.',
        );
        $this->assertTrue($result->stoppedEarly);
        $this->assertNotNull($result->stopReason, 'Sessizce durulmamalı (§13 · no silent caps).');
    }

    /**
     * Sayfa hatası turu DURDURUR ama o ana kadar yazılanları KORUR: tek
     * ürünün bozukluğu o ürüne özgüdür, sayfa çekilemiyorsa kanal
     * konuşmuyor demektir.
     */
    #[Test]
    public function a_failing_page_stops_the_run_but_keeps_what_was_written(): void
    {
        [$tenant, $connection] = $this->makeConnection();

        ProgrammableImportAdapter::returnsPages('woocommerce', [
            [$this->remote(sku: 'Y-1')],
            [$this->remote(sku: 'Y-2')],
        ]);
        ProgrammableImportAdapter::failsOnPage('woocommerce', '2', 'kanal 500 döndü');

        $result = $this->import($tenant, $connection);

        $this->assertSame(1, $result->created, 'İlk sayfanın ürünü KORUNMALI.');
        $this->assertTrue($result->stoppedEarly);
        $this->assertStringContainsString('kanal 500 döndü', (string) $result->stopReason);

        $this->assertSame(
            1,
            $this->asTenant($tenant, fn (): int => Product::query()->count()),
            'Yazılan ürün geri alınmamalı — tur tek transaction DEĞİLDİR.',
        );
    }

    // ---------------------------------------------------------------- yetenek

    /**
     * DESTEKLEMEYEN KANAL SESSİZCE BOŞ DÖNMEZ — "0 ürün bulundu" satıcıya
     * kataloğunun boş olduğunu düşündürürdü (§7).
     */
    #[Test]
    public function a_channel_without_the_capability_is_reported_not_silently_empty(): void
    {
        // ProgrammableCatalogAdapter SupportsCatalogImport UYGULAMAZ.
        [$tenant, $connection] = $this->makeConnection(ProgrammableCatalogAdapter::class);

        $result = $this->import($tenant, $connection);

        $this->assertFalse($result->supported);
        $this->assertNotNull($result->stopReason);
        $this->assertSame(0, $result->created);
    }

    // ---------------------------------------------------------------- izolasyon

    #[Test]
    public function imported_products_belong_to_the_importing_tenant_only(): void
    {
        [$tenantA, $connectionA] = $this->makeConnection();
        [$tenantB] = $this->makeConnection();

        ProgrammableImportAdapter::returns('woocommerce', [$this->remote(sku: 'IZOLE')]);

        $this->import($tenantA, $connectionA);

        $this->assertSame(
            0,
            $this->asTenant($tenantB, fn (): int => Product::query()->count()),
            'Başka kiracının kataloğuna sızmamalı.',
        );
    }

    // ---------------------------------------------------------------- yardımcı

    private function remote(
        ?string $sku = 'SKU',
        ?string $title = 'Ürün',
        ?string $price = '10.00',
        ?int $quantity = 0,
    ): RemoteProduct {
        return new RemoteProduct(
            externalId: '900',
            sku: $sku,
            title: $title,
            price: $price,
            quantity: $quantity,
        );
    }

    private function import(Tenant $tenant, ChannelConnection $connection)
    {
        return $this->asTenant(
            $tenant,
            fn () => app(ImportProductsFromChannel::class)->run(
                connection: $connection,
                warehouseId: $tenant->defaultWarehouse()->id,
            ),
        );
    }

    /** @return array{0: Tenant, 1: ChannelConnection} */
    private function makeConnection(string $adapter = ProgrammableImportAdapter::class): array
    {
        $user = User::factory()->create();
        $tenant = (new CreateTenant)->run(name: 'İçe aktarma '.uniqid(), owner: $user);

        $this->asSystem(function () use ($adapter): void {
            ChannelType::query()->updateOrCreate(
                ['code' => 'woocommerce'],
                [
                    'name' => 'WooCommerce',
                    'kind' => 'marketplace',
                    'adapter_class' => $adapter,
                    'is_active' => true,
                ],
            );
        });

        $connection = $this->asTenant($tenant, fn () => ChannelConnection::factory()->create([
            'tenant_id' => $tenant->id,
            'channel_type_code' => 'woocommerce',
            'status' => 'active',
        ]));

        return [$tenant, $connection];
    }
}
