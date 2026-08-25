<?php

declare(strict_types=1);

namespace Tests\Feature\Channels;

use App\Domain\Catalog\Models\Variant;
use App\Domain\Channels\Adapters\Shopify\ShopifyAdapter;
use App\Domain\Channels\Adapters\Shopify\ShopifyGraphqlException;
use App\Domain\Channels\Contracts\SupportsPricing;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Models\ChannelType;
use App\Domain\Channels\Support\ChannelHttpClient;
use App\Domain\Channels\Support\CredentialVault;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use App\Domain\Sync\Models\Listing;
use App\Domain\Sync\Support\PricePushBatch;
use App\Support\Logging\PayloadRedactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Shopify fiyat — slice 1.6.
 *
 * V3.0 · §04 (capability matrisi) · §22 (`variants.price`) · v2.2 §7 · §9.
 *
 * ⚠️ FİYAT STRING TAŞINIR. Para float taşınmaz: `19.90 * 100` IEEE-754'te
 * `1989.99...` olur ve `(int)` cast'i onu AŞAĞI keser — kuruş kayması
 * sessizdir ve her turda tekrarlanır.
 *
 * ⚠️ `compareAtPrice`'A DOKUNULMAZ. O üstü çizili fiyattır ve satıcının
 * KAMPANYASIDIR; §9 "sessizce ezmek EN SIK ŞİKAYET" diyor. Bizim kanonik
 * `compare_at_price` alanımız yükte GÖNDERİLMEZ — Trendyol'daki `listPrice`
 * kuralı buraya KOPYALANMAZ: orada alan ZORUNLUDUR ve atlanırsa kanal
 * `VALIDATION` döner; Shopify'da alan İSTEĞE BAĞLIDIR ve göndermek
 * satıcının kanal panelinden kurduğu indirimi ezer.
 */
final class ShopifyPricingTest extends TestCase
{
    use RefreshDatabase;

    // ───────────────────────────────────────────────────────────── gönderim

    /**
     * Varyant fiyatı `productVariantsBulkUpdate` ile yazılır.
     *
     * `productSet` KULLANILMAZ: o ürünün TAMAMINI yazar ve yükte olmayan
     * her alan (başlık, açıklama, durum) sıfırlanma riski taşır. Fiyat
     * turu içeriğe DOKUNMAMALIDIR — içerik kendi domainindedir ve
     * `PushListing` üzerinden gider.
     */
    #[Test]
    public function prices_are_written_with_the_variant_bulk_update_mutation(): void
    {
        $this->fakeSuccess();

        [$adapter, $batch] = $this->adapterWithBatch();

        $adapter->pushPrices($batch);

        Http::assertSent(function ($request): bool {
            $query = (string) ($request->data()['query'] ?? '');

            return str_contains($query, 'productVariantsBulkUpdate');
        });
    }

    /**
     * ⚠️ FİYAT STRING GİDER — float dönüşümü YAPILMAZ.
     *
     * Bu testin varlık sebebi: `(float)` cast'i eklenirse `"19.90"` yükte
     * `19.9` olur. Shopify onu kabul eder, kanal 200 döner ve fark bugün
     * görünmez; ama kural bir kez kırıldığında `1299.90` gibi değerlerde
     * kuruş kayması üretir ve mutabakat her turda SAHTE çakışma raporlar.
     */
    #[Test]
    public function the_price_is_carried_as_a_string_never_a_float(): void
    {
        $this->fakeSuccess();

        [$adapter, $batch] = $this->adapterWithBatch(price: '19.90');

        $adapter->pushPrices($batch);

        Http::assertSent(function ($request): bool {
            $variant = $request->data()['variables']['variants'][0] ?? [];

            return ($variant['price'] ?? null) === '19.90'
                && is_string($variant['price'] ?? null);
        });
    }

