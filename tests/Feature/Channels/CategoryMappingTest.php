<?php

declare(strict_types=1);

namespace Tests\Feature\Channels;

use App\Domain\Catalog\Models\OptionDefinition;
use App\Domain\Catalog\Models\OptionValue;
use App\Domain\Channels\Actions\SaveAttributeMapping;
use App\Domain\Channels\Actions\SaveAttributeValueMapping;
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
use App\Support\Tenancy\Exceptions\MissingTenantContextException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\UuidV7;
use Tests\TestCase;

/**
 * Kategori ve öznitelik eşleştirmesi — §13 · Faz 2 · "Kategori ve öznitelik
 * eşleştirme arayüzü — 28 sa". §4 · Mapping, §14 · ön koşul kapısı.
 *
 * DEĞİŞMEZ KURAL — EŞLEŞTİRME KİRACIYA AİTTİR, TAKSONOMİNİN AKSİNE:
 *   `channel_categories` `tenant_id` TAŞIMAZ (ağaç kanalın gerçeği);
 *   `category_mappings` TAŞIR (eşleştirme satıcının kararı). İki satıcı
 *   aynı iç kategoriyi kanalın farklı kategorilerine bağlayabilir ve ikisi
 *   de haklıdır. Bu testler ayrımı İKİ YÖNDE de doğrular: eşleştirme
 *   sızmaz, ağaç paylaşılır.
 *
 * DEĞİŞMEZ KURAL — BAYAT EŞLEŞTİRME SİLİNMEZ, İŞARETLENİR:
 *   Kanal yeni sürüm yayınladığında eşleştirme eski sürüme bakmaya devam
 *   eder ve panelde "yeniden doğrula" damgası yer. Silinseydi satıcının
 *   aylarca emek verdiği kararlar bir gecede yok olurdu.
 *
 * DEĞİŞMEZ KURAL — ÜRÜN YALNIZCA YAPRAĞA EŞLENİR:
 *   Ara kategoriye ürün açılamaz; eşleştirmeye izin vermek satıcıyı
 *   aktarım anında kanal hatasıyla baş başa bırakırdı.
 */
final class CategoryMappingTest extends TestCase
{
    use RefreshDatabase;

    // ═══════════════════════════════════════════════ kiracı izolasyonu

    /**
     * İki satıcı aynı iç kategoriyi FARKLI kanal kategorilerine bağlayabilir.
     *
     * Bu, taksonomi ile eşleştirme arasındaki ayrımın tam kalbi: ağaç
     * paylaşılır (aynı `ChannelCategory` satırları), karar paylaşılmaz.
     * Tekillik `(tenant_id, internal_category_id, channel_type_code)`
     * olduğu için ikinci kiracı ilkini ihlal ETMEZ.
     */
    #[Test]
    public function two_tenants_map_the_same_internal_category_differently(): void
    {
        [$tenantA, $userA] = $this->makeTenant('A');
        [$tenantB, $userB] = $this->makeTenant('B');

        // Ağaç KİRACISIZ — tek kez yazılır ve iki kiracı da aynı satırları görür.
        [$dress, $shoe] = $this->makeTree();

        $this->asTenant($tenantA, fn () => app(SaveCategoryMapping::class)->run(
            internalCategoryId: 'giyim',
            channelCategory: $dress,
        ));

        $this->asTenant($tenantB, fn () => app(SaveCategoryMapping::class)->run(
            internalCategoryId: 'giyim',
            channelCategory: $shoe,
        ));

        $mappingA = $this->asTenant($tenantA, fn () => CategoryMapping::query()->firstOrFail());
        $mappingB = $this->asTenant($tenantB, fn () => CategoryMapping::query()->firstOrFail());

        $this->assertSame($dress->id, $mappingA->channel_category_id);
        $this->assertSame($shoe->id, $mappingB->channel_category_id,
            'Eşleştirme satıcının KARARIDIR; iki satıcı aynı iç kategoriyi farklı bağlayabilir.');

        // Ağaç GERÇEKTEN paylaşıldı: iki eşleştirme aynı taksonomi sürümünde.
        $this->assertSame($mappingA->taxonomy_version, $mappingB->taxonomy_version);
    }

