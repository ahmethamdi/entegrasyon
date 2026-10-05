<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\Actions\ImportProductsFromChannel;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductImage;
use App\Domain\Catalog\Models\Variant;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Models\ChannelType;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Models\InventoryLevel;
use App\Domain\Inventory\Models\InventoryMovement;
use App\Domain\Sync\Models\Listing;
use App\Domain\Sync\Support\RemoteProduct;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
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

    // ---------------------------------------------------------------- kanal bağı

    /**
     * SKU'SUZ AMA ADRESLİ ÜRÜN GELİR: SKU adresten üretilir, ürün bağlanır.
     *
     * Shopify test mağazasında 26 varyantın 23'ü SKU'suzdu ve hepsi
     * atlanıyordu — satıcının ilk deneyimi "17 üründen 3'ü geldi".
     */
    #[Test]
    public function a_product_without_a_sku_is_created_with_a_generated_sku_and_linked(): void
    {
        [$tenant, $connection] = $this->makeConnection();

        ProgrammableImportAdapter::returns('woocommerce', [
            $this->remote(sku: null, title: 'SKU yok', quantity: 4, externalId: 'gid://x/Variant/48213'),
        ]);

        $result = $this->import($tenant, $connection);

        $this->assertSame(1, $result->created);
        $this->assertSame(0, $result->skipped);
        $this->assertSame([], $result->errors);

        $listing = $this->listingOf($tenant, $connection, 'WOO-48213');

        $this->assertNotNull($listing, 'Ürün kanaldaki karşılığına bağlanmadı.');
        $this->assertSame('gid://x/Variant/48213', $listing->external_id);
        $this->assertSame('P-gid://x/Variant/48213', $listing->external_parent_id);
        $this->assertSame('INV-gid://x/Variant/48213', $listing->channel_metadata['inventory_item_gid']);
        $this->assertSame('live', $listing->lifecycle_status, 'Bağ canlı değil — stok bu kanala hiç gitmez.');
        $this->assertNotNull($listing->listed_at);
    }

    /**
     * KANALIN PARA BİRİMİ YAZILIR: USD mağazanın fiyatı TL sanılmaz.
     */
    #[Test]
    public function a_new_product_takes_the_channel_currency(): void
    {
        [$tenant, $connection] = $this->makeConnection();

        ProgrammableImportAdapter::returns('woocommerce', [
            new RemoteProduct(externalId: '1', sku: 'USD-1', title: 'Dolar', price: '729.95', quantity: 1, currency: 'USD'),
            $this->remote(sku: 'TL-1'),
        ]);

        $this->import($tenant, $connection);

        $currencies = $this->asTenant($tenant, fn () => Variant::query()->pluck('currency', 'sku')->all());

        $this->assertSame('USD', $currencies['USD-1']);
        $this->assertSame('TRY', $currencies['TL-1'], 'Bildirmeyen kanalda varsayılan TRY.');
    }

    /**
     * SKU'lu ürün de bağlanır: kanaldan GELDİ, yani orada zaten satışta.
     */
    #[Test]
    public function a_product_with_a_sku_is_linked_to_its_channel_variant(): void
    {
        [$tenant, $connection] = $this->makeConnection();

        ProgrammableImportAdapter::returns('woocommerce', [
            $this->remote(sku: 'K-BAG', externalId: 'V-1'),
        ]);

        $this->import($tenant, $connection);

        $this->assertSame('V-1', $this->listingOf($tenant, $connection, 'K-BAG')?->external_id);
    }

    /**
     * YENİDEN İÇE AKTARMADA EŞLEŞME BAĞDAN YÜRÜR, SKU'DAN DEĞİL.
     *
     * Satıcı SKU'yu kanalda sonradan girdi: SKU araması tutmaz ve bağa
     * bakılmasaydı aynı ürün İKİNCİ KEZ açılırdı.
     */
    #[Test]
    public function a_reimport_matches_through_the_link_when_the_sku_changed(): void
    {
        [$tenant, $connection] = $this->makeConnection();

        ProgrammableImportAdapter::returns('woocommerce', [
            $this->remote(sku: null, title: 'İlk', externalId: 'V-7'),
        ]);
        $this->import($tenant, $connection);

        ProgrammableImportAdapter::returns('woocommerce', [
            $this->remote(sku: 'YENI-SKU', title: 'Güncel', externalId: 'V-7'),
        ]);
        $result = $this->import($tenant, $connection);

        $this->assertSame(0, $result->created, 'Aynı kanal ürünü ikinci kez açıldı.');
        $this->assertSame(1, $result->updated);

        $products = $this->asTenant($tenant, fn () => Product::query()->get());

        $this->assertCount(1, $products);
        $this->assertSame('Güncel', $products[0]->title);
        $this->assertSame('WOO-7', $products[0]->sku, 'SKU değişmemeli — eşleşme bağdan yürür.');
    }

    /**
     * VAR OLAN BAŞKA BAĞ EZİLMEZ: stok başka kanal ürününe yazılmaya başlardı.
     */
    #[Test]
    public function an_existing_link_to_another_channel_item_is_not_overwritten(): void
    {
        [$tenant, $connection] = $this->makeConnection();

        ProgrammableImportAdapter::returns('woocommerce', [
            $this->remote(sku: 'K-ESKI', externalId: 'V-ESKI'),
        ]);
        $this->import($tenant, $connection);

        // Aynı SKU kanalda BAŞKA varyantta görünüyor.
        ProgrammableImportAdapter::returns('woocommerce', [
            $this->remote(sku: 'K-ESKI', externalId: 'V-YENI'),
        ]);
        $result = $this->import($tenant, $connection);

        $this->assertSame('V-ESKI', $this->listingOf($tenant, $connection, 'K-ESKI')?->external_id);
        $this->assertCount(1, $result->errors);
        $this->assertStringContainsString('başka bir kayda bağlı', $result->errors[0]['message']);
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

    // ---------------------------------------------------------------- görsel (A15)

    /** Kanal görselleri sırasıyla ve kaynağıyla yazılır. */
    #[Test]
    public function imported_images_are_stored_in_order_with_their_source(): void
    {
        [$tenant, $connection] = $this->makeConnection();

        ProgrammableImportAdapter::returns('woocommerce', [
            $this->remote(sku: 'K-1', images: ['https://cdn.x/a.jpg', 'https://cdn.x/b.jpg', 'ftp://bozuk', 'https://cdn.x/a.jpg']),
        ]);

        $this->import($tenant, $connection);

        $images = $this->imagesOf($tenant, 'K-1');

        $this->assertSame(['https://cdn.x/a.jpg', 'https://cdn.x/b.jpg'], $images->pluck('storage_path')->all());
        $this->assertSame([0, 1], $images->pluck('position')->all());
        $this->assertSame([$connection->id, $connection->id], $images->pluck('source_connection_id')->all());
    }

    /**
     * ⚠️ YENİDEN İÇE AKTARMA SATICININ SEÇİMİNİ VE ELLE EKLENENİ KORUR.
     *
     * Kaynakta silinen görsel silinir; kalan görselin "Trendyol'a gitmesin"
     * seçimi KORUNUR; elle eklenen görsele dokunulmaz. Kanal hiç görsel
     * döndürmezse hiçbir şey SİLİNMEZ.
     */
    #[Test]
    public function reimport_keeps_exclusions_and_foreign_images(): void
    {
        [$tenant, $connection] = $this->makeConnection();

        ProgrammableImportAdapter::returns('woocommerce', [
            $this->remote(sku: 'K-1', images: ['https://cdn.x/a.jpg', 'https://cdn.x/b.jpg']),
        ]);
        $this->import($tenant, $connection);

        $this->asTenant($tenant, function () use ($tenant): void {
            $product = Product::query()->where('sku', 'K-1')->firstOrFail();

            ProductImage::query()->where('storage_path', 'https://cdn.x/b.jpg')
                ->update(['excluded_channels' => json_encode(['trendyol'])]);

            ProductImage::query()->create([
                'tenant_id' => $tenant->id,
                'product_id' => $product->id,
                'storage_path' => 'https://elle.x/manuel.jpg',
                'position' => 9,
            ]);
        });

        // Kaynakta a.jpg silindi, b.jpg başa geçti.
        ProgrammableImportAdapter::returns('woocommerce', [
            $this->remote(sku: 'K-1', images: ['https://cdn.x/b.jpg']),
        ]);
        $this->import($tenant, $connection);

        $images = $this->imagesOf($tenant, 'K-1')->keyBy('storage_path');

        $this->assertFalse($images->has('https://cdn.x/a.jpg'), 'Kaynakta silinen görsel silinmeli.');
        $this->assertSame(['trendyol'], $images['https://cdn.x/b.jpg']->excluded_channels, 'Kanal seçimi korunmalı.');
        $this->assertSame(0, $images['https://cdn.x/b.jpg']->position);
        $this->assertTrue($images->has('https://elle.x/manuel.jpg'), 'Elle eklenen görsele dokunulmamalı.');

        // Kanal görsel döndürmedi: hiçbir şey silinmez.
        ProgrammableImportAdapter::returns('woocommerce', [$this->remote(sku: 'K-1')]);
        $this->import($tenant, $connection);

        $this->assertCount(2, $this->imagesOf($tenant, 'K-1'));
    }

    // ---------------------------------------------------------------- yardımcı

    private function imagesOf(Tenant $tenant, string $sku): Collection
    {
        return $this->asTenant($tenant, fn () => ProductImage::query()
            ->whereHas('product', fn ($q) => $q->where('sku', $sku))
            ->orderBy('position')
            ->get());
    }

    private function remote(
        ?string $sku = 'SKU',
        ?string $title = 'Ürün',
        ?string $price = '10.00',
        ?int $quantity = 0,
        array $images = [],
        ?string $externalId = null,
    ): RemoteProduct {
        return new RemoteProduct(
            externalId: $externalId ?? '900',
            sku: $sku,
            title: $title,
            price: $price,
            quantity: $quantity,
            images: $images,
            listingIdentity: $externalId === null ? [] : [
                'external_id' => $externalId,
                'external_parent_id' => 'P-'.$externalId,
                'channel_metadata' => ['inventory_item_gid' => 'INV-'.$externalId],
            ],
        );
    }

    private function listingOf(Tenant $tenant, ChannelConnection $connection, string $sku): ?Listing
    {
        return $this->asTenant($tenant, fn () => Listing::query()
            ->where('channel_connection_id', $connection->id)
            ->whereHas('variant', fn ($q) => $q->where('sku', $sku))
            ->first());
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
