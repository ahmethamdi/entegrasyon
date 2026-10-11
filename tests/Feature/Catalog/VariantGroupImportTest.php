<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Domain\Billing\Models\Plan;
use App\Domain\Billing\Models\Subscription;
use App\Domain\Catalog\Actions\AddVariantToProduct;
use App\Domain\Catalog\Actions\CreateProduct;
use App\Domain\Catalog\Actions\ImportProductsFromChannel;
use App\Domain\Catalog\Exceptions\DuplicateSkuException;
use App\Domain\Catalog\Models\OptionDefinition;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductImage;
use App\Domain\Catalog\Models\Variant;
use App\Domain\Catalog\Models\VariantOption;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Models\ChannelType;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Models\InventoryLevel;
use App\Domain\Inventory\Models\InventoryMovement;
use App\Domain\Messaging\Models\OutboxEvent;
use App\Domain\Sync\Models\Listing;
use App\Domain\Sync\Support\RemoteProduct;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\AssertsLedgerIntegrity;
use Tests\Support\Channels\ProgrammableImportAdapter;
use Tests\TestCase;

/**
 * ADR-003 — gerçek varyant grubu: ürün = grup, varyant = satılabilir birim.
 *
 * Önceden kanal içe aktarması varyant başına AYRI ÜRÜN açıyordu: 3 bedenli
 * tişört panelde 3 ürün, Trendyol'a gönderimde 3 ayrı ürün grubu oluyordu.
 * Bu testlerin koruduğu kurallar:
 *   - aynı `external_parent_id` TEK ürün, seçenekleri yazılmış varyantlar;
 *   - fiyat ve kanal bağı DOĞRU varyanta gider;
 *   - açılış stoğu her varyantta ledger'dan girer, var olan varyantta stok
 *     yazılmaz;
 *   - tek varyantlı ürünler eskisi gibi kalır, eski bölünmüş veri
 *     tahminle birleştirilmez.
 */
