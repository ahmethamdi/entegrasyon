<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\OrderEvent;
use App\Domain\Orders\Models\OrderLine;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Sipariş listesi — kullanıcının siparişi göreceği tek yer.
 *
 * Mimari Karar Dokümanı v2.2 · §13 · faz 1.6 · "Panelde sipariş listesi ve
 * fazla satış uyarısı", §17 · P0 · "Fazla satış ekranı".
 *
 * DEĞİŞMEZ KURAL — FAZLA SATIŞ GİZLENMEZ:
 *   OVERSOLD satırlar UYARIYLA listelenir (§4 · order_lines). Sipariş alımı
 *   fazla satışı bilinçli olarak KABUL EDER ve bakiyeyi negatife düşürür;
 *   satıcının bunu göreceği başka yer yoktur. Gizlemek, gönderilemeyecek bir
 *   siparişin kabul edildiğini satıcıdan saklamak demektir.
 *
 * DEĞİŞMEZ KURAL — EŞLEŞMEMİŞ SKU AYRI UYARIDIR:
 *   `variant_id` NULL olan satırın stoğu HİÇ düşülmez ve satır PENDING kalır.
 *   Bu fazla satıştan FARKLI bir sorundur: orada stok düşmüş ve eksik
 *   görünür, burada stoğa hiç dokunulmamıştır ve tablo "her şey yolunda" der.
 *
 * DEĞİŞMEZ KURAL — INERTIA'YA MODEL GÖNDERİLMEZ:
 *   Sipariş `customer_ref` taşır (§11 · maskelenmiş kişisel veri) ve bağlantı
 *   ilişkisi kimlik bilgisine açılır. Yalnızca görünen alanlar gider.
 *
 * SATIR SAYILARI TEK SORGUDA toplanır. Sipariş başına ilişki gezmek 50
 * satırlık bir listede 100 sorgu demekti; bu ekran her gün açılıyor.
 */
final class OrderController extends Controller
{
    private const PER_PAGE = 50;

    public function index(Request $request): InertiaResponse
    {
        $filter = $this->normalizeFilter($request->string('filter')->toString());
        $search = $request->string('search')->trim()->toString();

        $orders = $this->orderQuery($filter, $search)
            // Kanal adı satır başına gösterilir; eager-load olmadan 50
            // satırlık listede 50 ek sorgu olurdu (lazy loading kapalı,
            // istisna fırlatır — bu ekran her gün açılıyor).
            ->with('connection:id,channel_type_code,label')
            // En yeni üstte: satıcı önce bugünün siparişine bakar.
            // `placed_at` NULL olabilir (kanal vermemiş); o satırlar
            // kaybolmasın diye `created_at`'e düşülür.
            ->orderByRaw('coalesce(orders.placed_at, orders.created_at) DESC')
            ->limit(self::PER_PAGE)
            ->get();

        $lineStats = $this->lineStatsFor($orders->pluck('id')->all());

        return Inertia::render('Orders/Index', [
            'rows' => $orders->map(
                fn (Order $order): array => $this->presentRow($order, $lineStats),
            )->all(),
            'summary' => $this->summary(),
            'filters' => ['filter' => $filter, 'search' => $search],
        ]);
    }

