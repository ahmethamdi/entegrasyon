<?php

declare(strict_types=1);

namespace App\Domain\Sync\Support;

use App\Domain\Inventory\Enums\MovementType;
use App\Domain\Reconciliation\Enums\ItemStatus;
use App\Domain\Sync\Enums\SyncDomain;
use App\Domain\Sync\Enums\SyncOperationStatus;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * S11 · "Fazla satış yok" kanıt ekranı — son N günün ÖLÇÜLMÜŞ rakamları.
 *
 * ⚠️ HER SAYI KAYITTAN GELİR, TAHMİN YOK. Rakamlar satışta ekran görüntüsü
 * olarak kullanılır; tahmini bir "önlenen kayıp ₺" ya da "kurtarılan
 * sipariş" sayısı yazılsaydı satıcıya doğrulayamayacağı bir vaat
 * gösterilirdi. Tanımlar ekranda da yazılıdır (`Reports/Oversell.vue`).
 *
 * Tanımlar (kiracı başına, pencere `[from, to]`):
 *   - SATIŞ: `inventory_movements.type = SALE` ve kanalı belli (bir kanal
 *     siparişi). Elle düşüş, iade, içe aktarma sayılmaz.
 *   - DİĞER İLAN: aynı varyantın, satışın geldiği bağlantı DIŞINDAKİ,
 *     satıştan önce açılmış ve bugün canlı (`lifecycle_status = live`)
 *     ilanı, bağlantısı `active`. Bugün canlı olma şartı yaklaşıktır:
 *     sonradan kaldırılan ilan sayılmaz.
 *   - HEDEF: satışın stok olayı (`InventoryLevelChanged`, yükte
 *     `movement_id`) o ilana bir `INVENTORY_PUSH` açtı. Satış anında canlı
 *     olmayan ya da stok gönderimi kapalı ilana fan-out iş açmaz; bugünkü
 *     canlılık o anı kanıtlamaz (24 Ekim: test kiracısında Trendyol ilanı
 *     satış günü pasifti, ilk stok 23 saat sonra elle düzeltmeyle gitti ve
 *     ekran bunu "23 sa gecikme" diye gösterdi). Hedef olmayan ilan süreye
 *     ve korumaya GİRMEZ; satış `not_targeted` olarak ayrıca sayılır ki
 *     fan-out'un bir ilanı ATLAMASI da görünür kalsın.
 *   - ULAŞMA: HEDEF ilan için satıştan SONRA yaratılmış ve `completed` olmuş
 *     ilk `INVENTORY_PUSH`. Zaman damgaları saniye hassasiyetinde olduğu
 *     için yaratılma 1 sn geriye toleranslıdır.
 *   - GECİKME: satış kaydı → son DİĞER İLANA ulaşma (en yavaş kanal).
 *     Ölçüm satışın 34Pazar'a ulaştığı andan başlar; pazaryerinin
 *     siparişi bize bildirme süresi dahil DEĞİLDİR.
 *   - TÜKENME KORUMASI: satış o depodaki satılabilir stoğu
 *     (`on_hand_after − reserved_after`) 0'a ya da altına indirdi ve
 *     bütün diğer ilanlara ulaşıldı. Senkron olmasaydı o ilanlar stoklu
 *     görünmeye devam ederdi — fazla satışın tam olarak oluştuğu an.
 *   - MUTABAKAT: pencerede kontrol edilen kalemler; "fark" = kanal ile
 *     bizim değerimiz uyuşmadı, "onarıldı" = otomatik düzeltildi.
 *     Kanala ulaşılamayan kontroller ayrı sayılır, farka katılmaz.
 */
final class OversellProtectionReport
{
    public const DEFAULT_DAYS = 30;

    /** Farka sayılan mutabakat durumları — eşleşme ve ulaşılamama hariç. */
    private const DRIFT_STATUSES = [
        ItemStatus::DRIFT_DETECTED,
        ItemStatus::REMOTE_MISSING,
        ItemStatus::REPAIR_QUEUED,
        ItemStatus::REPAIRED,
        ItemStatus::MANUAL_REVIEW,
        ItemStatus::PRICE_CONFLICT,
        ItemStatus::SUSPECTED_SALE,
    ];