final class VariantGroupImportTest extends TestCase
{
    use AssertsLedgerIntegrity;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        ProgrammableImportAdapter::reset();
    }

    // ------------------------------------------------------------ gruplama

    #[Test]
    public function siblings_with_the_same_parent_become_one_product_with_variants(): void
    {
        [$tenant, $connection] = $this->makeConnection();

        ProgrammableImportAdapter::returns('woocommerce', [
            $this->remote('TS-S', 'G-1', 'V-1', ['Renk' => 'Beyaz', 'Beden' => 'S'], price: '100.00', quantity: 3),
            $this->remote('TS-M', 'G-1', 'V-2', ['Renk' => 'Beyaz', 'Beden' => 'M'], price: '110.00', quantity: 5),
            $this->remote('TS-L', 'G-1', 'V-3', ['Renk' => 'Beyaz', 'Beden' => 'L'], price: '120.00', quantity: 0),
        ]);

        $result = $this->import($tenant, $connection);

        $this->assertSame(3, $result->created, 'Üç yeni satılabilir birim.');
        $this->assertSame(2, $result->variantsAdded, 'İlki ürünü açar, ikisi varyant olarak eklenir.');
        $this->assertSame([], $result->errors, 'Hiçbir kardeş hata raporuna düşmemeli.');

        $this->asTenant($tenant, function () use ($connection): void {
            $product = Product::query()->sole();

            // Ürün SKU'su grubun kodu: ilk varyantın SKU'su.
            $this->assertSame('TS-S', $product->sku);
            $this->assertSame(
                ['TS-L' => '120.00', 'TS-M' => '110.00', 'TS-S' => '100.00'],
                $product->variants()->orderBy('sku')->pluck('price', 'sku')->all(),
                'Fiyat VARYANT düzeyinde.',
            );

            $this->assertSame(['M' => 'TS-M'], $this->variantsWith('Beden', 'M'));
            $this->assertSame(1, OptionDefinition::query()->where('name', 'Beden')->count(), 'Tanım kiracı düzeyinde tek.');

            // Her listing KENDİ varyantına bağlı.
            $this->assertSame(
                ['V-1' => 'TS-S', 'V-2' => 'TS-M', 'V-3' => 'TS-L'],
                Listing::query()->where('channel_connection_id', $connection->id)->with('variant')->get()
                    ->mapWithKeys(fn (Listing $l): array => [$l->external_id => $l->variant->sku])->sortKeys()->all(),
            );

            // Açılış stoğu her varyantta ayrı, ledger'dan.
            $onHand = fn (string $sku): int => (int) InventoryLevel::query()
                ->where('variant_id', Variant::query()->where('sku', $sku)->value('id'))->value('on_hand');
            $this->assertSame([3, 5, 0], [$onHand('TS-S'), $onHand('TS-M'), $onHand('TS-L')]);
            $this->assertNotNull(
                InventoryLevel::query()->where('variant_id', Variant::query()->where('sku', 'TS-L')->value('id'))->first(),
                'Sıfır stoklu varyantın da stok satırı açılmalı.',
            );
        });

        $this->assertLedgerMatchesProjectionForTenant($tenant->id);
    }

    #[Test]
    public function a_sibling_on_a_later_page_joins_the_same_product(): void
    {
        [$tenant, $connection] = $this->makeConnection();

        ProgrammableImportAdapter::returnsPages('woocommerce', [
            [$this->remote('TS-S', 'G-1', 'V-1', ['Beden' => 'S'])],
            [$this->remote('TS-M', 'G-1', 'V-2', ['Beden' => 'M'])],
        ]);

        $this->import($tenant, $connection);

        $this->asTenant($tenant, function (): void {
            $this->assertSame(1, Product::query()->count());
            $this->assertSame(2, Variant::query()->count());
        });
    }

    #[Test]
    public function a_sibling_arriving_in_a_later_run_is_added_to_the_existing_product(): void
    {
        [$tenant, $connection] = $this->makeConnection();

        ProgrammableImportAdapter::returns('woocommerce', [$this->remote('TS-S', 'G-1', 'V-1', ['Beden' => 'S'])]);
        $this->import($tenant, $connection);

        // Satıcı kanalda yeni beden açtı.
        ProgrammableImportAdapter::returns('woocommerce', [
            $this->remote('TS-S', 'G-1', 'V-1', ['Beden' => 'S']),
            $this->remote('TS-XL', 'G-1', 'V-4', ['Beden' => 'XL'], quantity: 2),
        ]);
        $result = $this->import($tenant, $connection);

        $this->assertSame(1, $result->updated);
        $this->assertSame(1, $result->created);

        $this->asTenant($tenant, function (): void {
            $this->assertSame(1, Product::query()->count());
            $this->assertSame(['XL' => 'TS-XL'], $this->variantsWith('Beden', 'XL'));
        });

        $this->assertLedgerMatchesProjectionForTenant($tenant->id);
    }

    // --------------------------------------------- yeniden içe aktarma

    /**
     * Fiyat DOĞRU VARYANTA yazılır. Önceden `UpdateProduct` çok varyantlı
     * üründe fiyata dokunmuyordu ve kanal fiyatı sessizce kayboluyordu.
     */
    #[Test]
    public function reimport_updates_the_price_of_the_matching_variant_only_and_never_writes_stock(): void
    {
        [$tenant, $connection] = $this->makeConnection();

        ProgrammableImportAdapter::returns('woocommerce', [
            $this->remote('TS-S', 'G-1', 'V-1', ['Beden' => 'S'], price: '100.00', quantity: 3),
            $this->remote('TS-M', 'G-1', 'V-2', ['Beden' => 'M'], price: '110.00', quantity: 5),
        ]);
        $this->import($tenant, $connection);

        $movementsBefore = $this->asTenant($tenant, fn (): int => InventoryMovement::query()->count());

        ProgrammableImportAdapter::returns('woocommerce', [
            $this->remote('TS-S', 'G-1', 'V-1', ['Beden' => 'S'], price: '100.00', quantity: 99),
            $this->remote('TS-M', 'G-1', 'V-2', ['Beden' => 'M'], price: '125.50', quantity: 99),
        ]);
        $result = $this->import($tenant, $connection);

        $this->assertSame(0, $result->created);
        $this->assertSame(2, $result->updated);

        $this->asTenant($tenant, function () use ($movementsBefore): void {
            $this->assertSame(
                ['TS-M' => '125.50', 'TS-S' => '100.00'],
                Variant::query()->orderBy('sku')->pluck('price', 'sku')->all(),
            );

            // Fiyat olayı YALNIZ değişen varyant için.
            $events = OutboxEvent::query()->where('event_type', 'VariantPriceChanged')->get();
            $this->assertCount(1, $events);
            $this->assertSame(Variant::query()->where('sku', 'TS-M')->value('id'), $events->first()->aggregate_id);

            $this->assertSame($movementsBefore, InventoryMovement::query()->count(), 'Var olan varyantta stok YAZILMAZ.');
        });
    }

    #[Test]
    public function missing_options_are_backfilled_but_existing_ones_are_never_overwritten(): void
    {
        [$tenant, $connection] = $this->makeConnection();

        // Eski içe aktarma: seçeneksiz.
        ProgrammableImportAdapter::returns('woocommerce', [$this->remote('TS-S', 'G-1', 'V-1', [])]);
        $this->import($tenant, $connection);

        ProgrammableImportAdapter::returns('woocommerce', [$this->remote('TS-S', 'G-1', 'V-1', ['Beden' => 'S'])]);
        $this->import($tenant, $connection);

        $this->asTenant($tenant, fn () => $this->assertSame(['S' => 'TS-S'], $this->variantsWith('Beden', 'S')));

        // Kanal farklı değer gönderse de satıcının/ilk turun değeri korunur.
        ProgrammableImportAdapter::returns('woocommerce', [$this->remote('TS-S', 'G-1', 'V-1', ['Beden' => 'XS'])]);
        $this->import($tenant, $connection);

        $this->asTenant($tenant, function (): void {
            $this->assertSame(['S' => 'TS-S'], $this->variantsWith('Beden', 'S'));
            $this->assertSame(1, VariantOption::query()->count());
        });
    }

    // ----------------------------------------------- geriye uyumluluk

    #[Test]
    public function single_variant_products_stay_as_before(): void
    {
        [$tenant, $connection] = $this->makeConnection();

        ProgrammableImportAdapter::returns('woocommerce', [
            $this->remote('A-1', 'G-A', 'V-A', []),
            $this->remote('B-1', 'G-B', 'V-B', []),
        ]);
        $result = $this->import($tenant, $connection);

        $this->assertSame(2, $result->created);
        $this->assertSame(0, $result->variantsAdded);

        $this->asTenant($tenant, function (): void {
            $this->assertSame(2, Product::query()->count());

            foreach (Product::query()->with('variants')->get() as $product) {
                $this->assertCount(1, $product->variants);
                $this->assertSame($product->sku, $product->variants->first()->sku, 'Varsayılan varyant ürünün SKU\'sunu taşır.');
            }

            $this->assertSame(0, VariantOption::query()->count());
        });
    }

    /**
     * ESKİ BÖLÜNMÜŞ VERİ TAHMİNLE BİRLEŞTİRİLMEZ.
     *
     * ADR-003 öncesi aynı grubun varyantları ayrı ürün açtı. Yeni kardeş
     * hangisine eklenmeli? Tahmin yanlışsa stok başka ürünün altına yazılır;
     * o yüzden eski davranışla ayrı ürün açılır ve başlıklar ezilmez.
     */
    #[Test]
    public function a_legacy_split_group_is_not_merged_by_guessing(): void
    {
        [$tenant, $connection] = $this->makeConnection();

        // ADR-003 öncesi durum: aynı grubun iki varyantı iki ayrı ürün.
        $this->asTenant($tenant, function () use ($tenant, $connection): void {
            foreach (['S' => 'V-1', 'M' => 'V-2'] as $size => $externalId) {
                $product = app(CreateProduct::class)->run(
                    sku: "TS-{$size}",
                    title: "Tişört — {$size}",
                    price: 100,
                    openingStock: 1,
                    warehouseId: $tenant->defaultWarehouse()->id,
                );

                Listing::query()->create([
                    'tenant_id' => $tenant->id,
                    'channel_connection_id' => $connection->id,
                    'variant_id' => $product->variants()->value('id'),
                    'external_id' => $externalId,
                    'external_parent_id' => 'G-1',
                    'lifecycle_status' => 'live',
                ]);
            }
        });

        ProgrammableImportAdapter::returns('woocommerce', [
            $this->remote('TS-S', 'G-1', 'V-1', ['Beden' => 'S'], title: 'Tişört'),
            $this->remote('TS-L', 'G-1', 'V-3', ['Beden' => 'L'], title: 'Tişört'),
        ]);
        $result = $this->import($tenant, $connection);

        $this->assertSame(0, $result->variantsAdded, 'Belirsiz grupta varyant eklenmez.');

        $this->asTenant($tenant, function (): void {
            $this->assertSame(3, Product::query()->count());
            $this->assertSame('Tişört — S', Product::query()->where('sku', 'TS-S')->value('title'), 'Eski başlık ezilmez.');
            $this->assertSame('Tişört — L', Product::query()->where('sku', 'TS-L')->value('title'), 'Yeni kardeş ayırt edilebilir başlıkla açılır.');
        });

        $this->assertLedgerMatchesProjectionForTenant($tenant->id);
    }

    // ------------------------------------------------------- korumalar

    #[Test]
    public function a_duplicate_option_combination_is_reported_not_silently_added(): void
    {
        [$tenant, $connection] = $this->makeConnection();

        ProgrammableImportAdapter::returns('woocommerce', [
            $this->remote('TS-S', 'G-1', 'V-1', ['Beden' => 'S']),
            $this->remote('TS-S2', 'G-1', 'V-2', ['Beden' => 'S']),
            $this->remote('TS-M', 'G-1', 'V-3', ['Beden' => 'M']),
        ]);
        $result = $this->import($tenant, $connection);

        $this->assertSame(2, $result->created, 'Hatalı satır turu düşürmez.');
        $this->assertCount(1, $result->errors);
        $this->assertStringContainsString('TS-S2', $result->errors[0]['message']);
        $this->assertStringContainsString('zaten var', $result->errors[0]['message'], 'Birleşim kuralı yüzünden reddedilmeli.');

        $this->asTenant($tenant, fn () => $this->assertSame(2, Variant::query()->count()));
    }

    /**
     * Kota ÜRÜN sayar. Sınırdaki kiracının var olan ürününe yeni beden
     * eklenebilmeli — yoksa plan "1 ürün" iken satıcı tek tişörtünün
     * ikinci bedenini açamazdı.
     */
    #[Test]
    public function adding_a_variant_does_not_consume_the_product_quota(): void
    {
        [$tenant, $connection] = $this->makeConnection();
        $this->putOnPlan($tenant, ['products' => 2]);

        // Varyant kotadan düşseydi ikinci ürün (OTHER) de engellenirdi.
        ProgrammableImportAdapter::returns('woocommerce', [
            $this->remote('TS-S', 'G-1', 'V-1', ['Beden' => 'S']),
            $this->remote('TS-M', 'G-1', 'V-2', ['Beden' => 'M']),
            $this->remote('OTHER', 'G-2', 'V-9', []),
            $this->remote('THIRD', 'G-3', 'V-10', []),
        ]);
        $result = $this->import($tenant, $connection);

        $this->assertSame(3, $result->created);

        $this->asTenant($tenant, function (): void {
            $this->assertSame(['OTHER', 'TS-S'], Product::query()->orderBy('sku')->pluck('sku')->all());
            $this->assertSame(3, Variant::query()->count());
        });

        $this->assertStringContainsString('1 yeni ürün içe aktarılmadı', $result->errors[array_key_last($result->errors)]['message']);
    }

    /**
     * Ürün SKU'su (grup kodu) yalnız TEK varyantlı üründe eşleşme sayılır.
     * Çok varyantlı üründe hangi bedene denk geldiği belirsizdir; tahmin
     * edilseydi kanalın fiyatı rastgele bir bedene yazılırdı.
     */
    #[Test]
    public function the_group_sku_of_a_multi_variant_product_is_not_matched_to_a_variant(): void
    {
        [$tenant, $connection] = $this->makeConnection();

        $this->asTenant($tenant, function () use ($tenant): void {
            $warehouse = $tenant->defaultWarehouse()->id;
            $product = app(CreateProduct::class)->run(sku: 'GRP', title: 'Grup', price: 50, openingStock: 0, warehouseId: $warehouse);
            app(AddVariantToProduct::class)->run($product, 'GRP-M', 50, 0, $warehouse, ['Beden' => 'M']);
            // Satıcı ilk varyantın SKU'sunu değiştirmiş: ürün SKU'su artık
            // hiçbir varyantta yok.
            $product->variants()->where('sku', 'GRP')->update(['sku' => 'GRP-S']);
        });

        ProgrammableImportAdapter::returns('woocommerce', [$this->remote('GRP', 'G-X', 'V-X', [], price: '999.00')]);
        $result = $this->import($tenant, $connection);

        $this->assertSame(0, $result->updated);
        $this->assertCount(1, $result->errors);
        $this->assertStringContainsString('Bu SKU zaten kullanılıyor', $result->errors[0]['message'], 'Ürün SKU çakışması raporlanır.');

        $this->asTenant($tenant, function (): void {
            $this->assertSame(['50.00', '50.00'], Variant::query()->orderBy('sku')->pluck('price')->all(), 'Hiçbir bedenin fiyatı ezilmez.');
            $this->assertSame(0, Listing::query()->count());
        });
    }

    /**
     * Görsel senkronu kaynak başına "listede olmayanı sil" yapar; varyant
     * başına çalışsaydı kırmızının görseli mavinin turunda silinirdi.
     */
    #[Test]
    public function sibling_images_are_merged_not_overwritten(): void
    {
        [$tenant, $connection] = $this->makeConnection();

        ProgrammableImportAdapter::returns('woocommerce', [
            $this->remote('TS-R', 'G-1', 'V-1', ['Renk' => 'Kırmızı'], images: ['https://cdn.x/red.jpg', 'https://cdn.x/main.jpg']),
            $this->remote('TS-B', 'G-1', 'V-2', ['Renk' => 'Mavi'], images: ['https://cdn.x/blue.jpg', 'https://cdn.x/main.jpg']),
        ]);
        $this->import($tenant, $connection);

        $this->asTenant($tenant, function (): void {
            $this->assertSame(
                ['https://cdn.x/red.jpg', 'https://cdn.x/main.jpg', 'https://cdn.x/blue.jpg'],
                ProductImage::query()->orderBy('position')->pluck('storage_path')->all(),
            );
        });
    }

    #[Test]
    public function add_variant_rejects_a_duplicate_sku_and_negative_stock(): void
    {
        [$tenant] = $this->makeConnection();

        $this->asTenant($tenant, function () use ($tenant): void {
            $warehouse = $tenant->defaultWarehouse()->id;
            $product = app(CreateProduct::class)->run(sku: 'P-1', title: 'P', price: 10, openingStock: 0, warehouseId: $warehouse);

            try {
                app(AddVariantToProduct::class)->run($product, 'P-1', 10, 0, $warehouse);
                $this->fail('Aynı SKU reddedilmeli.');
            } catch (DuplicateSkuException) {
                // beklenen
            }

            try {
                app(AddVariantToProduct::class)->run($product, 'P-2', 10, -1, $warehouse);
                $this->fail('Negatif açılış stoğu reddedilmeli.');
            } catch (InvalidArgumentException) {
                // beklenen
            }

            $this->assertSame(1, $product->variants()->count(), 'Başarısız ekleme iz bırakmaz.');
        });
    }

    // ---------------------------------------------------------- yardımcı

    /**
     * Bu seçenek değerini taşıyan varyantların SKU'ları: [değer => sku].
     *
     * @return array<string, string>
     */
    private function variantsWith(string $name, string $value): array
    {
        return VariantOption::query()
            ->whereHas('definition', fn ($q) => $q->where('name', $name))
            ->whereHas('value', fn ($q) => $q->where('value', $value))
            ->with(['variant', 'value'])
            ->get()
            ->mapWithKeys(fn (VariantOption $o): array => [$o->value->value => $o->variant->sku])
            ->all();
    }

    /**
     * @param  array<string, string>  $options
     * @param  list<string>  $images
     */
    private function remote(
        string $sku,
        string $parent,
        string $externalId,
        array $options,
        string $price = '10.00',
        int $quantity = 1,
        string $title = 'Tişört',
        array $images = [],
    ): RemoteProduct {
        return new RemoteProduct(
            externalId: $externalId,
            sku: $sku,
            title: $title,
            price: $price,
            quantity: $quantity,
            images: $images,
            listingIdentity: [
                'external_id' => $externalId,
                'external_parent_id' => $parent,
            ],
            options: $options,
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

    /** @param array<string, int|null> $limits */
    private function putOnPlan(Tenant $tenant, array $limits): void
    {
        Plan::firstOrCreate(
            ['code' => 'free'],
            ['name' => 'Ücretsiz', 'price_monthly' => 0, 'limits' => ['products' => 1]],
        );

        $plan = Plan::create([
            'code' => 'varyant-test',
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
    }

    /** @return array{0: Tenant, 1: ChannelConnection} */
    private function makeConnection(): array
    {
        $user = User::factory()->create();
        $tenant = (new CreateTenant)->run(name: 'Varyant '.uniqid(), owner: $user);

        $this->asSystem(function (): void {
            ChannelType::query()->updateOrCreate(
                ['code' => 'woocommerce'],
                [
                    'name' => 'WooCommerce',
                    'kind' => 'marketplace',
                    'adapter_class' => ProgrammableImportAdapter::class,
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
