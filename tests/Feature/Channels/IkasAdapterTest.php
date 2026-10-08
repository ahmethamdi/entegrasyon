<?php

declare(strict_types=1);

namespace Tests\Feature\Channels;

use App\Domain\Channels\Adapters\Ikas\IkasAdapter;
use App\Domain\Channels\Adapters\Ikas\IkasGraphQLException;
use App\Domain\Channels\Adapters\Ikas\IkasQueries;
use App\Domain\Channels\Contracts\DeclaresConnectionSettings;
use App\Domain\Channels\Contracts\SupportsCatalog;
use App\Domain\Channels\Contracts\SupportsCatalogImport;
use App\Domain\Channels\Contracts\SupportsFulfillment;
use App\Domain\Channels\Contracts\SupportsInventory;
use App\Domain\Channels\Contracts\SupportsOrders;
use App\Domain\Channels\Contracts\SupportsPricing;
use App\Domain\Channels\Contracts\SupportsTokenRefresh;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Models\ChannelType;
use App\Domain\Channels\Support\ChannelHttpClient;
use App\Domain\Channels\Support\CredentialVault;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use App\Domain\Sync\Enums\ErrorClass;
use App\Domain\Sync\Models\Listing;
use App\Domain\Sync\Models\SyncOperation;
use App\Domain\Sync\Support\InventoryPushBatch;
use App\Domain\Sync\Support\InventoryPushItem;
use App\Domain\Sync\Support\PricePushBatch;
use App\Support\Logging\PayloadRedactor;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * IkasAdapter — GraphQL Admin API v2, Private App kimliği.
 *
 * Yanıtlar ikas'ın kendi istemci paketindeki (`@ikas/admin-api-client`
 * 2.1.0) üretilmiş şema tiplerindeki alan adlarıyla kurulur.
 *
 * DEĞİŞMEZ KURAL — HATA ORANI: ikas hata oranı yüksek mağazayı kalıcı
 * engeller. Kimliksiz, lokasyonsuz ya da ürün kimliksiz istek ATILMAZ;
 * GraphQL hatası KALICI sayılır.
 */
