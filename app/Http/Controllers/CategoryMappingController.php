<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Catalog\Models\OptionDefinition;
use App\Domain\Catalog\Models\OptionValue;
use App\Domain\Channels\Actions\SaveAttributeMapping;
use App\Domain\Channels\Actions\SaveAttributeValueMapping;
use App\Domain\Channels\Actions\SaveCategoryMapping;
use App\Domain\Channels\Models\AttributeMapping;
use App\Domain\Channels\Models\AttributeValueMapping;
use App\Domain\Channels\Models\CategoryMapping;
use App\Domain\Channels\Models\ChannelCategory;
use App\Domain\Channels\Models\ChannelCategoryAttribute;
use App\Domain\Channels\Models\ChannelType;
use App\Domain\Sync\Support\PrerequisiteGate;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use InvalidArgumentException;

/**
 * Kategori ve öznitelik eşleştirme ekranı — §13 · Faz 2 · 28 sa.
 *
 * Katalog aktarımının ÖN KOŞULU: §14'ün `PrerequisiteGate`'i bu ekranda
 * verilen kararları okur. Eksik eşleştirmede listing `blocked` düşer ve
 * STOK AKIŞI ETKİLENMEZ.
 *
 * DEĞİŞMEZ KURAL — EŞLEŞTİRME KİRACIYA AİTTİR, TAKSONOMİ DEĞİL:
 *   Kategori ağacı (`channel_categories`) kiracısız okunur ve tüm
 *   satıcılar aynı satırları görür. Eşleştirme kiracı scope'u altında
 *   yazılır ve okunur.
 *
 * DEĞİŞMEZ KURAL — YALNIZCA GÜNCEL SÜRÜMÜN YAPRAKLARI SEÇENEKTİR:
 *   Ara kategoriye ürün açılamaz; eski sürümden seçmek ise satıcıyı
 *   doğduğu anda bayat olan bir eşleştirmeye sürüklerdi. Var olan bayat
 *   eşleştirme SİLİNMEZ — yalnızca işaretlenir.
 *
 * DEĞİŞMEZ KURAL — INERTIA'YA MODEL GÖNDERİLMEZ: yalnızca görünen alanlar.
 *
 * DEĞİŞMEZ KURAL — ROTA MODEL BAĞLAMASI KULLANILMAZ: kimlik `string`
 *   alınır ve kiracı scope'u altında aranır (`SubstituteBindings` `tenant`
 *   ara katmanından ÖNCE çalışır).
 *
 * İÇ KATEGORİ TABLOSU YOKTUR (§4): `products.internal_category_id` serbest
 * metindir ve satıcının gerçekte kullandığı DISTINCT değerler tek doğru
 * kaynaktır. Ayrı bir tablo tutmak, ürüne yazılan değerle listenin
 * ayrışmasına yol açardı.
 */
final class CategoryMappingController extends Controller
{
    public function __construct(
        // Eksik zorunlu öznitelik hesabı ÖN KOŞUL KAPISINDAN gelir:
        // ekranın gösterdiği "hazır" ile gönderimin uyguladığı kural TEK
        // kaynaktan okunur. İkisi ayrı yazılsaydı biri değiştiğinde panel
        // ile davranış ayrışırdı.
        private readonly PrerequisiteGate $gate,
    ) {}