    /**
     * @return array{
     *     from: string, to: string,
     *     orders: array{count: int, channels: int},
     *     sales: array{total: int, multi_channel: int, reached: int, pending: int, not_targeted: int},
     *     latency: array{avg: float|null, p50: float|null, p95: float|null, max: float|null},
     *     stockouts: array{protected: int, unprotected: int},
     *     reconciliation: array{checked: int, drift: int, repaired: int, review: int, unreachable: int}
     * }
     */
    public function build(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $tenant = TenantContext::idOrFail();
        $window = [$from->utc()->toDateTimeString(), $to->utc()->toDateTimeString()];

        $orders = DB::selectOne(
            'SELECT count(*) AS n, count(DISTINCT channel_connection_id) AS c FROM orders
             WHERE tenant_id = ? AND coalesce(placed_at, created_at) BETWEEN ? AND ?',
            [$tenant, ...$window],
        );

        // Satış başına: diğer ilan, hedef ilan, ulaşılan hedef, en yavaş ulaşma.
        $rows = DB::select(
            'SELECT m.id,
                    (m.on_hand_after - m.reserved_after) AS available_after,
                    count(l.id) AS others,
                    count(t.id) AS targeted,
                    count(r.completed_at) FILTER (WHERE t.id IS NOT NULL) AS reached,
                    max(extract(epoch FROM r.completed_at - m.created_at)) FILTER (WHERE t.id IS NOT NULL) AS slowest
               FROM inventory_movements m
               LEFT JOIN listings l
                      ON l.tenant_id = m.tenant_id
                     AND l.variant_id = m.variant_id
                     AND l.channel_connection_id <> m.channel_connection_id
                     AND l.lifecycle_status = ?
                     AND l.created_at <= m.created_at
                     AND EXISTS (SELECT 1 FROM channel_connections c
                                  WHERE c.id = l.channel_connection_id AND c.status = ?)
               LEFT JOIN inventory_levels il
                      ON il.tenant_id = m.tenant_id
                     AND il.warehouse_id = m.warehouse_id
                     AND il.variant_id = m.variant_id
               LEFT JOIN LATERAL (
                      SELECT o.id
                        FROM outbox_events e
                        JOIN sync_operations o
                          ON o.outbox_event_id = e.id
                         AND o.entity_type = ?
                         AND o.entity_id = l.id
                       WHERE e.aggregate_type = ?
                         AND e.aggregate_id = il.id
                         AND e.payload->>\'movement_id\' = m.id::text
                       LIMIT 1
                  ) t ON l.id IS NOT NULL
               LEFT JOIN LATERAL (
                      SELECT min(o.completed_at) AS completed_at
                        FROM sync_operations o
                       WHERE o.tenant_id = m.tenant_id
                         AND o.entity_type = ?
                         AND o.entity_id = l.id
                         AND o.operation_type = ?
                         AND o.status = ?
                         AND o.created_at >= m.created_at - interval \'1 second\'
                  ) r ON l.id IS NOT NULL
              WHERE m.tenant_id = ?
                AND m.type = ?
                AND m.channel_connection_id IS NOT NULL
                AND m.created_at BETWEEN ? AND ?
              GROUP BY m.id, m.on_hand_after, m.reserved_after',
            ['live', 'active', 'listing', 'inventory_level', 'listing', SyncDomain::INVENTORY->operationType(), SyncOperationStatus::COMPLETED->value, $tenant, MovementType::SALE->value, ...$window],
        );

        $latencies = [];
        $multi = $reached = $pending = $notTargeted = $protected = $unprotected = 0;

        foreach ($rows as $row) {
            if ((int) $row->others === 0) {
                continue;
            }

            if ((int) $row->targeted < (int) $row->others) {
                $notTargeted++;
            }

            if ((int) $row->targeted === 0) {
                continue;
            }

            $multi++;
            $complete = (int) $row->reached === (int) $row->targeted;

            if ($complete) {
                $reached++;
                $latencies[] = max(0.0, (float) $row->slowest);
            } else {
                $pending++;
            }

            if ((int) $row->available_after <= 0) {
                $complete ? $protected++ : $unprotected++;
            }
        }

        $statuses = DB::select(
            'SELECT status, count(*) AS n FROM reconciliation_items
              WHERE tenant_id = ? AND checked_at BETWEEN ? AND ? GROUP BY status',
            [$tenant, ...$window],
        );
        $byStatus = [];
        foreach ($statuses as $s) {
            $byStatus[(string) $s->status] = (int) $s->n;
        }

        $drift = array_sum(array_map(static fn (ItemStatus $st): int => $byStatus[$st->value] ?? 0, self::DRIFT_STATUSES));
        $unreachable = $byStatus[ItemStatus::REMOTE_UNREACHABLE->value] ?? 0;

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'orders' => ['count' => (int) $orders->n, 'channels' => (int) $orders->c],
            'sales' => ['total' => count($rows), 'multi_channel' => $multi, 'reached' => $reached, 'pending' => $pending, 'not_targeted' => $notTargeted],
            'latency' => self::summary($latencies),
            'stockouts' => ['protected' => $protected, 'unprotected' => $unprotected],
            'reconciliation' => [
                'checked' => array_sum($byStatus) - $unreachable,
                'drift' => $drift,
                'repaired' => $byStatus[ItemStatus::REPAIRED->value] ?? 0,
                'review' => $byStatus[ItemStatus::MANUAL_REVIEW->value] ?? 0,
                'unreachable' => $unreachable,
            ],
        ];
    }

    /**
     * Ölçüm yoksa NULL — 0 sn "anında" demek olurdu.
     *
     * @param  list<float>  $values
     * @return array{avg: float|null, p50: float|null, p95: float|null, max: float|null}
     */
    private static function summary(array $values): array
    {
        if ($values === []) {
            return ['avg' => null, 'p50' => null, 'p95' => null, 'max' => null];
        }

        sort($values);
        $pct = static fn (int $p): float => $values[(int) max(0, ceil($p / 100 * count($values)) - 1)];

        return [
            'avg' => round(array_sum($values) / count($values), 1),
            'p50' => round($pct(50), 1),
            'p95' => round($pct(95), 1),
            'max' => round(end($values), 1),
        ];
    }
}