    /**
     * ⚠️ `compareAtPrice` YÜKTE HİÇ GEÇMEZ.
     *
     * Kanonik `compare_at_price` DOLU olsa bile gönderilmez. Gönderilseydi
     * satıcının Shopify panelinden kurduğu üstü çizili fiyat bizim
     * değerimizle EZİLİRDİ — §9'un "fiyatta üzerine YAZILMAZ" politikasının
     * doğrudan ihlali ve dokümanın "EN SIK ŞİKAYET" dediği hata biçimi.
     */
    #[Test]
    public function the_compare_at_price_is_never_touched(): void
    {
        $this->fakeSuccess();

        [$adapter, $batch] = $this->adapterWithBatch(
            price: '89.90',
            compareAtPrice: '129.90',
        );

        $adapter->pushPrices($batch);

        Http::assertSent(function ($request): bool {
            $variant = $request->data()['variables']['variants'][0] ?? [];
            $query = (string) ($request->data()['query'] ?? '');

            return ! array_key_exists('compareAtPrice', $variant)
                && ! str_contains($query, 'compareAtPrice');
        });
    }

    /**
     * ⚠️ HEDEF ÜRÜN gid'İDİR ve varyantlar ONUN altında gider.
     *
     * `productVariantsBulkUpdate` `productId` ZORUNLU alanını ister;
     * varyant gid'i tek başına verilemez. Kimlik `external_parent_id`'de
     * yaşıyor (slice 1.3) — burada okunmasaydı her fiyat itmesi ek bir
     * GraphQL sorgusu gerektirirdi.
     */
    #[Test]
    public function the_mutation_targets_the_product_gid_with_variant_ids_beneath_it(): void
    {
        $this->fakeSuccess();

        [$adapter, $batch] = $this->adapterWithBatch(
            externalId: 'gid://shopify/ProductVariant/456',
            parentId: 'gid://shopify/Product/123',
        );

        $adapter->pushPrices($batch);

        Http::assertSent(function ($request): bool {
            $variables = $request->data()['variables'] ?? [];

            return ($variables['productId'] ?? null) === 'gid://shopify/Product/123'
                && ($variables['variants'][0]['id'] ?? null) === 'gid://shopify/ProductVariant/456';
        });
    }

    /**
     * ⚠️ AYNI ÜRÜNÜN VARYANTLARI TEK ÇAĞRIDA BİRLEŞİR.
     *
     * Mutation ürün başınadır; varyant başına ayrı istek atılsaydı çok
     * varyantlı bir üründe fiyat turu N istek harcar ve maliyet tabanlı
     * kovayı (§06.8) gereksizce yakardı.
     */
    #[Test]
    public function variants_of_the_same_product_are_sent_in_one_call(): void
    {
        $this->fakeSuccess();

        [$adapter, $batch] = $this->adapterWithTwoVariants(
            firstParentId: 'gid://shopify/Product/123',
            secondParentId: 'gid://shopify/Product/123',
        );

        $adapter->pushPrices($batch);

        Http::assertSentCount(1);

        Http::assertSent(function ($request): bool {
            return count($request->data()['variables']['variants'] ?? []) === 2;
        });
    }

    /**
     * ⚠️ FARKLI ÜRÜNLER AYRI ÇAĞRI DEMEKTİR — TEK ÇAĞRIDA BİRLEŞTİRİLEMEZ.
     *
     * Bu, Shopify'ın mutation'ının GERÇEK sınırıdır: `productId` tekildir.
     * İki ürünün varyantı tek çağrıya sıkıştırılsaydı Shopify ikinci ürünün
     * varyantlarını TANIMAZ ve `userErrors` dönerdi — o hata `VALIDATION`
     * yani KALICIDIR ve o listing'ler "düzeltilemez" damgasıyla ölürdü.
     */
    #[Test]
    public function variants_of_different_products_are_sent_in_separate_calls(): void
    {
        $this->fakeSuccess();

        [$adapter, $batch] = $this->adapterWithTwoVariants(
            firstParentId: 'gid://shopify/Product/1',
            secondParentId: 'gid://shopify/Product/2',
        );

        $result = $adapter->pushPrices($batch);

        Http::assertSentCount(2);
        $this->assertTrue($result->successful);
        $this->assertSame(2, $result->data['pushed'] ?? null);
    }

