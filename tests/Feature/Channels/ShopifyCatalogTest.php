<?php

declare(strict_types=1);

namespace Tests\Feature\Channels;

use App\Domain\Catalog\Models\Variant;
use App\Domain\Channels\Adapters\Shopify\ShopifyAdapter;
use App\Domain\Channels\Contracts\SupportsCatalog;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Models\ChannelType;
use App\Domain\Channels\Support\ChannelHttpClient;
use App\Domain\Channels\Support\CredentialVault;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use App\Domain\Sync\Models\Listing;
use App\Domain\Sync\Support\ListingPayload;
use App\Support\Logging\PayloadRedactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Shopify katalog — slice 1.3.
 *
 * V3.0 · §06.4 · §07.
 *
 * ⚠️ EN KRİTİK İDDİA: ÜÇ KİMLİK BİRDEN SAKLANIR.
 *
 *   Product        gid://shopify/Product/123        → external_parent_id
 *     ProductVariant  gid://shopify/ProductVariant/456 → external_id
 *       InventoryItem   gid://shopify/InventoryItem/789 → channel_metadata
 *
 * `inventory_item_gid` STOK YAZMA HEDEFİDİR: Shopify'ın stok mutation'ı
 * variant gid'i KABUL ETMEZ. Kaydedilmezse her stok itmesi ek bir GraphQL
 * sorgusu gerektirir ve kritik yolu (`inventory:high`, 45 sn) İKİ KATINA
 * çıkarır (§06.4).
 *
 * `external_id` = VARIANT gid, product DEĞİL: bizde listing satırı VARYANT
 * başınadır. Product gid yazılsaydı üç varyantlı ürünün üç listing satırı
 * AYNI `external_id`'yi taşır ve tekillik kısıtı ikincisini REDDEDERDİ.
 */
final class ShopifyCatalogTest extends TestCase
{
    use RefreshDatabase;

    /**
     * ⚠️ ÜÇ KİMLİK DE OKUNUR ve `AdapterResult` ile taşınır.
     *
     * Adapter veritabanına YAZMAZ (v2.2 · "adapter yan etkisizdir");
     * yazmayı `PushListing` yapar.
     */
    #[Test]
    public function creating_a_listing_returns_all_three_persistent_identifiers(): void
    {
        Http::fake(['*' => Http::response([
            'data' => ['productSet' => [
                'product' => [
                    'id' => 'gid://shopify/Product/123',
                    'variants' => ['nodes' => [[
                        'id' => 'gid://shopify/ProductVariant/456',
                        'sku' => 'TSH-KIRMIZI-M',
                        'inventoryItem' => ['id' => 'gid://shopify/InventoryItem/789'],
                    ]]],
                ],
                'userErrors' => [],
            ]],
        ], 200)]);

        [$adapter, $listing] = $this->adapterWithListing();

        $result = $adapter->createListing($this->payload($listing));

        $this->assertFalse($result->failed());
        $this->assertSame('gid://shopify/ProductVariant/456', $result->data['external_id']);
        $this->assertSame('gid://shopify/Product/123', $result->data['external_parent_id']);
        $this->assertSame(
            'gid://shopify/InventoryItem/789',
            $result->data['channel_metadata']['inventory_item_gid'],
            'inventory_item_gid saklanmadı — stok yazma hedefi kaybolur.',
        );
    }

    /**
     * ⚠️ P0-1 — `userErrors` DOLU İSE create BAŞARISIZDIR.
     *
     * Yanıt HTTP 200'dür. Kontrol edilmezse `synced_version` ilerler ve
     * kanalda ürün YOKKEN satır "senkron" görünür.
     */
    #[Test]
    public function a_create_with_user_errors_does_not_report_success(): void
    {
        Http::fake(['*' => Http::response([
            'data' => ['productSet' => [
                'product' => null,
                'userErrors' => [['field' => ['input', 'title'], 'message' => 'Title required']],
            ]],
        ], 200)]);

        [$adapter, $listing] = $this->adapterWithListing();

        $this->expectExceptionMessageMatches('/Title required/');

        $adapter->createListing($this->payload($listing));
    }

    /**
     * 200 + `userErrors` boş AMA ürün YOK → yine de BAŞARISIZ.
     *
     * Sözleşme ihlalidir. Başarı dönülseydi `synced_version` ilerler ve
     * satır kanalda karşılığı olmadan "senkron" görünürdü — P0-1'in
     * kardeşi ve tam olarak aynı sonucu doğurur.
     */
    #[Test]
    public function a_response_without_a_product_is_a_failure(): void
    {
        Http::fake(['*' => Http::response([
            'data' => ['productSet' => ['product' => null, 'userErrors' => []]],
        ], 200)]);

        [$adapter, $listing] = $this->adapterWithListing();

        $result = $adapter->createListing($this->payload($listing));

        $this->assertTrue($result->failed());
    }

