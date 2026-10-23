<?php

declare(strict_types=1);

namespace Tests\Feature\Publishing;

use App\Domain\Catalog\Models\OptionDefinition;
use App\Domain\Catalog\Models\OptionValue;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductChannelAttributeValue;
use App\Domain\Catalog\Models\ProductImage;
use App\Domain\Catalog\Models\Variant;
use App\Domain\Catalog\Models\VariantOption;
use App\Domain\Channels\Adapters\Ciceksepeti\CiceksepetiAdapter;
use App\Domain\Channels\Adapters\Hepsiburada\HepsiburadaAdapter;
use App\Domain\Channels\Adapters\N11\N11Adapter;
use App\Domain\Channels\Adapters\Pazarama\Catalog\PazaramaCatalog;
use App\Domain\Channels\Adapters\Pazarama\PazaramaAdapter;
use App\Domain\Channels\Models\AttributeMapping;
use App\Domain\Channels\Models\AttributeValueMapping;
use App\Domain\Channels\Models\CategoryMapping;
use App\Domain\Channels\Models\ChannelCategory;
use App\Domain\Channels\Models\ChannelCategoryAttribute;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Models\ChannelType;
use App\Domain\Channels\Registry\AdapterRegistry;
use App\Domain\Channels\Support\CredentialVault;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Models\InventoryLevel;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Sync\Actions\PublishListing;
use App\Domain\Sync\Enums\SyncDomain;
use App\Domain\Sync\Jobs\PushListing;
use App\Domain\Sync\Models\Listing;
use App\Domain\Sync\Models\ListingSyncState;
use App\Domain\Sync\Support\ListingPayloadBuilder;
use App\Domain\Sync\Support\SyncResultRecorder;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;

/**
 * S3 yayın testleri — kanonik ürün + 3 varyant (Kırmızı/S, Kırmızı/M,
 * Mavi/S), HER varyantın FARKLI fiyatı, stoğu, barkodu ve kendi görseli
 * (kardeş karışması görünür olsun), HB ve N11 taksonomisi + eşlemeler.
 *
 * Kanal yanıtları belgedeki biçimdir (HB "Katalog Ürün Giriş Önemli
 * Bilgiler", developer.n11.com "Ürün Yükleme"); canlı hesap yanıtı DEĞİL.
 */
trait BuildsPublishingScenario
{
    protected ?User $owner = null;

    protected const MERCHANT = '6fc6d90d-ee1d-4372-b3a6-264b1275e9ff';

    /** sku → [renk, beden, fiyat, stok, barkod] */
    protected const VARIANTS = [
        'TS-RED-S' => ['Kırmızı', 'S', '100.00', 5, '8690000000012'],
        'TS-RED-M' => ['Kırmızı', 'M', '110.00', 7, '8690000000029'],
        'TS-BLU-S' => ['Mavi', 'S', '120.00', 9, '8690000000036'],
    ];

    protected function setUpPublishing(): void
    {
        Redis::connection()->flushdb();
        Queue::fake();
    }

    protected function tenant(): Tenant
    {
        $this->owner = User::factory()->create();

        return (new CreateTenant)->run(name: 'Yayın '.uniqid(), owner: $this->owner);
    }

    protected function hbConnection(Tenant $tenant, array $settings = [], string $merchant = self::MERCHANT): ChannelConnection
    {
        TenantContext::runAsSystem(fn () => ChannelType::query()->updateOrCreate(['code' => 'hepsiburada'], [
            'name' => 'Hepsiburada', 'kind' => 'marketplace', 'adapter_class' => HepsiburadaAdapter::class,
            'supports_webhooks' => false, 'is_active' => true,
        ]));

        return TenantContext::runFor($tenant->id, function () use ($settings, $merchant): ChannelConnection {
            $c = ChannelConnection::factory()->create([
                'channel_type_code' => 'hepsiburada',
                'external_account_id' => $merchant,
                'status' => 'active',
                'settings' => [
                    HepsiburadaAdapter::INTEGRATOR_KEY => 'pazar_dev',
                    HepsiburadaAdapter::ENVIRONMENT_KEY => HepsiburadaAdapter::ENVIRONMENT_TEST,
                    'vat_rate' => 20,
                    ...$settings,
                ],
            ]);
            app(CredentialVault::class)->store($c, [HepsiburadaAdapter::SERVICE_KEY_SECRET => 'servis-anahtari']);

            return $c->fresh(['channelType']);
        });
    }