    /**
     * ⚠️ ÜST ÜRÜN KİMLİĞİ OLMAYAN KALEM YÜKE ALINMAZ — VE RAPORLANIR.
     *
     * Boş `productId` ile giden mutation `userErrors` döner ve o hata
     * KALICIDIR; listing "düzeltilemez" damgasıyla ölürdü. Kalem sessizce
     * DÜŞMEZ: sonuç verisinde ADIYLA taşınır (stoktaki
     * "kimliği olmayan kalem" kuralının aynısı).
     */
    #[Test]
    public function an_item_without_a_parent_product_gid_is_skipped_and_reported(): void
    {
        $this->fakeSuccess();

        [$adapter, $batch] = $this->adapterWithTwoVariants(
            firstParentId: 'gid://shopify/Product/1',
            secondParentId: null,
        );

        $result = $adapter->pushPrices($batch);

        Http::assertSentCount(1);
        $this->assertSame(1, $result->data['pushed'] ?? null);
        $this->assertSame(
            ['gid://shopify/ProductVariant/2'],
            $result->data['skipped_without_product'] ?? null,
        );
    }

    /**
     * Hiçbir kalemin ürün kimliği yoksa çağrı ATILMAZ ve hata döner.
     *
     * Başarı dönülseydi `synced_version` ilerler ve satır kanalda hiçbir şey
     * değişmemişken "senkron" görünürdü (P0-1'in kardeşi).
     */
    #[Test]
    public function a_batch_with_no_usable_identity_fails_without_calling_the_channel(): void
    {
        Http::fake();

        [$adapter, $batch] = $this->adapterWithBatch(parentId: null);

        $result = $adapter->pushPrices($batch);

        $this->assertTrue($result->failed());
        Http::assertNothingSent();
    }

    /** Boş yükte çağrı yapılmaz; kota boşa harcanmaz. */
    #[Test]
    public function an_empty_batch_sends_nothing(): void
    {
        Http::fake();

        [$adapter] = $this->adapterWithBatch();

        $result = $adapter->pushPrices(new PricePushBatch('conn', [], []));

        $this->assertTrue($result->successful);
        $this->assertSame(0, $result->data['pushed'] ?? null);
        Http::assertNothingSent();
    }

    /**
     * ⚠️ `userErrors` YAKALANIR — 200 GÖVDE KODU BAŞARI SAYILMAZ (P0-1).
     *
     * GraphQL'de HER ŞEY 200'dür. Kontrol edilmezse `SyncResultRecorder`
     * başarı yazar, `synced_version` ilerler ve kanalda fiyat hiç
     * değişmemişken satır "senkron" görünür.
     */
    #[Test]
    public function a_user_error_is_raised_even_though_the_response_is_200(): void
    {
        Http::fake(['*' => Http::response([
            'data' => ['productVariantsBulkUpdate' => [
                'productVariants' => [],
                'userErrors' => [['field' => ['price'], 'message' => 'Price must be positive']],
            ]],
        ], 200)]);

        [$adapter, $batch] = $this->adapterWithBatch();

        $this->expectException(ShopifyGraphqlException::class);

        $adapter->pushPrices($batch);
    }

    // ───────────────────────────────────────────────────── mutabakat okuma

    /**
     * Uzak fiyat TOPLU okunur ve `external_id` (VARIANT gid) ile anahtarlanır.
     *
     * ⚠️ ANAHTAR VARIANT GID OLMALIDIR — mutabakat kalemi onunla eşleştirir.
     * Ürün gid'i ile anahtarlansaydı karşılaştırma HİÇBİR listing'i bulamaz
     * ve her tur "uzak değer okunamadı" derdi.
     */
    #[Test]
    public function remote_prices_are_keyed_by_the_variant_gid(): void
    {
        Http::fake(['*' => Http::response([
            'data' => ['nodes' => [
                ['id' => 'gid://shopify/ProductVariant/456', 'price' => '89.90'],
            ]],
        ], 200)]);

        [$adapter, , $listing] = $this->adapterWithBatch(
            externalId: 'gid://shopify/ProductVariant/456',
        );

        $snapshot = $adapter->fetchPrices([$listing]);

        $this->assertSame('89.90', $snapshot->priceFor('gid://shopify/ProductVariant/456'));
    }