    /**
     * Varyant kimliği YOKSA başarı dönülmez.
     *
     * `external_id` yazılmadan listing kanalda ADRESLENEMEZ: sonraki tur
     * update çağırır, Shopify boş gid'i tanımaz ve `VALIDATION` döner —
     * o hata KALICIDIR ve listing "düzeltilemez" damgasıyla ölür.
     */
    #[Test]
    public function a_product_without_a_variant_id_is_a_failure(): void
    {
        Http::fake(['*' => Http::response([
            'data' => ['productSet' => [
                'product' => ['id' => 'gid://shopify/Product/123', 'variants' => ['nodes' => []]],
                'userErrors' => [],
            ]],
        ], 200)]);

        [$adapter, $listing] = $this->adapterWithListing();

        $result = $adapter->createListing($this->payload($listing));

        $this->assertTrue($result->failed());
    }

    /**
     * SKU ve FİYAT varyanta yazılır; fiyat STRING taşınır.
     *
     * Para float taşınmaz — yuvarlama kuruş kayması üretir. `decimal(12,2)`
     * PHP'ye zaten string döner ve `(float)` dönüşümü YAPILMAZ.
     */
    #[Test]
    public function the_payload_carries_sku_and_a_string_price(): void
    {
        Http::fake(['*' => Http::response([
            'data' => ['productSet' => [
                'product' => [
                    'id' => 'gid://shopify/Product/1',
                    'variants' => ['nodes' => [[
                        'id' => 'gid://shopify/ProductVariant/2',
                        'sku' => 'SKU-1',
                        'inventoryItem' => ['id' => 'gid://shopify/InventoryItem/3'],
                    ]]],
                ],
                'userErrors' => [],
            ]],
        ], 200)]);

        [$adapter, $listing] = $this->adapterWithListing(sku: 'SKU-1', price: '129.90');

        $adapter->createListing($this->payload($listing));

        Http::assertSent(function ($request): bool {
            $variant = $request->data()['variables']['input']['variants'][0] ?? [];

            return ($variant['sku'] ?? null) === 'SKU-1'
                && ($variant['price'] ?? null) === '129.90'
                && is_string($variant['price'] ?? null);
        });
    }

    /**
     * ⚠️ İÇERİK YÜKÜ STOK TAŞIMAZ.
     *
     * v2.2 · katalog kuralı: içerik düzenlemesi stoğa DOKUNMAZ. Yükte stok
     * gönderilseydi her başlık düzeltmesi kanaldaki stoğu da ezer ve
     * `PushInventory` dışında ikinci bir stok yazma yolu doğardı — mutlak
     * değer kuralının tek kapısı kırılırdı.
     */
    #[Test]
    public function the_content_payload_never_carries_stock(): void
    {
        Http::fake(['*' => Http::response([
            'data' => ['productSet' => [
                'product' => [
                    'id' => 'gid://shopify/Product/1',
                    'variants' => ['nodes' => [[
                        'id' => 'gid://shopify/ProductVariant/2',
                        'inventoryItem' => ['id' => 'gid://shopify/InventoryItem/3'],
                    ]]],
                ],
                'userErrors' => [],
            ]],
        ], 200)]);

        [$adapter, $listing] = $this->adapterWithListing();

        $adapter->createListing($this->payload($listing));

        Http::assertSent(function ($request): bool {
            $variant = $request->data()['variables']['input']['variants'][0] ?? [];

            return ! array_key_exists('inventoryQuantities', $variant)
                && ! array_key_exists('quantity', $variant)
                && ! array_key_exists('inventoryQuantity', $variant);
        });
    }

    /**
     * Güncelleme ÜRÜN gid'ini hedefler.
     *
     * İçerik ürün seviyesindedir; varyant gid tek başına ürün mutation'ına
     * verilemez.
     */
    #[Test]
    public function updating_targets_the_product_gid(): void
    {
        Http::fake(['*' => Http::response([
            'data' => ['productSet' => [
                'product' => [
                    'id' => 'gid://shopify/Product/123',
                    'variants' => ['nodes' => [[
                        'id' => 'gid://shopify/ProductVariant/456',
                        'inventoryItem' => ['id' => 'gid://shopify/InventoryItem/789'],
                    ]]],
                ],
                'userErrors' => [],
            ]],
        ], 200)]);

        [$adapter, $listing] = $this->adapterWithListing(
            externalId: 'gid://shopify/ProductVariant/456',
            externalParentId: 'gid://shopify/Product/123',
        );

        $result = $adapter->updateListing($this->payload($listing));

        $this->assertFalse($result->failed());

        Http::assertSent(function ($request): bool {
            return ($request->data()['variables']['input']['id'] ?? null)
                === 'gid://shopify/Product/123';
        });
    }