    protected function n11Connection(Tenant $tenant, array $settings = []): ChannelConnection
    {
        TenantContext::runAsSystem(fn () => ChannelType::query()->updateOrCreate(['code' => 'n11'], [
            'name' => 'N11', 'kind' => 'marketplace', 'adapter_class' => N11Adapter::class,
            'supports_webhooks' => false, 'is_active' => true,
        ]));

        return TenantContext::runFor($tenant->id, function () use ($settings): ChannelConnection {
            $c = ChannelConnection::factory()->create([
                'channel_type_code' => 'n11',
                'external_account_id' => 'magaza-'.uniqid(),
                'status' => 'active',
                'settings' => ['vat_rate' => 20, 'shipment_template' => 'STANDART', 'preparing_day' => 3, ...$settings],
            ]);
            app(CredentialVault::class)->store($c, [N11Adapter::APP_KEY_SECRET => 'k', N11Adapter::APP_SECRET_SECRET => 's']);

            return $c->fresh(['channelType']);
        });
    }

    protected function pazaramaConnection(Tenant $tenant, array $settings = []): ChannelConnection
    {
        TenantContext::runAsSystem(fn () => ChannelType::query()->updateOrCreate(['code' => 'pazarama'], [
            'name' => 'Pazarama', 'kind' => 'marketplace', 'adapter_class' => PazaramaAdapter::class,
            'supports_webhooks' => false, 'is_active' => true,
        ]));

        return TenantContext::runFor($tenant->id, function () use ($settings): ChannelConnection {
            $c = ChannelConnection::factory()->create([
                'channel_type_code' => 'pazarama',
                'external_account_id' => 'pzr-'.uniqid(),
                'status' => 'active',
                'settings' => [PazaramaCatalog::SETTING_VAT_RATE => '20', PazaramaCatalog::SETTING_DESI => '2', ...$settings],
            ]);
            app(CredentialVault::class)->store($c, [
                PazaramaAdapter::CLIENT_ID_SECRET => 'id', PazaramaAdapter::CLIENT_SECRET_SECRET => 's', 'access_token' => 'tok',
            ]);

            return $c->fresh(['channelType']);
        });
    }

    protected function ciceksepetiConnection(Tenant $tenant, array $settings = []): ChannelConnection
    {
        TenantContext::runAsSystem(fn () => ChannelType::query()->updateOrCreate(['code' => 'ciceksepeti'], [
            'name' => 'Çiçeksepeti', 'kind' => 'marketplace', 'adapter_class' => CiceksepetiAdapter::class,
            'supports_webhooks' => false, 'is_active' => true,
        ]));

        return TenantContext::runFor($tenant->id, function () use ($settings): ChannelConnection {
            $c = ChannelConnection::factory()->create([
                'channel_type_code' => 'ciceksepeti',
                'external_account_id' => '7'.random_int(10000, 99999),
                'status' => 'active',
                'settings' => [CiceksepetiAdapter::SELLER_ID_KEY => '123456', 'ciceksepeti_delivery_type' => '2', 'ciceksepeti_delivery_message_type' => '5', ...$settings],
            ]);
            app(CredentialVault::class)->store($c, [CiceksepetiAdapter::API_KEY_SECRET => 'key']);

            return $c->fresh(['channelType']);
        });
    }

    /**
     * Kanal kategorisi + öznitelikleri.
     *
     * @param  list<array{0: string, 1: string, 2: bool, 3: bool, 4: string, 5: list<array{id: string, label: string}>}>  $attributes  [id, ad, zorunlu, varyant, tip, değerler]
     */
    protected function channelCategory(string $code, string $externalId, array $attributes): ChannelCategory
    {
        return TenantContext::runAsSystem(function () use ($code, $externalId, $attributes): ChannelCategory {
            $category = ChannelCategory::query()->updateOrCreate(
                ['channel_type_code' => $code, 'taxonomy_version' => 'v1', 'external_id' => $externalId],
                ['name' => 'Tişört', 'path' => 'Giyim > Tişört', 'is_leaf' => true],
            );

            foreach ($attributes as [$id, $name, $required, $variant, $type, $values]) {
                ChannelCategoryAttribute::query()->updateOrCreate(
                    ['channel_category_id' => $category->id, 'external_attribute_id' => $id],
                    ['name' => $name, 'is_required' => $required, 'is_variant_defining' => $variant, 'data_type' => $type, 'allowed_values' => $values],
                );
            }

            return $category;
        });
    }

