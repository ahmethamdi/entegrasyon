<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Inventory\Models\InventoryLevel;
use App\Domain\Orders\Models\Fulfillment;
use App\Domain\Orders\Models\Order;
use App\Domain\Reconciliation\Enums\ItemStatus;
use App\Domain\Sync\Enums\SyncOperationStatus;
use App\Domain\Sync\Models\Listing;
use App\Domain\Sync\Models\ListingSyncState;
use App\Domain\Sync\Models\SyncOperation;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Panel ana ekranı — senkron sağlığı tek bakışta.
 *
 * Mimari Karar Dokümanı v2.2 · §17 · P0 · "Panelde senkron geçmişi ve hata
 * görünürlüğü — destek yükünü belirleyen tek ekran".
 *
 * DÖRT SORUYA CEVAP VERİR:
 *   1. Kanallar ayakta mı?          → bağlantı sağlığı
 *   2. Bekleyen iş var mı?          → kirli satır sayısı
 *   3. Bir şey bozuldu mu?          → hatalı operasyonlar ve kalıcı hatalar
 *   4. Fazla satış var mı?          → negatif available
 *
 * FAZLA SATIŞ AYRI GÖSTERİLİR (§17 · P0): negatif `available` kullanıcıya
 * anlatılmak zorundadır. Eksik miktar gizlenirse satıcı stoğunun neden
 * tutmadığını anlamaz ve sisteme güvenmez.
 *
 * Tüm sorgular kiracı scope'u altında çalışır; bağlamı `tenant` ara katmanı
 * kurar ve istek sonunda bırakır.
 */
final class DashboardController extends Controller
{
    public function __invoke(Request $request): InertiaResponse
    {
        return Inertia::render('Dashboard', [
            'tenant' => $this->tenantSummary($request),
            'connections' => $this->connections(),
            'syncHealth' => $this->syncHealth(),
            'oversold' => $this->oversold(),
            'recentOperations' => $this->recentOperations(),
            'todos' => $this->todos(),
            'today' => $this->today(),
        ]);
    }

    /**
     * "Bugün yapman gerekenler" — satıcının diliyle, eyleme bağlı.
     *
     * Ana ekranın işi SAYI GÖSTERMEK DEĞİL, NE YAPILACAĞINI SÖYLEMEKTİR.
     * Teknik sağlık sayıları (senkron, geçici hata) satıcıya bir şey
     * yaptırmaz; her madde bir ekrana gider ve orada çözülür. Sayısı sıfır
     * olan madde GÖSTERİLMEZ: boş liste "her şey yolunda" demektir ve bu da
     * bir bilgidir.
     *
     * SIRA ACİLİYETE GÖREDİR: elinde olmayan malı satmış olmak (fazla
     * satış) kargo beklemekten önce gelir — müşteriye söz verilmiş ve
     * tutulamıyor.
     *
     * @return list<array{key: string, count: int, title: string, hint: string, href: string, tone: string}>
     */
    private function todos(): array
    {
        $tenantId = TenantContext::idOrFail();

        $items = [
            [
                'key' => 'oversold',
                'count' => InventoryLevel::query()->where('available', '<', 0)->count(),
                'title' => __('ürünü elinde olandan fazla sattın'),
                'hint' => __('Stoğu düzelt ya da müşteriye haber ver.'),
                'href' => '/products?filter=out',
                'tone' => 'urgent',
            ],
            [
                'key' => 'awaiting_shipment',
                'count' => Order::query()->awaitingShipment()->count(),
                'title' => __('sipariş kargolanmayı bekliyor'),
                'hint' => __('Kargoya verince takip numarasını gir.'),
                'href' => '/orders?filter=awaiting_shipment',
                'tone' => 'action',
            ],
            [
                'key' => 'shipment_failed',
                'count' => Fulfillment::query()->where('push_status', Fulfillment::PUSH_FAILED)->count(),
                'title' => __('kargo bilgisi kanala gönderilemedi'),
                'hint' => __('Takip numarasını kontrol edip tekrar gönder.'),
                'href' => '/orders',
                'tone' => 'urgent',
            ],
            [
                'key' => 'unmatched',
                'count' => Order::query()->whereHas('lines', fn ($q) => $q->whereNull('variant_id'))->count(),
                'title' => __('siparişte tanımadığımız ürün var'),
                'hint' => __('Stok kodunu (SKU) kataloğundaki ürünle aynı yap; stok o zaman düşer.'),
                'href' => '/orders?filter=unmatched',
                'tone' => 'action',
            ],
            [
                'key' => 'rejected',
                'count' => Listing::query()->where('lifecycle_status', 'rejected')->count(),
                'title' => __('ürün kanal tarafından reddedildi'),
                'hint' => __('Red sebebini oku, ürünü düzeltip tekrar gönder.'),
                'href' => '/approvals?status=rejected',
                'tone' => 'action',
            ],
            [
                'key' => 'price_conflict',
                'count' => $this->priceConflictCount($tenantId),
                'title' => __('üründe kanaldaki fiyat seninkinden farklı'),
                'hint' => __('Hangi fiyatın geçerli olacağına sen karar ver.'),
                'href' => '/reconciliation',
                'tone' => 'action',
            ],
            [
                'key' => 'failed_sync',
                'count' => SyncOperation::query()->unresolvedDead()->count(),
                'title' => __('güncelleme kanala gönderilemedi'),
                'hint' => __('Sebebine bak, düzeltip tek tıkla tekrar dene.'),
                'href' => '/failures',
                'tone' => 'urgent',
            ],
            [
                'key' => 'broken_channel',
                'count' => ChannelConnection::query()->where('health_status', 'unhealthy')->count(),
                'title' => __('kanal bağlantısı koptu'),
                'hint' => __('Kanal bilgilerini yenile; kopukken stok gönderilemez.'),
                'href' => '/channels',
                'tone' => 'urgent',
            ],
        ];

        return array_values(array_filter($items, static fn (array $item): bool => $item['count'] > 0));
    }

