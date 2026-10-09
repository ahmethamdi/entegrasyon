<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Billing\Actions\EnforceQuota;
use App\Domain\Billing\Enums\QuotaMetric;
use App\Domain\Billing\Exceptions\QuotaExceededException;
use App\Domain\Catalog\Actions\CreateProduct;
use App\Domain\Catalog\Actions\SetVariantCost;
use App\Domain\Catalog\Actions\UpdateProduct;
use App\Domain\Catalog\Exceptions\DuplicateSkuException;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductImage;
use App\Domain\Catalog\Models\Variant;
use App\Domain\Sync\Models\SyncOperation;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Ürün yönetimi — §13 · faz 1.2 · "Panelde ürün oluşturma, düzenleme".
 *
 * DEĞİŞMEZ KURAL — INERTIA'YA MODEL GÖNDERİLMEZ: yalnızca görünen alanlar.
 *
 * DEĞİŞMEZ KURAL — FAZLA SATIŞ BURADA DA GİZLENMEZ (§17 · P0):
 *   Ürün listesindeki toplam bakiye KIRPILMADAN gösterilir ve fazla satış
 *   işaretlenir. Uyarıyı yalnızca stok ekranına saklamak, ürüne bakan
 *   kullanıcıyı eksikten habersiz bırakır.
 *
 * STOK TOPLAMLARI TEK SORGUDA toplanır: ürün başına ilişki gezmek 200
 * satırlık listede yüzlerce sorgu demekti.
 */
final class ProductController extends Controller
{
    private const PER_PAGE = 50;

    /**
     * "Stoğu az" eşiği — satılabilir adet 1..5. Sabit: satıcı başına eşik
     * ayarı yok; istenirse kiracı ayarına taşınır.
     */
    public const LOW_STOCK = 5;

    private const FILTERS = ['all', 'low', 'out', 'problem'];

    /**
     * Ürünler + stok TEK ekran (5 Ekim panel yenilemesi, kullanıcı kararı:
     * stok ürün listesinden girilir). Eski `/inventory` buraya yönlenir.
     *
     * VARYANT GRUPLAMASI YALNIZ EKRANDADIR: model "1 ürün = 1 varyant"
     * kalır; kanalda aynı ürünün varyantı olan satırlar (aynı bağlantı +
     * aynı `external_parent_id`) `groupKey` taşır ve ekran onları tek
     * başlık altında toplar. Çekirdeğe dokunulmaz.
     */
    public function index(Request $request): InertiaResponse
    {
        $search = $request->string('search')->trim()->toString();
        $filter = in_array($request->string('filter')->toString(), self::FILTERS, true)
            ? $request->string('filter')->toString()
            : 'all';
        $page = max(1, $request->integer('page', 1));

        $total = $this->listQuery($search, $filter)->count();

        // Aynı başlık yan yana: kardeş varyantlar sayfada bitişik düşer.
        $products = $this->listQuery($search, $filter)
            ->orderBy('title')
            ->orderBy('sku')
            ->forPage($page, self::PER_PAGE)
            ->get();

        $ids = $products->pluck('id')->all();
        $stock = $this->stockFor($ids);
        $channels = $this->channelsFor($ids);
        $images = $this->imagesFor($ids);
        $groups = $this->groupKeysFor($ids);
        $counts = $this->countTargetsFor($ids, $this->defaultWarehouseId($request));

        return Inertia::render('Products/Index', [
            'rows' => $products->map(fn (Product $p): array => [
                ...$this->presentRow($p, $stock),
                'imageUrl' => $images[$p->id] ?? null,
                'channels' => $channels[$p->id] ?? [],
                'groupKey' => $groups[$p->id] ?? null,
                ...($counts[$p->id] ?? ['countVariantId' => null, 'countOnHand' => null]),
            ])->all(),
            'filters' => ['search' => $search, 'filter' => $filter],
            'tabCounts' => $this->tabCounts($search),
            'pagination' => [
                'page' => $page,
                'lastPage' => max(1, (int) ceil($total / self::PER_PAGE)),
                'total' => $total,
            ],
            'lowStock' => self::LOW_STOCK,
        ]);
    }

    public function create(): InertiaResponse
    {
        return Inertia::render('Products/Create');
    }