    /**
     * Ürün gid'i bilinmiyorsa güncelleme YAPILMAZ.
     *
     * Sessizce create'e düşülseydi kanalda KOPYA ürün açılırdı.
     */
    #[Test]
    public function updating_without_a_product_gid_fails_cleanly(): void
    {
        Http::fake();

        [$adapter, $listing] = $this->adapterWithListing(
            externalId: 'gid://shopify/ProductVariant/456',
            externalParentId: null,
        );

        $result = $adapter->updateListing($this->payload($listing));

        $this->assertTrue($result->failed());
        Http::assertNothingSent();
    }

    /**
     * ⚠️ `delist` SİLMEZ — ARŞİVLER.
     *
     * v2.2 kuralı: silme geri alınamaz ve kanaldaki yorumları, sıralamayı,
     * SEO geçmişini de götürür. `ARCHIVED` seçildi, `DRAFT` değil: taslak
     * "henüz yayınlanmadı" der, arşiv "yayındaydı, kaldırıldı" der.
     */
    #[Test]
    public function delisting_archives_the_product_instead_of_deleting_it(): void
    {
        Http::fake(['*' => Http::response([
            'data' => ['productSet' => [
                'product' => ['id' => 'gid://shopify/Product/123', 'status' => 'ARCHIVED'],
                'userErrors' => [],
            ]],
        ], 200)]);

        [$adapter, $listing] = $this->adapterWithListing(
            externalId: 'gid://shopify/ProductVariant/456',
            externalParentId: 'gid://shopify/Product/123',
        );

        $adapter->delist($listing);

        Http::assertSent(function ($request): bool {
            $input = $request->data()['variables']['input'] ?? [];

            return ($input['status'] ?? null) === 'ARCHIVED'
                && ! str_contains($request->body(), 'productDelete');
        });
    }

    /**
     * Kanalda karşılığı olmayan listing için çağrı ATILMAZ.
     */
    #[Test]
    public function delisting_a_listing_that_was_never_pushed_sends_nothing(): void
    {
        Http::fake();

        [$adapter, $listing] = $this->adapterWithListing(externalParentId: null);

        $result = $adapter->delist($listing);

        $this->assertFalse($result->failed());
        Http::assertNothingSent();
    }

    /**
     * ⚠️ `findExistingListing` create'ten ÖNCE sorulur ve SKU ile arar.
     *
     * Bu adım olmadan satıcının Shopify panelinden elle açtığı ürünler
     * yeniden yaratılır ve kanalda KOPYA listeler oluşur — geri alınamaz,
     * yorumlar ve sıralama ilk üründe kalır (v2.2 · §7).
     */
    #[Test]
    public function an_existing_variant_is_found_by_sku(): void
    {
        Http::fake(['*' => Http::response([
            'data' => ['productVariants' => ['nodes' => [[
                'id' => 'gid://shopify/ProductVariant/456',
                'sku' => 'TSH-KIRMIZI-M',
                'price' => '99.90',
                'inventoryQuantity' => 7,
                'inventoryItem' => ['id' => 'gid://shopify/InventoryItem/789'],
                'product' => ['id' => 'gid://shopify/Product/123', 'title' => 'Tişört'],
            ]]]],
        ], 200)]);

        [$adapter, , $variant] = $this->adapterWithListing(sku: 'TSH-KIRMIZI-M');

        $remote = $adapter->findExistingListing($variant);

        $this->assertNotNull($remote);
        $this->assertSame('gid://shopify/ProductVariant/456', $remote->externalId);
        $this->assertSame('99.90', $remote->price);
    }

    /**
     * ⚠️ SKU TAM EŞLEŞMELİ — ÖN EK EŞLEŞMESİ KABUL EDİLMEZ.
     *
     * Shopify'ın arama motoru `TSH-1` sorgusuna `TSH-10`'u döndürebilir.
     * Yanlış ürünü benimsemek, satıcının BAŞKA ürününü bizim listing'imiz
     * sanıp üzerine yazmak demektir — geri alınamaz.
     */
    #[Test]
    public function a_prefix_match_is_rejected(): void
    {
        Http::fake(['*' => Http::response([
            'data' => ['productVariants' => ['nodes' => [[
                'id' => 'gid://shopify/ProductVariant/999',
                'sku' => 'TSH-10',
                'product' => ['id' => 'gid://shopify/Product/999'],
            ]]]],
        ], 200)]);

        [$adapter, , $variant] = $this->adapterWithListing(sku: 'TSH-1');

        $this->assertNull(
            $adapter->findExistingListing($variant),
            'Ön ek eşleşmesi kabul edildi — BAŞKA ürünün üzerine yazılırdı.',
        );
    }

