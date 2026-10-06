<?php

declare(strict_types=1);

namespace Tests\Feature\Channels;

use App\Domain\Catalog\Actions\ImportProductsFromChannel;
use App\Domain\Catalog\Models\Product;
use App\Domain\Channels\Adapters\Etsy\EtsyAdapter;
use App\Domain\Channels\Contracts\SupportsCatalogImport;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Models\ChannelType;
use App\Domain\Channels\Support\ChannelHttpClient;
use App\Domain\Channels\Support\CredentialVault;
use App\Domain\Identity\Actions\CreateTenant;
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
 * Etsy ürün içe aktarma — 7 Eki 2026'ya kadar YOKTU: mağazası dolu satıcı
 * bağlandığında ilanları 34Pazar'a gelmiyordu.
 *
 * Yanıt biçimi Etsy Open API v3 `getListingsByShop` (`includes=Images,
 * Inventory`) alan adlarıyla kurulur. Gerçek hesapla henüz sınanmadı.
 */
final class EtsyCatalogImportTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Her Etsy varyantı (product) ayrı ürün olur; ilan kimliği üst kimliktir.
     * Silinmiş product ve kapalı offering alınmaz. Fiyat `amount/divisor`.
     */
    #[Test]
    public function each_etsy_product_becomes_a_variant_linked_to_its_listing(): void
    {
        Http::fake(['*' => Http::response(['count' => 1, 'results' => [$this->listing()]], 200)]);

        $page = $this->adapter()->fetchProductPage();

        $this->assertCount(2, $page->products, 'Silinmiş ve kapalı seçenek alınmamalı.');
        [$small, $medium] = $page->products;

        $this->assertSame('TSH-S', $small->sku);
        $this->assertSame('Kedi Tişört — Beyaz / S', $small->title, 'HTML varlığı çözülür, varyant değerleri eklenir.');
        $this->assertSame('19.90', $small->price);
        $this->assertSame(3, $small->quantity);
        $this->assertSame('TRY', $small->currency);
        $this->assertSame(['https://i.etsystatic.com/1.jpg', 'https://i.etsystatic.com/2.jpg'], $small->images, 'Sıra rank\'e göre.');
        $this->assertSame([
            'external_id' => '5000',
            'external_parent_id' => '9001',
            'external_url' => 'https://www.etsy.com/listing/9001/kedi',
            'channel_metadata' => ['offering_id' => '7000'],
        ], $small->listingIdentity);

        // Etsy'de SKU zorunlu değil: boş SKU null geçer, içe aktarma üretir.
        $this->assertNull($medium->sku);
        $this->assertSame('5001', $medium->externalId);
    }

    /**
     * Durumlar sırayla taranır: active → sold_out → inactive. Yalnız aktif
     * çekilseydi stoğu bitmiş ilanlar hiç gelmezdi.
     */
    #[Test]
    public function active_then_sold_out_then_inactive_listings_are_walked(): void
    {
        Http::fake(['*' => fn (Request $r) => Http::response(
            ['count' => 150, 'results' => [$this->listing()]],
            200,
        )]);

        $adapter = $this->adapter();

        $this->assertSame('0:100', $adapter->fetchProductPage()->nextCursor, 'Aynı durumda sonraki sayfa.');
        $this->assertSame('1:0', $adapter->fetchProductPage('0:100')->nextCursor, 'Aktif bitti → tükenenler.');
        $this->assertSame('2:0', $adapter->fetchProductPage('1:100')->nextCursor);

        $last = $adapter->fetchProductPage('2:100');
        $this->assertFalse($last->hasMore);
        $this->assertNull($last->nextCursor);

        $states = collect(Http::recorded())->map(static function (array $pair): string {
            parse_str((string) parse_url($pair[0]->url(), PHP_URL_QUERY), $query);

            return $query['state'].':'.$query['offset'].':'.$query['includes'];
        })->all();

        $this->assertSame([
            'active:0:Images,Inventory', 'active:100:Images,Inventory',
            'sold_out:100:Images,Inventory', 'inactive:100:Images,Inventory',
        ], $states);
    }

    #[Test]
    public function the_adapter_declares_the_catalog_import_capability(): void
    {
        $this->assertInstanceOf(SupportsCatalogImport::class, $this->adapter());
    }

    /**
     * Uçtan uca: içe aktarılan ürün Etsy ilanına bağlanır; SKU'suz varyanta
     * SKU üretilir ve bağ `product_id` ile kurulur.
     */
    #[Test]
    public function imported_products_are_linked_to_their_etsy_listing(): void
    {
        Http::fake(['*' => fn (Request $r) => Http::response(
            str_contains($r->url(), 'state=active') ? ['count' => 1, 'results' => [$this->listing()]] : ['count' => 0, 'results' => []],
            200,
        )]);

        $this->adapter();
        $connection = $this->connection;

        $result = $this->asTenant($connection->tenant_id, fn () => app(ImportProductsFromChannel::class)->run(
            $connection,
            (string) Warehouse::query()->value('id'),
        ));

        $this->assertSame(2, $result->created);

        $this->asTenant($connection->tenant_id, function (): void {
            $listings = Listing::query()->with('variant')->orderBy('external_id')->get();

            $this->assertSame(['5000', '5001'], $listings->pluck('external_id')->all());
            $this->assertSame(['9001', '9001'], $listings->pluck('external_parent_id')->all());
            $this->assertSame('TSH-S', $listings[0]->variant->sku);
            $this->assertNotSame('', (string) $listings[1]->variant->sku, 'SKU\'suz varyanta SKU üretilmeli.');
            $this->assertSame(2, Product::query()->count());
        });
    }

    // ─────────────────────────────────────────────────────── yardımcılar

    private ChannelConnection $connection;

    private function adapter(): EtsyAdapter
    {
        $this->asSystem(fn (): ChannelType => ChannelType::query()->updateOrCreate(
            ['code' => 'etsy'],
            ['name' => 'Etsy', 'kind' => 'marketplace', 'adapter_class' => EtsyAdapter::class, 'supports_webhooks' => false, 'is_active' => true],
        ));

        $tenant = (new CreateTenant)->run(name: 'Etsy İçe Aktarma '.uniqid(), owner: User::factory()->create());

        return $this->asTenant($tenant, function (): EtsyAdapter {
            $this->connection = ChannelConnection::factory()->create([
                'channel_type_code' => 'etsy',
                'external_account_id' => 'etsy-'.uniqid(),
                'status' => 'active',
                'settings' => [EtsyAdapter::SHOP_ID_KEY => '777'],
            ]);

            app(CredentialVault::class)->store($this->connection, ['access_token' => '12345.token', 'refresh_token' => '12345.refresh']);

            return new EtsyAdapter(
                $this->connection,
                new ChannelHttpClient($this->connection, app(CredentialVault::class), app(PayloadRedactor::class)),
            );
        });
    }

    /** @return array<string, mixed> */
    private function listing(): array
    {
        $product = fn (int $id, string $sku, string $size, int $qty, bool $enabled = true, bool $deleted = false): array => [
            'product_id' => $id,
            'sku' => $sku,
            'is_deleted' => $deleted,
            'property_values' => [
                ['property_id' => 200, 'property_name' => 'Renk', 'values' => ['Beyaz']],
                ['property_id' => 100, 'property_name' => 'Beden', 'values' => [$size]],
            ],
            'offerings' => [[
                'offering_id' => $id + 2000,
                'quantity' => $qty,
                'is_enabled' => $enabled,
                'is_deleted' => false,
                'price' => ['amount' => 1990, 'divisor' => 100, 'currency_code' => 'TRY'],
            ]],
        ];

        return [
            'listing_id' => 9001,
            'title' => 'Kedi Ti&#351;&#246;rt',
            'description' => 'Pamuklu.',
            'state' => 'active',
            'url' => 'https://www.etsy.com/listing/9001/kedi',
            'images' => [
                ['rank' => 2, 'url_fullxfull' => 'https://i.etsystatic.com/2.jpg'],
                ['rank' => 1, 'url_fullxfull' => 'https://i.etsystatic.com/1.jpg'],
            ],
            'inventory' => ['products' => [
                $product(5000, 'TSH-S', 'S', 3),
                $product(5001, '', 'M', 5),
                $product(5002, 'TSH-L', 'L', 7, enabled: false),
                $product(5003, 'TSH-XL', 'XL', 9, deleted: true),
            ]],
        ];
    }
}
