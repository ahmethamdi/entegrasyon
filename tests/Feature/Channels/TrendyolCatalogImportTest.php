<?php

declare(strict_types=1);

namespace Tests\Feature\Channels;

use App\Domain\Catalog\Actions\ImportProductsFromChannel;
use App\Domain\Catalog\Models\Product;
use App\Domain\Channels\Adapters\Trendyol\TrendyolAdapter;
use App\Domain\Channels\Contracts\SupportsCatalogImport;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Models\ChannelType;
use App\Domain\Channels\Support\ChannelHttpClient;
use App\Domain\Channels\Support\CredentialVault;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Sync\Models\Listing;
use App\Support\Logging\PayloadRedactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Trendyol ürün içe aktarma — 6 Eki 2026'ya kadar YOKTU.
 *
 * Gerçek hesapla ilk denemede panel "ürün çekmeyi destekleyen kanal yok"
 * dedi: adapter `SupportsCatalogImport`'u hiç uygulamıyordu. Yanıt biçimi
 * gerçek hesaptan (2.918 ürün) alındı: `stockCode` boş, bir içerikte
 * birden çok varyant, arşivli varyantlar.
 */
final class TrendyolCatalogImportTest extends TestCase
{
    use RefreshDatabase;

    private const SELLER = '1328369';

    #[Test]
    public function variants_become_products_keyed_by_barcode_and_archived_ones_are_skipped(): void
    {
        Http::fake(['*' => Http::response($this->page([
            $this->content('1198547935', [
                $this->variant('FSSSTT-D86177', stock: 12, price: 149.9),
                $this->variant('FSSSTT-ARSIV', archived: true),
            ]),
        ]), 200)]);

        $page = $this->adapter()->fetchProductPage();

        $this->assertCount(1, $page->products, 'Arşivli varyant alınmamalı.');
        $product = $page->products[0];

        // stockCode boş → SKU barkod (gerçek hesapta tüm ürünler böyle).
        $this->assertSame('FSSSTT-D86177', $product->sku);
        $this->assertSame('FSSSTT-D86177', $product->barcode);
        $this->assertSame('Ritmix Krem', $product->title);
        $this->assertSame('149.9', $product->price);
        $this->assertSame(12, $product->quantity);
        $this->assertSame('BNM', $product->brand);
        $this->assertSame('TRY', $product->currency);
        $this->assertSame(['https://cdn.dsmcdn.com/a.jpg'], $product->images, 'Yalnız https görsel.');
        $this->assertSame([
            'external_id' => 'FSSSTT-D86177',
            'external_parent_id' => '1198547935',
            'external_url' => 'https://www.trendyol.com/x-p-1198547935',
        ], $product->listingIdentity);
    }

    #[Test]
    public function the_sellers_own_stock_code_wins_over_the_barcode(): void
    {
        Http::fake(['*' => Http::response($this->page([
            $this->content('1', [$this->variant('869000', stockCode: 'KREM-60')]),
        ]), 200)]);

        $this->assertSame('KREM-60', $this->adapter()->fetchProductPage()->products[0]->sku);
    }

