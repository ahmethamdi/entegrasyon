<?php

declare(strict_types=1);

namespace App\Domain\Reconciliation\Support;

use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Reconciliation\Enums\ReconciliationScope;
use App\Domain\Sync\Enums\SyncDomain;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * §10 · SOĞUK KATMAN — uzun kuyruk örneklemi.
 *
 * Mimari Karar Dokümanı v2.2 · §10 · bütçe tablosu ("Rastgele örneklem —
 * uzun kuyruk", aktif listing'lerin %2'si, üst sınır 500), §4 ·
 * `sync_states_observed_idx`.
 *
 * BU SINIFIN VARLIK NEDENİ — HİÇBİR TETİKLEYİCİSİ OLMAYAN SÜRÜKLENME:
 *   Sıcak ve ılık katmanların dört sebep sorgusu bir OLAY arar: taze satış,
 *   geçici hata, bekleyen iş, sürüklenme geçmişi. Bir listing bunların
 *   HİÇBİRİNE takılmadan sürüklenebilir — satıcı kanal panelinden stoğu
 *   elle değiştirir ve o ürün aylardır satmıyordur. O satır dört sorguda
 *   SONSUZA KADAR görünmez; yalnızca burada yakalanır.
 *
 * SIRALAMA `last_observed_at NULLS FIRST` — "RASTGELE" DEĞİL, EN ESKİ:
 *   Doküman kapsamı "rastgele örneklem" diye adlandırır ama §4 bu iş için
 *   AÇIKÇA `sync_states_observed_idx (domain, last_observed_at NULLS FIRST)`
 *   indeksini tanımlar; o indeksin başka hiçbir kullanıcısı yoktur.
 *   Gerçekten rastgele (`ORDER BY random()`) seçilseydi hem indeks
 *   kullanılamaz (her turda tam tarama) hem de bir satırın ne zaman
 *   bakılacağı garanti edilemezdi: %2'lik bütçeyle rastgele seçim,
 *   bazı satırların AYLARCA hiç seçilmemesi demektir. En eski gözlemden
 *   başlamak her satıra sırayla gelinmesini GARANTİ eder ve indeksi
 *   kullanır.
 *
 * `NULLS FIRST` ÖNEMLİDİR: `last_observed_at` NULL olan listing uzak durumu
 * HİÇ okunmamış olandır ve sürüklenmeye en açık satırdır. `NULLS LAST`
 * olsaydı hiç bakılmamış satırlar örneklemin SONUNA düşer ve dar bütçede
 * ASLA seçilmezlerdi — soğuk katman yalnızca zaten baktığı satırlara
 * tekrar bakardı.
 *
 * `DB::select()` GLOBAL SCOPE'A TABİ DEĞİLDİR — kiracı filtresi AÇIKÇA
 * yazılır. Yazılmazsa başka kiracının listing'i bu turda okunur.
 */
final class SampledCandidates
{
    /** Örneklem sebebi — dört sebep sorgusunun hiçbiri değil. */
    public const REASON = 'sampled';

    /**
     * Öncelik EN DÜŞÜKTÜR (dört sebep 100/90/80/70).
     *
     * Örneklem bir KANIT değil bir TARAMADIR: seçilme sebebi "bu satırda
     * bir şey oldu" değil "buna uzun süredir bakılmadı". Soğuk katman bu
     * sorguyu tek başına çalıştırdığı için öncelik bugün sıralamayı
     * değiştirmez; alan, kalemde sebep etiketiyle birlikte denetim izi
     * olarak yaşar.
     */
    private const PRIORITY = 60;

    /**
     * Bu bağlantı için örneklem adaylarını seçer.
     *
     * @return list<array{listing_id: string, reason: string, priority: int}>
     */
    public function for(
        ChannelConnection $connection,
        int $budget,
        SyncDomain $domain = SyncDomain::INVENTORY,
    ): array {
        if ($budget < 1) {
            return [];
        }

        $tenantId = TenantContext::idOrFail();

        $rows = DB::select(<<<'SQL'
            SELECT l.id AS listing_id
              FROM listings l
              LEFT JOIN listing_sync_states s
                     ON s.listing_id = l.id AND s.domain = ?
             WHERE l.tenant_id = ?
               AND l.channel_connection_id = ?
               AND l.lifecycle_status = 'live'
               AND coalesce(s.status, 'pending') <> 'error_permanent'
             ORDER BY s.last_observed_at ASC NULLS FIRST, l.id ASC
             LIMIT ?
        SQL, [$domain->value, $tenantId, $connection->id, $budget]);

        return array_map(static fn (object $row): array => [
            'listing_id' => $row->listing_id,
            'reason' => self::REASON,
            'priority' => self::PRIORITY,
        ], $rows);
    }

    /**
     * Bu bağlantıda kaç AKTİF (canlı) listing var — oransal bütçenin tabanı.
     *
     * SAYIM ÖRNEKLEM HAVUZUYLA AYNI YÜKLEMLERİ TAŞIR — `error_permanent`
     * DAHİL. Gerçek çalıştırmada bulundu: sayım o satırları içerip örneklem
     * hariç tutuyordu ve iki küme AYRIŞIYORDU. Kalıcı hataya düşmüş satırı
     * çok olan bir bağlantıda bütçe, gerçekte taranabilecek satır sayısının
     * ÜSTÜNE çıkar — "%2'sine bak" kuralı sessizce "%5'ine bak"a dönerdi ve
     * fark, oranın en çok korumak istediği yerde (büyük katalog, çok hatalı
     * satır) en büyük olurdu.
     *
     * Kiracı filtresi burada da AÇIKÇA yazılır: `DB::table()` global
     * scope'a tabi değildir ve sayım şişerse bütçe de şişer.
     */
    public function activeListingCount(
        ChannelConnection $connection,
        SyncDomain $domain = SyncDomain::INVENTORY,
    ): int {
        return DB::table('listings as l')
            ->leftJoin('listing_sync_states as s', function ($join) use ($domain): void {
                $join->on('s.listing_id', '=', 'l.id')
                    ->where('s.domain', '=', $domain->value);
            })
            ->where('l.tenant_id', TenantContext::idOrFail())
            ->where('l.channel_connection_id', $connection->id)
            ->where('l.lifecycle_status', 'live')
            ->whereRaw("coalesce(s.status, 'pending') <> 'error_permanent'")
            ->count();
    }

    /**
     * ORANSAL BÜTÇE — aktif listing'lerin %2'si, üst sınır `cap`.
     *
     * SABİT `cap` KULLANILMAZ: 50 listing'i olan bir bağlantıda sabit 500,
     * günlük turun katalogun TAMAMINI okuması demektir ve "tam katalog
     * taraması hiçbir katmanda yok" kuralı sessizce çiğnenirdi (§10).
     *
     * ALT SINIR 1: küçük kataloglarda %2 sıfıra yuvarlanır ve bütçe sıfır
     * olsaydı soğuk katman küçük satıcılar için HİÇ çalışmazdı — oysa uzun
     * kuyruk sorunu onlarda da vardır. Hiç listing yoksa iş de yoktur ve
     * bütçe gerçekten sıfırdır.
     */
    public function budgetFor(int $activeListings, int $cap): int
    {
        if ($activeListings < 1) {
            return 0;
        }

        $share = (int) floor($activeListings * ReconciliationScope::COLD->samplePercent() / 100);

        return max(1, min($share, $cap));
    }
}