    public function index(Request $request): InertiaResponse
    {
        $channelTypeCode = $this->selectedChannelType($request);

        // Taksonomi KİRACISIZ okunur — ağaç kanalın gerçeğidir.
        $version = $this->currentTaxonomyVersion($channelTypeCode);

        $leaves = $version === null
            ? new Collection
            : $this->leavesFor($channelTypeCode, $version);

        $mappings = CategoryMapping::query()
            ->where('channel_type_code', $channelTypeCode)
            ->get()
            ->keyBy('internal_category_id');

        // Yaprak kimliğinden yola: eşleşen kategorinin yolu, satır başına
        // ilişki gezmeden gösterilir.
        $categoriesById = $this->categoriesById($mappings->pluck('channel_category_id')->all(), $leaves);

        $requiredAttributes = $this->requiredAttributesFor($categoriesById->keys()->all());
        $mappedAttributes = $this->mappedAttributesFor($categoriesById->keys()->all());

        return Inertia::render('Mappings/Index', [
            'channelTypes' => $this->taxonomyChannelTypes(),
            'selectedChannelType' => $channelTypeCode,
            'taxonomyVersion' => $version,
            'channelCategories' => $leaves->map(fn (ChannelCategory $c): array => [
                'id' => $c->id,
                'externalId' => $c->external_id,
                'name' => $c->name,
                // Kullanıcı kategoriyi ancak BAĞLAMIYLA tanır: iki farklı
                // ağaç dalında aynı adlı kategori olabilir.
                'path' => $c->path ?? $c->name,
            ])->values()->all(),
            'internalCategories' => $this->internalCategories(
                $mappings,
                $categoriesById,
                $requiredAttributes,
                $mappedAttributes,
                $version,
            ),
            'optionDefinitions' => $this->optionDefinitions(),
        ]);
    }

    /** Kategori eşleştirmesini kaydeder. */
    public function storeCategory(Request $request, SaveCategoryMapping $save): RedirectResponse
    {
        $validated = $request->validate([
            'internal_category_id' => ['required', 'string', 'max:255'],
            'channel_category_id' => ['required', 'string'],
        ]);

        // Kategori KİRACISIZ okunur; kimlik istekten gelir ama ağaç
        // paylaşılan bir gerçektir ve kapsanmaz.
        $category = ChannelCategory::query()->find($validated['channel_category_id']);

        if ($category === null) {
            throw ValidationException::withMessages([
                'channel_category_id' => 'Kategori bulunamadı.',
            ]);
        }

        try {
            $save->run(
                internalCategoryId: $validated['internal_category_id'],
                channelCategory: $category,
            );
        } catch (InvalidArgumentException $e) {
            // Ara kategori isteği: kullanıcıya 500 değil açıklama göster.
            throw ValidationException::withMessages([
                'channel_category_id' => $e->getMessage(),
            ]);
        }

        return redirect()->back()->with(
            'success',
            sprintf('"%s" → %s eşleştirildi.', $validated['internal_category_id'], $category->path ?? $category->name),
        );
    }

    /** Öznitelik eşleştirmesini kaydeder. */
    public function storeAttribute(Request $request, SaveAttributeMapping $save): RedirectResponse
    {
        $validated = $request->validate([
            'option_definition_id' => ['required', 'string'],
            'channel_category_id' => ['required', 'string'],
            'external_attribute_id' => ['required', 'string', 'max:255'],
        ]);

        // Seçenek tanımı KİRACIYA aittir ve scope altında aranır: form
        // kurcalayan biri aksi halde başka kiracının seçeneğini kendi
        // eşleştirmesine bağlardı.
        $definition = OptionDefinition::query()->find($validated['option_definition_id']);

        if ($definition === null) {
            throw ValidationException::withMessages([
                'option_definition_id' => 'Seçenek tanımı bulunamadı.',
            ]);
        }

        $category = ChannelCategory::query()->find($validated['channel_category_id']);

        if ($category === null) {
            throw ValidationException::withMessages([
                'channel_category_id' => 'Kategori bulunamadı.',
            ]);
        }

        try {
            $save->run($definition, $category, $validated['external_attribute_id']);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages([
                'external_attribute_id' => $e->getMessage(),
            ]);
        }

        return redirect()->back()->with('success', "{$definition->name} özniteliği eşleştirildi.");
    }