    #[Test]
    public function pages_are_walked_by_page_number_until_the_last_one(): void
    {
        Http::fake(['*' => fn (Request $r) => Http::response(
            $this->page([], totalPages: 3, page: (int) ($r->data()['page'] ?? 0)),
            200,
        )]);

        $adapter = $this->adapter();
        $page = $adapter->fetchProductPage('1');

        $this->assertTrue($page->hasMore);
        $this->assertSame('2', $page->nextCursor);
        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), '/product/sellers/'.self::SELLER.'/products/approved')
            && str_contains($r->url(), 'page=1'));

        $last = $adapter->fetchProductPage('2');

        $this->assertFalse($last->hasMore, 'Son sayfadan sonra tur durmalı.');
        $this->assertNull($last->nextCursor);
    }

    #[Test]
    public function the_adapter_declares_the_catalog_import_capability(): void
    {
        $this->assertInstanceOf(SupportsCatalogImport::class, $this->adapter());
    }

    /**
     * İçe aktarılan ürün Trendyol ilanına BARKODLA bağlanır.
     *
     * Bağ kurulmasaydı ilk stok/ürün gönderimi Trendyol'da KOPYA ürün
     * yaratmaya çalışırdı (Shopify'da 5 Eki'de aynı hata bulundu).
     */
    #[Test]
    public function an_imported_product_is_linked_to_its_live_trendyol_listing(): void
    {
        Http::fake(['*' => Http::response($this->page([
            $this->content('1198547935', [$this->variant('FSSSTT-D86177', stock: 5)]),
        ]), 200)]);

        $adapter = $this->adapter();
        $connection = $this->connection;

        $result = $this->asTenant($connection->tenant_id, fn () => app(ImportProductsFromChannel::class)->run(
            $connection,
            (string) Warehouse::query()->value('id'),
        ));

        $this->assertSame(1, $result->created);

        $this->asTenant($connection->tenant_id, function (): void {
            $product = Product::query()->sole();
            $listing = Listing::query()->sole();

            $this->assertSame('FSSSTT-D86177', $product->variants()->first()->sku);
            $this->assertSame('FSSSTT-D86177', $listing->external_id);
            $this->assertSame('1198547935', $listing->external_parent_id);
            $this->assertSame('live', $listing->lifecycle_status);
        });

        unset($adapter);
    }

    /**
     * Tamamı arşivli katalog "Tamamlandı 0/0/0/0" GÖRÜNMEZ: arşivliler
     * atlandı sayılır ve sebebi yazılır (gerçek hesap, 6 Eki 2026).
     */
    #[Test]
    public function archived_variants_are_reported_as_skipped_with_a_reason(): void
    {
        Http::fake(['*' => Http::response($this->page([
            $this->content('1', [$this->variant('A1', archived: true), $this->variant('A2', archived: true)]),
        ]), 200)]);

        $this->adapter();
        $connection = $this->connection;

        $result = $this->asTenant($connection->tenant_id, fn () => app(ImportProductsFromChannel::class)->run(
            $connection,
            (string) Warehouse::query()->value('id'),
        ));

        $this->assertSame(0, $result->created);
        $this->assertSame(2, $result->skipped);
        $this->assertStringContainsString('arşivde', $result->errors[0]['message'] ?? '');
    }

    // ─────────────────────────────────────────────────────── yardımcılar

    private ChannelConnection $connection;

    private function adapter(): TrendyolAdapter
    {
        $tenant = $this->makeTenant();

        return $this->asTenant($tenant, function (): TrendyolAdapter {
            $this->connection = ChannelConnection::factory()->create([
                'channel_type_code' => 'trendyol',
                'external_account_id' => self::SELLER,
                'settings' => [TrendyolAdapter::SELLER_ID_KEY => self::SELLER],
            ]);

            app(CredentialVault::class)->store($this->connection, ['api_key' => 'k', 'api_secret' => 's']);

            return new TrendyolAdapter(
                $this->connection,
                new ChannelHttpClient($this->connection, app(CredentialVault::class), app(PayloadRedactor::class)),
            );
        });
    }

    private function makeTenant(): Tenant
    {
        $this->asSystem(fn (): ChannelType => ChannelType::query()->updateOrCreate(
            ['code' => 'trendyol'],
            ['name' => 'Trendyol', 'kind' => 'marketplace', 'adapter_class' => TrendyolAdapter::class, 'supports_webhooks' => false, 'is_active' => true],
        ));

        return (new CreateTenant)->run(name: 'Trendyol İçe Aktarma '.uniqid(), owner: User::factory()->create());
    }

    /** @param list<array<string, mixed>> $content */
    private function page(array $content, int $totalPages = 1, int $page = 0): array
    {
        return ['totalElements' => count($content), 'totalPages' => $totalPages, 'page' => $page, 'size' => 100, 'content' => $content];
    }

    /** @param list<array<string, mixed>> $variants */
    private function content(string $contentId, array $variants): array
    {
        return [
            'contentId' => $contentId,
            'productMainId' => 'PM-'.$contentId,
            'brand' => ['id' => 1365273, 'name' => 'BNM'],
            'category' => ['id' => 5372, 'name' => 'Masaj Kremi'],
            'title' => 'Ritmix Krem',
            'description' => 'Oldukça popüler bir üründür.',
            'images' => [['url' => 'https://cdn.dsmcdn.com/a.jpg'], ['url' => 'http://eski.example/b.jpg']],
            'variants' => $variants,
        ];
    }

    private function variant(string $barcode, int $stock = 0, float $price = 0, bool $archived = false, string $stockCode = ''): array
    {
        return [
            'variantId' => 1684467508,
            'barcode' => $barcode,
            'stockCode' => $stockCode,
            'productUrl' => 'https://www.trendyol.com/x-p-1198547935',
            'onSale' => ! $archived,
            'archived' => $archived,
            'stock' => ['quantity' => $stock],
            'price' => ['salePrice' => $price, 'listPrice' => $price],
        ];
    }
}