    /**
     * Fiyat çakışması — listing başına SON kalem, çözülmemiş olan.
     *
     * Mutabakat ekranıyla AYNI kural: eski turların kalemleri sayılsaydı
     * tek çakışma her turda bir daha sayılır, çözülmüş olan da sayılsaydı
     * satıcının karar verdiği satır "bekliyor" görünürdü.
     */
    private function priceConflictCount(string $tenantId): int
    {
        $row = DB::selectOne(<<<'SQL'
            SELECT count(*) AS n FROM (
                SELECT DISTINCT ON (listing_id) status, resolved_at
                  FROM reconciliation_items
                 WHERE tenant_id = ?
                 ORDER BY listing_id, id DESC
            ) latest
             WHERE latest.status = ? AND latest.resolved_at IS NULL
        SQL, [$tenantId, ItemStatus::PRICE_CONFLICT->value]);

        return (int) ($row?->n ?? 0);
    }

    /**
     * Bugünün özeti — satıcının sabah ilk baktığı iki sayı.
     *
     * Ciro PARA BİRİMİ BAŞINA ayrı toplanır: TL ile Euro toplansaydı
     * anlamsız bir sayı çıkardı (Etsy/eBay siparişi yabancı parayla gelir).
     * İptal edilenler sayılmaz.
     *
     * @return array{orderCount: int, revenue: list<array{currency: string, total: string}>}
     */
    private function today(): array
    {
        $base = Order::query()
            ->whereRaw('coalesce(placed_at, created_at) >= ?', [now()->startOfDay()])
            ->whereRaw('lower(status) <> ?', ['cancelled']);

        $revenue = (clone $base)
            ->selectRaw('currency, sum(grand_total) AS total')
            ->groupBy('currency')
            ->orderBy('currency')
            ->get()
            ->map(fn ($row): array => [
                'currency' => (string) ($row->currency ?? 'TRY'),
                'total' => number_format((float) $row->total, 2, '.', ''),
            ])
            ->all();

        return [
            'orderCount' => (clone $base)->count(),
            'revenue' => $revenue,
        ];
    }

    /** @return array<string, mixed> */
    private function tenantSummary(Request $request): array
    {
        $tenant = $request->attributes->get('tenant');

        return [
            'id' => $tenant?->id,
            'name' => $tenant?->name,
        ];
    }