    public function store(Request $request, CreateProduct $createProduct): RedirectResponse
    {
        $validated = $request->validate([
            'sku' => ['required', 'string', 'max:120'],
            'title' => ['required', 'string', 'max:255'],
            'price' => ['required', 'numeric', 'min:0'],
            // Açılış stoğu NEGATİF olamaz: eksiltme satış/transfer yoluyla olur.
            'opening_stock' => ['nullable', 'integer', 'min:0'],
            'description' => ['nullable', 'string', 'max:5000'],
            'brand' => ['nullable', 'string', 'max:120'],
            'barcode' => ['nullable', 'string', 'max:120'],
            // İç kategori: kanal eşleştirmesinin çıpası (§13 · Faz 2).
            // Serbest metindir — ayrı bir iç kategori tablosu yoktur (§4).
            'internal_category_id' => ['nullable', 'string', 'max:255'],
        ]);

        // Plan kotası (§13 · Faz 4). Kota YARATMAYI engeller; var olan
        // ürünlere ve onların senkronuna DOKUNMAZ. `DuplicateSkuException`
        // ile aynı kalıpla alan hatasına çevrilir — kullanıcı 500 değil
        // ne yapması gerektiğini söyleyen bir mesaj görür.
        // Plan kotası (§13 · Faz 4). Kota YARATMAYI engeller; var olan
        // ürünlere ve onların senkronuna DOKUNMAZ. `DuplicateSkuException`
        // ile aynı kalıpla alan hatasına çevrilir — kullanıcı 500 değil
        // ne yapması gerektiğini söyleyen bir mesaj görür.
        try {
            app(EnforceQuota::class)->check(QuotaMetric::PRODUCTS);
        } catch (QuotaExceededException $e) {
            throw ValidationException::withMessages(['sku' => $e->userMessage()]);
        }

        try {
            $product = $createProduct->run(
                sku: $validated['sku'],
                title: $validated['title'],
                price: (float) $validated['price'],
                openingStock: (int) ($validated['opening_stock'] ?? 0),
                warehouseId: $this->defaultWarehouseId($request),
                description: $validated['description'] ?? null,
                brand: $validated['brand'] ?? null,
                barcode: $validated['barcode'] ?? null,
                internalCategoryId: $validated['internal_category_id'] ?? null,
            );
        } catch (DuplicateSkuException $e) {
            // Kısıt ihlalini alan hatasına çevir: kullanıcı 500 değil açıklama görür.
            throw ValidationException::withMessages(['sku' => $e->getMessage()]);
        }

        return redirect('/products')->with(
            'success',
            __(':sku eklendi.', ['sku' => $product->sku]),
        );
    }

    public function edit(string $product): InertiaResponse
    {
        // Kiracı scope'u altında aranır: başka kiracının ürünü 404.
        $model = Product::query()->with('variants')->findOrFail($product);

        $stock = $this->stockFor([$model->id]);

        return Inertia::render('Products/Edit', [
            'product' => [
                ...$this->presentRow($model, $stock),
                'description' => $model->description,
                'brand' => $model->brand,
                'status' => $model->status,
                'internalCategoryId' => $model->internal_category_id,
                'price' => $model->variants->first()?->price,
                // Alış maliyeti varyant başınadır; zarar korumasının tabanı.
                'variants' => $model->variants->sortBy('sku')->values()->map(fn (Variant $variant): array => [
                    'id' => $variant->id,
                    'sku' => $variant->sku,
                    'currency' => $variant->currency,
                    'costPrice' => $variant->cost_price === null ? null : (string) $variant->cost_price,
                ])->all(),
            ],
        ]);
    }

    /**
     * Varyantın alış maliyeti — kanala gitmez, zarar korumasının tabanıdır.
     */
    public function updateCost(Request $request, string $product, string $variant, SetVariantCost $setCost): RedirectResponse
    {
        // Kiracı scope'u: başka kiracının ürünü/varyantı 404.
        $model = Variant::query()->where('product_id', $product)->findOrFail($variant);

        $validated = $request->validate([
            'cost_price' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
        ]);

        $setCost->run($model, $validated['cost_price'] === null ? null : (string) $validated['cost_price']);

        return back()->with('success', __(':sku alış maliyeti kaydedildi.', ['sku' => $model->sku]));
    }

