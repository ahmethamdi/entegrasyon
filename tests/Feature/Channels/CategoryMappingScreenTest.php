<?php

declare(strict_types=1);

namespace Tests\Feature\Channels;

use App\Domain\Catalog\Models\OptionDefinition;
use App\Domain\Catalog\Models\OptionValue;
use App\Domain\Catalog\Models\Product;
use App\Domain\Channels\Actions\SaveAttributeMapping;
use App\Domain\Channels\Actions\SaveCategoryMapping;
use App\Domain\Channels\Adapters\Trendyol\TrendyolAdapter;
use App\Domain\Channels\Models\AttributeMapping;
use App\Domain\Channels\Models\AttributeValueMapping;
use App\Domain\Channels\Models\CategoryMapping;
use App\Domain\Channels\Models\ChannelCategory;
use App\Domain\Channels\Models\ChannelCategoryAttribute;
use App\Domain\Channels\Models\ChannelType;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Eşleştirme ekranı — §13 · Faz 2 · "Kategori ve öznitelik eşleştirme
 * arayüzü". Katalog aktarımının ön koşulu.
 *
 * DEĞİŞMEZ KURAL — INERTIA'YA MODEL GÖNDERİLMEZ: yalnızca görünen alanlar.
 *
 * DEĞİŞMEZ KURAL — ROTA MODEL BAĞLAMASI KULLANILMAZ:
 *   `SubstituteBindings` `web` grubundadır ve rota seviyesindeki `tenant`
 *   ara katmanından ÖNCE çalışır; bağlama kullanılsaydı sorgu kiracı
 *   bağlamı kurulmadan atılır ve izolasyon istisnası fırlatırdı.
 *
 * DEĞİŞMEZ KURAL — YAPRAK OLMAYAN KATEGORİ SEÇENEK OLARAK SUNULMAZ.
 */
