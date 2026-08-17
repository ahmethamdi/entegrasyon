<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Catalog\Models\Variant;
use App\Domain\Inventory\Actions\AdjustStock;
use App\Domain\Inventory\Models\InventoryLevel;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Ürün/stok listesi — satıcının her gün bakacağı ekran.
 *
 * Mimari Karar Dokümanı v2.2 · §13 · faz 1.2 ve 1.5 panel maddeleri,
 * §17 · P0 · "Fazla satış ekranı".
 *
 * DEĞİŞMEZ KURAL — FAZLA SATIŞ GİZLENMEZ:
 *   Negatif `available` KIRPILMADAN gönderilir ve eksik miktar açıkça
 *   söylenir. Kırpma yalnızca kanala giden yükte meşrudur
 *   (`OutboundQuantity::forChannel()`); panelde gizlemek satıcıyı eksikten
 *   habersiz bırakır ve stoğunun neden tutmadığını anlamaz.
 *
 * DEĞİŞMEZ KURAL — SENKRON ROZETİ TÜRETİLMİŞ ALANDAN OKUNUR:
 *   `is_dirty` veritabanı tarafından üretilir (`desired > synced`). Ayrı bir
 *   sayaç tutulmadığı için rozet tanım gereği tutarlıdır.
 *
 * DEĞİŞMEZ KURAL — INERTIA'YA MODEL GÖNDERİLMEZ: yalnızca görünen alanlar.
 *
 * ROZET SAYILARI TEK SORGUDA toplanır. Varyant başına ilişki gezmek 200
 * satırlık bir listede 400 sorgu demekti; bu ekran her gün açılıyor.
 */
final class InventoryController extends Controller
{
    private const PER_PAGE = 50;

    public function index(Request $request): InertiaResponse
    {
        $filter = $request->string('filter')->toString();
        $search = $request->string('search')->trim()->toString();

        $levels = $this->levelQuery($filter, $search)
            ->orderBy('available')          // Fazla satış önce: eylem gereken satır üstte.
            ->orderBy('variants.sku')
            ->limit(self::PER_PAGE)
            ->get();

        $syncByVariant = $this->syncCountsFor($levels->pluck('variant_id')->all());

        return Inertia::render('Inventory/Index', [
            'rows' => $levels->map(
                fn (InventoryLevel $level): array => $this->presentRow($level, $syncByVariant),
            )->all(),
            'summary' => $this->summary(),
            'filters' => [
                'filter' => $filter === 'oversold' ? 'oversold' : 'all',
                'search' => $search,
            ],
        ]);
    }