    public function update(
        Request $request,
        string $product,
        UpdateProduct $updateProduct,
    ): RedirectResponse {
        $model = Product::query()->findOrFail($product);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'description' => ['nullable', 'string', 'max:5000'],
            'brand' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'string', 'in:draft,active,archived'],
            'internal_category_id' => ['nullable', 'string', 'max:255'],
        ]);

        // STOĞA DOKUNULMAZ: içerik ve stok ayrı alanlar.
        $updateProduct->run(
            product: $model,
            title: $validated['title'],
            price: isset($validated['price']) ? (float) $validated['price'] : null,
            description: $validated['description'] ?? null,
            brand: $validated['brand'] ?? null,
            status: $validated['status'] ?? null,
            internalCategoryId: $validated['internal_category_id'] ?? null,
        );

        return redirect('/products')->with('success', __(':sku güncellendi.', ['sku' => $model->sku]));
    }

    // ─────────────────────────────────────────────────── liste sorguları

    /**
     * Arama + filtre. Stok filtreleri SATILABİLİR adede bakar (fazla satış
     * negatif olduğu için "stoksuz"a girer — eksik ürün gizlenmez).
     *
     * @return Builder<Product>
     */
    private function listQuery(string $search, string $filter): Builder
    {
        $available = $this->availableSql();

        return Product::query()
            ->when($search !== '', fn (Builder $q) => $q->where(
                fn (Builder $inner) => $inner
                    ->whereRaw('products.sku ILIKE ?', ['%'.$search.'%'])
                    ->orWhereRaw('products.title ILIKE ?', ['%'.$search.'%'])
                    // Varyant SKU'su da aranır: satıcı rafta gördüğü kodu yazar.
                    ->orWhereExists(fn ($sub) => $sub->selectRaw('1')
                        ->from('variants')
                        ->whereColumn('variants.product_id', 'products.id')
                        ->whereColumn('variants.tenant_id', 'products.tenant_id')
                        ->whereRaw('variants.sku ILIKE ?', ['%'.$search.'%']))
            ))
            ->when($filter === 'out', fn (Builder $q) => $q->whereRaw("{$available} <= 0"))
            ->when($filter === 'low', fn (Builder $q) => $q->whereRaw("{$available} BETWEEN 1 AND ?", [self::LOW_STOCK]))
            ->when($filter === 'problem', fn (Builder $q) => $q->whereExists(
                fn ($sub) => $this->problemListings($sub)->whereColumn('variants.product_id', 'products.id'),
            ));
    }

    /** Ürünün satılabilir toplamı — kiracı filtresi AÇIK (çapraz varyant). */
    private function availableSql(): string
    {
        return '(SELECT coalesce(sum(il.available), 0) FROM variants v
            JOIN inventory_levels il ON il.variant_id = v.id
            WHERE v.product_id = products.id AND v.tenant_id = products.tenant_id)';
    }

    /**
     * SORUNLU LİSTE — üç kaynak, biri yeter:
     *   · kanal reddetti ya da engellendi (`rejected` / `blocked`)
     *   · kalıcı senkron hatası (`error_permanent`, kullanıcı müdahalesi ister)
     *   · son işlem öldü ve SONRADAN başaran yok (`unresolvedDead`)
     * Listeden çıkarılmış satır sayılmaz: o kanala artık bir şey gitmiyor.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @return \Illuminate\Database\Query\Builder
     */
    private function problemListings($query)
    {
        $tenantId = TenantContext::idOrFail();

        return $query->selectRaw('1')
            ->from('listings')
            ->join('variants', 'variants.id', '=', 'listings.variant_id')
            ->where('listings.tenant_id', $tenantId)
            ->where('variants.tenant_id', $tenantId)
            ->where('listings.lifecycle_status', '!=', 'delisted')
            ->where(fn ($any) => $any
                ->whereIn('listings.lifecycle_status', ['rejected', 'blocked'])
                ->orWhereExists(fn ($s) => $s->selectRaw('1')
                    ->from('listing_sync_states')
                    ->whereColumn('listing_sync_states.listing_id', 'listings.id')
                    ->where('listing_sync_states.status', 'error_permanent'))
                // Gönderilemeyenler ekranıyla AYNI tanım: scope'tan alınır,
                // kopyalanmaz. Kiracı filtresi açık (global scope'suz alt sorgu).
                ->orWhereExists(SyncOperation::query()
                    ->withoutGlobalScopes()
                    ->unresolvedDead()
                    ->selectRaw('1')
                    ->whereColumn('sync_operations.entity_id', 'listings.id')
                    ->where('sync_operations.entity_type', 'listing')
                    ->where('sync_operations.tenant_id', $tenantId)
                    ->toBase()));
    }

    /**
     * Sekme sayıları — satıcı tıklamadan "3 ürün stoksuz" görür.
     *
     * @return array<string, int>
     */
    private function tabCounts(string $search): array
    {
        $counts = [];

        foreach (self::FILTERS as $filter) {
            $counts[$filter] = $this->listQuery($search, $filter)->count();
        }

        return $counts;
    }

    /**
     * Ürün × bağlantı başına kanal durumu — TEK sorgu.
     *
     * Öncelik: Sorun > Bekliyor > Satışta. Sorun bekleyenden önce gelir:
     * "bekliyor" demek satıcıyı kendiliğinden düzelecek sanmaya iter.
     * Bekliyor = kanal onayı / ilk gönderim sürüyor ya da gönderilecek
     * değişiklik var (`is_dirty`, geçici hata).
     *
     * @param  list<string>  $productIds
     * @return array<string, list<array{id: string, label: string, code: string, state: string}>>
     */
    private function channelsFor(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        $tenantId = TenantContext::idOrFail();

        $problem = DB::query()->tap(fn ($q) => $this->problemListings($q))
            ->whereColumn('listings.id', 'l.id');

        $rows = DB::table('listings as l')
            ->join('variants as v', 'v.id', '=', 'l.variant_id')
            ->join('channel_connections as c', 'c.id', '=', 'l.channel_connection_id')
            ->leftJoin('channel_types as ct', 'ct.code', '=', 'c.channel_type_code')
            ->whereIn('v.product_id', $productIds)
            ->where('l.tenant_id', $tenantId)
            ->where('v.tenant_id', $tenantId)
            ->where('l.lifecycle_status', '!=', 'delisted')
            ->groupBy('v.product_id', 'c.id', 'c.label', 'c.channel_type_code', 'ct.name')
            ->select('v.product_id', 'c.id', 'c.label', 'c.channel_type_code', 'ct.name')
            ->selectRaw('bool_or(EXISTS ('.$problem->toSql().')) AS has_problem', $problem->getBindings())
            ->selectRaw("bool_or(l.lifecycle_status IN ('pending_approval', 'draft')
                OR EXISTS (SELECT 1 FROM listing_sync_states s WHERE s.listing_id = l.id
                    AND (s.is_dirty OR s.status = 'error_transient'))) AS has_pending")
            ->orderBy('ct.name')
            ->get();

        $channels = [];

        foreach ($rows as $row) {
            $channels[$row->product_id][] = [
                'id' => $row->id,
                'label' => $row->label ?: ($row->name ?? $row->channel_type_code),
                'code' => $row->channel_type_code,
                'state' => $row->has_problem ? 'problem' : ($row->has_pending ? 'pending' : 'live'),
            ];
        }

        return $channels;
    }

    /**
     * Ürün başına İLK görsel — tek sorgu (`DISTINCT ON`). Ürünün kendi
     * görseli varyantınkinden önce: listede ürünü temsil eden o.
     *
     * @param  list<string>  $productIds
     * @return array<string, string>
     */
    private function imagesFor(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        $images = [];

        ProductImage::query()
            ->whereIn('product_id', $productIds)
            ->selectRaw('DISTINCT ON (product_id) *')
            ->orderBy('product_id')
            ->orderBy('position')
            ->orderBy('created_at')
            ->get()
            ->each(function (ProductImage $image) use (&$images): void {
                $url = $image->publicUrl();

                if ($url !== null) {
                    $images[$image->product_id] = $url;
                }
            });

        return $images;
    }

    /**
     * Kanal tarafındaki ÜST ürün — aynı anahtarı taşıyan satırlar ekranda
     * tek başlık altında toplanır. Birden çok kanalda listeliyse en küçük
     * anahtar seçilir (kararlı; her sayfa yüklemesinde aynı grup).
     *
     * @param  list<string>  $productIds
     * @return array<string, string>
     */
    private function groupKeysFor(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        $tenantId = TenantContext::idOrFail();

        return DB::table('listings as l')
            ->join('variants as v', 'v.id', '=', 'l.variant_id')
            ->whereIn('v.product_id', $productIds)
            ->where('l.tenant_id', $tenantId)
            ->where('v.tenant_id', $tenantId)
            ->whereNotNull('l.external_parent_id')
            ->where('l.lifecycle_status', '!=', 'delisted')
            ->groupBy('v.product_id')
            ->selectRaw("v.product_id, min(l.channel_connection_id::text || '|' || l.external_parent_id) AS group_key")
            ->pluck('group_key', 'product_id')
            ->all();
    }

    /**
     * YERİNDE SAYIM yalnız tek varyantlı üründe listeden yapılır (sayım
     * hedefi tek bir varyant olmalı). Değer VARSAYILAN depodaki rafta olan
     * adettir — `AdjustStock::setTo` o depoya yazar; toplamı göstermek çok
     * depolu satıcıda yanlış farkı kaydettirirdi.
     *
     * @param  list<string>  $productIds
     * @return array<string, array{countVariantId: string, countOnHand: int}>
     */
    private function countTargetsFor(array $productIds, string $warehouseId): array
    {
        if ($productIds === []) {
            return [];
        }

        $rows = DB::table('variants')
            ->leftJoin('inventory_levels', fn ($join) => $join
                ->on('inventory_levels.variant_id', '=', 'variants.id')
                ->where('inventory_levels.warehouse_id', '=', $warehouseId))
            ->whereIn('variants.product_id', $productIds)
            ->where('variants.tenant_id', TenantContext::idOrFail())
            ->groupBy('variants.product_id')
            ->havingRaw('count(DISTINCT variants.id) = 1')
            ->selectRaw('variants.product_id, min(variants.id::text) AS variant_id, coalesce(sum(inventory_levels.on_hand), 0) AS on_hand')
            ->get();

        $targets = [];

        foreach ($rows as $row) {
            $targets[$row->product_id] = [
                'countVariantId' => $row->variant_id,
                'countOnHand' => (int) $row->on_hand,
            ];
        }

        return $targets;
    }

    // ─────────────────────────────────────────────────── yardımcılar

    /**
     * Ürün başına stok toplamı — TEK sorgu.
     *
     * KIRPMA YOK: negatif toplam olduğu gibi döner ve fazla satış işaretlenir.
     *
     * `DB::table()` Eloquent global scope'una TABİ DEĞİLDİR; kiracı filtresi
     * AÇIKÇA yazılır. Yazılmazsa başka kiracının bakiyesi bu toplama karışır.
     *
     * Fiyat: tek fiyatlı üründe o fiyat; varyant fiyatları farklıysa en
     * düşük ve en yüksek ("₺100 – ₺140"). Para birimi varyanttan gelir —
     * Shopify'dan EUR gelen ürün TL diye basılmaz.
     *
     * @param  list<string>  $productIds
     * @return array<string, array{onHand: int, available: int, variants: int, oversold: int, minPrice: ?string, maxPrice: ?string, currency: ?string}>
     */
    private function stockFor(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        $rows = DB::table('variants')
            ->leftJoin('inventory_levels', 'inventory_levels.variant_id', '=', 'variants.id')
            ->whereIn('variants.product_id', $productIds)
            ->where('variants.tenant_id', TenantContext::idOrFail())
            ->groupBy('variants.product_id')
            ->selectRaw('variants.product_id')
            ->selectRaw('count(DISTINCT variants.id) AS variant_count')
            ->selectRaw('coalesce(sum(inventory_levels.on_hand), 0) AS on_hand')
            ->selectRaw('coalesce(sum(inventory_levels.available), 0) AS available')
            ->selectRaw('count(*) FILTER (WHERE inventory_levels.available < 0) AS oversold')
            ->selectRaw('min(variants.price) AS min_price, max(variants.price) AS max_price')
            ->selectRaw('min(variants.currency) AS currency')
            ->get();

        $stock = [];

        foreach ($rows as $row) {
            $stock[$row->product_id] = [
                'onHand' => (int) $row->on_hand,
                'available' => (int) $row->available,
                'variants' => (int) $row->variant_count,
                'oversold' => (int) $row->oversold,
                'minPrice' => $row->min_price,
                'maxPrice' => $row->max_price,
                'currency' => $row->currency,
            ];
        }

        return $stock;
    }

    /**
     * @param  array<string, array<string, mixed>>  $stock
     * @return array<string, mixed>
     */
    private function presentRow(Product $product, array $stock): array
    {
        $counts = $stock[$product->id] ?? [
            'onHand' => 0, 'available' => 0, 'variants' => 0, 'oversold' => 0,
            'minPrice' => null, 'maxPrice' => null, 'currency' => null,
        ];

        return [
            'id' => $product->id,
            'sku' => $product->sku,
            'title' => $product->title,
            'status' => $product->status,
            'variantCount' => $counts['variants'],
            // KIRPMA YOK — negatif toplam olduğu gibi gider.
            'totalOnHand' => $counts['onHand'],
            'available' => $counts['available'],
            // Eksik miktar açıkça söylenir: "kaç adet açıktasın".
            'shortfall' => $counts['available'] < 0 ? abs($counts['available']) : 0,
            'hasOversold' => $counts['oversold'] > 0,
            'price' => $counts['minPrice'],
            'maxPrice' => $counts['maxPrice'] !== $counts['minPrice'] ? $counts['maxPrice'] : null,
            'currency' => $counts['currency'],
            'contentVersion' => $product->content_version,
        ];
    }

    private function defaultWarehouseId(Request $request): string
    {
        $warehouse = $request->attributes->get('tenant')?->defaultWarehouse();

        abort_if($warehouse === null, 409, __('Kiracının varsayılan deposu yok.'));

        return $warehouse->id;
    }
}