    /**
     * Sipariş ayrıntısı — satırlar ve olay geçmişi.
     *
     * KİMLİK ROTA MODEL BAĞLAMASIYLA DEĞİL, KONTROLCÜDE ÇÖZÜLÜR.
     *
     * `SubstituteBindings` `web` grubundadır ve rota seviyesindeki `tenant`
     * ara katmanından ÖNCE çalışır; model bağlaması kullanılsaydı sorgu
     * kiracı bağlamı kurulmadan atılır ve izolasyon istisnası fırlatırdı.
     * Kimliği `string` alıp burada aramak sorguyu kiracı scope'unun ALTINA
     * taşır: başka kiracının siparişi 404 verir ve yetkilendirme kimliğin
     * tahmin edilemezliğine DAYANDIRILMAZ. Panelin diğer ekranları da
     * (`ProductController`, `ProductChannelController`) aynı biçimi izler.
     */
    public function show(string $order): InertiaResponse
    {
        $order = Order::query()->with([
            'lines' => fn ($query) => $query->orderBy('created_at'),
            'lines.variant:id,sku',
            'events' => fn ($query) => $query->orderByDesc('occurred_at'),
            'connection:id,channel_type_code,label',
        ])->findOrFail($order);

        return Inertia::render('Orders/Show', [
            'order' => [
                'id' => $order->id,
                'externalId' => $order->external_id,
                'externalNumber' => $order->external_number,
                'status' => $order->status,
                'financialStatus' => $order->financial_status,
                'currency' => $order->currency,
                'subtotal' => $order->subtotal,
                'shippingTotal' => $order->shipping_total,
                'taxTotal' => $order->tax_total,
                'grandTotal' => $order->grand_total,
                'placedAt' => $order->placed_at?->toIso8601String(),
                'channel' => $this->presentChannel($order),

                'lines' => $order->lines->map(fn (OrderLine $line): array => [
                    'id' => $line->id,
                    'sku' => $line->sku,
                    'title' => $line->title,
                    'quantity' => $line->quantity,
                    'quantityCancelled' => $line->quantity_cancelled,
                    'quantityReturned' => $line->quantity_returned,
                    'quantityFulfilled' => $line->quantity_fulfilled,
                    'effectiveQuantity' => $line->effectiveQuantity(),
                    'unitPrice' => $line->unit_price,
                    'lineTotal' => $line->line_total,

                    'stockStatus' => $line->stock_status->value,
                    'isOversold' => $line->stock_status->isOversold(),
                    // Eşleşmemiş SKU: stoğu HİÇ düşülmedi.
                    'isMatched' => $line->isStockable(),
                    'variantSku' => $line->variant?->sku,
                ])->all(),

                'events' => $order->events->map(fn (OrderEvent $event): array => [
                    'id' => $event->id,
                    // `type` ENUM'a cast edilir; ekrana DEĞERİ gider.
                    // Enum nesnesini olduğu gibi göndermek Inertia'da
                    // nesneye serileşir ve şablon karşılaştırması tutmaz.
                    'type' => $event->type->value,
                    'quantity' => $event->quantity,
                    'occurredAt' => $event->occurred_at?->toIso8601String(),
                    'source' => $event->source,
                ])->all(),
            ],
        ]);
    }

    // ─────────────────────────────────────────────────── sorgular

    /**
     * Sipariş satırları — filtre ve arama uygulanmış.
     *
     * Filtreler `order_lines` üzerinde `whereExists` ile kurulur: sipariş
     * başına birden çok satır vardır ve `join` kullanılsaydı çok satırlı
     * sipariş listede TEKRAR ederdi.
     *
     * @return Builder<Order>
     */
    private function orderQuery(string $filter, string $search): Builder
    {
        $query = Order::query();

        if ($filter === 'oversold') {
            $query->whereHas('lines', fn (Builder $lines) => $lines->where('stock_status', 'OVERSOLD'));
        }

        if ($filter === 'unmatched') {
            $query->whereHas('lines', fn (Builder $lines) => $lines->whereNull('variant_id'));
        }

        if ($search !== '') {
            $needle = '%'.$search.'%';

            $query->where(function (Builder $outer) use ($needle): void {
                $outer->whereRaw('orders.external_number ILIKE ?', [$needle])
                    ->orWhereRaw('orders.external_id ILIKE ?', [$needle])
                    // SKU sipariş satırındadır: satıcı çoğu zaman ürünü arar.
                    ->orWhereHas('lines', fn (Builder $lines) => $lines->whereRaw('order_lines.sku ILIKE ?', [$needle]));
            });
        }

        return $query;
    }

    /**
     * Sipariş başına satır sayıları — TEK sorgu.
     *
     * KİRACI FİLTRESİ AÇIKÇA YAZILIR: `DB::table()` Eloquent global
     * scope'una TABİ DEĞİLDİR. Yazılmazsa başka kiracının satırları bu
     * kiracının siparişinde sayılır ve izolasyon ekranın en sessiz
     * köşesinden delinir.
     *
     * @param  list<string>  $orderIds
     * @return array<string, array{lines: int, items: int, oversold: int, unmatched: int}>
     */
    private function lineStatsFor(array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }

        $rows = DB::table('order_lines')
            ->whereIn('order_id', $orderIds)
            ->where('tenant_id', TenantContext::idOrFail())
            ->groupBy('order_id')
            ->selectRaw('order_id')
            ->selectRaw('count(*) AS line_count')
            ->selectRaw('coalesce(sum(quantity), 0) AS item_count')
            ->selectRaw("count(*) FILTER (WHERE stock_status = 'OVERSOLD') AS oversold")
            ->selectRaw('count(*) FILTER (WHERE variant_id IS NULL) AS unmatched')
            ->get();

        $stats = [];

        foreach ($rows as $row) {
            $stats[$row->order_id] = [
                'lines' => (int) $row->line_count,
                'items' => (int) $row->item_count,
                'oversold' => (int) $row->oversold,
                'unmatched' => (int) $row->unmatched,
            ];
        }

