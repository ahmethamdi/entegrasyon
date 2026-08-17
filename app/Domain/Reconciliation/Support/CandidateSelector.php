<?php

declare(strict_types=1);

namespace App\Domain\Reconciliation\Support;

use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Sync\Enums\SyncDomain;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Mutabakat adaylarını seçer — her sebep AYRI sorgu.
 *
 * Mimari Karar Dokümanı v2.2 · §10 · Aday seçimi.
 *
 * DÖRT AYRI SORGU, TEK UNION DEĞİL: her sorgu kendi kısmi indeksini
 * kullanır (`movements_type_time_idx`, `sync_states_dirty_idx`,
 * `sync_states_error_idx`, `recon_items_listing_time_idx`). Tek dev UNION
 * planlayıcıyı zorlar ve indeks seçimini bozar. Birleştirme UYGULAMA
 * katmanında yapılır: listing başına en yüksek öncelik alınır.
 *
 * DEĞİŞMEZ KURAL — `error_permanent` ASLA ADAY DEĞİLDİR:
 *   Düzeltilemeyecek bir listing her beş dakikada yeniden kontrol edilirse
 *   mutabakat bütçesi boşa gider ve gerçek sürüklenmeler geç fark edilir. Bu
 *   satırlar ancak kullanıcı müdahalesiyle `pending`'e döndükten sonra akışa
 *   girer.
 *
 * DEĞİŞMEZ KURAL — YALNIZCA CANLI LISTING:
 *   Taslak ve listeden çıkarılmış satırın kanalda karşılığı yoktur; okumak
 *   hem anlamsız hem bütçe israfıdır.
 *
 * `DB::table()` global scope'a TABİ DEĞİLDİR — kiracı filtresi her sorguda
 * AÇIKÇA yazılır. Yazılmazsa başka kiracının listing'i bu turda okunur ve
 * onun stoğu yanlış kanala gönderilir.
 *
 * KAYNAK KANAL DAHİLDİR: fan-out'ta kaynak kanal anlık yankıdan muaf tutulur
 * ama bu bir ENİYİLEMEDİR, otorite devri değil. Kaynak kanal kendi
 * güncellemesini uygulamamış olabilir; mutabakat onu da okur (§10).
 */
final class CandidateSelector
{
    /** Sebep → öncelik; büyük sayı önce gelir (§10). */
    private const PRIORITY = [
        'recently_sold' => 100,
        'previous_error' => 90,
        'stale_sync' => 80,
        'drift_detected' => 70,
    ];

    /**
     * @return list<array{listing_id: string, reason: string, priority: int}>
     */
    public function for(ChannelConnection $connection, int $budget): array
    {
        $tenantId = TenantContext::idOrFail();

        $found = [
            ...$this->recentlySold($tenantId, $connection->id),
            ...$this->previousError($tenantId, $connection->id),
            ...$this->staleSync($tenantId, $connection->id),
            ...$this->driftDetected($tenantId, $connection->id),
        ];

        return $this->mergeByHighestPriority($found, $budget);
    }

    /**
     * (1) Son 30 dakikada satış olan varyantlar.
     *
     * En yüksek öncelik: satış olan satır hem en çok değişen hem de
     * sürüklenmesi en pahalı olandır (fazla satış riski).
     *
     * @return list<array{listing_id: string, reason: string, priority: int}>
     */
    private function recentlySold(string $tenantId, string $connectionId): array
    {
        $rows = DB::select(<<<'SQL'
            SELECT DISTINCT l.id AS listing_id
              FROM inventory_movements m
              JOIN listings l ON l.variant_id = m.variant_id
              LEFT JOIN listing_sync_states s
                     ON s.listing_id = l.id AND s.domain = ?
             WHERE m.tenant_id = ?
               AND m.type = 'SALE'
               AND m.occurred_at > clock_timestamp() - interval '30 minutes'
               AND l.tenant_id = ?
               AND l.channel_connection_id = ?
               AND l.lifecycle_status = 'live'
               AND coalesce(s.status, 'pending') <> 'error_permanent'
        SQL, [SyncDomain::INVENTORY->value, $tenantId, $tenantId, $connectionId]);

        return $this->label($rows, 'recently_sold');
    }