    /** Değer eşleştirmesini kaydeder. */
    public function storeAttributeValue(Request $request, SaveAttributeValueMapping $save): RedirectResponse
    {
        $validated = $request->validate([
            'option_value_id' => ['required', 'string'],
            'external_attribute_id' => ['required', 'string', 'max:255'],
            'external_value_id' => ['required', 'string', 'max:255'],
            'external_value_label' => ['nullable', 'string', 'max:255'],
        ]);

        // Seçenek değeri KİRACIYA aittir ve scope altında aranır.
        $value = OptionValue::query()->find($validated['option_value_id']);

        if ($value === null) {
            throw ValidationException::withMessages([
                'option_value_id' => 'Seçenek değeri bulunamadı.',
            ]);
        }

        try {
            $save->run(
                optionValue: $value,
                externalAttributeId: $validated['external_attribute_id'],
                externalValueId: $validated['external_value_id'],
                externalValueLabel: $validated['external_value_label'] ?? null,
            );
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages([
                'external_value_id' => $e->getMessage(),
            ]);
        }

        return redirect()->back()->with('success', "{$value->value} değeri eşleştirildi.");
    }

    // ─────────────────────────────────────────────────── yardımcılar

    /**
     * Taksonomi taşıyan kanal türleri.
     *
     * `channel_categories` üzerinden türetilir, kanal koduna bakılmaz:
     * hangi kanalın taksonomisi çekilmişse o listelenir ve yeni kanal
     * eklendiğinde bu dosya DEĞİŞMEZ.
     *
     * @return list<array{code: string, name: string}>
     */
    private function taxonomyChannelTypes(): array
    {
        $codes = ChannelCategory::query()
            ->distinct()
            ->orderBy('channel_type_code')
            ->pluck('channel_type_code')
            ->all();

        if ($codes === []) {
            return [];
        }

        return ChannelType::query()
            ->whereIn('code', $codes)
            ->orderBy('name')
            ->get(['code', 'name'])
            ->map(fn (ChannelType $t): array => ['code' => $t->code, 'name' => $t->name])
            ->all();
    }

    private function selectedChannelType(Request $request): string
    {
        $requested = $request->string('channel_type')->trim()->toString();

        if ($requested !== '') {
            return $requested;
        }

        return $this->taxonomyChannelTypes()[0]['code'] ?? '';
    }

    /**
     * Kanalın EN GÜNCEL taksonomi sürümü.
     *
     * Sürümler içerikten türer ve sıralanabilir bir zaman bilgisi
     * taşımaz; bu yüzden "en güncel" olan, en son YAZILAN satırın
     * sürümüdür. `created_at` üzerinden okunur.
     */
    private function currentTaxonomyVersion(string $channelTypeCode): ?string
    {
        $row = ChannelCategory::query()
            ->where('channel_type_code', $channelTypeCode)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first(['taxonomy_version']);

        return $row?->taxonomy_version;
    }

    /**
     * Güncel sürümün YAPRAKLARI — ara kategori seçenek olarak sunulmaz.
     *
     * @return Collection<int, ChannelCategory>
     */
    private function leavesFor(string $channelTypeCode, string $version): Collection
    {
        return ChannelCategory::query()
            ->forVersion($channelTypeCode, $version)
            ->leaves()
            ->orderBy('path')
            ->get();
    }

    /**
     * Eşleşmiş kategoriler kimliğe göre — güncel yapraklar dahil.
     *
     * Bayat eşleştirmeler ESKİ sürümün satırına bakar ve o satır güncel
     * yaprak listesinde yoktur; ayrıca çekilir ki panel eşleşmenin YOLUNU
     * gösterebilsin. Gösteremeseydi satıcı neyi yeniden doğrulayacağını
     * bilemezdi.
     *
     * @param  list<string>  $mappedIds
     * @param  Collection<int, ChannelCategory>  $leaves
     * @return Collection<string, ChannelCategory>
     */
    private function categoriesById(array $mappedIds, Collection $leaves): Collection
    {
        $byId = $leaves->keyBy('id');

        $missing = array_values(array_filter(
            $mappedIds,
            fn (string $id): bool => ! $byId->has($id),
        ));

        if ($missing !== []) {
            foreach (ChannelCategory::query()->whereIn('id', $missing)->get() as $category) {
                $byId->put($category->id, $category);
            }
        }

        return $byId;
    }

