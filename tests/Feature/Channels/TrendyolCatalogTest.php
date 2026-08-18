<?php

declare(strict_types=1);

namespace Tests\Feature\Channels;

use App\Domain\Catalog\Models\OptionDefinition;
use App\Domain\Catalog\Models\OptionValue;
use App\Domain\Catalog\Models\Product;
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
        Http::fake(['*' => Http::response(['batchRequestId' => 'b-1'], 200)]);

        [$tenant, $connection, $listing] = $this->scenario();

        $payload = $this->asTenant($tenant, fn () => app(ListingPayloadBuilder::class)
            ->build($listing, 1));

        $result = $this->asTenant($tenant, fn () => $this->adapter($connection)
            ->createListing($payload));

        $this->assertTrue($result->successful);

        Http::assertSent(function ($request): bool {
            $item = $request->data()['items'][0] ?? [];

            // İÇ kategori adı DEĞİL, kanalın sayısal kimliği gitmeli.
            $this->assertSame(11, $item['categoryId'] ?? null,
                'İç kategori kanal kimliğine çevrilmeli.');

            $this->assertSame('SKU-1', $item['barcode'] ?? null);
            $this->assertSame('Yazlık Elbise', $item['title'] ?? null);

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
        Http::fake(['*' => Http::response(['batchRequestId' => 'b-1'], 200)]);

        [$tenant, $connection, $listing] = $this->scenario(withAttributes: true);

        $payload = $this->asTenant($tenant, fn () => app(ListingPayloadBuilder::class)
            ->build($listing, 1));

        $this->asTenant($tenant, fn () => $this->adapter($connection)->createListing($payload));

        Http::assertSent(function ($request): bool {
            $attributes = $request->data()['items'][0]['attributes'] ?? [];

            $this->assertSame([
                ['attributeId' => 'attr-size', 'attributeValueId' => 'v-small'],
            ], $attributes, 'Öznitelik ve değer KANAL kimlikleriyle gitmeli.');

            return true;
        });
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
        Http::fake(['*' => Http::response([
            'content' => [['barcode' => 'SKU-1', 'title' => 'Yazlık Elbise', 'productUrl' => 'https://ty/p/1']],
        ], 200)]);

        [$tenant, $connection, $listing] = $this->scenario();

        $variant = $this->asTenant($tenant, fn () => $listing->variant);

        $found = $this->asTenant($tenant, fn () => $this->adapter($connection)
            ->findExistingListing($variant));

        $this->assertNotNull($found);
        $this->assertSame('SKU-1', $found->externalId);
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
                'internal_category_id' => 'kadin-elbise',
            ]);

            $variant = Variant::factory()->create([
                'tenant_id' => $tenant->id,
                'product_id' => $product->id,
                'sku' => 'SKU-1',
                'barcode' => 'SKU-1',
            ]);

            if ($withCategoryMapping) {
                app(SaveCategoryMapping::class)->run('kadin-elbise', $dress);
            }

            if ($withAttributes) {
                $this->asSystem(fn () => ChannelCategoryAttribute::query()->updateOrCreate(
                    ['channel_category_id' => $dress->id, 'external_attribute_id' => 'attr-size'],
                    [
                        'name' => 'Beden',
                        'is_required' => true,
                        'is_variant_defining' => true,
                        'data_type' => 'string',
                        'allowed_values' => [['id' => 'v-small', 'label' => 'SMALL']],
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

                app(SaveAttributeMapping::class)->run($definition, $dress, 'attr-size');
                app(SaveAttributeValueMapping::class)->run(
                    optionValue: $value,
                    externalAttributeId: 'attr-size',
                    externalValueId: 'v-small',
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
