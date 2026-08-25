<?php

declare(strict_types=1);

namespace Tests\Feature\Channels;

use App\Domain\Channels\Adapters\Shopify\ShopifyAdapter;
use App\Domain\Channels\Contracts\SupportsCatalogImport;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Models\ChannelType;
use App\Domain\Channels\Registry\AdapterRegistry;
use App\Domain\Channels\Support\ChannelHttpClient;
use App\Domain\Channels\Support\CredentialVault;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use App\Support\Logging\PayloadRedactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Shopify ürün içe aktarma — slice 1.4.
 *
 * V3.0 · §06 · v2.2 §13 · Faz 3 · madde 5 · `SupportsCatalogImport`.
 *
 * Bu yetenek `SupportsCatalog`'un okuma metotlarının TERSİNİ sorar:
 * onlar "benim varyantım kanalda var mı" der, bu "kanalda ne var ki bende
 * YOK" der. Girdi olarak yerel kayıt YOKTUR.
 *
 * ⚠️ VARYANT SORGULANIR, ÜRÜN DEĞİL. Bizde satılabilir birim VARYANTTIR ve
 * SKU orada yaşar; `products` sorgusu kullanılsaydı çok varyantlı bir
 * Shopify ürünü tek satıra çöker ve varyantların SKU'ları KAYBOLURDU.
 */