    /**
     * A kiracısı B'nin eşleştirmesini GÖREMEZ.
     *
     * `BelongsToTenant` global scope'u bunu sağlar; trait düşerse bu test
     * kırmızıya döner.
     */
    #[Test]
    public function tenant_cannot_see_another_tenants_mapping(): void
    {
        [$tenantA] = $this->makeTenant('A');
        [$tenantB] = $this->makeTenant('B');

        [$dress] = $this->makeTree();

        $this->asTenant($tenantB, fn () => app(SaveCategoryMapping::class)->run(
            internalCategoryId: 'giyim',
            channelCategory: $dress,
        ));

        $seenByA = $this->asTenant($tenantA, fn () => CategoryMapping::query()->count());

        $this->assertSame(0, $seenByA, 'Eşleştirme kiracıya aittir ve sızmamalıdır.');

        // Ama AĞAÇ paylaşılır: A da aynı kategoriyi görür.
        $treeSeenByA = $this->asTenant($tenantA, fn () => ChannelCategory::query()->count());

        $this->assertSame(3, $treeSeenByA, 'Taksonomi kiracısızdır ve herkes görür.');
    }

    /**
     * Bağlam olmadan eşleştirme yazılamaz — sessizce kiracısız satır açılmaz.
     */
    #[Test]
    public function saving_without_tenant_context_throws(): void
    {
        [$dress] = $this->makeTree();

        $this->expectException(MissingTenantContextException::class);

        app(SaveCategoryMapping::class)->run(
            internalCategoryId: 'giyim',
            channelCategory: $dress,
        );
    }

    // ═══════════════════════════════════════════════ kategori eşleştirmesi

    /**
     * Eşleştirme kaydedilir ve seçilen kategorinin SÜRÜMÜNÜ damgalar.
     *
     * Sürüm FK'dan da okunabilirdi; kolon olarak tutulması "hangi
     * eşleştirmeler bayat" sorusunu join'siz cevaplar.
     */
    #[Test]
    public function mapping_records_the_taxonomy_version_of_the_chosen_category(): void
    {
        [$tenant] = $this->makeTenant('A');
        [$dress] = $this->makeTree(version: 'v1');

        $mapping = $this->asTenant($tenant, fn () => app(SaveCategoryMapping::class)->run(
            internalCategoryId: 'giyim',
            channelCategory: $dress,
        ));

        $this->assertSame('v1', $mapping->taxonomy_version);
        $this->assertSame('trendyol', $mapping->channel_type_code);

        // Elle yapılan eşleştirme tam güvenle ve DOĞRULANMIŞ olarak yazılır:
        // satıcı bizzat seçti, ayrıca onaylatmak gereksiz iş yüküdür.
        $this->assertSame(100, $mapping->confidence);
        $this->assertSame('user', $mapping->mapped_by);
        $this->assertNotNull($mapping->verified_at);
    }

    /**
     * Aynı iç kategori ikinci kez eşlenirse İKİNCİ SATIR AÇILMAZ — güncellenir.
     *
     * `UNIQUE(tenant_id, internal_category_id, channel_type_code)` bunu
     * zorlar; action onu ihlal etmek yerine var olan satırı yeniden kullanır.
     * İki satır olsaydı ürünün hangi kategoriye açılacağı belirsiz kalırdı.
     */
    #[Test]
    public function remapping_updates_the_existing_row_instead_of_adding_one(): void
    {
        [$tenant] = $this->makeTenant('A');
        [$dress, $shoe] = $this->makeTree();

        $first = $this->asTenant($tenant, fn () => app(SaveCategoryMapping::class)->run(
            internalCategoryId: 'giyim',
            channelCategory: $dress,
        ));

        $second = $this->asTenant($tenant, fn () => app(SaveCategoryMapping::class)->run(
            internalCategoryId: 'giyim',
            channelCategory: $shoe,
        ));

        $this->assertSame($first->id, $second->id, 'Yeniden eşleştirme aynı satırı günceller.');

        $count = $this->asTenant($tenant, fn () => CategoryMapping::query()->count());
        $this->assertSame(1, $count);

        // KALICILIK: Eloquent kimlik haritası yanıltır, HAM SATIRI oku.
        $raw = DB::table('category_mappings')->where('id', $first->id)->first();
        $this->assertSame($shoe->id, $raw->channel_category_id,
            'Güncelleme gerçekten kalıcı olmalı.');
    }