    /**
     * Bağlantı sağlığı — kanal ayakta mı?
     *
     * @return array<int, array<string, mixed>>
     */
    private function connections(): array
    {
        return ChannelConnection::query()
            ->with('channelType:code,name')
            ->orderBy('channel_type_code')
            ->get()
            ->map(fn (ChannelConnection $c): array => [
                'id' => $c->id,
                'label' => $c->label,
                'channel' => $c->channelType?->name ?? $c->channel_type_code,
                'status' => $c->status,
                'health' => $c->health_status,
                'lastHealthyAt' => $c->last_healthy_at?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * Senkron sağlığı — bekleyen iş ve hata sayıları.
     *
     * `is_dirty` veritabanı tarafından üretilir (`desired > synced`); ayrı
     * bir sayaç tutulmadığı için bu rakam tanım gereği tutarlıdır.
     *
     * @return array<string, int>
     */
    private function syncHealth(): array
    {
        $states = ListingSyncState::query()
            ->selectRaw('count(*) FILTER (WHERE is_dirty) AS dirty')
            ->selectRaw("count(*) FILTER (WHERE status = 'synced') AS synced")
            ->selectRaw("count(*) FILTER (WHERE status = 'error_transient') AS transient")
            ->selectRaw("count(*) FILTER (WHERE status = 'error_permanent') AS permanent")
            ->first();

        $operations = SyncOperation::query()
            ->selectRaw("count(*) FILTER (WHERE status IN ('pending', 'retrying')) AS waiting")
            ->selectRaw("count(*) FILTER (WHERE status = 'dead') AS dead")
            ->first();

        return [
            'dirty' => (int) ($states?->dirty ?? 0),
            'synced' => (int) ($states?->synced ?? 0),
            'errorTransient' => (int) ($states?->transient ?? 0),
            'errorPermanent' => (int) ($states?->permanent ?? 0),
            'waiting' => (int) ($operations?->waiting ?? 0),
            'dead' => (int) ($operations?->dead ?? 0),
        ];
    }

    /**
     * Fazla satılan varyantlar — negatif `available`.
     *
     * NEGATİF DEĞER OLDUĞU GİBİ GÖSTERİLİR, kırpılmaz. Kırpma yalnızca
     * kanala giden yükte meşrudur (`OutboundQuantity`); panelde gizlemek
     * satıcıyı eksik miktardan habersiz bırakırdı.
     *
     * @return array<int, array<string, mixed>>
     */
    private function oversold(): array
    {
        return InventoryLevel::query()
            ->with('variant:id,sku')
            ->where('available', '<', 0)
            ->orderBy('available')
            ->limit(20)
            ->get()
            ->map(fn (InventoryLevel $level): array => [
                'sku' => $level->variant?->sku,
                'available' => $level->available,
                // Eksik miktar açıkça söylenir: "kaç adet açıktasın".
                'shortfall' => abs($level->available),
            ])
            ->all();
    }

    /**
     * Son senkron operasyonları — "ne oldu" sorusunun cevabı.
     *
     * SIRALAMA `id` ÜZERİNDEN: `created_at` SANİYE hassasiyetlidir ve
     * fan-out tek bir olaydan onlarca operasyonu AYNI SANİYEDE açar —
     * o satırların sırası belirsiz kalır ve "son 15" her yenilemede
     * farklı bir alt küme gösterebilirdi. `id` UUIDv7'dir: zaman sıralı
     * ve saniye içinde de ayırt edici.
     *
     * @return array<int, array<string, mixed>>
     */
    private function recentOperations(): array
    {
        return SyncOperation::query()
            ->with('connection.channelType:code,name')
            ->orderByDesc('id')
            ->limit(15)
            ->get()
            ->map(fn (SyncOperation $op): array => [
                'id' => $op->id,
                'type' => $op->operation_type,
                'channel' => $op->connection?->channelType?->name
                    ?? $op->connection?->channel_type_code,
                'version' => $op->entity_version,
                'status' => $op->status->value,
                'attempts' => $op->attempt_count,
                'errorClass' => $op->last_error_class,
                'createdAt' => $op->created_at?->toIso8601String(),
                'isFailed' => in_array($op->status, [
                    SyncOperationStatus::DEAD,
                    SyncOperationStatus::RETRYING,
                ], true),
            ])
            ->all();
    }
}