    /**
     * Kategori başına ZORUNLU öznitelikler.
     *
     * Ön koşul kapısının (§14) panel karşılığı: satıcı ürünü göndermeden
     * ÖNCE neyin eksik olduğunu görmelidir.
     *
     * @param  list<string>  $categoryIds
     * @return array<string, list<array{externalId: string, name: string}>>
     */
    private function requiredAttributesFor(array $categoryIds): array
    {
        if ($categoryIds === []) {
            return [];
        }

        $rows = ChannelCategoryAttribute::query()
            ->whereIn('channel_category_id', $categoryIds)
            ->where('is_required', true)
            ->orderBy('name')
            ->get();

        $byCategory = [];

        foreach ($rows as $row) {
            $byCategory[$row->channel_category_id][] = [
                'externalId' => $row->external_attribute_id,
                'name' => $row->name,
            ];
        }

        return $byCategory;
    }

    /**
     * Kategori başına EŞLEŞTİRİLMİŞ öznitelikler — kiracı scope'unda.
     *
     * Kanal özniteliği → İÇ seçenek tanımı yönünde döner: panel açılır
     * listede hangi seçeneğin seçili olduğunu ancak bu yönle bilebilir.
     * Yalnızca kimlik listesi dönseydi "eşleşti" rozeti gösterilir ama
     * kutu boş görünür ve kullanıcı eşleştirmeyi yapılmamış sanardı.
     *
     * @param  list<string>  $categoryIds
     * @return array<string, array<string, string>>
     */
    private function mappedAttributesFor(array $categoryIds): array
    {
        if ($categoryIds === []) {
            return [];
        }

        $rows = AttributeMapping::query()
            ->whereIn('channel_category_id', $categoryIds)
            ->get(['channel_category_id', 'external_attribute_id', 'option_definition_id']);

        $byCategory = [];

        foreach ($rows as $row) {
            $byCategory[$row->channel_category_id][$row->external_attribute_id] = $row->option_definition_id;
        }

        return $byCategory;
    }

    /**
     * Kiracının kullandığı iç kategoriler ve eşleştirme durumları.
     *
     * `DB::table()` GLOBAL SCOPE'A TABİ DEĞİLDİR — kiracı filtresi AÇIKÇA
     * yazılır. Yazılmazsa başka kiracının ürünleri bu sayıma karışır; bu
     * boşluk projede dört ayrı turda çıktı ve her seferinde testle
     * kapatıldı.
     *
     * @param  Collection<string, CategoryMapping>  $mappings
     * @param  Collection<string, ChannelCategory>  $categoriesById
     * @param  array<string, list<array{externalId: string, name: string}>>  $requiredAttributes
     * @param  array<string, array<string, string>>  $mappedAttributes
     * @return list<array<string, mixed>>
     */
    private function internalCategories(
        Collection $mappings,
        Collection $categoriesById,
        array $requiredAttributes,
        array $mappedAttributes,
        ?string $currentVersion,
    ): array {
        $rows = DB::table('products')
            ->where('tenant_id', TenantContext::idOrFail())
            ->whereNotNull('internal_category_id')
            ->groupBy('internal_category_id')
            ->orderBy('internal_category_id')
            ->selectRaw('internal_category_id')
            ->selectRaw('count(*) AS product_count')
            ->get();

        $out = [];

        foreach ($rows as $row) {
            $mapping = $mappings->get($row->internal_category_id);

            $out[] = [
                'id' => $row->internal_category_id,
                'productCount' => (int) $row->product_count,
                'mapping' => $mapping === null
                    ? null
                    : $this->presentMapping($mapping, $categoriesById, $requiredAttributes, $mappedAttributes, $currentVersion),
            ];
        }

        return $out;
    }