    protected function hbCategory(): ChannelCategory
    {
        return $this->channelCategory('hepsiburada', '18021982', [
            ['UrunAdi', 'Ürün Adı', true, false, 'string', []],
            ['Marka', 'Marka', true, false, 'string', []],
            ['GarantiSuresi', 'Garanti Süresi', true, false, 'string', []],
            ['renk_variant_property', 'Renk', true, true, 'enum', [['id' => 'r1', 'label' => 'Kırmızı'], ['id' => 'r2', 'label' => 'Mavi']]],
            ['beden_variant_property', 'Beden', true, true, 'enum', [['id' => 'b1', 'label' => 'S'], ['id' => 'b2', 'label' => 'M']]],
        ]);
    }

    protected function n11Category(): ChannelCategory
    {
        return $this->channelCategory('n11', '1002571', [
            ['1', 'Marka', true, false, 'string', [['id' => '8372688', 'label' => 'Kanonik']]],
            ['500', 'Menşei', true, false, 'enum', [['id' => '9001', 'label' => 'Türkiye'], ['id' => '9002', 'label' => 'Çin']]],
            ['429', 'Renk', true, true, 'string', [['id' => '8773717', 'label' => 'Kırmızı'], ['id' => '2523534', 'label' => 'Mavi']]],
            ['430', 'Beden', true, true, 'enum', [['id' => '7001', 'label' => 'S'], ['id' => '7002', 'label' => 'M']]],
        ]);
    }

    /**
     * Kanonik ürün + 3 varyant + seçenekler + farklı fiyat/stok/barkod/görsel.
     *
     * @return array{0: Product, 1: array<string, Variant>}
     */
    protected function tshirt(Tenant $tenant, string $sku = 'TS-1'): array
    {
        return TenantContext::runFor($tenant->id, function () use ($tenant, $sku): array {
            $product = Product::query()->create([
                'tenant_id' => $tenant->id, 'sku' => $sku, 'title' => 'Kanonik Pamuklu Basic Tişört',
                'description' => '<p>%100 pamuk.</p>', 'brand' => 'Kanonik', 'internal_category_id' => 'giyim/tisort',
                'status' => 'active', 'content_version' => 1,
            ]);

            $color = OptionDefinition::query()->firstOrCreate(['tenant_id' => $tenant->id, 'name' => 'Renk'], ['position' => 0]);
            $size = OptionDefinition::query()->firstOrCreate(['tenant_id' => $tenant->id, 'name' => 'Beden'], ['position' => 1]);
            $warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();

            ProductImage::query()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'storage_path' => 'https://cdn.example.com/ortak.jpg', 'position' => 5]);

            $variants = [];

            foreach (self::VARIANTS as $variantSku => [$colorName, $sizeName, $price, $stock, $barcode]) {
                $variant = Variant::factory()->create([
                    'tenant_id' => $tenant->id, 'product_id' => $product->id, 'sku' => $variantSku,
                    'barcode' => $barcode, 'price' => $price, 'currency' => 'TRY',
                ]);

                foreach ([[$color, $colorName], [$size, $sizeName]] as [$definition, $value]) {
                    $optionValue = OptionValue::query()->firstOrCreate(['tenant_id' => $tenant->id, 'option_definition_id' => $definition->id, 'value' => $value]);
                    VariantOption::query()->create(['tenant_id' => $tenant->id, 'variant_id' => $variant->id, 'option_definition_id' => $definition->id, 'option_value_id' => $optionValue->id]);
                }

                ProductImage::query()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'variant_id' => $variant->id, 'storage_path' => "https://cdn.example.com/{$variantSku}.jpg", 'position' => 0]);
                InventoryLevel::query()->create(['tenant_id' => $tenant->id, 'warehouse_id' => $warehouse->id, 'variant_id' => $variant->id, 'on_hand' => $stock, 'reserved' => 0, 'version' => 1]);

