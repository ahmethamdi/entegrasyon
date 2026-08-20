<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Billing\Actions\EnforceQuota;
use App\Domain\Billing\Enums\QuotaMetric;
use App\Domain\Billing\Exceptions\QuotaExceededException;
use App\Domain\Catalog\Actions\CreateProduct;
use App\Domain\Catalog\Actions\UpdateProduct;
use App\Domain\Catalog\Exceptions\DuplicateSkuException;
use App\Domain\Catalog\Models\Product;
use App\Support\Tenancy\TenantContext;
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

    public function index(Request $request): InertiaResponse
    {
        $search = $request->string('search')->trim()->toString();

        $products = Product::query()
            ->when($search !== '', fn ($q) => $q->where(
                fn ($inner) => $inner
                    ->whereRaw('sku ILIKE ?', ['%'.$search.'%'])
                    ->orWhereRaw('title ILIKE ?', ['%'.$search.'%'])
            ))
            ->orderBy('title')
            ->limit(self::PER_PAGE)
            ->get();

        $stock = $this->stockFor($products->pluck('id')->all());

        return Inertia::render('Products/Index', [
            'rows' => $products->map(
                fn (Product $p): array => $this->presentRow($p, $stock),
            )->all(),
            'filters' => ['search' => $search],
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
            "{$product->sku} eklendi.",
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
            ],
        ]);
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

        return redirect('/products')->with('success', "{$model->sku} güncellendi.");
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
     * @param  list<string>  $productIds
     * @return array<string, array{onHand: int, variants: int, oversold: int}>
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
            ->selectRaw('count(*) FILTER (WHERE inventory_levels.available < 0) AS oversold')
            ->get();

        $stock = [];

        foreach ($rows as $row) {
            $stock[$row->product_id] = [
                'onHand' => (int) $row->on_hand,
                'variants' => (int) $row->variant_count,
                'oversold' => (int) $row->oversold,
            ];
        }

        return $stock;
    }

    /**
     * @param  array<string, array{onHand: int, variants: int, oversold: int}>  $stock
     * @return array<string, mixed>
     */
    private function presentRow(Product $product, array $stock): array
    {
        $counts = $stock[$product->id] ?? ['onHand' => 0, 'variants' => 0, 'oversold' => 0];

        return [
            'id' => $product->id,
            'sku' => $product->sku,
            'title' => $product->title,
            'status' => $product->status,
            'variantCount' => $counts['variants'],
            // KIRPMA YOK — negatif toplam olduğu gibi gider.
            'totalOnHand' => $counts['onHand'],
            'hasOversold' => $counts['oversold'] > 0,
            'contentVersion' => $product->content_version,
        ];
    }

    private function defaultWarehouseId(Request $request): string
    {
        $warehouse = $request->attributes->get('tenant')?->defaultWarehouse();

        abort_if($warehouse === null, 409, 'Kiracının varsayılan deposu yok.');

        return $warehouse->id;
    }
}
