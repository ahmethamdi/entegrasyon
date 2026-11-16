<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Catalog\Actions\SetVariantCost;
use App\Domain\Catalog\Models\Variant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Toplu maliyet girişi — kâr panelinin "eksik maliyet" yolu.
 *
 * Maliyeti boş (ya da bütün) varyantlar tek ekranda listelenir; satıcı
 * satır satır girer, tek düğmeyle kaydeder. Yazma `SetVariantCost`
 * üzerindendir (zarar koruması yeniden gönderimi dahil) — ürün düzenleme
 * ekranıyla AYNI yol. Geçmiş siparişlerin satış anı maliyeti değişmez.
 */
final class VariantCostController extends Controller
{
    private const PER_PAGE = 50;

    /** Bir kayıtta en çok satır (sayfa boyutunun üstü güvenlik payı). */
    private const MAX_ROWS = 200;

    private const FILTERS = ['missing', 'all'];

    public function index(Request $request): InertiaResponse
    {
        $filter = in_array($request->string('filter')->toString(), self::FILTERS, true) ? $request->string('filter')->toString() : 'missing';
        $search = trim($request->string('search')->toString());

        $variants = $this->query($search)
            ->when($filter === 'missing', fn (Builder $q) => $q->whereNull('variants.cost_price'))
            ->with(['product:id,title,brand', 'options.definition', 'options.value'])
            ->orderBy('product_id')
            ->orderBy('sku')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (Variant $v): array => [
                'id' => $v->id,
                'productId' => $v->product_id,
                'sku' => $v->sku,
                'title' => $v->product?->title,
                'brand' => $v->product?->brand,
                'options' => $v->options->map(fn ($o) => ($o->definition?->name ?? '').': '.($o->value?->value ?? ''))->implode(', '),
                'price' => $v->price === null ? null : (string) $v->price,
                'currency' => $v->currency,
                'cost' => $v->cost_price === null ? null : (string) $v->cost_price,
                'bundle' => $v->kind !== Variant::KIND_STANDARD,
            ]);

        return Inertia::render('Products/Costs', [
            'variants' => $variants,
            'filters' => ['filter' => $filter, 'search' => $search],
            'counts' => [
                'missing' => $this->query('')->whereNull('variants.cost_price')->count(),
                'all' => $this->query('')->count(),
            ],
        ]);
    }

    public function store(Request $request, SetVariantCost $setCost): RedirectResponse
    {
        $validated = $request->validate([
            'costs' => ['required', 'array', 'min:1', 'max:'.self::MAX_ROWS],
            'costs.*.id' => ['required', 'string'],
            'costs.*.cost' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
        ]);

        $rows = collect($validated['costs'])->keyBy('id');
        // Kiracı scope'u: başka kiracının varyant kimliği sessizce bulunmaz.
        $variants = Variant::query()->whereIn('id', $rows->keys())->get();

        $changed = DB::transaction(function () use ($variants, $rows, $setCost): int {
            $changed = 0;

            foreach ($variants as $variant) {
                $cost = $rows[$variant->id]['cost'] ?? null;

                if ($setCost->run($variant, $cost === null || $cost === '' ? null : (string) $cost)) {
                    $changed++;
                }
            }

            return $changed;
        });

        return back()->with('success', $changed === 0
            ? __('Değişiklik yok.')
            : __(':count ürünün alış maliyeti kaydedildi.', ['count' => $changed]));
    }

    /** @return Builder<Variant> */
    private function query(string $search): Builder
    {
        // Set varyantı da dahil: sipariş satırı maliyeti satırın KENDİ
        // varyantının cost_price'ından alınır (IngestChannelOrder).
        return Variant::query()
            ->when($search !== '', fn (Builder $q) => $q->where(function (Builder $q) use ($search): void {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';
                $q->where('variants.sku', 'ilike', $like)
                    ->orWhere('variants.barcode', 'ilike', $like)
                    ->orWhereHas('product', fn (Builder $p) => $p->where('title', 'ilike', $like));
            }));
    }
}