                $variants[$variantSku] = $variant;
            }

            return [$product->fresh(), $variants];
        });
    }

    /**
     * Kategori + varyant öznitelik + değer eşlemeleri.
     *
     * @param  array<string, string>  $valueIds  "Renk:Kırmızı" → kanal değer kimliği
     */
    protected function mapCategory(Tenant $tenant, ChannelCategory $category, string $colorAttribute, string $sizeAttribute, array $valueIds): void
    {
        TenantContext::runFor($tenant->id, function () use ($tenant, $category, $colorAttribute, $sizeAttribute, $valueIds): void {
            CategoryMapping::query()->updateOrCreate(
                ['tenant_id' => $tenant->id, 'internal_category_id' => 'giyim/tisort', 'channel_type_code' => $category->channel_type_code],
                ['channel_category_id' => $category->id, 'taxonomy_version' => 'v1'],
            );

            foreach (['Renk' => $colorAttribute, 'Beden' => $sizeAttribute] as $definitionName => $attributeId) {
                $definition = OptionDefinition::query()->where('name', $definitionName)->firstOrFail();
                AttributeMapping::query()->updateOrCreate(
                    ['tenant_id' => $tenant->id, 'option_definition_id' => $definition->id, 'channel_category_id' => $category->id],
                    ['external_attribute_id' => $attributeId],
                );

                foreach (OptionValue::query()->where('option_definition_id', $definition->id)->get() as $value) {
                    $external = $valueIds["{$definitionName}:{$value->value}"] ?? null;

                    if ($external !== null) {
                        AttributeValueMapping::query()->updateOrCreate(
                            ['tenant_id' => $tenant->id, 'option_value_id' => $value->id, 'external_attribute_id' => $attributeId],
                            ['external_value_id' => $external, 'external_value_label' => $value->value],
                        );
                    }
                }
            }
        });
    }

    protected function productValue(Tenant $tenant, Product $product, ChannelCategory $category, string $attributeId, ?string $valueId, ?string $custom): void
    {
        TenantContext::runFor($tenant->id, fn () => ProductChannelAttributeValue::query()->updateOrCreate(
            ['product_id' => $product->id, 'channel_category_id' => $category->id, 'external_attribute_id' => $attributeId],
            ['tenant_id' => $tenant->id, 'external_value_id' => $valueId, 'custom_value' => $custom],
        ));
    }

    /** HB için tam hazır senaryo. @return array{0: Tenant, 1: ChannelConnection, 2: Product, 3: array<string, Variant>, 4: ChannelCategory} */
    protected function readyHb(): array
    {
        $tenant = $this->tenant();
        $connection = $this->hbConnection($tenant);
        [$product, $variants] = $this->tshirt($tenant);
        $category = $this->hbCategory();
        $this->mapCategory($tenant, $category, 'renk_variant_property', 'beden_variant_property', ['Renk:Kırmızı' => 'r1', 'Renk:Mavi' => 'r2', 'Beden:S' => 'b1', 'Beden:M' => 'b2']);
        $this->productValue($tenant, $product, $category, 'GarantiSuresi', null, '24');

        return [$tenant, $connection, $product, $variants, $category];
    }

    /** N11 için tam hazır senaryo. @return array{0: Tenant, 1: ChannelConnection, 2: Product, 3: array<string, Variant>, 4: ChannelCategory} */
    protected function readyN11(): array
    {
        $tenant = $this->tenant();
        $connection = $this->n11Connection($tenant);
        [$product, $variants] = $this->tshirt($tenant);
        $category = $this->n11Category();
        $this->mapCategory($tenant, $category, '429', '430', ['Renk:Kırmızı' => '8773717', 'Renk:Mavi' => '2523534', 'Beden:S' => '7001', 'Beden:M' => '7002']);
        $this->productValue($tenant, $product, $category, '500', '9001', null);

        return [$tenant, $connection, $product, $variants, $category];
    }

    /** @return list<string> açılan operasyon kimlikleri */
    protected function publish(Tenant $tenant, Product $product, ChannelConnection $connection): array
    {
        return TenantContext::runFor($tenant->id, fn () => app(PublishListing::class)->run($product->fresh(), $connection->fresh()));
    }

    protected function runPush(Tenant $tenant, string $operationId): PushListing
    {
        Redis::connection()->flushdb();
        $job = (new PushListing($operationId, $tenant->id))->withFakeQueueInteractions();
        $job->handle(app(ListingPayloadBuilder::class), app(SyncResultRecorder::class), app(AdapterRegistry::class));

        return $job;
    }

    protected function listing(Tenant $tenant, Variant $variant, ChannelConnection $connection): Listing
    {
        return TenantContext::runFor($tenant->id, fn () => Listing::query()->where('variant_id', $variant->id)->where('channel_connection_id', $connection->id)->firstOrFail());
    }

    protected function contentState(Tenant $tenant, Listing $listing): ?ListingSyncState
    {
        return TenantContext::runFor($tenant->id, fn () => ListingSyncState::query()->where('listing_id', $listing->id)->where('domain', SyncDomain::CONTENT->value)->first());
    }
}