final class CategoryMappingScreenTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Ekran kiracının iç kategorilerini ve kanalın YAPRAK kategorilerini listeler.
     *
     * İç kategoriler `products.internal_category_id` üzerinden DISTINCT
     * okunur: ayrı bir iç kategori tablosu yoktur (§4) ve satıcının
     * gerçekte kullandığı değerler tek doğru kaynaktır.
     */
    #[Test]
    public function screen_lists_internal_categories_and_leaf_channel_categories(): void
    {
        [$tenant, $user] = $this->makeTenant();
        $this->makeTree();

        $this->asTenant($tenant, function () use ($tenant): void {
            Product::factory()->create(['tenant_id' => $tenant->id, 'internal_category_id' => 'giyim']);
            Product::factory()->create(['tenant_id' => $tenant->id, 'internal_category_id' => 'giyim']);
            Product::factory()->create(['tenant_id' => $tenant->id, 'internal_category_id' => 'ayakkabi']);
            // İç kategorisi olmayan ürün listede YER ALMAZ.
            Product::factory()->create(['tenant_id' => $tenant->id, 'internal_category_id' => null]);
        });

        $response = $this->actingAs($user)->get('/mappings?channel_type=trendyol');

        $response->assertOk();

        $props = $response->viewData('page')['props'];

        $internal = collect($props['internalCategories']);

        $this->assertCount(2, $internal, 'DISTINCT iç kategori; NULL taşıyan ürün sayılmaz.');
        $this->assertSame(['ayakkabi', 'giyim'], $internal->pluck('id')->sort()->values()->all());
        $this->assertSame(2, $internal->firstWhere('id', 'giyim')['productCount']);

        // YALNIZCA YAPRAKLAR: ara kategori seçenek olarak sunulmaz.
        $categories = collect($props['channelCategories']);

        $this->assertCount(2, $categories);
        $this->assertSame(['11', '12'], $categories->pluck('externalId')->sort()->values()->all());
        $this->assertSame('Giyim > Elbise', $categories->firstWhere('externalId', '11')['path'],
            'Kullanıcı kategoriyi ancak bağlamıyla tanır; yol gösterilir.');
    }

    /**
     * Kategori eşleştirmesi panelden kaydedilir.
     */
    #[Test]
    public function category_mapping_is_saved_from_the_panel(): void
    {
        [$tenant, $user] = $this->makeTenant();
        [$dress] = $this->makeTree();

        $this->asTenant($tenant, fn () => Product::factory()->create([
            'tenant_id' => $tenant->id,
            'internal_category_id' => 'giyim',
        ]));

        $response = $this->actingAs($user)->post('/mappings/category', [
            'internal_category_id' => 'giyim',
            'channel_category_id' => $dress->id,
        ]);

        $response->assertRedirect();

        $mapping = $this->asTenant($tenant, fn () => CategoryMapping::query()->firstOrFail());

        $this->assertSame($dress->id, $mapping->channel_category_id);
        $this->assertSame('v1', $mapping->taxonomy_version);
    }

    /**
     * ARA KATEGORİ İSTEĞİ ALAN HATASINA çevrilir — 500 verilmez.
     *
     * Form kurcalayan biri ara kategori kimliği gönderebilir; kullanıcıya
     * ne yapacağını söyleyen bir mesaj gerekir.
     */
    #[Test]
    public function mapping_to_a_non_leaf_category_returns_a_field_error(): void
    {
        [$tenant, $user] = $this->makeTenant();
        $this->makeTree();

        $root = $this->asSystem(fn () => ChannelCategory::query()
            ->where('external_id', '1')->firstOrFail());

        $response = $this->actingAs($user)->post('/mappings/category', [
            'internal_category_id' => 'giyim',
            'channel_category_id' => $root->id,
        ]);

        $response->assertSessionHasErrors('channel_category_id');

        $count = $this->asTenant($tenant, fn () => CategoryMapping::query()->count());
        $this->assertSame(0, $count);
    }

    /**
     * Ekran eşleşen kategorinin ZORUNLU özniteliklerini ve eksikleri gösterir.
     *
     * Ön koşul kapısının (§14) panel karşılığı: satıcı ürünü göndermeden
     * ÖNCE neyin eksik olduğunu görmelidir. Aktarım anında öğrenmek,
     * listing'in `blocked` düşmesi demektir.
     */
    #[Test]
    public function screen_shows_required_attributes_and_which_are_missing(): void
    {
        [$tenant, $user] = $this->makeTenant();
        [$dress] = $this->makeTree();

        $this->makeAttribute($dress, 'attr-size', 'Beden', isRequired: true);
        $this->makeAttribute($dress, 'attr-color', 'Renk', isRequired: true);
        $this->makeAttribute($dress, 'attr-fabric', 'Kumaş', isRequired: false);

        $size = $this->asTenant($tenant, fn () => OptionDefinition::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Beden',
        ]));

        $this->asTenant($tenant, function () use ($tenant, $dress, $size): void {
            Product::factory()->create([
                'tenant_id' => $tenant->id,
                'internal_category_id' => 'giyim',
            ]);

            app(SaveCategoryMapping::class)->run('giyim', $dress);
            app(SaveAttributeMapping::class)->run($size, $dress, 'attr-size');
        });

        $response = $this->actingAs($user)->get('/mappings?channel_type=trendyol');

        $props = $response->viewData('page')['props'];

        $row = collect($props['internalCategories'])->firstWhere('id', 'giyim');

        $this->assertNotNull($row['mapping']);
        $this->assertSame('Giyim > Elbise', $row['mapping']['categoryPath']);

        // İki zorunlu öznitelik var, biri eşleşmiş: bir tanesi EKSİK.
        $this->assertSame(2, $row['mapping']['requiredAttributeCount']);
        $this->assertSame(1, $row['mapping']['mappedRequiredCount']);
        $this->assertSame(['Renk'], $row['mapping']['missingRequiredAttributes'],
            'Eksik zorunlu öznitelik ADIYLA gösterilir; sayı tek başına ne yapacağını söylemez.');
        $this->assertFalse($row['mapping']['ready'],
            'Zorunlu öznitelik eksikken eşleştirme HAZIR sayılmaz.');
    }

    /**
     * Tüm zorunlu öznitelikler eşleştiğinde satır HAZIR görünür.
     */
    #[Test]
    public function a_fully_mapped_category_is_marked_ready(): void
    {
        [$tenant, $user] = $this->makeTenant();
        [$dress] = $this->makeTree();

        $this->makeAttribute($dress, 'attr-size', 'Beden', isRequired: true);

        $size = $this->asTenant($tenant, fn () => OptionDefinition::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Beden',
        ]));

        $this->asTenant($tenant, function () use ($tenant, $dress, $size): void {
            Product::factory()->create([
                'tenant_id' => $tenant->id,
                'internal_category_id' => 'giyim',
            ]);

            app(SaveCategoryMapping::class)->run('giyim', $dress);
            app(SaveAttributeMapping::class)->run($size, $dress, 'attr-size');
        });

        $response = $this->actingAs($user)->get('/mappings?channel_type=trendyol');

        $row = collect($response->viewData('page')['props']['internalCategories'])
            ->firstWhere('id', 'giyim');

        $this->assertTrue($row['mapping']['ready']);
        $this->assertSame([], $row['mapping']['missingRequiredAttributes']);
    }

    /**
     * BAYAT EŞLEŞTİRME PANELDE İŞARETLENİR — ve satır SİLİNMEZ.
     *
     * Kanal yeni sürüm yayınladığında satıcının kararı yaşamaya devam
     * eder; panel yalnızca "yeniden doğrula" der.
     */
    #[Test]
    public function a_stale_mapping_is_flagged_in_the_panel(): void
    {
        [$tenant, $user] = $this->makeTenant();
        [$dress] = $this->makeTree(version: 'v1');

        $this->asTenant($tenant, function () use ($tenant, $dress): void {
            Product::factory()->create([
                'tenant_id' => $tenant->id,
                'internal_category_id' => 'giyim',
            ]);

            app(SaveCategoryMapping::class)->run('giyim', $dress);
        });

        // Kanal yeni sürüm yayınlar.
        $this->makeTree(version: 'v2');

        $response = $this->actingAs($user)->get('/mappings?channel_type=trendyol');

        $props = $response->viewData('page')['props'];
        $row = collect($props['internalCategories'])->firstWhere('id', 'giyim');

        $this->assertNotNull($row['mapping'], 'Bayat eşleştirme SİLİNMEZ.');
        $this->assertTrue($row['mapping']['stale']);
        $this->assertSame('v1', $row['mapping']['taxonomyVersion']);
        $this->assertSame('v2', $props['taxonomyVersion'], 'Ekran güncel sürümü gösterir.');

        // Ve seçenekler GÜNCEL sürümden gelir — satıcı eskisinden seçmemeli.
        $categories = collect($props['channelCategories']);
        $this->assertCount(2, $categories, 'Seçenekler yalnızca güncel sürümün yaprakları.');
    }

    /**
     * Öznitelik ve değer eşleştirmesi panelden kaydedilir.
     */
    #[Test]
    public function attribute_and_value_mappings_are_saved_from_the_panel(): void
    {
        [$tenant, $user] = $this->makeTenant();
        [$dress] = $this->makeTree();

        $this->makeAttribute($dress, 'attr-size', 'Beden', allowedValues: [
            ['id' => 'v-small', 'label' => 'SMALL'],
        ]);

        [$definition, $value] = $this->makeOption($tenant, 'Beden', 'S');

        $this->actingAs($user)->post('/mappings/attribute', [
            'option_definition_id' => $definition->id,
            'channel_category_id' => $dress->id,
            'external_attribute_id' => 'attr-size',
        ])->assertRedirect();

        $this->actingAs($user)->post('/mappings/attribute-value', [
            'option_value_id' => $value->id,
            'external_attribute_id' => 'attr-size',
            'external_value_id' => 'v-small',
            'external_value_label' => 'SMALL',
        ])->assertRedirect();

        $attribute = $this->asTenant($tenant, fn () => AttributeMapping::query()->firstOrFail());
        $this->assertSame('attr-size', $attribute->external_attribute_id);

        $valueMapping = $this->asTenant($tenant, fn () => AttributeValueMapping::query()->firstOrFail());
        $this->assertSame('v-small', $valueMapping->external_value_id);
        $this->assertSame('SMALL', $valueMapping->external_value_label);
    }

    /**
     * İZİNLİ LİSTE DIŞINDAKİ DEĞER alan hatasına çevrilir.
     */
    #[Test]
    public function a_value_outside_the_allowed_list_returns_a_field_error(): void
    {
        [$tenant, $user] = $this->makeTenant();
        [$dress] = $this->makeTree();

        $this->makeAttribute($dress, 'attr-size', 'Beden', allowedValues: [
            ['id' => 'v-small', 'label' => 'SMALL'],
        ]);

        [, $value] = $this->makeOption($tenant, 'Beden', 'S');

        $response = $this->actingAs($user)->post('/mappings/attribute-value', [
            'option_value_id' => $value->id,
            'external_attribute_id' => 'attr-size',
            'external_value_id' => 'v-uydurma',
        ]);

        $response->assertSessionHasErrors('external_value_id');

        $count = $this->asTenant($tenant, fn () => AttributeValueMapping::query()->count());
        $this->assertSame(0, $count);
    }

    // ═══════════════════════════════════════════════ kiracı izolasyonu

    /**
     * BAŞKA KİRACININ SEÇENEK TANIMI KABUL EDİLMEZ.
     *
     * Kimlik istekten gelir ve kiracı scope'u altında aranır; form
     * kurcalayan biri aksi halde başka kiracının seçeneğini kendi
     * eşleştirmesine bağlardı.
     */
    #[Test]
    public function another_tenants_option_definition_is_rejected(): void
    {
        [$tenantA, $userA] = $this->makeTenant();
        [$tenantB] = $this->makeTenant();
        [$dress] = $this->makeTree();

        $this->makeAttribute($dress, 'attr-size', 'Beden');

        [$definitionB] = $this->makeOption($tenantB, 'Beden', 'S');

        $response = $this->actingAs($userA)->post('/mappings/attribute', [
            'option_definition_id' => $definitionB->id,
            'channel_category_id' => $dress->id,
            'external_attribute_id' => 'attr-size',
        ]);

        $response->assertSessionHasErrors('option_definition_id');

        $count = $this->asTenant($tenantA, fn () => AttributeMapping::query()->count());
        $this->assertSame(0, $count);
    }

    /**
     * İÇ KATEGORİ SAYIMI KİRACI FİLTRESİ TAŞIR.
     *
     * Sayım `DB::table()` ile yapılır ve `DB::table()` Eloquent global
     * scope'una TABİ DEĞİLDİR; filtre yazılmazsa başka kiracının ürünleri
     * bu sayıma karışır. Bu boşluk projede DÖRT ayrı turda çıktı.
     */
    #[Test]
    public function internal_category_counts_are_scoped_to_the_tenant(): void
    {
        [$tenantA, $userA] = $this->makeTenant();
        [$tenantB] = $this->makeTenant();
        $this->makeTree();

        $this->asTenant($tenantA, fn () => Product::factory()->create([
            'tenant_id' => $tenantA->id,
            'internal_category_id' => 'giyim',
        ]));

        // B kiracısının AYNI iç kategori adını taşıyan iki ürünü.
        $this->asTenant($tenantB, function () use ($tenantB): void {
            Product::factory()->create(['tenant_id' => $tenantB->id, 'internal_category_id' => 'giyim']);
            Product::factory()->create(['tenant_id' => $tenantB->id, 'internal_category_id' => 'ayakkabi']);
        });

        $response = $this->actingAs($userA)->get('/mappings?channel_type=trendyol');

        $internal = collect($response->viewData('page')['props']['internalCategories']);

        $this->assertCount(1, $internal, 'B kiracısının iç kategorisi A’nın listesine sızmamalı.');
        $this->assertSame('giyim', $internal->first()['id']);
        $this->assertSame(1, $internal->first()['productCount'],
            'Sayım yalnızca A kiracısının ürünlerini saymalı.');
    }

    /**
     * Misafir eşleştirme ekranını göremez.
     */
    #[Test]
    public function guest_is_redirected_to_login(): void
    {
        $this->get('/mappings')->assertRedirect('/login');
    }

    // ───────────────────────────────────────────────────── yardımcılar

    /** @return array{0: Tenant, 1: User} */
    private function makeTenant(): array
    {
        $user = User::factory()->create();

        $tenant = (new CreateTenant)->run(name: 'Ekran '.uniqid(), owner: $user);

        return [$tenant, $user];
    }

    /** @return array{0: ChannelCategory, 1: ChannelCategory} */
    private function makeTree(string $version = 'v1', string $channelTypeCode = 'trendyol'): array
    {
        return $this->asSystem(function () use ($version, $channelTypeCode): array {
            ChannelType::query()->updateOrCreate(
                ['code' => $channelTypeCode],
                [
                    'name' => ucfirst($channelTypeCode),
                    'kind' => 'marketplace',
                    'adapter_class' => TrendyolAdapter::class,
                    'is_active' => true,
                ],
            );

            ChannelCategory::query()->updateOrCreate(
                [
                    'channel_type_code' => $channelTypeCode,
                    'taxonomy_version' => $version,
                    'external_id' => '1',
                ],
                ['name' => 'Giyim', 'path' => 'Giyim', 'is_leaf' => false],
            );

            $dress = ChannelCategory::query()->updateOrCreate(
                [
                    'channel_type_code' => $channelTypeCode,
                    'taxonomy_version' => $version,
                    'external_id' => '11',
                ],
                [
                    'parent_external_id' => '1',
                    'name' => 'Elbise',
                    'path' => 'Giyim > Elbise',
                    'is_leaf' => true,
                ],
            );

            $shoe = ChannelCategory::query()->updateOrCreate(
                [
                    'channel_type_code' => $channelTypeCode,
                    'taxonomy_version' => $version,
                    'external_id' => '12',
                ],
                [
                    'parent_external_id' => '1',
                    'name' => 'Ayakkabı',
                    'path' => 'Giyim > Ayakkabı',
                    'is_leaf' => true,
                ],
            );

            return [$dress, $shoe];
        });
    }

    /** @param  list<array{id: string, label: string}>  $allowedValues */
    private function makeAttribute(
        ChannelCategory $category,
        string $externalAttributeId,
        string $name,
        bool $isRequired = true,
        array $allowedValues = [],
    ): ChannelCategoryAttribute {
        return $this->asSystem(fn () => ChannelCategoryAttribute::query()->updateOrCreate(
            [
                'channel_category_id' => $category->id,
                'external_attribute_id' => $externalAttributeId,
            ],
            [
                'name' => $name,
                'is_required' => $isRequired,
                'is_variant_defining' => true,
                'data_type' => 'string',
                'allowed_values' => $allowedValues,
            ],
        ));
    }

    /** @return array{0: OptionDefinition, 1: OptionValue} */
    private function makeOption(Tenant $tenant, string $name, string $value): array
    {
        return $this->asTenant($tenant, function () use ($tenant, $name, $value): array {
            $definition = OptionDefinition::query()->create([
                'tenant_id' => $tenant->id,
                'name' => $name,
            ]);

            $optionValue = OptionValue::query()->create([
                'tenant_id' => $tenant->id,
                'option_definition_id' => $definition->id,
                'value' => $value,
            ]);

            return [$definition, $optionValue];
        });
    }
}