    /**
     * ⚠️ SKU ARAMA DİZESİNDE TIRNAK İÇİNE ALINIR.
     *
     * Boşluk veya tire içeren SKU (`TSH-KIRMIZI-M`) tırnaksız yazılsaydı
     * Shopify onu birden çok terime böler ve BAŞKA ürünü döndürürdü.
     */
    #[Test]
    public function the_sku_search_term_is_quoted(): void
    {
        Http::fake(['*' => Http::response(['data' => ['productVariants' => ['nodes' => []]]], 200)]);

        [$adapter, , $variant] = $this->adapterWithListing(sku: 'TSH-KIRMIZI-M');

        $adapter->findExistingListing($variant);

        Http::assertSent(function ($request): bool {
            return ($request->data()['variables']['query'] ?? null) === 'sku:"TSH-KIRMIZI-M"';
        });
    }

    /** Kanalda bulunamayan SKU null döner — create yoluna girilir. */
    #[Test]
    public function a_missing_sku_returns_null(): void
    {
        Http::fake(['*' => Http::response(['data' => ['productVariants' => ['nodes' => []]]], 200)]);

        [$adapter, , $variant] = $this->adapterWithListing();

        $this->assertNull($adapter->findExistingListing($variant));
    }

    /**
     * `fetchListing` VARYANT gid ile sorgular — mutabakat bunu okur (§10).
     */
    #[Test]
    public function fetching_reads_the_variant_by_gid(): void
    {
        Http::fake(['*' => Http::response([
            'data' => ['productVariant' => [
                'id' => 'gid://shopify/ProductVariant/456',
                'sku' => 'SKU-1',
                'price' => '149.90',
                'inventoryQuantity' => 12,
                'product' => ['id' => 'gid://shopify/Product/123', 'title' => 'X', 'status' => 'ACTIVE'],
            ]],
        ], 200)]);

        [$adapter, $listing] = $this->adapterWithListing(
            externalId: 'gid://shopify/ProductVariant/456',
        );

        $remote = $adapter->fetchListing($listing);

        $this->assertNotNull($remote);
        $this->assertSame(12, $remote->quantity);
        $this->assertSame('149.90', $remote->price);
        $this->assertSame('ACTIVE', $remote->status);
    }

    /**
     * Kanalda olmayan varyant null döner.
     *
     * Mutabakat bunu `REMOTE_MISSING` sayar ve otomatik onarım AÇMAZ
     * (v2.2 · §10): sessizce yeniden yaratmak kanalda kopya ürün açardı.
     */
    #[Test]
    public function fetching_a_deleted_variant_returns_null(): void
    {
        Http::fake(['*' => Http::response(['data' => ['productVariant' => null]], 200)]);

        [$adapter, $listing] = $this->adapterWithListing(
            externalId: 'gid://shopify/ProductVariant/456',
        );

        $this->assertNull($adapter->fetchListing($listing));
    }

    /** Yetenek `instanceof` ile okunur — panelde `if type === ...` yazılmaz. */
    #[Test]
    public function the_adapter_declares_the_catalog_capability(): void
    {
        [$adapter] = $this->adapterWithListing();

        $this->assertInstanceOf(SupportsCatalog::class, $adapter);
    }

    // ─────────────────────────────────────────────────── yardımcılar

    /** @return array{0: ShopifyAdapter, 1: Listing, 2: Variant} */
    private function adapterWithListing(
        string $sku = 'SKU-TEST',
        string $price = '99.90',
        ?string $externalId = null,
        ?string $externalParentId = null,
    ): array {
        $tenant = $this->makeTenant();

        return $this->asTenant($tenant, function () use (
            $sku, $price, $externalId, $externalParentId
        ): array {
            $connection = ChannelConnection::factory()->create([
                'channel_type_code' => 'shopify',
                'external_account_id' => 'magaza.myshopify.com',
                'settings' => ['location_gid' => 'gid://shopify/Location/12'],
            ]);

            app(CredentialVault::class)->store($connection, ['access_token' => 'shpat_test']);

            $variant = Variant::factory()->create(['sku' => $sku, 'price' => $price]);

            $listing = Listing::factory()->create([
                'channel_connection_id' => $connection->id,
                'variant_id' => $variant->id,
                'external_id' => $externalId,
                'external_parent_id' => $externalParentId,
            ]);

            $adapter = new ShopifyAdapter(
                $connection,
                new ChannelHttpClient(
                    $connection,
                    app(CredentialVault::class),
                    app(PayloadRedactor::class),
                ),
            );

            return [$adapter, $listing->load('variant'), $variant];
        });
    }

    private function payload(Listing $listing): ListingPayload
    {
        return new ListingPayload(
            listing: $listing,
            title: 'Test Ürünü',
            description: '<p>Açıklama</p>',
            version: 1,
        );
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
            name: 'Shopify Katalog '.uniqid(),
            owner: User::factory()->create(),
        );
    }
}
