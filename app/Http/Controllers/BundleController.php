<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Catalog\Actions\SaveBundle;
use App\Domain\Catalog\Exceptions\DuplicateSkuException;
use App\Domain\Catalog\Exceptions\InvalidBundle;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Variant;
use App\Domain\Inventory\Support\SellableStock;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Set / paket ürün ekranları — oluştur, bileşenleri değiştir, bileşen ara.
 *
 * İş kuralları `SaveBundle`'dadır (set içinde set yok, adet sınırı, tanım
 * sürümü); burası yalnız biçim doğrulaması ve ekran.
 */
final class BundleController extends Controller
{
    public function create(): InertiaResponse
    {
        return Inertia::render('Products/BundleCreate', [
            'maxComponents' => SaveBundle::MAX_COMPONENTS,
        ]);
    }

    public function store(Request $request, SaveBundle $save): RedirectResponse
    {
        $validated = $request->validate([
            'sku' => ['required', 'string', 'max:191'],
            'title' => ['required', 'string', 'max:500'],
            'price' => ['required', 'numeric', 'gt:0', 'max:9999999'],
            'description' => ['nullable', 'string', 'max:20000'],
            'components' => ['required', 'array', 'min:1', 'max:'.SaveBundle::MAX_COMPONENTS],
            'components.*.variant_id' => ['required', 'uuid'],
            'components.*.quantity' => ['required', 'integer', 'min:1', 'max:'.SaveBundle::MAX_QUANTITY],
        ]);

        try {
            $product = $save->create(
                sku: trim($validated['sku']),
                title: trim($validated['title']),
                price: (float) $validated['price'],
                components: $validated['components'],
                description: $validated['description'] ?? null,
            );
        } catch (DuplicateSkuException $e) {
            throw ValidationException::withMessages(['sku' => $e->getMessage()]);
        } catch (InvalidBundle $e) {
            throw ValidationException::withMessages(['components' => $e->getMessage()]);
        }

        return redirect()->route('products.edit', $product->id)->with('success', __('Set oluşturuldu. Stoğu bileşenlerinden hesaplanır.'));
    }

    public function update(Request $request, string $product, SaveBundle $save): RedirectResponse
    {
        $validated = $request->validate([
            'components' => ['required', 'array', 'min:1', 'max:'.SaveBundle::MAX_COMPONENTS],
            'components.*.variant_id' => ['required', 'uuid'],
            'components.*.quantity' => ['required', 'integer', 'min:1', 'max:'.SaveBundle::MAX_QUANTITY],
        ]);

        $variant = Variant::query()
            ->where('product_id', Product::query()->findOrFail($product)->id)
            ->where('kind', Variant::KIND_BUNDLE)
            ->firstOrFail();

        try {
            $save->update($variant, $validated['components']);
        } catch (InvalidBundle $e) {
            throw ValidationException::withMessages(['components' => $e->getMessage()]);
        }

        return back()->with('success', __('Setin içeriği kaydedildi.'));
    }

    /**
     * Bileşen arama — yalnız STANDART varyantlar (set içinde set yok).
     *
     * `DB::table()` global scope'a tabi değil; kiracı filtresi AÇIK.
     */
    public function variants(Request $request): JsonResponse
    {
        $term = trim((string) $request->query('q', ''));
        $tenantId = TenantContext::idOrFail();

        $query = DB::table('variants')
            ->join('products', 'products.id', '=', 'variants.product_id')
            ->where('variants.tenant_id', $tenantId)
            ->where('variants.kind', Variant::KIND_STANDARD)
            ->orderBy('products.title')
            ->orderBy('variants.sku')
            ->limit(20)
            ->select(['variants.id', 'variants.sku', 'products.title']);

        if ($term !== '') {
            $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $term).'%';
            $query->where(fn ($q) => $q->where('variants.sku', 'ilike', $like)
                ->orWhere('variants.barcode', 'ilike', $like)
                ->orWhere('products.title', 'ilike', $like));
        }

        $rows = $query->get();
        $stock = SellableStock::forVariants($tenantId, $rows->pluck('id')->map(static fn (mixed $id): string => (string) $id)->all());

        return response()->json($rows->map(static fn (object $row): array => [
            'id' => (string) $row->id,
            'sku' => (string) $row->sku,
            'title' => (string) $row->title,
            'available' => $stock[(string) $row->id]->available ?? 0,
        ])->values()->all());
    }
}