final class IkasAdapterTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_declares_only_the_capabilities_it_implements(): void
    {
        $adapter = $this->adapter();

        $this->assertInstanceOf(SupportsCatalogImport::class, $adapter);
        $this->assertInstanceOf(SupportsInventory::class, $adapter);
        $this->assertInstanceOf(SupportsPricing::class, $adapter);
        $this->assertInstanceOf(SupportsOrders::class, $adapter);
        $this->assertInstanceOf(SupportsTokenRefresh::class, $adapter);
        $this->assertInstanceOf(DeclaresConnectionSettings::class, $adapter);

        $this->assertNotInstanceOf(SupportsCatalog::class, $adapter);
        $this->assertNotInstanceOf(SupportsFulfillment::class, $adapter);
    }

    // ─────────────────────────────────────────────────── kimlik

    /** İstek v2 GraphQL adresine Bearer anahtarla gider. */
    #[Test]
    public function requests_go_to_the_v2_endpoint_with_the_bearer_token(): void
    {
        Http::fake(['*' => Http::response(['data' => ['getMerchant' => ['id' => 'm1', 'storeName' => 'magazam']]])]);

        $this->assertTrue($this->adapter()->healthCheck()->healthy);

        Http::assertSent(static fn (Request $r): bool => $r->url() === IkasQueries::GRAPHQL_URL
            && $r->method() === 'POST'
            && $r->hasHeader('Authorization', 'Bearer TOKEN-1')
            && str_contains((string) $r['query'], 'getMerchant'));
    }

    /** Erişim anahtarı yoksa istek HİÇ atılmaz — 401 hata oranına yazılırdı. */
    #[Test]
    public function without_an_access_token_nothing_is_sent(): void
    {
        Http::fake(['*' => Http::response([], 200)]);

        $health = $this->adapter(secrets: ['client_id' => 'CID', 'client_secret' => 'CSECRET-123'])->healthCheck();

        $this->assertFalse($health->healthy);
        $this->assertStringContainsString('erişim anahtarı', (string) $health->message);
        Http::assertNothingSent();
    }

    /** Başka mağazanın uygulama bilgileri sağlıklı SAYILMAZ (stok yanlış mağazaya giderdi). */
    #[Test]
    public function credentials_of_another_store_are_unhealthy(): void
    {
        Http::fake(['*' => Http::response(['data' => ['getMerchant' => ['id' => 'm1', 'storeName' => 'baskamagaza']]])]);

        $health = $this->adapter()->healthCheck();

        $this->assertFalse($health->healthy);
        $this->assertStringContainsString('baskamagaza', (string) $health->message);
    }

    /** `client_credentials` form gövdesiyle yeni anahtar; çift korunur, süre yanıttan. */
    #[Test]
    public function credentials_are_refreshed_with_client_credentials(): void
    {
        Http::fake([IkasQueries::TOKEN_URL => Http::response(['access_token' => 'TOKEN-2', 'token_type' => 'Bearer', 'expires_in' => 14400])]);

        $fresh = $this->adapter()->refreshCredentials();

        $this->assertSame('TOKEN-2', $fresh->secrets['access_token']);
        $this->assertSame('CID', $fresh->secrets['client_id']);
        $this->assertSame('CSECRET-123', $fresh->secrets['client_secret']);
        $this->assertEqualsWithDelta(time() + 14400, $fresh->expiresAt?->getTimestamp(), 5);

        Http::assertSent(static fn (Request $r): bool => $r->url() === IkasQueries::TOKEN_URL
            && str_contains($r->header('Content-Type')[0] ?? '', 'application/x-www-form-urlencoded')
            && $r['grant_type'] === 'client_credentials'
            && $r['client_id'] === 'CID'
            && $r['client_secret'] === 'CSECRET-123');
    }

    /** Uygulama çifti yoksa token isteği atılmaz, istisna yükselir. */
    #[Test]
    public function refresh_without_the_client_pair_throws_without_a_request(): void
    {
        Http::fake();

        try {
            $this->adapter(secrets: ['access_token' => 'TOKEN-1'])->refreshCredentials();
            $this->fail('İstisna bekleniyordu.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('client_id', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    // ─────────────────────────────────────────────────── hatalar

    /** 200 içindeki GraphQL hatası BAŞARI sayılmaz ve KALICIDIR (yeniden denenmez). */
    #[Test]
    public function graphql_errors_inside_a_200_are_permanent_failures(): void
    {
        Http::fake(['*' => Http::response(['errors' => [['message' => 'Cannot query field', 'extensions' => ['code' => 'GRAPHQL_VALIDATION_FAILED']]]])]);

        $adapter = $this->adapter();

        try {
            $adapter->fetchProductPage();
            $this->fail('İstisna bekleniyordu.');
        } catch (IkasGraphQLException $e) {
            $this->assertSame(ErrorClass::VALIDATION, $adapter->classifyError($e));
        }

        $this->assertSame(ErrorClass::AUTHENTICATION, $adapter->classifyError(new IkasGraphQLException('x', 'UNAUTHENTICATED')));
        $this->assertSame(ErrorClass::SERVER_ERROR, $adapter->classifyError(new IkasGraphQLException('x', 'INTERNAL_SERVER_ERROR')));
    }

    // ─────────────────────────────────────────────────── içe aktarma

    /**
     * Her varyant ayrı ürün; ürün kimliği `external_parent_id`; silinmiş
     * atlanır; SKU'suz varyanta `IKAS-` SKU'su; stok seçili lokasyonun;
     * fiyat müşterinin ödediği (indirim).
     */
    #[Test]
    public function variants_are_imported_as_products(): void
    {
        Http::fake(['*' => Http::response(['data' => ['listProduct' => [
            'count' => 3, 'hasNext' => true, 'page' => 1, 'limit' => 100,
            'data' => [
                [
                    'id' => 'p-1', 'name' => 'Tişört', 'description' => 'Pamuk', 'type' => 'PHYSICAL', 'deleted' => false,
                    'brand' => ['name' => 'Marka'],
                    'variants' => [
                        [
                            'id' => 'v-1', 'sku' => 'TS-KIR-M', 'barcodeList' => ['8690000000011'], 'isActive' => true, 'deleted' => false,
                            'prices' => [['sellPrice' => 300, 'discountPrice' => 249.9, 'currency' => 'TRY', 'priceListId' => null]],
                            'stocks' => [
                                ['stockLocationId' => 'loc-a', 'stockCount' => 4, 'deleted' => false],
                                ['stockLocationId' => 'loc-b', 'stockCount' => 9, 'deleted' => false],
                            ],
                            'variantValues' => [['variantTypeName' => 'Renk', 'variantValueName' => 'Kırmızı'], ['variantTypeName' => 'Beden', 'variantValueName' => 'M']],
                        ],
                        [
                            'id' => 'aaaa-bbbb-ccc', 'sku' => '', 'barcodeList' => [], 'isActive' => false, 'deleted' => false,
                            'prices' => [['sellPrice' => 300, 'discountPrice' => null, 'currency' => 'TRY', 'priceListId' => null]],
                            'stocks' => [], 'variantValues' => [],
                        ],
                        ['id' => 'v-dead', 'sku' => 'X', 'deleted' => true, 'isActive' => true, 'prices' => [], 'stocks' => []],
                    ],
                ],
                ['id' => 'p-gone', 'name' => 'Silinmiş', 'deleted' => true, 'type' => 'PHYSICAL', 'variants' => [['id' => 'v-9', 'sku' => 'Z', 'deleted' => false]]],
            ],
        ]]])]);

        $page = $this->adapter(settings: [IkasAdapter::STOCK_LOCATION_KEY => 'loc-a'])->fetchProductPage();

        $this->assertCount(2, $page->products);
        $this->assertTrue($page->hasMore);
        $this->assertSame('2', $page->nextCursor);

        [$first, $second] = $page->products;

        $this->assertSame('v-1', $first->externalId);
        $this->assertSame('TS-KIR-M', $first->sku);
        $this->assertSame('Tişört — Kırmızı / M', $first->title);
        $this->assertSame('249.90', $first->price);
        $this->assertSame(4, $first->quantity);
        $this->assertSame('8690000000011', $first->barcode);
        $this->assertSame('Marka', $first->brand);
        $this->assertSame(['external_id' => 'v-1', 'external_parent_id' => 'p-1'], $first->listingIdentity);

        $this->assertSame('IKAS-AAAABBBBCCC', $second->sku);
        $this->assertSame('300.00', $second->price);
        $this->assertSame('inactive', $second->status);

        Http::assertSent(static fn (Request $r): bool => $r['variables']['pagination'] === ['page' => 1, 'limit' => 100]);
    }

    /** Lokasyon seçilmemişse içe aktarılan stok bütün lokasyonların toplamıdır. */
    #[Test]
    public function without_a_chosen_location_the_imported_stock_is_the_total(): void
    {
        Http::fake(['*' => Http::response(['data' => ['listProduct' => ['hasNext' => false, 'data' => [[
            'id' => 'p-1', 'name' => 'A', 'deleted' => false, 'type' => 'PHYSICAL',
            'variants' => [['id' => 'v-1', 'sku' => 'A', 'deleted' => false, 'isActive' => true, 'prices' => [],
                'stocks' => [['stockLocationId' => 'loc-a', 'stockCount' => 4], ['stockLocationId' => 'loc-b', 'stockCount' => 9]]]],
        ]]]]])]);

        $page = $this->adapter()->fetchProductPage();

        $this->assertSame(13, $page->products[0]->quantity);
        $this->assertFalse($page->hasMore);
        $this->assertNull($page->nextCursor);
    }

    // ─────────────────────────────────────────────────── stok

    /** Seçili lokasyona MUTLAK değer; ürün kimliği listing'in üst kimliğinden. */
    #[Test]
    public function stock_is_written_to_the_chosen_location(): void
    {
        Http::fake(['*' => Http::response(['data' => ['saveVariantStocks' => ['isSuccess' => true, 'errorInputs' => []]]])]);

        [$adapter, $listingId] = $this->adapterWithListing(settings: [IkasAdapter::STOCK_LOCATION_KEY => 'loc-a']);

        $result = $adapter->pushInventory($this->stockBatch($listingId, 7));

        $this->assertTrue($result->successful);
        $this->assertFalse($result->hasFailedOperations());

        Http::assertSent(static fn (Request $r): bool => str_contains((string) $r['query'], 'saveVariantStocks')
            && $r['variables']['input']['stockInputs'] === [[
                'productId' => 'p-1', 'variantId' => 'v-1', 'stockLocationId' => 'loc-a', 'stockCount' => 7, 'deleted' => false,
            ]]);
    }

    /** Tek lokasyonlu mağazada seçim beklenmez; o lokasyona yazılır. */
    #[Test]
    public function a_single_location_store_needs_no_choice(): void
    {
        Http::fakeSequence()
            ->push(['data' => ['listStockLocation' => [['id' => 'only-loc', 'name' => 'Depo', 'deleted' => false]]]])
            ->push(['data' => ['saveVariantStocks' => ['isSuccess' => true, 'errorInputs' => []]]]);

        [$adapter, $listingId] = $this->adapterWithListing();

        $this->assertTrue($adapter->pushInventory($this->stockBatch($listingId, 3))->successful);

        Http::assertSent(static fn (Request $r): bool => ($r['variables']['input']['stockInputs'][0]['stockLocationId'] ?? null) === 'only-loc');
    }

    /** Çok lokasyonlu mağazada seçim yoksa stok YAZILMAZ — kalıcı hata, yazma isteği yok. */
    #[Test]
    public function several_locations_without_a_choice_write_nothing(): void
    {
        Http::fake(['*' => Http::response(['data' => ['listStockLocation' => [
            ['id' => 'loc-a', 'name' => 'A', 'deleted' => false],
            ['id' => 'loc-b', 'name' => 'B', 'deleted' => false],
        ]]])]);

        [$adapter, $listingId] = $this->adapterWithListing();

        $result = $adapter->pushInventory($this->stockBatch($listingId, 3));

        $this->assertTrue($result->failed());
        $this->assertSame(ErrorClass::VALIDATION, $result->errorClass);
        Http::assertNotSent(static fn (Request $r): bool => str_contains((string) $r['query'], 'saveVariantStocks'));
    }

    /**
     * ikas'ın reddettiği varyant KENDİ operasyonuna kalıcı hata olarak
     * yazılır (sırayla değil kimlikle); geçen kalem başarılı kalır.
     */
    #[Test]
    public function a_rejected_variant_fails_only_its_own_operation(): void
    {
        Http::fake(['*' => Http::response(['data' => ['saveVariantStocks' => [
            'isSuccess' => false, 'errorInputs' => [['productId' => 'p-1', 'variantId' => 'v-2']],
        ]]])]);

        [$tenant, $connection] = $this->connection(settings: [IkasAdapter::STOCK_LOCATION_KEY => 'loc-a']);
        $first = $this->listing($tenant, $connection, 'v-1', 'p-1');
        $second = $this->listing($tenant, $connection, 'v-2', 'p-1');

        $ops = [$this->operation('op-1'), $this->operation('op-2')];

        $result = $this->asTenant($tenant, fn () => $this->adapterFor($connection)->pushInventory(new InventoryPushBatch(
            channelConnectionId: $connection->id,
            items: [
                new InventoryPushItem(listingId: $first, externalId: 'v-1', sku: 'A', quantity: 1, version: 1),
                new InventoryPushItem(listingId: $second, externalId: 'v-2', sku: 'B', quantity: 2, version: 1),
            ],
            operations: $ops,
        )));

        $this->assertTrue($result->successful);
        $this->assertSame(['op-2'], array_keys($result->failedOperations));
        $this->assertSame(ErrorClass::VALIDATION, $result->errorClass);
    }

    /** Ürün kimliği bilinmeyen kalem için istek kurulmaz. */
    #[Test]
    public function a_listing_without_a_product_id_is_not_sent(): void
    {
        Http::fake();

        [$tenant, $connection] = $this->connection(settings: [IkasAdapter::STOCK_LOCATION_KEY => 'loc-a']);
        $listing = $this->listing($tenant, $connection, 'v-1', null);

        $result = $this->asTenant($tenant, fn () => $this->adapterFor($connection)->pushInventory($this->stockBatch($listing, 1)));

        $this->assertTrue($result->failed());
        $this->assertStringContainsString('ürün kimliği', (string) $result->errorMessage);
        Http::assertNothingSent();
    }

    // ─────────────────────────────────────────────────── fiyat

    /**
     * Karşılaştırma fiyatı yüksekse `sellPrice` = karşılaştırma,
     * `discountPrice` = satış; değilse indirim AÇIKÇA silinir.
     */
    #[Test]
    public function prices_map_to_sell_and_discount_price(): void
    {
        Http::fake(['*' => Http::response(['data' => ['updateVariantPrices' => ['isSuccess' => true, 'errorInputs' => []]]])]);

        [$tenant, $connection] = $this->connection(settings: [IkasAdapter::CURRENCY_KEY => 'eur']);
        $a = $this->listing($tenant, $connection, 'v-1', 'p-1');
        $b = $this->listing($tenant, $connection, 'v-2', 'p-1');

        $result = $this->asTenant($tenant, fn () => $this->adapterFor($connection)->pushPrices(new PricePushBatch(
            channelConnectionId: $connection->id,
            items: [
                ['listing_id' => $a, 'external_id' => 'v-1', 'price' => '199.90', 'compare_at_price' => '249.90', 'version' => 1],
                ['listing_id' => $b, 'external_id' => 'v-2', 'price' => '99.00', 'compare_at_price' => null, 'version' => 1],
            ],
        )));

        $this->assertTrue($result->successful);

        Http::assertSent(static fn (Request $r): bool => $r['variables']['input']['priceListId'] === null
            && $r['variables']['input']['variantPriceInputs'] === [
                ['productId' => 'p-1', 'variantId' => 'v-1', 'price' => ['sellPrice' => 249.9, 'discountPrice' => 199.9, 'currency' => 'EUR'], 'deleted' => false],
                ['productId' => 'p-1', 'variantId' => 'v-2', 'price' => ['sellPrice' => 99.0, 'discountPrice' => null, 'currency' => 'EUR'], 'deleted' => false],
            ]);
    }

    /** Uzak stok/fiyat ürün kimliğiyle okunur; kimliksiz listing yoksa çağrı yok. */
    #[Test]
    public function remote_stock_and_price_are_read_by_product_id(): void
    {
        Http::fake(['*' => Http::response(['data' => ['listProduct' => ['hasNext' => false, 'data' => [[
            'id' => 'p-1', 'name' => 'A', 'deleted' => false, 'type' => 'PHYSICAL',
            'variants' => [['id' => 'v-1', 'sku' => 'A', 'deleted' => false, 'isActive' => true,
                'prices' => [['sellPrice' => 120, 'discountPrice' => 100, 'priceListId' => null], ['sellPrice' => 1, 'priceListId' => 'pl-b2b']],
                'stocks' => [['stockLocationId' => 'loc-a', 'stockCount' => 5], ['stockLocationId' => 'loc-b', 'stockCount' => 2]]]],
        ]]]]])]);

        [$tenant, $connection] = $this->connection(settings: [IkasAdapter::STOCK_LOCATION_KEY => 'loc-a']);
        $this->listing($tenant, $connection, 'v-1', 'p-1');

        [$stock, $prices] = $this->asTenant($tenant, function () use ($connection): array {
            $listings = Listing::query()->where('channel_connection_id', $connection->id)->get()->all();
            $adapter = $this->adapterFor($connection);

            return [$adapter->fetchInventory($listings), $adapter->fetchPrices($listings)];
        });

        $this->assertSame(5, $stock->quantityFor('v-1'));
        $this->assertSame('100.00', $prices->priceFor('v-1'));

        Http::assertSent(static fn (Request $r): bool => ($r['variables']['id'] ?? null) === ['in' => ['p-1']]);

        Http::fake();
        $this->asTenant($tenant, fn () => $this->adapterFor($connection)->fetchInventory([]));
        Http::assertNothingSent();
    }

    // ─────────────────────────────────────────────────── webhook

    /** İmza gövdedeki `data` METNİNİN HMAC-SHA256'sı; anahtar `client_secret`. */
    #[Test]
    public function the_webhook_signature_is_the_hmac_of_the_data_string(): void
    {
        $adapter = $this->adapter();
        $data = '{"id":"o-1","orderNumber":"1001"}';

        $good = json_encode(['data' => $data, 'signature' => hash_hmac('sha256', $data, 'CSECRET-123')]);
        $bad = json_encode(['data' => $data, 'signature' => hash_hmac('sha256', $data, 'yanlis')]);

        $this->assertTrue($adapter->verifyWebhookSignature((string) $good, []));
        $this->assertFalse($adapter->verifyWebhookSignature((string) $bad, []));
        $this->assertFalse($adapter->verifyWebhookSignature('{"data":{}}', []));
    }

    // ─────────────────────────────────────────────────── siparişler

    /**
     * Yoklama `updatedAt ≥ since` MİLİSANİYE; taslak sipariş alınmaz;
     * iptal edilen ve iadesi teslim alınan her kalem ayrı kayıt; REFUNDED
     * (yalnız para iadesi) stoğa dönmez.
     */
    #[Test]
    public function orders_are_polled_by_update_time_and_split_per_line(): void
    {
        Http::fake(['*' => Http::response(['data' => ['listOrder' => ['hasNext' => true, 'data' => [
            ['id' => 'o-draft', 'status' => 'DRAFT', 'orderLineItems' => []],
            ['id' => 'o-1', 'orderNumber' => '1001', 'status' => 'PARTIALLY_CANCELLED', 'orderLineItems' => [
                ['id' => 'l-1', 'quantity' => 1, 'status' => 'FULFILLED', 'variant' => ['id' => 'v-1', 'sku' => 'A']],
                ['id' => 'l-2', 'quantity' => 1, 'status' => 'CANCELLED', 'variant' => ['id' => 'v-2', 'sku' => 'B']],
                ['id' => 'l-3', 'quantity' => 1, 'status' => 'REFUND_DELIVERED', 'variant' => ['id' => 'v-3', 'sku' => 'C']],
                ['id' => 'l-4', 'quantity' => 1, 'status' => 'REFUNDED', 'variant' => ['id' => 'v-4', 'sku' => 'D']],
            ]],
        ]]]])]);

        $adapter = $this->adapter();
        $page = $adapter->fetchOrders(Carbon::createFromTimestamp(1_760_000_000));

        $ids = array_map(fn (array $o): ?string => $adapter->pollingEventIdFor($o), $page->orders);

        $this->assertSame(['o-1:created', 'o-1:cancel:l-2', 'o-1:return:l-3'], $ids);
        $this->assertTrue($page->hasMore);
        $this->assertSame('2', $page->nextCursor);

        Http::assertSent(static fn (Request $r): bool => $r['variables']['updatedAt'] === ['gte' => 1_760_000_000_000]);
    }

    // ─────────────────────────────────────────────────── yardımcılar

    private function stockBatch(string $listingId, int $quantity): InventoryPushBatch
    {
        return new InventoryPushBatch(
            channelConnectionId: 'c1',
            items: [new InventoryPushItem(listingId: $listingId, externalId: 'v-1', sku: 'A', quantity: $quantity, version: 1)],
        );
    }

    private function operation(string $id): SyncOperation
    {
        $operation = new SyncOperation;
        $operation->forceFill(['id' => $id]);

        return $operation;
    }

    /**
     * @param  array<string, string>  $settings
     * @return array{0: IkasAdapter, 1: string}
     */
    private function adapterWithListing(array $settings = []): array
    {
        [$tenant, $connection] = $this->connection(settings: $settings);
        $listingId = $this->listing($tenant, $connection, 'v-1', 'p-1');

        return [$this->asTenant($tenant, fn () => $this->adapterFor($connection)), $listingId];
    }

    private function listing(Tenant $tenant, ChannelConnection $connection, string $variantId, ?string $productId): string
    {
        return $this->asTenant($tenant, fn (): string => Listing::factory()->create([
            'tenant_id' => $tenant->id,
            'channel_connection_id' => $connection->id,
            'external_id' => $variantId,
            'external_parent_id' => $productId,
        ])->id);
    }

    /**
     * @param  array<string, string>  $secrets
     * @param  array<string, string>  $settings
     */
    private function adapter(
        array $secrets = ['client_id' => 'CID', 'client_secret' => 'CSECRET-123', 'access_token' => 'TOKEN-1'],
        array $settings = [],
    ): IkasAdapter {
        [$tenant, $connection] = $this->connection($secrets, $settings);

        return $this->asTenant($tenant, fn () => $this->adapterFor($connection));
    }

    /**
     * @param  array<string, string>  $secrets
     * @param  array<string, string>  $settings
     * @return array{0: Tenant, 1: ChannelConnection}
     */
    private function connection(
        array $secrets = ['client_id' => 'CID', 'client_secret' => 'CSECRET-123', 'access_token' => 'TOKEN-1'],
        array $settings = [],
    ): array {
        $this->channelType();

        $tenant = (new CreateTenant)->run(name: 'ikas '.uniqid(), owner: User::factory()->create());

        $connection = $this->asTenant($tenant, function () use ($secrets, $settings): ChannelConnection {
            $connection = ChannelConnection::factory()->create([
                'channel_type_code' => 'ikas',
                'external_account_id' => 'magazam',
                'settings' => $settings,
            ]);

            app(CredentialVault::class)->store($connection, $secrets);

            return $connection;
        });

        return [$tenant, $connection];
    }

    private function adapterFor(ChannelConnection $connection): IkasAdapter
    {
        return new IkasAdapter(
            $connection,
            new ChannelHttpClient($connection, app(CredentialVault::class), app(PayloadRedactor::class)),
        );
    }

    private function channelType(): ChannelType
    {
        return $this->asSystem(fn (): ChannelType => ChannelType::query()->updateOrCreate(
            ['code' => 'ikas'],
            [
                'name' => 'ikas',
                'kind' => 'storefront',
                'adapter_class' => IkasAdapter::class,
                'capabilities' => [
                    'catalog' => false, 'catalog_import' => true, 'inventory' => true, 'pricing' => true,
                    'orders' => true, 'taxonomy' => false, 'approval' => false, 'fulfillment' => false,
                ],
                'rate_limit_profile' => [],
                'supports_webhooks' => false,
                'is_active' => false,
            ],
        ));
    }
}