final class ShopifyCatalogImportTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Sayfa okunur ve varyantlar içe aktarılabilir ürüne dönüşür.
     */
    #[Test]
    public function a_page_of_variants_is_mapped_to_importable_products(): void
    {
        Http::fake(['*' => Http::response([
            'data' => ['productVariants' => [
                'nodes' => [
                    [
                        'id' => 'gid://shopify/ProductVariant/456',
                        'sku' => 'TSH-KIRMIZI-M',
                        'barcode' => '8690000000001',
                        'price' => '129.90',
                        'inventoryQuantity' => 7,
                        'product' => [
                            'id' => 'gid://shopify/Product/123',
                            'title' => 'Kırmızı Tişört',
                            'status' => 'ACTIVE',
                            'vendor' => 'Marka A',
                            'descriptionHtml' => '<p>Pamuklu</p>',
                        ],
                    ],
                ],
                'pageInfo' => ['hasNextPage' => false, 'endCursor' => 'eyJsYXN0X2lkIjo0NTZ9'],
            ]],
        ], 200)]);

        $page = $this->adapter()->fetchProductPage();

        $this->assertCount(1, $page->products);

        $product = $page->products[0];

        $this->assertSame('TSH-KIRMIZI-M', $product->sku);
        $this->assertSame('Kırmızı Tişört', $product->title);
        $this->assertSame('129.90', $product->price);
        $this->assertSame(7, $product->quantity);
        $this->assertSame('Marka A', $product->brand);
        $this->assertSame('8690000000001', $product->barcode);
        $this->assertTrue($product->isImportable());
    }

    /**
     * ⚠️ FİYAT STRING KALIR — float dönüşümü kuruş kayması üretir.
     *
     * `19.90 * 100` IEEE-754'te `1989.99...` olabilir ve `(int)` cast'i
     * onu aşağı keser. Para her zaman metin taşınır.
     */
    #[Test]
    public function the_price_is_carried_as_a_string(): void
    {
        Http::fake(['*' => Http::response([
            'data' => ['productVariants' => [
                'nodes' => [[
                    'id' => 'gid://shopify/ProductVariant/1',
                    'sku' => 'A',
                    'price' => '19.90',
                    'product' => ['id' => 'gid://shopify/Product/1', 'title' => 'X'],
                ]],
                'pageInfo' => ['hasNextPage' => false],
            ]],
        ], 200)]);

        $price = $this->adapter()->fetchProductPage()->products[0]->price;

        $this->assertIsString($price);
        $this->assertSame('19.90', $price);
    }

    /**
     * ⚠️ SKU'SUZ VARYANT `null` SKU ile döner — UYDURULMAZ.
     *
     * Shopify'da SKU zorunlu DEĞİLDİR. Kanal kimliğini (gid) SKU yapmak,
     * satıcı aynı ürünü kendi SKU'suyla yüklediğinde KOPYA ürün üretirdi ve
     * iki satır ayrı ayrı senkronlanırdı.
     *
     * Satır DÜŞÜRÜLMEZ, `isImportable()` false döner: içe aktarma onu SAYAR
     * ve ADIYLA raporlar. Sessizce düşseydi satıcı "50 ürünüm vardı, 47'si
     * geldi" der ve sebebini bulamazdı (§13 · madde 5).
     */
    #[Test]
    public function a_variant_without_a_sku_is_returned_but_marked_unimportable(): void
    {
        Http::fake(['*' => Http::response([
            'data' => ['productVariants' => [
                'nodes' => [[
                    'id' => 'gid://shopify/ProductVariant/1',
                    'sku' => '',
                    'price' => '10.00',
                    'product' => ['id' => 'gid://shopify/Product/1', 'title' => 'SKU\'suz Ürün'],
                ]],
                'pageInfo' => ['hasNextPage' => false],
            ]],
        ], 200)]);

        $page = $this->adapter()->fetchProductPage();

        $this->assertCount(1, $page->products, 'Satır DÜŞÜRÜLDÜ — kullanıcı sebebini göremez.');
        $this->assertNull($page->products[0]->sku);
        $this->assertFalse($page->products[0]->isImportable());
        $this->assertSame('SKU\'suz Ürün', $page->products[0]->title);
    }

    /**
     * ⚠️ İMLEÇ OPAKTIR ve olduğu gibi geri verilir.
     *
     * Shopify'da imleç base64 bir token'dır (Woo'daki sayfa numarasının
     * aksine). Çekirdek onu YORUMLAMAZ; sayı varsayılsaydı bu kanal
     * eklenirken kırılırdı.
     */
    #[Test]
    public function the_opaque_cursor_is_carried_verbatim(): void
    {
        Http::fake(['*' => Http::response([
            'data' => ['productVariants' => [
                'nodes' => [],
                'pageInfo' => ['hasNextPage' => true, 'endCursor' => 'eyJsYXN0X2lkIjo5OTl9'],
            ]],
        ], 200)]);

        $page = $this->adapter()->fetchProductPage();

        $this->assertSame('eyJsYXN0X2lkIjo5OTl9', $page->nextCursor);
        $this->assertTrue($page->hasMore);
    }

    /** Verilen imleç sorguya `after` olarak geçer. */
    #[Test]
    public function the_cursor_is_sent_back_as_the_after_argument(): void
    {
        Http::fake(['*' => Http::response([
            'data' => ['productVariants' => ['nodes' => [], 'pageInfo' => ['hasNextPage' => false]]],
        ], 200)]);

        $this->adapter()->fetchProductPage('eyJsYXN0X2lkIjo0NTZ9');

        Http::assertSent(function ($request): bool {
            return ($request->data()['variables']['cursor'] ?? null) === 'eyJsYXN0X2lkIjo0NTZ9';
        });
    }

    /**
     * ⚠️ `hasMore` FALSE İSE İMLEÇ TAŞINMAZ.
     *
     * Shopify son sayfada BİLE `endCursor` döndürür. `nextCursor !== null`
     * turu durduran koşul sayılsaydı tur sonsuza kadar boş sayfa çeker ve
     * kotayı yakardı — `hasMore` AYRI bir alandır ve turu o durdurur.
     */
    #[Test]
    public function the_last_page_carries_no_cursor_even_when_shopify_returns_one(): void
    {
        Http::fake(['*' => Http::response([
            'data' => ['productVariants' => [
                'nodes' => [],
                // Shopify son sayfada da endCursor döndürür.
                'pageInfo' => ['hasNextPage' => false, 'endCursor' => 'SON-IMLEC'],
            ]],
        ], 200)]);

        $page = $this->adapter()->fetchProductPage();

        $this->assertFalse($page->hasMore);
        $this->assertNull(
            $page->nextCursor,
            'Son sayfanın imleci taşındı — sonraki tur boş sayfadan başlar.',
        );
    }

    /**
     * ⚠️ SORGU VARYANT SEVİYESİNDEDİR.
     *
     * `products` sorgusu kullanılsaydı çok varyantlı ürünlerin SKU'ları
     * kaybolurdu — bu testin varlık sebebi.
     */
    #[Test]
    public function the_query_reads_variants_not_products(): void
    {
        Http::fake(['*' => Http::response([
            'data' => ['productVariants' => ['nodes' => [], 'pageInfo' => ['hasNextPage' => false]]],
        ], 200)]);

        $this->adapter()->fetchProductPage();

        Http::assertSent(function ($request): bool {
            $query = (string) ($request->data()['query'] ?? '');

            return str_contains($query, 'productVariants(')
                && str_contains($query, 'sku');
        });
    }

    /**
     * SAYFA ÜST SINIRI EMNİYETTİR.
     *
     * `hasNextPage` sonsuza kadar `true` dönen bozuk bir kanalda tur hiç
     * bitmez ve worker'ı süresiz meşgul ederdi.
     */
    #[Test]
    public function the_import_declares_a_page_ceiling(): void
    {
        $this->assertGreaterThan(0, $this->adapter()->maxImportPages());
    }

    /**
     * ⚠️ P0-1 — içe aktarma sorgusu da `errors` kontrolünden geçer.
     *
     * GraphQL'de her şey 200'dür; kontrol atlanırsa bozuk bir sorgu BOŞ
     * sayfa olarak okunur ve kullanıcı "kanalda ürün yok" sanır.
     */
    #[Test]
    public function a_graphql_error_during_import_is_not_read_as_an_empty_page(): void
    {
        Http::fake(['*' => Http::response([
            'errors' => [['message' => 'Access denied for productVariants field']],
        ], 200)]);

        $this->expectExceptionMessageMatches('/Access denied/');

        $this->adapter()->fetchProductPage();
    }

    /** Yetenek `instanceof` ile okunur — panel yalnızca destekleyeni listeler. */
    #[Test]
    public function the_adapter_declares_the_catalog_import_capability(): void
    {
        $this->assertInstanceOf(SupportsCatalogImport::class, $this->adapter());
    }

    /**
     * Registry yetenekleri TİP SİSTEMİNDEN okur — seeder kolonundan değil.
     *
     * `channel_types.capabilities` kolonu yalnızca bir YANSIMADIR; asıl
     * kaynak `instanceof`'tur (§07 · değişmez kural). Panel bu haritayı
     * okur ve `if type === 'shopify'` bloğu YAZILMAZ.
     *
     * Bugün açık olan yetenekler yazıldı; kapalı olanlar slice 1.6–1.9'da
     * açılacak. Kapalı bir yeteneğin `false` görünmesi bir eksiklik değil,
     * §05'in "ilan edilen ama çalışmayan yetenek panelde çalışmayan sekme
     * demektir" kuralının uygulanmasıdır.
     */
    #[Test]
    public function the_registry_reports_shopify_capabilities_from_the_type_system(): void
    {
        $tenant = $this->makeTenant();

        $capabilities = $this->asTenant($tenant, function (): array {
            $connection = ChannelConnection::factory()->create([
                'channel_type_code' => 'shopify',
                'external_account_id' => 'magaza.myshopify.com',
            ]);

            return app(AdapterRegistry::class)->capabilitiesFor(
                $connection->load('channelType')
            );
        });

        // Slice 1.3–1.8'de yazıldı.
        $this->assertTrue($capabilities['catalog']);
        $this->assertTrue($capabilities['catalog_import']);
        $this->assertTrue($capabilities['inventory']);
        $this->assertTrue($capabilities['pricing']);
        $this->assertTrue($capabilities['orders']);
        $this->assertTrue($capabilities['fulfillment']);

        // HİÇ yazılmayacak: Shopify'da kategori zorunlu değil ve onay
        // süreci yoktur (§04 · dipnotlar).
        $this->assertFalse($capabilities['taxonomy']);
        $this->assertFalse($capabilities['approval']);
    }

    // ─────────────────────────────────────────────────── yardımcılar

    private function adapter(): ShopifyAdapter
    {
        $tenant = $this->makeTenant();

        return $this->asTenant($tenant, function (): ShopifyAdapter {
            $connection = ChannelConnection::factory()->create([
                'channel_type_code' => 'shopify',
                'external_account_id' => 'magaza.myshopify.com',
                'settings' => ['location_gid' => 'gid://shopify/Location/12'],
            ]);

            app(CredentialVault::class)->store($connection, ['access_token' => 'shpat_test']);

            return new ShopifyAdapter(
                $connection,
                new ChannelHttpClient(
                    $connection,
                    app(CredentialVault::class),
                    app(PayloadRedactor::class),
                ),
            );
        });
    }

    private function makeTenant(): Tenant
    {
        $this->asSystem(fn (): ChannelType => ChannelType::query()->updateOrCreate(
            ['code' => 'shopify'],
            [
                'name' => 'Shopify',
                'kind' => 'storefront',
                'adapter_class' => ShopifyAdapter::class,
                'supports_webhooks' => true,
                'is_active' => false,
            ],
        ));

        return (new CreateTenant)->run(
            name: 'Shopify İçe Aktarma '.uniqid(),
            owner: User::factory()->create(),
        );
    }
}