    /**
     * ⚠️ OKUNAN FİYAT STRING KALIR — sayıya çevrilmez.
     *
     * §9'un para karşılaştırması kuruş ölçeğinde tam sayıdır ve `round()`
     * ZORUNLUDUR; snapshot float taşısaydı o hesap kaynağında bozulurdu.
     * Shopify değeri zaten string döndürür ve dönüşüm YAPILMAZ.
     */
    #[Test]
    public function the_remote_price_stays_a_string(): void
    {
        Http::fake(['*' => Http::response([
            'data' => ['nodes' => [
                ['id' => 'gid://shopify/ProductVariant/456', 'price' => '1299.90'],
            ]],
        ], 200)]);

        [$adapter, , $listing] = $this->adapterWithBatch(
            externalId: 'gid://shopify/ProductVariant/456',
        );

        $price = $adapter->fetchPrices([$listing])->priceFor('gid://shopify/ProductVariant/456');

        $this->assertIsString($price);
        $this->assertSame('1299.90', $price);
    }

    /**
     * ⚠️ SİLİNMİŞ VARYANT SIFIR OLARAK OKUNMAZ — ATLANIR.
     *
     * Shopify silinmiş düğüm için `null` döner. `"0"` yazılsaydı mutabakat
     * "kanalda 0 TL" sanır ve `PRICE_CONFLICT` açardı; satıcı var olmayan
     * bir fiyat için karar vermeye zorlanırdı. Doğru sınıflandırma
     * `REMOTE_MISSING`'dir (v2.2 · §10).
     */
    #[Test]
    public function a_deleted_variant_is_skipped_not_read_as_zero(): void
    {
        Http::fake(['*' => Http::response(['data' => ['nodes' => [null]]], 200)]);

        [$adapter, , $listing] = $this->adapterWithBatch(
            externalId: 'gid://shopify/ProductVariant/456',
        );

        $snapshot = $adapter->fetchPrices([$listing]);

        $this->assertNull($snapshot->priceFor('gid://shopify/ProductVariant/456'));
    }

    /**
     * ⚠️ FİYATI OLMAYAN DÜĞÜM DE SIFIR OKUNMAZ.
     *
     * Kimlik DOLU ama `price` alanı boş/null gelebilir. `null` düğüm
     * elemesine TAKILMAZ ve `"0"` yazılsaydı her tur SAHTE bir
     * `PRICE_CONFLICT` üretirdi — satıcı hiç değişmemiş bir fiyat için
     * sonsuza kadar karar vermeye çağrılırdı (slice 1.5'in "takibi kapalı
     * varyant" tuzağının fiyat karşılığı).
     */
    #[Test]
    public function a_variant_without_a_price_is_skipped_not_read_as_zero(): void
    {
        Http::fake(['*' => Http::response(['data' => ['nodes' => [
            ['id' => 'gid://shopify/ProductVariant/456', 'price' => null],
        ]]], 200)]);

        [$adapter, , $listing] = $this->adapterWithBatch(
            externalId: 'gid://shopify/ProductVariant/456',
        );

        $this->assertNull(
            $adapter->fetchPrices([$listing])->priceFor('gid://shopify/ProductVariant/456'),
            'Fiyatsız varyant "0" okundu — her tur sahte fiyat çakışması raporlanır.',
        );
    }

    /** Kimliksiz listing sorguya girmez ve boş çağrı atılmaz. */
    #[Test]
    public function fetching_with_no_external_ids_sends_nothing(): void
    {
        Http::fake();

        [$adapter, , $listing] = $this->adapterWithBatch(externalId: null);

        $this->assertSame([], $adapter->fetchPrices([$listing])->pricesByExternalId);
        Http::assertNothingSent();
    }

    // ────────────────────────────────────────────────────────────── yetenek