    /**
     * Farklı KANALLAR aynı iç kategori için ayrı satır alır.
     *
     * Tekilliğe `channel_type_code` girer: satıcı "giyim"i hem Trendyol'a
     * hem başka bir kanala bağlayabilmelidir.
     */
    #[Test]
    public function the_same_internal_category_maps_separately_per_channel(): void
    {
        [$tenant] = $this->makeTenant('A');
        [$dress] = $this->makeTree();
        $other = $this->makeTree(channelTypeCode: 'hepsiburada')[0];

        $this->asTenant($tenant, function () use ($dress, $other): void {
            app(SaveCategoryMapping::class)->run('giyim', $dress);
            app(SaveCategoryMapping::class)->run('giyim', $other);
        });

        $count = $this->asTenant($tenant, fn () => CategoryMapping::query()->count());

        $this->assertSame(2, $count, 'Kanal başına ayrı eşleştirme; tekillik kanalı içerir.');
    }

    /**
     * ARA KATEGORİYE EŞLEŞTİRME REDDEDİLİR — ürün yalnızca yaprağa açılır.
     *
     * İzin verilseydi satıcı eşleştirmeyi tamamlanmış sanır, ön koşul
     * kapısından geçer ve hata ancak kanala gönderildiğinde ortaya çıkardı.
     */
    #[Test]
    public function mapping_to_a_non_leaf_category_is_rejected(): void
    {
        [$tenant] = $this->makeTenant('A');

        $this->makeTree();

        $root = $this->asSystem(fn () => ChannelCategory::query()
            ->where('external_id', '1')->firstOrFail());

        $this->assertFalse($root->is_leaf, 'Kurulum: kök kategori yaprak değil.');

        $this->expectException(\InvalidArgumentException::class);

        $this->asTenant($tenant, fn () => app(SaveCategoryMapping::class)->run(
            internalCategoryId: 'giyim',
            channelCategory: $root,
        ));
    }

