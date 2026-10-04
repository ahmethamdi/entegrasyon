<?php

declare(strict_types=1);

namespace Tests\Feature\Channels;

use App\Domain\Catalog\Models\OptionDefinition;
use App\Domain\Catalog\Models\OptionValue;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductImage;
use App\Domain\Catalog\Models\Variant;
use App\Domain\Catalog\Models\VariantOption;
use App\Domain\Channels\Actions\SaveAttributeMapping;
use App\Domain\Channels\Actions\SaveAttributeValueMapping;
use App\Domain\Channels\Actions\SaveCategoryMapping;
use App\Domain\Channels\Adapters\Trendyol\TrendyolAdapter;
use App\Domain\Channels\Models\ChannelCategory;
use App\Domain\Channels\Models\ChannelCategoryAttribute;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Models\ChannelType;
use App\Domain\Channels\Support\ChannelHttpClient;
use App\Domain\Channels\Support\CredentialVault;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use App\Domain\Sync\Models\Listing;
use App\Domain\Sync\Support\ListingPayloadBuilder;
use App\Support\Logging\PayloadRedactor;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Trendyol katalog aktarımı — §13 · Faz 2 · "Katalog aktarımı", §14.
 *
 * DEĞİŞMEZ KURAL — EŞLEŞTİRME YÜKE BURADA ÇEVRİLİR:
 *   Kanonik yük İÇ kategori adını taşır ("kadin-elbise"); kanal sayısal
 *   bir kategori kimliği bekler. Çeviri ADAPTER'ın işidir — çekirdek
 *   kanalın kategori kimliklerini bilmez.
 *
 * DEĞİŞMEZ KURAL — EŞLEŞTİRME YOKSA SESSİZCE GÖNDERİLMEZ:
 *   Ön koşul kapısı bunu zaten eler, ama adapter da kendini korur:
 *   eşleştirmesiz çağrı istisna fırlatır. Kategorisiz gönderim kanalda
 *   doğrulama hatası verir ve KALICI sayılırdı.
 *
 * DEĞİŞMEZ KURAL — BARKOD ZORUNLUDUR:
 *   Trendyol ürünü barkodla tanır ve `external_id` odur. Barkodsuz
 *   gönderim kanalda kimliksiz ürün yaratır; sonraki güncelleme onu
 *   bulamaz ve her turda KOPYA ürün açardı.
 */