    /**
     * Elle stok düzeltme — fazla satışın düzeltme yolu.
     *
     * Düzeltme LEDGER üzerinden geçer: `AdjustStock` bir MANUAL_ADJUSTMENT
     * hareketi yazar ve projeksiyon ondan türer.
     */
    public function adjust(Request $request, AdjustStock $adjustStock): RedirectResponse
    {
        $validated = $request->validate([
            'variant_id' => ['required', 'uuid'],
            // Yön hareket türünden gelir; düzeltme EKLER, eksiltmez.
            'quantity' => ['required', 'integer', 'min:1'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        // Kiracı scope'u altında aranır: başka kiracının varyantı 404.
        $variant = Variant::query()->findOrFail($validated['variant_id']);

        $warehouseId = $this->defaultWarehouseId($request);

        $adjustStock->run(
            warehouseId: $warehouseId,
            variantId: $variant->id,
            quantity: $validated['quantity'],
            note: $validated['note'] ?? null,
            actorId: $request->user()?->id,
        );

        return back()->with(
            'success',
            "{$variant->sku} için {$validated['quantity']} adet düzeltme kaydedildi.",
        );
    }

    // ─────────────────────────────────────────────────── sorgular

    /**
     * Bakiye satırları — varyantla birlikte.
     *
     * @return Builder<InventoryLevel>
     */
    private function levelQuery(string $filter, string $search): Builder
    {
        $query = InventoryLevel::query()
            ->join('variants', 'variants.id', '=', 'inventory_levels.variant_id')
            ->with(['variant:id,sku,product_id,price,currency', 'warehouse:id,name'])
            ->select('inventory_levels.*');

        if ($filter === 'oversold') {
            $query->where('inventory_levels.available', '<', 0);
        }

        if ($search !== '') {
            $query->whereRaw('variants.sku ILIKE ?', ['%'.$search.'%']);
        }

        return $query;
    }

    /**
     * Varyant başına senkron sayıları — TEK sorgu.
     *
     * `is_dirty` üretilmiş kolondur; burada yalnızca sayılır. `error_permanent`
     * ve `error_transient` ayrı sayılır: ilki kullanıcı müdahalesi bekler.
     *
     * @param  list<string>  $variantIds
     * @return array<string, array{total: int, dirty: int, errors: int, transient: int}>
     */
    private function syncCountsFor(array $variantIds): array
    {
        if ($variantIds === []) {
            return [];
        }

        $rows = DB::table('listings')
            ->join('listing_sync_states', function ($join): void {
                $join->on('listing_sync_states.listing_id', '=', 'listings.id')
                    ->where('listing_sync_states.domain', '=', 'INVENTORY');
            })
            ->whereIn('listings.variant_id', $variantIds)
            ->where('listings.lifecycle_status', 'live')
            // Kiracı filtresi AÇIKÇA yazılır: DB::table global scope'a tabi
            // değildir ve bu sorgu doğrudan tablo üzerinden gider.
            ->where('listings.tenant_id', TenantContext::idOrFail())
            ->groupBy('listings.variant_id')
            ->selectRaw('listings.variant_id')
            ->selectRaw('count(*) AS total')
            ->selectRaw('count(*) FILTER (WHERE listing_sync_states.is_dirty) AS dirty')
            ->selectRaw("count(*) FILTER (WHERE listing_sync_states.status = 'error_permanent') AS permanent")
            ->selectRaw("count(*) FILTER (WHERE listing_sync_states.status = 'error_transient') AS transient")
            ->get();

        $counts = [];

        foreach ($rows as $row) {
            $counts[$row->variant_id] = [
                'total' => (int) $row->total,
                'dirty' => (int) $row->dirty,
                'errors' => (int) $row->permanent,
                'transient' => (int) $row->transient,
            ];
        }

        return $counts;
    }

    /**
     * Üst özet — satıcı önce fazla satışı görmeli.
     *
     * Fazla satış miktarı `SUM(-available)` üzerinden hesaplanır; §10'daki
     * metrik tanımıyla aynı. Ayrı sayaç YOKTUR.
     *
     * @return array<string, int>
     */
    private function summary(): array
    {
        $row = InventoryLevel::query()
            ->selectRaw('count(*) AS variant_count')
            ->selectRaw('count(*) FILTER (WHERE available < 0) AS oversold_count')
            ->selectRaw('coalesce(sum(-available) FILTER (WHERE available < 0), 0) AS total_shortfall')
            ->first();

        return [
            'variantCount' => (int) ($row?->variant_count ?? 0),
            'oversoldCount' => (int) ($row?->oversold_count ?? 0),
            'totalShortfall' => (int) ($row?->total_shortfall ?? 0),
        ];
    }

    // ─────────────────────────────────────────────────── sunum

    /**
     * @param  array<string, array{total: int, dirty: int, errors: int, transient: int}>  $syncByVariant
     * @return array<string, mixed>
     */
    private function presentRow(InventoryLevel $level, array $syncByVariant): array
    {
        $sync = $syncByVariant[$level->variant_id] ?? [
            'total' => 0, 'dirty' => 0, 'errors' => 0, 'transient' => 0,
        ];

        return [
            'levelId' => $level->id,
            'variantId' => $level->variant_id,
            'sku' => $level->variant?->sku,
            'price' => $level->variant?->price,
            'currency' => $level->variant?->currency,
            'warehouse' => $level->warehouse?->name,

            // KIRPMA YOK: negatif değer olduğu gibi gider.
            'onHand' => $level->on_hand,
            'reserved' => $level->reserved,
            'available' => $level->available,

            'isOversold' => $level->isOversold(),
            // Eksik miktar açıkça söylenir: "kaç adet açıktasın".
            'shortfall' => $level->isOversold() ? abs($level->available) : 0,

            'sync' => [...$sync, 'badge' => $this->badgeFor($sync)],
        ];
    }

    /**
     * Rozet — öncelik sırası önemlidir.
     *
     * KALICI HATA BEKLEYENDEN ÖNCE GELİR: `error_permanent` kullanıcı
     * müdahalesi bekler ve "bekliyor" demek satıcıyı kendiliğinden
     * düzelecek sanmaya iter; o satıra hiç bakmaz.
     *
     * @param  array{total: int, dirty: int, errors: int, transient: int}  $sync
     */
    private function badgeFor(array $sync): string
    {
        if ($sync['total'] === 0) {
            return 'unlisted';
        }

        if ($sync['errors'] > 0) {
            return 'error';
        }

        if ($sync['transient'] > 0) {
            return 'retrying';
        }

        if ($sync['dirty'] > 0) {
            return 'pending';
        }

        return 'synced';
    }

    /**
     * Kiracının varsayılan deposu.
     *
     * "En az bir varsayılan" DB kısıtıyla zorlanmaz; `CreateTenant` garanti
     * eder. Yoksa bu bir veri bütünlüğü hatasıdır ve sessizce geçilmemeli.
     */
    private function defaultWarehouseId(Request $request): string
    {
        $tenant = $request->attributes->get('tenant');

        $warehouse = $tenant?->defaultWarehouse();

        abort_if($warehouse === null, 409, 'Kiracının varsayılan deposu yok.');

        return $warehouse->id;
    }
}