    /**
     * Veritabanı kısıtı da ikinci satırı reddeder — action atlansa bile.
     *
     * Action'ın `updateOrCreate` kullanması bir kolaylıktır; asıl koruma
     * kısıttır ve ham insert ile doğrulanır.
     */
    #[Test]
    public function duplicate_mapping_is_rejected_by_the_database(): void
    {
        [$tenant] = $this->makeTenant('A');
        [$dress, $shoe] = $this->makeTree();

        $this->asTenant($tenant, fn () => app(SaveCategoryMapping::class)->run('giyim', $dress));

        $this->expectException(QueryException::class);

        DB::table('category_mappings')->insert([
            'id' => (string) UuidV7::generate(),
            'tenant_id' => $tenant->id,
            'internal_category_id' => 'giyim',
            'channel_type_code' => 'trendyol',
            'channel_category_id' => $shoe->id,
            'taxonomy_version' => 'v1',
            'confidence' => 100,
            'mapped_by' => 'user',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // ═══════════════════════════════════════════════ bayatlama

    /**
     * YENİ SÜRÜM ESKİ EŞLEŞTİRMEYİ SİLMEZ — bayat olarak İŞARETLENİR.
     *
     * Bu, taksonomi maddesindeki "sürüm bir ayıraçtır, imha emri değil"
     * kuralının eşleştirme tarafındaki karşılığıdır.
     */
    #[Test]
    public function a_new_taxonomy_version_marks_mappings_stale_without_deleting_them(): void
    {
        [$tenant] = $this->makeTenant('A');
        [$dress] = $this->makeTree(version: 'v1');

        $mapping = $this->asTenant($tenant, fn () => app(SaveCategoryMapping::class)->run('giyim', $dress));

        // Kanal yeni sürüm yayınlar — ESKİ SATIRLAR KALIR (taksonomi kuralı).
        $this->makeTree(version: 'v2');

        $stillThere = DB::table('category_mappings')->where('id', $mapping->id)->first();

        $this->assertNotNull($stillThere, 'Eşleştirme yeni sürümle SİLİNMEZ.');
        $this->assertSame('v1', $stillThere->taxonomy_version);

        // Ve bayat olarak sorgulanabilir.
        $stale = $this->asTenant($tenant, fn () => CategoryMapping::query()
            ->staleFor('trendyol', 'v2')->count());

        $this->assertSame(1, $stale, 'Eski sürüme bakan eşleştirme bayat sayılır.');
    }

    // ═══════════════════════════════════════════════ öznitelik eşleştirmesi

    /**
     * Öznitelik eşleştirmesi KATEGORİ BAŞINADIR.
     *
     * Aynı "Beden" seçenek tanımı iki farklı kategoride iki farklı
     * `external_attribute_id` taşıyabilir; tekillik kategoriyi içerir.
     */
    #[Test]
    public function attribute_mapping_is_per_category(): void
    {
        [$tenant] = $this->makeTenant('A');
        [$dress, $shoe] = $this->makeTree();

        $size = $this->asTenant($tenant, fn () => OptionDefinition::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Beden',
        ]));

        $this->makeAttribute($dress, 'attr-dress-size', 'Beden');
        $this->makeAttribute($shoe, 'attr-shoe-size', 'Numara');

        $this->asTenant($tenant, function () use ($size, $dress, $shoe): void {
            app(SaveAttributeMapping::class)->run($size, $dress, 'attr-dress-size');
            app(SaveAttributeMapping::class)->run($size, $shoe, 'attr-shoe-size');
        });

        $mappings = $this->asTenant($tenant, fn () => AttributeMapping::query()
            ->orderBy('external_attribute_id')->get());

        $this->assertCount(2, $mappings,
            'Aynı seçenek tanımı kategori başına ayrı eşleşir; tekillik kategoriyi içerir.');
        $this->assertSame('attr-dress-size', $mappings[0]->external_attribute_id);
        $this->assertSame('attr-shoe-size', $mappings[1]->external_attribute_id);
    }

    /**
     * Aynı kategoride ikinci kez eşlenirse satır GÜNCELLENİR.
     */
    #[Test]
    public function remapping_an_attribute_updates_the_existing_row(): void
    {
        [$tenant] = $this->makeTenant('A');
        [$dress] = $this->makeTree();

        $size = $this->asTenant($tenant, fn () => OptionDefinition::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Beden',
        ]));

        $this->makeAttribute($dress, 'attr-a', 'Beden');
        $this->makeAttribute($dress, 'attr-b', 'Ölçü');

        $first = $this->asTenant($tenant, fn () => app(SaveAttributeMapping::class)
            ->run($size, $dress, 'attr-a'));

        $second = $this->asTenant($tenant, fn () => app(SaveAttributeMapping::class)
            ->run($size, $dress, 'attr-b'));

        $this->assertSame($first->id, $second->id);