        return $stats;
    }

    /**
     * Üst özet — satıcı önce eylem gerektireni görmeli.
     *
     * Sayım SİPARİŞ bazındadır: "kaç siparişi eksik gönderiyorum" sorusunun
     * cevabı budur. Eksik ADET ayrı bir sayaçtan değil
     * `inventory_levels.available` üzerinden okunur (§10 metriği); burada
     * ikinci bir gerçek kaynağı yaratılmaz.
     *
     * @return array<string, int>
     */
    private function summary(): array
    {
        $tenantId = TenantContext::idOrFail();

        $row = DB::table('orders')
            ->where('orders.tenant_id', $tenantId)
            ->selectRaw('count(*) AS order_count')
            ->selectRaw(<<<'SQL'
                count(*) FILTER (
                    WHERE EXISTS (
                        SELECT 1 FROM order_lines
                         WHERE order_lines.order_id = orders.id
                           AND order_lines.stock_status = 'OVERSOLD'
                    )
                ) AS oversold_order_count
            SQL)
            ->selectRaw(<<<'SQL'
                count(*) FILTER (
                    WHERE EXISTS (
                        SELECT 1 FROM order_lines
                         WHERE order_lines.order_id = orders.id
                           AND order_lines.variant_id IS NULL
                    )
                ) AS unmatched_order_count
            SQL)
            ->first();

        return [
            'orderCount' => (int) ($row?->order_count ?? 0),
            'oversoldOrderCount' => (int) ($row?->oversold_order_count ?? 0),
            'unmatchedOrderCount' => (int) ($row?->unmatched_order_count ?? 0),
        ];
    }

    // ─────────────────────────────────────────────────── sunum

    /**
     * @param  array<string, array{lines: int, items: int, oversold: int, unmatched: int}>  $lineStats
     * @return array<string, mixed>
     */
    private function presentRow(Order $order, array $lineStats): array
    {
        $stats = $lineStats[$order->id] ?? [
            'lines' => 0, 'items' => 0, 'oversold' => 0, 'unmatched' => 0,
        ];

        return [
            'id' => $order->id,
            'externalNumber' => $order->external_number,
            'status' => $order->status,
            'financialStatus' => $order->financial_status,
            'currency' => $order->currency,
            'grandTotal' => $order->grand_total,
            'placedAt' => $order->placed_at?->toIso8601String(),
            'channel' => $this->presentChannel($order),

            'lineCount' => $stats['lines'],
            'itemCount' => $stats['items'],

            // FAZLA SATIŞ GİZLENMEZ.
            'hasOversold' => $stats['oversold'] > 0,
            'oversoldLineCount' => $stats['oversold'],

            // Eşleşmemiş SKU AYRI uyarıdır: stok hiç düşülmedi.
            'hasUnmatched' => $stats['unmatched'] > 0,
            'unmatchedLineCount' => $stats['unmatched'],

            'stockBadge' => $this->stockBadgeFor($stats),
        ];
    }

    /** @return array<string, string|null> */
    private function presentChannel(Order $order): array
    {
        return [
            'type' => $order->connection?->channel_type_code,
            // Bağlantının kullanıcı tarafından verilen adı `label`
            // kolonundadır; `name` diye bir kolon YOKTUR.
            'label' => $order->connection?->label,
        ];
    }

    /**
     * Stok rozeti — öncelik sırası önemlidir.
     *
     * FAZLA SATIŞ EŞLEŞMEMİŞTEN ÖNCE GELİR: fazla satış SATILMIŞ ve stoğu
     * eksiye düşmüş bir kalemdir, kargo çıkışı gerçekten tehlikededir.
     * Eşleşmemiş satır ise henüz stoğa dokunmamıştır ve düzeltmesi katalog
     * işidir — ikisi aynı siparişte olabilir ve daha acil olan gösterilir.
     *
     * @param  array{lines: int, items: int, oversold: int, unmatched: int}  $stats
     */
    private function stockBadgeFor(array $stats): string
    {
        if ($stats['oversold'] > 0) {
            return 'OVERSOLD';
        }

        // Stoğu hiç düşülmemiş satır varken "uygulandı" demek yanlış olurdu.
        if ($stats['unmatched'] > 0) {
            return 'PENDING';
        }

        return 'APPLIED';
    }

    /** Bilinmeyen filtre sessizce "hepsi"ne düşer. */
    private function normalizeFilter(string $filter): string
    {
        return in_array($filter, ['oversold', 'unmatched'], strict: true) ? $filter : 'all';
    }
}