    /**
     * Yetenek `instanceof` ile okunur — `capabilities` kolonundan DEĞİL.
     *
     * `reconcile:prices` `SupportsPricing` uygulayan HER kanalı gezer;
     * yeni kanal için tek satır kod yazılmaz (§22).
     */
    #[Test]
    public function the_adapter_declares_the_pricing_capability(): void
    {
        [$adapter] = $this->adapterWithBatch();

        $this->assertInstanceOf(SupportsPricing::class, $adapter);
        $this->assertSame(250, $adapter->maxPriceBatchSize());
    }

    // ────────────────────────────────────────────────────────── yardımcılar

    private function fakeSuccess(): void
    {
        Http::fake(['*' => Http::response([
            'data' => ['productVariantsBulkUpdate' => [
                'productVariants' => [['id' => 'gid://shopify/ProductVariant/456', 'price' => '19.90']],
                'userErrors' => [],
            ]],
        ], 200)]);
    }

    /** @return array{0: ShopifyAdapter, 1: PricePushBatch, 2: Listing} */
    private function adapterWithBatch(
        string $price = '19.90',
        ?string $compareAtPrice = null,
        ?string $externalId = 'gid://shopify/ProductVariant/456',
        ?string $parentId = 'gid://shopify/Product/123',
    ): array {
        $tenant = $this->makeTenant();

        return $this->asTenant($tenant, function () use (
            $price, $compareAtPrice, $externalId, $parentId
        ): array {
            $connection = $this->connection();

            $variant = Variant::factory()->create(['sku' => 'SKU-1', 'price' => $price]);

            $listing = Listing::factory()->create([
                'channel_connection_id' => $connection->id,
                'variant_id' => $variant->id,
                'external_id' => $externalId,
                'external_parent_id' => $parentId,
            ]);

            $batch = new PricePushBatch($connection->id, [[
                'listing_id' => $listing->id,
                'external_id' => (string) $externalId,
                'price' => $price,
                'compare_at_price' => $compareAtPrice,
                'version' => 1,
            ]], []);

            return [$this->adapterFor($connection), $batch, $listing];
        });
    }

    /** @return array{0: ShopifyAdapter, 1: PricePushBatch} */
    private function adapterWithTwoVariants(
        ?string $firstParentId,
        ?string $secondParentId,
    ): array {
        $tenant = $this->makeTenant();

        return $this->asTenant($tenant, function () use ($firstParentId, $secondParentId): array {
            $connection = $this->connection();

            $items = [];

            foreach ([
                ['SKU-1', 'gid://shopify/ProductVariant/1', $firstParentId, '19.90'],
                ['SKU-2', 'gid://shopify/ProductVariant/2', $secondParentId, '29.90'],
            ] as [$sku, $variantGid, $productGid, $price]) {
                $variant = Variant::factory()->create(['sku' => $sku, 'price' => $price]);

                $listing = Listing::factory()->create([
                    'channel_connection_id' => $connection->id,
                    'variant_id' => $variant->id,
                    'external_id' => $variantGid,
                    'external_parent_id' => $productGid,
                ]);

                $items[] = [
                    'listing_id' => $listing->id,
                    'external_id' => $variantGid,
                    'price' => $price,
                    'compare_at_price' => null,
                    'version' => 1,
                ];
            }

            return [$this->adapterFor($connection), new PricePushBatch($connection->id, $items, [])];
        });
    }

    private function connection(): ChannelConnection
    {
        $connection = ChannelConnection::factory()->create([
            'channel_type_code' => 'shopify',
            'external_account_id' => 'magaza.myshopify.com',
            'settings' => ['location_gid' => 'gid://shopify/Location/12'],
        ]);

        app(CredentialVault::class)->store($connection, ['access_token' => 'shpat_test']);

        return $connection;
    }

    private function adapterFor(ChannelConnection $connection): ShopifyAdapter
    {
        return new ShopifyAdapter(
            $connection,
            new ChannelHttpClient(
                $connection,
                app(CredentialVault::class),
                app(PayloadRedactor::class),
            ),
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
            name: 'Shopify Fiyat '.uniqid(),
            owner: User::factory()->create(),
        );
    }
}