        $raw = DB::table('attribute_mappings')->where('id', $first->id)->first();
        $this->assertSame('attr-b', $raw->external_attribute_id);
    }

    /**
     * Kategoride BULUNMAYAN bir özniteliğe eşleştirme reddedilir.
     *
     * Uydurma kimlik kanalda doğrulama hatası verir ve listing kalıcı
     * hataya düşer; hatayı kaydederken yakalamak sonra yakalamaktan ucuzdur.
     */
    #[Test]
    public function mapping_to_an_attribute_the_category_does_not_have_is_rejected(): void
    {
        [$tenant] = $this->makeTenant('A');
        [$dress] = $this->makeTree();

        $size = $this->asTenant($tenant, fn () => OptionDefinition::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Beden',
        ]));

        $this->makeAttribute($dress, 'attr-real', 'Beden');

        $this->expectException(\InvalidArgumentException::class);

        $this->asTenant($tenant, fn () => app(SaveAttributeMapping::class)
            ->run($size, $dress, 'attr-uydurma'));
    }

    // ═══════════════════════════════════════════════ değer eşleştirmesi

    /**
     * Değer eşleştirmesi ÖZNİTELİK BAŞINADIR, kategori başına DEĞİL.
     *
     * `AttributeMapping`'in tersi ve fark bilinçli: öznitelik KİMLİĞİ
     * kategoriye göre değişir, değer LİSTESİ değişmez. Kategori de anahtara
     * girseydi satıcı aynı "S → SMALL" kararını her kategori için yeniden
     * vermek zorunda kalırdı.
     */
    #[Test]
    public function value_mapping_is_per_attribute_not_per_category(): void
    {
        [$tenant] = $this->makeTenant('A');
        [$dress] = $this->makeTree();

        [$size, $small] = $this->makeOption($tenant, 'Beden', 'S');

        $this->makeAttribute($dress, 'attr-size', 'Beden', allowedValues: [
            ['id' => 'v-small', 'label' => 'SMALL'],
            ['id' => 'v-medium', 'label' => 'MEDIUM'],
        ]);

        $mapping = $this->asTenant($tenant, fn () => app(SaveAttributeValueMapping::class)->run(
            optionValue: $small,
            externalAttributeId: 'attr-size',
            externalValueId: 'v-small',
            externalValueLabel: 'SMALL',
        ));

        $this->assertSame('v-small', $mapping->external_value_id);
        $this->assertSame('SMALL', $mapping->external_value_label,
            'Etiket saklanır: satıcı kimlikten ne seçtiğini anlayamaz.');

        // Tekillikte kategori YOKTUR — anahtar (kiracı, değer, öznitelik).
        $count = $this->asTenant($tenant, fn () => AttributeValueMapping::query()->count());
        $this->assertSame(1, $count);
    }

    /**
     * İzinli değerler listesinde OLMAYAN bir değere eşleştirme reddedilir.
     */
    #[Test]
    public function mapping_to_a_value_outside_the_allowed_list_is_rejected(): void
    {
        [$tenant] = $this->makeTenant('A');
        [$dress] = $this->makeTree();

        [$size, $small] = $this->makeOption($tenant, 'Beden', 'S');

        $this->makeAttribute($dress, 'attr-size', 'Beden', allowedValues: [
            ['id' => 'v-small', 'label' => 'SMALL'],
        ]);

        $this->expectException(\InvalidArgumentException::class);

        $this->asTenant($tenant, fn () => app(SaveAttributeValueMapping::class)->run(
            optionValue: $small,
            externalAttributeId: 'attr-size',
            externalValueId: 'v-uydurma',
            externalValueLabel: 'UYDURMA',
        ));
    }

    /**
     * Serbest metin kabul eden öznitelikte (izinli değer listesi BOŞ) her
     * değer kabul edilir.
     *
     * Boş liste "hiçbir değer geçerli değil" demek DEĞİLDİR; "kanal liste
     * vermiyor, serbest metin" demektir. Aksi yorumla satıcı hiçbir zaman
     * eşleştirme yapamazdı.
     */
    #[Test]
    public function free_text_attribute_accepts_any_value(): void
    {
        [$tenant] = $this->makeTenant('A');
        [$dress] = $this->makeTree();

        [$size, $small] = $this->makeOption($tenant, 'Kumaş', 'Pamuk');

        $this->makeAttribute($dress, 'attr-fabric', 'Kumaş', allowedValues: []);

        $mapping = $this->asTenant($tenant, fn () => app(SaveAttributeValueMapping::class)->run(
            optionValue: $small,
            externalAttributeId: 'attr-fabric',
            externalValueId: 'pamuk',
            externalValueLabel: 'Pamuk',
        ));

        $this->assertSame('pamuk', $mapping->external_value_id);
    }

    // ───────────────────────────────────────────────────── yardımcılar

    /** @return array{0: Tenant, 1: User} */
    private function makeTenant(string $name): array
    {
        $user = User::factory()->create();

        $tenant = (new CreateTenant)->run(name: 'Esleme '.$name.' '.uniqid(), owner: $user);

        return [$tenant, $user];
    }

    /**
     * Kiracısız kategori ağacı: kök + iki yaprak.
     *
     * Taksonomi KİRACISIZDIR ve `asSystem` altında yazılır — üretimdeki
     * `SyncTaxonomy` de aynısını yapar.
     *
     * @return array{0: ChannelCategory, 1: ChannelCategory}
     */
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

    /**
     * @param  list<array{id: string, label: string}>  $allowedValues
     */
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