final class TrendyolCatalogTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Yük kanal formatına çevrilir: iç kategori kanal kategorisine döner.
     */
    #[Test]
    public function the_payload_is_mapped_to_the_channel_format(): void
    {
        Http::fake([
            '*/brands/by-name*' => Http::response([['id' => 77, 'name' => 'Marka-A'], ['id' => 78, 'name' => 'Marka-A Kids']], 200),
            '*' => Http::response(['batchRequestId' => 'b-1'], 200),
        ]);

        [$tenant, $connection, $listing] = $this->scenario();

        $payload = $this->asTenant($tenant, fn () => app(ListingPayloadBuilder::class)
            ->build($listing, 1));

        $result = $this->asTenant($tenant, fn () => $this->adapter($connection)
            ->createListing($payload));

        $this->assertTrue($result->successful);

        Http::assertSent(function ($request): bool {
            if (! str_contains($request->url(), '/product/sellers/12345/v2/products')) {
                return false;
            }

            $item = $request->data()['items'][0] ?? [];

            // İÇ kategori adı DEĞİL, kanalın sayısal kimliği gitmeli.
            $this->assertSame(11, $item['categoryId'] ?? null,
                'İç kategori kanal kimliğine çevrilmeli.');

            $this->assertSame('SKU-1', $item['barcode'] ?? null);
            $this->assertSame('Yazlık Elbise', $item['title'] ?? null);

            // V2 zorunluları (A11 ④b): eksikse kanal toplu işte reddeder.
            $this->assertSame(77, $item['brandId'] ?? null, 'Marka TAM eşleşmeyle seçilmeli (Kids değil).');
            $this->assertSame('SKU-1', $item['productMainId'] ?? null);
            $this->assertSame(20, $item['vatRate'] ?? null);
            $this->assertSame(1.0, $item['dimensionalWeight'] ?? null);
            $this->assertSame([
                ['url' => 'https://cdn.example.com/kirmizi.jpg'],
                ['url' => 'https://cdn.example.com/ortak.jpg'],
            ], $item['images'] ?? null, 'Varyant görseli önce, başka varyantınki hiç.');
            $this->assertArrayNotHasKey('currencyType', $item);
            $this->assertArrayNotHasKey('shipmentAddressId', $item);

            return true;
        });
    }

    /**
     * ZORUNLU ÖZNİTELİKLER EŞLEŞTİRMEDEN TÜRETİLİR.
     *
     * Varyantın "Beden = S" seçeneği, kanalın öznitelik ve değer
     * kimliklerine çevrilir. Çeviri olmadan gönderilseydi kanal "S"
     * dizesini tanımaz ve doğrulama hatası verirdi.
     */
    #[Test]
    public function required_attributes_are_translated_from_the_mappings(): void
    {
        Http::fake([
            '*/brands/by-name*' => Http::response([['id' => 77, 'name' => 'Marka-A'], ['id' => 78, 'name' => 'Marka-A Kids']], 200),
            '*' => Http::response(['batchRequestId' => 'b-1'], 200),
        ]);

        [$tenant, $connection, $listing] = $this->scenario(withAttributes: true);

        $payload = $this->asTenant($tenant, fn () => app(ListingPayloadBuilder::class)
            ->build($listing, 1));

        $this->asTenant($tenant, fn () => $this->adapter($connection)->createListing($payload));

        Http::assertSent(function ($request): bool {
            if (! str_contains($request->url(), '/v2/products')) {
                return false;
            }

            $attributes = $request->data()['items'][0]['attributes'] ?? [];

            $this->assertSame([
                ['attributeId' => 293, 'attributeValueId' => 4602],
            ], $attributes, 'Öznitelik ve değer KANAL kimlikleriyle gitmeli.');

            return true;
        });
    }

    /**
     * SATICININ TRENDYOL'DAN HARİÇ TUTTUĞU GÖRSEL GİTMEZ (A15); başka
     * kanaldan hariç tutulan görsel gider.
     */
    #[Test]
    public function an_image_excluded_from_trendyol_is_not_sent(): void
    {
        Http::fake([
            '*/brands/by-name*' => Http::response([['id' => 77, 'name' => 'Marka-A']], 200),
            '*' => Http::response(['batchRequestId' => 'b-1'], 200),
        ]);

        [$tenant, $connection, $listing] = $this->scenario();

        $this->asTenant($tenant, function (): void {
            ProductImage::query()->where('storage_path', 'https://cdn.example.com/kirmizi.jpg')
                ->update(['excluded_channels' => json_encode(['trendyol'])]);
            ProductImage::query()->where('storage_path', 'https://cdn.example.com/ortak.jpg')
                ->update(['excluded_channels' => json_encode(['ebay'])]);
        });

        $payload = $this->asTenant($tenant, fn () => app(ListingPayloadBuilder::class)->build($listing, 1));

        $this->asTenant($tenant, fn () => $this->adapter($connection)->createListing($payload));

        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/v2/products')
            && ($request->data()['items'][0]['images'] ?? null) === [['url' => 'https://cdn.example.com/ortak.jpg']]);
    }

    /**
     * ONAYSIZ (bekleyen/reddedilmiş) ÜRÜN `unapproved-bulk-update` ile
     * güncellenir — yaratma uç noktasına ikinci kez GİTMEZ (A11 ④b).
     */
    #[Test]
    public function an_unapproved_product_is_updated_through_its_own_endpoint(): void
    {
        Http::fake([
            '*/brands/by-name*' => Http::response([['id' => 77, 'name' => 'Marka-A']], 200),
            '*/products/approved*' => Http::response(['content' => [], 'totalPages' => 1], 200),
            '*/products/unapproved-bulk-update' => Http::response(['batchRequestId' => 'u-1'], 200),
        ]);

        [$tenant, $connection, $listing] = $this->scenario();

        $this->asTenant($tenant, fn () => $listing->forceFill(['external_id' => 'SKU-1'])->save());

        $payload = $this->asTenant($tenant, fn () => app(ListingPayloadBuilder::class)->build($listing, 2));

        $result = $this->asTenant($tenant, fn () => $this->adapter($connection)->updateListing($payload));

        $this->assertSame(['u-1'], $result->data['channel_metadata']['batch_request_ids']);

        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/products/unapproved-bulk-update')
            && ($request->data()['items'][0]['barcode'] ?? null) === 'SKU-1'
            && ($request->data()['items'][0]['brandId'] ?? null) === 77);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/v2/products'));
    }

    /**
     * EŞLEŞTİRMESİZ ÇAĞRI İSTİSNA FIRLATIR — sessizce gönderilmez.
     */
    #[Test]
    public function creating_without_a_category_mapping_throws(): void
    {
        Http::fake();

        [$tenant, $connection, $listing] = $this->scenario(withCategoryMapping: false);

        $payload = $this->asTenant($tenant, fn () => app(ListingPayloadBuilder::class)
            ->build($listing, 1));

        $this->expectException(\RuntimeException::class);

        $this->asTenant($tenant, fn () => $this->adapter($connection)->createListing($payload));
    }

    /**
     * BAŞARISIZ YANIT İSTİSNA FIRLATIR — `AdapterResult::failure()` dönmez.
     *
     * Sınıflandırma ve yeniden deneme kararı `PushListing`'deki tek
     * try/catch'te toplanır (§7 · adapter kuralları).
     */
    #[Test]
    public function a_failed_response_throws(): void
    {
        Http::fake(['*' => Http::response(['errors' => [['message' => 'Barkod zaten var']]], 400)]);

        [$tenant, $connection, $listing] = $this->scenario();

        $payload = $this->asTenant($tenant, fn () => app(ListingPayloadBuilder::class)
            ->build($listing, 1));

        $this->expectException(\Throwable::class);

        $this->asTenant($tenant, fn () => $this->adapter($connection)->createListing($payload));
    }

    /**
     * KANALDA VAR OLAN ÜRÜN BULUNUR — kopya listeleme koruması.
     *
     * Satıcı ürünü daha önce Trendyol panelinden açmış olabilir; barkodla
     * aranır ve bulunursa kimliği benimsenir.
     */
    #[Test]
    public function an_existing_product_is_found_by_barcode(): void
    {
        // V2 onaylı gövdesi: içerik → `variants[]`.
        Http::fake([
            '*/products/approved*' => Http::response([
                'content' => [[
                    'contentId' => 7,
                    'title' => 'Yazlık Elbise',
                    'variants' => [['barcode' => 'SKU-1', 'productUrl' => 'https://ty/p/1']],
                ]],
                'totalPages' => 1,
            ], 200),
            '*' => Http::response(['content' => []], 200),
        ]);

        [$tenant, $connection, $listing] = $this->scenario();

        $variant = $this->asTenant($tenant, fn () => $listing->variant);

        $found = $this->asTenant($tenant, fn () => $this->adapter($connection)
            ->findExistingListing($variant));

        $this->assertNotNull($found);
        $this->assertSame('SKU-1', $found->externalId);
        $this->assertSame('Yazlık Elbise', $found->title);
        $this->assertSame('https://ty/p/1', $found->url);
        $this->assertSame(7, $found->raw['contentId']);
    }

    /**
     * ⚠️ ONAY BEKLEYEN ÜRÜN DE "VAR" SAYILIR (A11 ④).
     *
     * Satıcının panelden açtığı ürün henüz onaylanmadıysa `approved`
     * filtresinde görünmez. Yalnızca oraya bakılsaydı aynı barkod ikinci
     * kez gönderilir ve kanal kalıcı `VALIDATION` ile reddederdi.
     */
    #[Test]
    public function a_pending_product_counts_as_existing(): void
    {
        Http::fake([
            '*/products/approved*' => Http::response(['content' => [], 'totalPages' => 1], 200),
            '*/products/unapproved*' => Http::response([
                'content' => [['barcode' => 'SKU-1', 'title' => 'Bekleyen']],
                'totalPages' => 1,
            ], 200),
        ]);

        [$tenant, $connection, $listing] = $this->scenario();

        $variant = $this->asTenant($tenant, fn () => $listing->variant);

        $found = $this->asTenant($tenant, fn () => $this->adapter($connection)
            ->findExistingListing($variant));

        $this->assertNotNull($found);
        $this->assertSame('SKU-1', $found->externalId);
        $this->assertSame('Bekleyen', $found->title);
    }

    /**
     * KANALDA YOKSA null DÖNER — uydurma kimlik benimsenmez.
     */
    #[Test]
    public function a_missing_product_returns_null(): void
    {
        Http::fake(['*' => Http::response(['content' => []], 200)]);

        [$tenant, $connection, $listing] = $this->scenario();

        $variant = $this->asTenant($tenant, fn () => $listing->variant);

        $found = $this->asTenant($tenant, fn () => $this->adapter($connection)
            ->findExistingListing($variant));

        $this->assertNull($found);
    }

    // ───────────────────────────────────────────────────── yardımcılar

    /**
     * @return array{0: Tenant, 1: ChannelConnection, 2: Listing}
     */
    private function scenario(
        bool $withCategoryMapping = true,
        bool $withAttributes = false,
    ): array {
        $user = User::factory()->create();
        $tenant = (new CreateTenant)->run(name: 'Katalog '.uniqid(), owner: $user);

        $dress = $this->asSystem(function (): ChannelCategory {
            ChannelType::query()->updateOrCreate(
                ['code' => 'trendyol'],
                [
                    'name' => 'Trendyol',
                    'kind' => 'marketplace',
                    'adapter_class' => TrendyolAdapter::class,
                    'is_active' => true,
                ],
            );

            return ChannelCategory::query()->updateOrCreate(
                ['channel_type_code' => 'trendyol', 'taxonomy_version' => 'v1', 'external_id' => '11'],
                ['name' => 'Elbise', 'path' => 'Giyim > Elbise', 'is_leaf' => true],
            );
        });

        $connection = $this->asTenant($tenant, fn () => ChannelConnection::factory()->create([
            'tenant_id' => $tenant->id,
            'channel_type_code' => 'trendyol',
            'status' => 'active',
            'health_status' => 'healthy',
            'settings' => ['supplier_id' => '12345', 'base_url' => 'https://api.trendyol.com'],
        ]));

        TenantContext::runAsSystem(fn () => app(CredentialVault::class)->store(
            $connection,
            ['api_key' => 'k', 'api_secret' => 's'],
        ));

        $listing = $this->asTenant($tenant, function () use ($tenant, $connection, $dress, $withCategoryMapping, $withAttributes): Listing {
            $product = Product::factory()->create([
                'tenant_id' => $tenant->id,
                'sku' => 'SKU-1',
                'title' => 'Yazlık Elbise',
                'brand' => 'Marka-A',
                'internal_category_id' => 'kadin-elbise',
            ]);

            $variant = Variant::factory()->create([
                'tenant_id' => $tenant->id,
                'product_id' => $product->id,
                'sku' => 'SKU-1',
                'barcode' => 'SKU-1',
            ]);

            $other = Variant::factory()->create([
                'tenant_id' => $tenant->id,
                'product_id' => $product->id,
                'sku' => 'SKU-2',
            ]);

            // Ortak görsel, bu varyantın görseli, başka varyantın görseli
            // ve HTTPS olmayan bir adres.
            foreach ([
                ['https://cdn.example.com/ortak.jpg', null, 0],
                ['https://cdn.example.com/kirmizi.jpg', $variant->id, 5],
                ['https://cdn.example.com/mavi.jpg', $other->id, 0],
                ['http://cdn.example.com/guvensiz.jpg', null, 1],
            ] as [$path, $variantId, $position]) {
                ProductImage::query()->create([
                    'tenant_id' => $tenant->id,
                    'product_id' => $product->id,
                    'variant_id' => $variantId,
                    'storage_path' => $path,
                    'position' => $position,
                ]);
            }

            if ($withCategoryMapping) {
                app(SaveCategoryMapping::class)->run('kadin-elbise', $dress);
            }

            if ($withAttributes) {
                $this->asSystem(fn () => ChannelCategoryAttribute::query()->updateOrCreate(
                    ['channel_category_id' => $dress->id, 'external_attribute_id' => '293'],
                    [
                        'name' => 'Beden',
                        'is_required' => true,
                        'is_variant_defining' => true,
                        'data_type' => 'string',
                        'allowed_values' => [['id' => '4602', 'label' => 'SMALL']],
                    ],
                ));

                $definition = OptionDefinition::query()->create([
                    'tenant_id' => $tenant->id,
                    'name' => 'Beden',
                ]);

                $value = OptionValue::query()->create([
                    'tenant_id' => $tenant->id,
                    'option_definition_id' => $definition->id,
                    'value' => 'S',
                ]);

                VariantOption::query()->create([
                    'tenant_id' => $tenant->id,
                    'variant_id' => $variant->id,
                    'option_definition_id' => $definition->id,
                    'option_value_id' => $value->id,
                ]);

                app(SaveAttributeMapping::class)->run($definition, $dress, '293');
                app(SaveAttributeValueMapping::class)->run(
                    optionValue: $value,
                    externalAttributeId: '293',
                    externalValueId: '4602',
                    externalValueLabel: 'SMALL',
                );
            }

            return Listing::query()->create([
                'tenant_id' => $tenant->id,
                'channel_connection_id' => $connection->id,
                'variant_id' => $variant->id,
                'lifecycle_status' => 'draft',
            ]);
        });

        return [$tenant, $connection->fresh(['channelType']), $listing];
    }

    private function adapter(ChannelConnection $connection): TrendyolAdapter
    {
        $connection->loadMissing('channelType');

        return new TrendyolAdapter(
            connection: $connection,
            client: new ChannelHttpClient(
                connection: $connection,
                vault: app(CredentialVault::class),
                redactor: app(PayloadRedactor::class),
            ),
        );
    }
}
