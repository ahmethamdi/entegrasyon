<?php

declare(strict_types=1);

namespace Tests\Feature\Publishing;

use App\Domain\Channels\Adapters\Hepsiburada\HepsiburadaAdapter;
use App\Domain\Channels\Registry\AdapterRegistry;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * S3 · Ürün düzeyi kanal öznitelikleri ekranı, varyant başına sonuç,
 * kiracı sınırı ve HB taksonomi ayrıştırma.
 */
final class PublicationIsolationTest extends TestCase
{
    use BuildsPublishingScenario;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPublishing();
    }

    #[Test]
    public function the_channel_screen_lists_descriptive_attributes_and_per_variant_results(): void
    {
        [$tenant, $connection, $product] = $this->readyHb();
        DB::table('product_channel_attribute_values')->delete();
        $this->publish($tenant, $product, $connection);

        $this->actingAs($this->owner)->get("/products/{$product->id}/channels")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('channels.0.requirements.attributes.0.id', 'GarantiSuresi')
                ->where('channels.0.requirements.attributes.0.required', true)
                ->has('channels.0.requirements.attributes', 1, fn (AssertableInertia $a) => $a->etc())
                ->has('channels.0.variants', 3)
                ->where('channels.0.variants.0.reasonCode', 'ATTRIBUTE_VALUE_REQUIRED'));
    }

    #[Test]
    public function a_descriptive_value_is_saved_validated_and_cleared(): void
    {
        [$tenant, , $product, , $category] = $this->readyHb();

        $this->actingAs($this->owner)->post("/products/{$product->id}/channel-attributes", [
            'channel_category_id' => $category->id, 'external_attribute_id' => 'GarantiSuresi', 'custom_value' => '36',
        ])->assertRedirect();
        $this->assertSame('36', DB::table('product_channel_attribute_values')->value('custom_value'));

        // Varyant belirleyici ürün düzeyinde girilemez.
        $this->actingAs($this->owner)->post("/products/{$product->id}/channel-attributes", [
            'channel_category_id' => $category->id, 'external_attribute_id' => 'renk_variant_property', 'custom_value' => 'Kırmızı',
        ])->assertSessionHasErrors('external_attribute_id');

        $this->actingAs($this->owner)->post("/products/{$product->id}/channel-attributes", [
            'channel_category_id' => $category->id, 'external_attribute_id' => 'GarantiSuresi',
        ]);
        $this->assertSame(0, DB::table('product_channel_attribute_values')->count());
    }

    #[Test]
    public function another_tenants_product_and_category_cannot_be_written(): void
    {
        [$tenantA, , $productA, , $category] = $this->readyHb();
        $ownerA = $this->owner;
        $tenantB = $this->tenant();
        [$productB] = $this->tshirt($tenantB, 'TS-B');
        $ownerB = $this->owner;

        // B, A'nın ürününe yazamaz (404, var olduğu sızmaz).
        $this->actingAs($ownerB)->post("/products/{$productA->id}/channel-attributes", [
            'channel_category_id' => $category->id, 'external_attribute_id' => 'GarantiSuresi', 'custom_value' => '1',
        ])->assertNotFound();

        // B'nin kategorisi A'nın eşlemesiyle açılmaz.
        $this->actingAs($ownerB)->post("/products/{$productB->id}/channel-attributes", [
            'channel_category_id' => $category->id, 'external_attribute_id' => 'GarantiSuresi', 'custom_value' => '1',
        ])->assertSessionHasErrors('channel_category_id');

        $this->assertSame(1, DB::table('product_channel_attribute_values')->count(), 'Yalnız A\'nın kendi değeri.');
        $this->assertNotNull($ownerA);
        $this->assertNotNull($tenantA);
    }

    #[Test]
    public function the_hb_taxonomy_reads_leaf_categories_and_three_attribute_groups(): void
    {
        $tenant = $this->tenant();
        $connection = $this->hbConnection($tenant);

        Http::fake(function (Request $request) {
            $url = $request->url();

            return match (true) {
                str_contains($url, 'get-all-categories') => Http::response(['success' => true, 'last' => true, 'totalPages' => 1, 'data' => [
                    ['categoryId' => 18021982, 'name' => 'Tişört', 'parentCategoryId' => 100, 'paths' => ['Giyim', 'Tişört'], 'leaf' => true],
                ]]),
                str_contains($url, '/values') => Http::response(['success' => true, 'data' => [['id' => 'v1', 'value' => 'Siyah']]]),
                str_contains($url, '/attributes') => Http::response(['success' => true, 'data' => [
                    'baseAttributes' => [['id' => 'UrunAdi', 'name' => 'Ürün Adı', 'mandatory' => true, 'type' => 'string']],
                    'attributes' => [['id' => 'GarantiSuresi', 'name' => 'Garanti', 'mandatory' => true, 'type' => 'string']],
                    'variantAttributes' => [['id' => 'renk_variant_property', 'name' => 'Renk', 'mandatory' => true, 'type' => 'enum']],
                ]]),
                default => Http::response([], 599),
            };
        });

        $adapter = TenantContext::runAsSystem(fn () => app(AdapterRegistry::class)->for($connection));
        $this->assertInstanceOf(HepsiburadaAdapter::class, $adapter);

        $tree = $adapter->fetchCategoryTree();
        $this->assertSame([['external_id' => '18021982', 'parent_external_id' => '100', 'name' => 'Tişört', 'path' => 'Giyim > Tişört', 'is_leaf' => true]], $tree->categories);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'mpop-sit.hepsiburada.com/product/api/categories/get-all-categories') && str_contains($r->url(), 'leaf=true') && str_contains($r->url(), 'available=true'));

        $attrs = collect($adapter->fetchCategoryAttributes('18021982'))->keyBy('external_attribute_id');
        $this->assertFalse($attrs['UrunAdi']['is_variant_defining']);
        $this->assertTrue($attrs['renk_variant_property']['is_variant_defining']);
        $this->assertSame([['id' => 'v1', 'label' => 'Siyah']], $attrs['renk_variant_property']['allowed_values']);
        $this->assertSame([], $attrs['GarantiSuresi']['allowed_values'], 'Serbest metin özniteliğe değer çağrısı yok.');
    }
}