    /**
     * (2) Geçici hata almış — KALICI hatalar hariç.
     *
     * `error_count BETWEEN 1 AND 5`: hiç denenmemiş satır burada aranmaz ve
     * beşten çok kez düşen satır da bütçeyi tüketmemelidir.
     *
     * @return list<array{listing_id: string, reason: string, priority: int}>
     */
    private function previousError(string $tenantId, string $connectionId): array
    {
        $rows = DB::select(<<<'SQL'
            SELECT l.id AS listing_id
              FROM listing_sync_states s
              JOIN listings l ON l.id = s.listing_id
             WHERE s.tenant_id = ?
               AND s.domain = ?
               AND s.status = 'error_transient'
               AND s.error_count BETWEEN 1 AND 5
               AND l.tenant_id = ?
               AND l.channel_connection_id = ?
               AND l.lifecycle_status = 'live'
        SQL, [$tenantId, SyncDomain::INVENTORY->value, $tenantId, $connectionId]);

        return $this->label($rows, 'previous_error');
    }

    /**
     * (3) Bekleyen ve takılmış senkronlar.
     *
     * `is_dirty` ÜRETİLMİŞ KOLONDUR ve kısmi indeks onun üzerine kuruludur;
     * `desired_version > synced_version` yüklemi doğrudan indekslenemezdi.
     *
     * @return list<array{listing_id: string, reason: string, priority: int}>
     */
    private function staleSync(string $tenantId, string $connectionId): array
    {
        $rows = DB::select(<<<'SQL'
            SELECT l.id AS listing_id
              FROM listing_sync_states s
              JOIN listings l ON l.id = s.listing_id
             WHERE s.tenant_id = ?
               AND s.domain = ?
               AND s.is_dirty
               AND s.status <> 'error_permanent'
               AND s.last_requested_at < clock_timestamp() - interval '1 hour'
               AND l.tenant_id = ?
               AND l.channel_connection_id = ?
               AND l.lifecycle_status = 'live'
        SQL, [$tenantId, SyncDomain::INVENTORY->value, $tenantId, $connectionId]);

        return $this->label($rows, 'stale_sync');
    }

    /**
     * (4) Daha önce sürüklenme bulunmuş — DOĞRULAMA turu.
     *
     * Onarımdan hemen sonra okumak hem kota yer hem de kanalın kendi
     * gecikmesi yüzünden yanlış sonuç verir; bir sonraki tura bırakmak hem
     * bedava hem doğrudur (§10).
     *
     * @return list<array{listing_id: string, reason: string, priority: int}>
     */
    private function driftDetected(string $tenantId, string $connectionId): array
    {
        $rows = DB::select(<<<'SQL'
            SELECT DISTINCT l.id AS listing_id
              FROM reconciliation_items ri
              JOIN listings l ON l.id = ri.listing_id
              LEFT JOIN listing_sync_states s
                     ON s.listing_id = l.id AND s.domain = ?
             WHERE ri.tenant_id = ?
               AND ri.status IN ('REPAIR_QUEUED', 'DRIFT_DETECTED')
               AND ri.checked_at > clock_timestamp() - interval '24 hours'
               AND l.tenant_id = ?
               AND l.channel_connection_id = ?
               AND l.lifecycle_status = 'live'
               AND coalesce(s.status, 'pending') <> 'error_permanent'
        SQL, [SyncDomain::INVENTORY->value, $tenantId, $tenantId, $connectionId]);

        return $this->label($rows, 'drift_detected');
    }

    /**
     * @param  list<object>  $rows
     * @return list<array{listing_id: string, reason: string, priority: int}>
     */
    private function label(array $rows, string $reason): array
    {
        return array_map(static fn (object $row): array => [
            'listing_id' => $row->listing_id,
            'reason' => $reason,
            'priority' => self::PRIORITY[$reason],
        ], $rows);
    }

    /**
     * Listing başına EN YÜKSEK öncelik alınır, sonra bütçe uygulanır.
     *
     * Aynı listing birden çok sebeple aday olabilir; iki kez okumak hem
     * bütçeyi çalar hem aynı kalemi iki kez yazardı.
     *
     * @param  list<array{listing_id: string, reason: string, priority: int}>  $found
     * @return list<array{listing_id: string, reason: string, priority: int}>
     */
    private function mergeByHighestPriority(array $found, int $budget): array
    {
        $best = [];

        foreach ($found as $candidate) {
            $id = $candidate['listing_id'];

            if (! isset($best[$id]) || $candidate['priority'] > $best[$id]['priority']) {
                $best[$id] = $candidate;
            }
        }

        $merged = array_values($best);

        usort($merged, static fn (array $a, array $b): int => $b['priority'] <=> $a['priority']);

        return array_slice($merged, 0, max($budget, 0));
    }
}
