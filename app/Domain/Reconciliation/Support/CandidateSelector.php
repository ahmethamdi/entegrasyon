<?php

declare(strict_types=1);

namespace App\Domain\Reconciliation\Support;

use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Reconciliation\Enums\ReconciliationScope;
use App\Domain\Sync\Enums\SyncDomain;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Mutabakat adaylarını seçer — her sebep AYRI sorgu.
 *
 * Mimari Karar Dokümanı v2.2 · §10 · Aday seçimi.
 *
 * PENCERELER KATMANDAN GELİR (`ReconciliationScope`), SORGUYA GÖMÜLÜ
 * DEĞİLDİR: sıcak katman son 30 dakikanın satışına ve bir saattir bekleyen
 * işe bakar, ılık katman aynı sorgularla 24 saatlik pencerelere bakar.
 * Gömülü olsaydı ılık katman bu dosyanın bir KOPYASI olarak yazılır ve iki
 * kopya zamanla ayrışırdı — biri düzeltilir, öteki eski kuralı sessizce
 * uygulamaya devam ederdi.
 *
 * SOĞUK KATMAN BURAYA HİÇ UĞRAMAZ: kapsamı "rastgele örneklem — uzun
 * kuyruk"tur ve dört sebebin hiçbirine takılmayan satırı arar. Onun sorgusu
 * `SampledCandidates` içindedir.
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
 *
 * ─────────────────────────────────────────────────────────────────────
 * DOMAIN PARAMETREDİR — FİYAT TURU `recently_sold` KULLANMAZ
 * ─────────────────────────────────────────────────────────────────────
 * Dört sorgunun üçü domain-nötrdür ve yalnızca `listing_sync_states.domain`
 * değerini değiştirir. `recently_sold` ise DEĞİLDİR: `inventory_movements`
 * üzerinden çalışır ve sorduğu şey "bu varyantta satış oldu mu"dur.
 *
 * SATIŞ FİYATI DEĞİŞTİRMEZ. O sorgu fiyat turunda da koşsaydı her satan
 * ürün fiyat adayı olur, bütçe fiyatı hiç değişmemiş satırlarla dolar ve
 * gerçek çakışmalar (satıcının kanal panelinden yaptığı kampanya) bütçe
 * dışında kalırdı — üstelik çakışma tam da SATMAYAN üründe uzun süre
 * fark edilmeden durabilir.
 *
 * Bu, soğuk katmanın dört sorguyu çalıştırmama kararının kardeşidir:
 * sorgu kümesi kapsamdan türer, kapsam da sorulan sorudan.
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
    public function for(
        ChannelConnection $connection,
        int $budget,
        ReconciliationScope $scope = ReconciliationScope::HOT,
        SyncDomain $domain = SyncDomain::INVENTORY,
    ): array {
        $tenantId = TenantContext::idOrFail();

        $found = [
            // Gerekçe sınıf başlığında: satış fiyatı değiştirmez.
            ...($domain === SyncDomain::INVENTORY
                ? $this->recentlySold($tenantId, $connection->id, $scope)
                : []),
            ...$this->previousError($tenantId, $connection->id, $domain),
            ...$this->staleSync($tenantId, $connection->id, $scope, $domain),
            ...$this->driftDetected($tenantId, $connection->id, $scope, $domain),
        ];

        return $this->mergeByHighestPriority($found, $budget);
    }

    /**
     * (1) Son X içinde satış olan varyantlar — pencere KATMANDAN gelir.
     *
     * En yüksek öncelik: satış olan satır hem en çok değişen hem de
     * sürüklenmesi en pahalı olandır (fazla satış riski).
     *
     * Sıcak katman 30 dakika, ılık katman 24 saat (§10 · kapsam sütunu).
     * Interval PARAMETRE OLARAK BAĞLANAMAZ (`interval ?` sözdizimi geçersiz);
     * `?::interval` cast'i ile bağlanır — metni sorguya gömmek katman
     * değerini SQL enjeksiyon yüzeyine taşırdı.
     *
     * @return list<array{listing_id: string, reason: string, priority: int}>
     */
    private function recentlySold(string $tenantId, string $connectionId, ReconciliationScope $scope): array
    {
        $rows = DB::select(<<<'SQL'
            SELECT DISTINCT l.id AS listing_id
              FROM inventory_movements m
              JOIN listings l ON l.variant_id = m.variant_id
              LEFT JOIN listing_sync_states s
                     ON s.listing_id = l.id AND s.domain = ?
             WHERE m.tenant_id = ?
               AND m.type = 'SALE'
               AND m.occurred_at > clock_timestamp() - ?::interval
               AND l.tenant_id = ?
               AND l.channel_connection_id = ?
               AND l.lifecycle_status = 'live'
               AND coalesce(s.status, 'pending') <> 'error_permanent'
        SQL, [
            SyncDomain::INVENTORY->value,
            $tenantId,
            $scope->soldWithin(),
            $tenantId,
            $connectionId,
        ]);

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
    private function previousError(string $tenantId, string $connectionId, SyncDomain $domain): array
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
        SQL, [$tenantId, $domain->value, $tenantId, $connectionId]);

        return $this->label($rows, 'previous_error');
    }

    /**
     * (3) Bekleyen ve takılmış senkronlar — eşik KATMANDAN gelir.
     *
     * `is_dirty` ÜRETİLMİŞ KOLONDUR ve kısmi indeks onun üzerine kuruludur;
     * `desired_version > synced_version` yüklemi doğrudan indekslenemezdi.
     *
     * EŞİK SICAKTA 1 SAAT, ILIKTA 24 SAATTİR ve bu fark bilinçlidir: sıcak
     * katman bir saattir bekleyen satırı her beş dakikada bir zaten
     * görüyor. Ilık katman aynı eşiği kullansaydı 300'lük bütçesini sıcak
     * katmanın çoktan baktığı satırlarla doldurur ve HİÇBİR ŞEY EKLEMEZDİ.
     *
     * @return list<array{listing_id: string, reason: string, priority: int}>
     */
    private function staleSync(
        string $tenantId,
        string $connectionId,
        ReconciliationScope $scope,
        SyncDomain $domain,
    ): array {
        $rows = DB::select(<<<'SQL'
            SELECT l.id AS listing_id
              FROM listing_sync_states s
              JOIN listings l ON l.id = s.listing_id
             WHERE s.tenant_id = ?
               AND s.domain = ?
               AND s.is_dirty
               AND s.status <> 'error_permanent'
               AND s.last_requested_at < clock_timestamp() - ?::interval
               AND l.tenant_id = ?
               AND l.channel_connection_id = ?
               AND l.lifecycle_status = 'live'
        SQL, [
            $tenantId,
            $domain->value,
            $scope->pendingFor(),
            $tenantId,
            $connectionId,
        ]);

        return $this->label($rows, 'stale_sync');
    }

    /**
     * (4) Daha önce sürüklenme bulunmuş — DOĞRULAMA turu.
     *
     * Onarımdan hemen sonra okumak hem kota yer hem de kanalın kendi
     * gecikmesi yüzünden yanlış sonuç verir; bir sonraki tura bırakmak hem
     * bedava hem doğrudur (§10).
     *
     * KALEM DE DOMAİNE GÖRE FİLTRELENİR (`ri.domain`). Filtrelenmeseydi bir
     * stok sürüklenmesi fiyat turunu tetikler ve tersi olurdu: iki tur
     * birbirinin bütçesini yer ve "hangi domainde sorun var" sorusu
     * cevapsız kalırdı.
     *
     * `PRICE_CONFLICT` BURADA YOKTUR ve olmamalıdır. Çakışma KULLANICI
     * KARARI bekler (§9 · PRICE: "kullanıcı seçer"); her turda yeniden
     * okumak bütçeyi, satıcı karar verene kadar — belki günlerce — aynı
     * satıra harcar. Rozet zaten ekranda duruyor ve kanaldaki fiyat
     * değişmediği sürece yeni bir bilgi de doğmaz. `error_permanent`ın
     * aday olmama gerekçesinin aynısı: satır ancak kullanıcı müdahalesiyle
     * akışa döner.
     *
     * @return list<array{listing_id: string, reason: string, priority: int}>
     */
    private function driftDetected(
        string $tenantId,
        string $connectionId,
        ReconciliationScope $scope,
        SyncDomain $domain,
    ): array {
        $rows = DB::select(<<<'SQL'
            SELECT DISTINCT l.id AS listing_id
              FROM reconciliation_items ri
              JOIN listings l ON l.id = ri.listing_id
              LEFT JOIN listing_sync_states s
                     ON s.listing_id = l.id AND s.domain = ?
             WHERE ri.tenant_id = ?
               AND ri.domain = ?
               AND ri.status IN ('REPAIR_QUEUED', 'DRIFT_DETECTED')
               AND ri.checked_at > clock_timestamp() - ?::interval
               AND l.tenant_id = ?
               AND l.channel_connection_id = ?
               AND l.lifecycle_status = 'live'
               AND coalesce(s.status, 'pending') <> 'error_permanent'
        SQL, [
            $domain->value,
            $tenantId,
            $domain->value,
            $scope->driftWithin(),
            $tenantId,
            $connectionId,
        ]);

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