    /**
     * @param  Collection<string, ChannelCategory>  $categoriesById
     * @param  array<string, list<array{externalId: string, name: string}>>  $requiredAttributes
     * @param  array<string, array<string, string>>  $mappedAttributes
     * @return array<string, mixed>
     */
    private function presentMapping(
        CategoryMapping $mapping,
        Collection $categoriesById,
        array $requiredAttributes,
        array $mappedAttributes,
        ?string $currentVersion,
    ): array {
        $category = $categoriesById->get($mapping->channel_category_id);

        $required = $requiredAttributes[$mapping->channel_category_id] ?? [];

        // Kanal özniteliği → iç seçenek tanımı haritası.
        $mapped = $mappedAttributes[$mapping->channel_category_id] ?? [];

        // EKSİK HESABI ÖN KOŞUL KAPISINDAN GELİR, BURADA YENİDEN
        // YAZILMAZ. İki ayrı yerde hesaplansaydı biri değiştiğinde panel
        // "hazır" derken kapı "eksik" der ve satıcı neyi düzelteceğini
        // bilemezdi — ekranın vaadi ile gönderimin davranışı ayrışırdı.
        // Eksikler ADIYLA gösterilir: sayı tek başına ne yapacağını söylemez.
        $missing = $this->gate->missingRequiredAttributes($mapping->channel_category_id);

        // BAYAT: eşleştirme eski sürümün satırına bakıyor. Satır SİLİNMEZ,
        // yalnızca işaretlenir — satıcının emeği yok olmaz.
        $stale = $currentVersion !== null && $mapping->taxonomy_version !== $currentVersion;

        return [
            'id' => $mapping->id,
            'channelCategoryId' => $mapping->channel_category_id,
            'categoryPath' => $category?->path ?? $category?->name,
            'taxonomyVersion' => $mapping->taxonomy_version,
            'stale' => $stale,
            'mappedBy' => $mapping->mapped_by,
            'verified' => $mapping->verified_at !== null,
            'requiredAttributeCount' => count($required),
            'mappedRequiredCount' => count($required) - count($missing),
            'missingRequiredAttributes' => $missing,
            'requiredAttributes' => $required,
            'mappedAttributes' => $mapped,
            // HAZIR: kategori eşleşmiş VE tüm zorunlu öznitelikler
            // eşleşmiş. Bayatlık hazırlığı düşürmez — eşleştirme hâlâ
            // geçerlidir, yalnızca yeniden doğrulanması istenir.
            'ready' => $missing === [],
        ];
    }

    /**
     * Kiracının seçenek tanımları ve değerleri — öznitelik eşleştirmesinin girdisi.
     *
     * @return list<array<string, mixed>>
     */
    private function optionDefinitions(): array
    {
        $definitions = OptionDefinition::query()
            ->with(['values' => fn ($q) => $q->orderBy('position')->orderBy('value')])
            ->orderBy('position')
            ->orderBy('name')
            ->get();

        // Değer eşleştirmeleri TEK sorguda toplanır: değer başına ayrı
        // sorgu, birkaç yüz seçenekli katalogda ekranı yüzlerce sorguya
        // böler.
        $valueMappings = $this->valueMappingsByValueId(
            $definitions->flatMap(fn (OptionDefinition $d): array => $d->values->pluck('id')->all())->all(),
        );

        return $definitions->map(fn (OptionDefinition $d): array => [
            'id' => $d->id,
            'name' => $d->name,
            'values' => $d->values->map(fn (OptionValue $v): array => [
                'id' => $v->id,
                'value' => $v->value,
                'mappings' => $valueMappings[$v->id] ?? [],
            ])->all(),
        ])->all();
    }

    /**
     * Seçenek değeri başına kanal eşleştirmeleri — TEK sorgu.
     *
     * Değer eşleştirmesi ÖZNİTELİK başınadır: aynı "S" değeri farklı
     * öznitelikler altında ayrı satırlar taşıyabilir, bu yüzden değer
     * başına LİSTE döner.
     *
     * @param  list<string>  $valueIds
     * @return array<string, list<array<string, mixed>>>
     */
    private function valueMappingsByValueId(array $valueIds): array
    {
        if ($valueIds === []) {
            return [];
        }

        $byValue = [];

        foreach (AttributeValueMapping::query()->whereIn('option_value_id', $valueIds)->get() as $m) {
            $byValue[$m->option_value_id][] = [
                'externalAttributeId' => $m->external_attribute_id,
                'externalValueId' => $m->external_value_id,
                'externalValueLabel' => $m->external_value_label,
            ];
        }

        return $byValue;
    }
}
