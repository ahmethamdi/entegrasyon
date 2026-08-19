<?php

declare(strict_types=1);

namespace App\Support\Observability;

use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Saatlik metrik anlık görüntüsü — §11'in on üç metriği.
 *
 * Mimari Karar Dokümanı v2.2 · §11 · Ölçülecek metrikler,
 * §13 · Faz 3 · madde 2, §15 · CaptureMetrics (saatlik, maintenance),
 * §17 · P0 · "On bir metrik ve uyarı — ölçülmeyen güvenilirlik iddia
 * edilemez", §4 · metric_snapshots.
 *
 * KAPATILAN BOŞLUK: sistem çalışıyordu ama NE KADAR İYİ çalıştığı hiçbir
 * yerde görünmüyordu. Senkron gecikmesi bir haftada iki katına çıksa,
 * hata oranı %1'den %8'e tırmansa veya bir kanal sürekli 429 yese kimse
 * fark etmezdi — arıza ancak müşteri şikâyet edince görünürdü. §17 bunu
 * P0 listesine koyuyor: ürünün TEMEL İDDİASI güvenilirliktir ve
 * ölçülmeyen bir iddia kanıtlanamaz.
 *
 * DEĞİŞMEZ KURAL — ANLIK GÖRÜNTÜ ALINIR, PANEL CANLI SORGU YAPMAZ.
 *   Bu sorgular ağırdır (`percentile_cont` tam tarama ister) ve panel
 *   her açılışta on üçünü koşturamaz. Daha önemlisi grafik GEÇMİŞ
 *   ister: canlı sorgu yalnızca ŞU ANI verir ve "gecikme artıyor mu"
 *   sorusunu ASLA cevaplayamaz. Tablo bu yüzden bir ZAMAN SERİSİDİR ve
 *   her tur ÜZERİNE YAZMAZ, EKLER.
 *
 * DEĞİŞMEZ KURAL — ÖLÇÜLEMEYEN METRİK SIFIR YAZMAZ.
 *   Hiç tamamlanmış operasyon yoksa p95 hesaplanamaz ve satır HİÇ
 *   YAZILMAZ. Sıfır yazılsaydı grafik "gecikme sıfır, her şey mükemmel"
 *   derdi — oysa ölçüm YAPILAMADI. Sessizce en iyi durumu iddia etmek,
 *   hiç ölçmemekten KÖTÜDÜR: yanlış bir güven verir.
 *
 * DEĞİŞMEZ KURAL — TARAMA `runAsSystem()` İLE TÜM KİRACILARI GÖRÜR.
 *   Bağlam altında koşsaydı yalnızca bir kiracının verisini ölçer ve
 *   sistem geneli metrikler sessizce yanlış çıkardı — hata hiçbir yerde
 *   görünmez, yalnızca sayılar küçük olurdu. Sorgular ham
 *   `DB::table()` kullandığı için global scope zaten uygulanmıyor;
 *   sarmalayıcı niyeti belgeler ve bu tablolara bir gün model
 *   eklenirse taramanın tek kiracıya daralmasını önler.
 *
 * DEĞİŞMEZ KURAL — PENCERELER SABİTTİR VE §11'DEN GELİR.
 *   Gecikme ve hata oranı SON BİR SAAT, sürüklenme SON BİR GÜN. Pencere
 *   olmasaydı aylık geçmiş bugünkü bozulmayı düzler ve metrik hiçbir
 *   zaman alarm vermezdi.
 *
 * `clock_timestamp()` KULLANILIR, `now()` DEĞİL. Bu projenin tekrar eden
 * tuzağı: PostgreSQL'de `now()` transaction'ın BAŞLAMA anını döndürür ve
 * donmuş kalır. Tur transaction dışında koşuyor ama kural burada da
 * geçerlidir — sorgular ileride bir transaction'a sarılırsa pencereler
 * sessizce kayardı.
 */
final class CaptureMetrics
{
    /** §11 · gecikme ve hata oranı penceresi. */
    private const HOUR_WINDOW = '1 hour';

    /** §11 · sürüklenme oranı penceresi ("günlük > %1"). */
    private const DAY_WINDOW = '1 day';

    /** §11 · teslim boşluğu eşiği ("5 dk üstü > 0"). */
    private const DELIVERY_GAP_MINUTES = 5;

    /**
     * §11 · inbox kurtarma adayı eşiği.
     *
     * `RecoverPendingInbox` varsayılan olarak 2 dakikadan eski bekleyen
     * mesajları alır; metrik AYNI eşiği kullanır. Farklı olsaydı metrik
     * "kurtarılacak on mesaj var" derken tarama hiçbirini almaz ya da
     * tersi olurdu.
     */
    private const RECOVERY_CANDIDATE_MINUTES = 2;

    /**
     * Tek tur: on üç metriği ölçer ve `metric_snapshots`'a yazar.
     *
     * @return int Yazılan anlık görüntü sayısı
     */
    public function run(): int
    {
        return TenantContext::runAsSystem(function (): int {
            $rows = [];

            $this->collectSystemMetrics($rows);
            $this->collectTenantMetrics($rows);
            $this->collectConnectionMetrics($rows);

            if ($rows === []) {
                return 0;
            }

            // TEK INSERT: on üç ayrı INSERT, saatlik bir işte gereksiz
            // gidiş-dönüş demektir ve satırların ZAMAN DAMGALARI birbirinden
            // ayrışırdı — aynı turun ölçümleri aynı ana ait olmalı, yoksa
            // panel grafiği metrikleri hizalayamaz.
            DB::table('metric_snapshots')->insert($rows);

            return count($rows);
        });
    }

    // ────────────────────────────────────────────────── sistem geneli

    /** @param  list<array<string, mixed>>  $rows */
    private function collectSystemMetrics(array &$rows): void
    {
        $this->push($rows, Metric::INVENTORY_SYNC_LATENCY_P95, $this->inventoryLatencyP95());
        $this->push($rows, Metric::SYNC_ERROR_RATE, $this->syncErrorRate());
        $this->push($rows, Metric::OUTBOX_PUBLISH_LAG, $this->outboxPublishLag());
        $this->push($rows, Metric::OUTBOX_CONSUME_GAP, $this->outboxConsumeGap());
        $this->push($rows, Metric::INBOX_PROCESSING_LAG, $this->inboxProcessingLag());
        $this->push($rows, Metric::INBOX_RECOVERY_BACKLOG, $this->inboxRecoveryBacklog());
        $this->push($rows, Metric::SYNC_DELIVERY_GAP, $this->syncDeliveryGap());
        $this->push($rows, Metric::DRIFT_RATE, $this->driftRate());
    }

    /**
     * Stok senkronunun uçtan uca gecikmesi — §11'in sorgusu birebir.
     *
     * `completed_at - created_at` ÖLÇÜLÜR, deneme süresi değil: satıcıyı
     * ilgilendiren "stok değişti, kanala ne kadar sürede ulaştı"
     * sorusudur. Yalnızca tek denemenin süresi ölçülseydi üç kez
     * yeniden denenip sonunda başaran bir operasyon HIZLI görünürdü.
     */
    private function inventoryLatencyP95(): ?float
    {
        return $this->scalar(<<<'SQL'
            SELECT percentile_cont(0.95) WITHIN GROUP (
                       ORDER BY extract(epoch FROM (completed_at - created_at)) * 1000
                   ) AS value
              FROM sync_operations
             WHERE operation_type = 'INVENTORY_PUSH'
               AND status = 'completed'
               AND completed_at IS NOT NULL
               AND completed_at > clock_timestamp() - ?::interval
        SQL, [self::HOUR_WINDOW]);
    }

    /**
     * Başarısız denemelerin YÜZDESİ — ham sayı değil.
     *
     * §11 eşiği "%5 saatlik". Ham sayı ölçülseydi yüz denemede beş hata
     * ile on binde beş hata aynı görünürdü; oysa biri ciddi bir arıza,
     * diğeri normal gürültüdür.
     *
     * Payda SIFIRSA satır YAZILMAZ (`NULLIF`): hiç deneme yoksa hata
     * oranı TANIMSIZDIR ve sıfır yazmak "hiç hata yok" derdi.
     */
    private function syncErrorRate(): ?float
    {
        return $this->scalar(<<<'SQL'
            SELECT 100.0
                     * count(*) FILTER (WHERE outcome <> 'success')
                     / NULLIF(count(*), 0) AS value
              FROM sync_attempts
             WHERE started_at > clock_timestamp() - ?::interval
        SQL, [self::HOUR_WINDOW]);
    }

    /**
     * En eski yayınlanmamış olayın YAŞI, saniye.
     *
     * SAYI DEĞİL YAŞ ölçülür: bin olay saniyede yayınlanabiliyorsa sorun
     * yoktur, ama TEK bir olay altmış saniyedir bekliyorsa relay
     * durmuştur. §11 eşiği de yaş cinsindendir.
     *
     * `available_at` OKUNUR, `created_at` değil: gecikmeli planlanmış bir
     * olay (yeniden deneme geri çekilmesi) henüz zamanı gelmediği için
     * bekliyor olabilir ve onu birikme saymak metriği normal işleyişte
     * bile kırmızı gösterirdi.
     */
    private function outboxPublishLag(): ?float
    {
        return $this->scalar(<<<'SQL'
            SELECT extract(epoch FROM (clock_timestamp() - min(available_at))) AS value
              FROM outbox_events
             WHERE published_at IS NULL
               AND available_at <= clock_timestamp()
        SQL);
    }

    /**
     * Yayınlandığı hâlde tüketilmemiş olay sayısı.
     *
     * Yayın birikmesinden AYRI bir metriktir ve AYRI bir arızayı
     * gösterir: relay çalışıyor ama tüketici çalışmıyor. Tek metriğe
     * sıkıştırılsalardı "hangi halka koptu" sorusu cevapsız kalır ve
     * §6'nın iki ayrı taraması gibi bunlar da ayrı kalmalıdır.
     *
     * Sıfır ANLAMLIDIR ve yazılır: "boşluk yok" bir ölçümdür, ölçüm
     * yapılamaması değil. Eşik de zaten sıfırdır (§11 · "> 0").
     */
    private function outboxConsumeGap(): float
    {
        return (float) $this->scalar(<<<'SQL'
            SELECT count(*) AS value
              FROM outbox_events
             WHERE published_at IS NOT NULL
               AND consumed_at IS NULL
        SQL);
    }

    /**
     * Bekleyen en eski inbox mesajının yaşı, saniye.
     *
     * KAYBEDİLEN ŞEY SİPARİŞTİR: webhook 202 döndüğü için kanal yeniden
     * göndermez ve takılı bir mesaj sessizce sipariş kaybettirir.
     */
    private function inboxProcessingLag(): ?float
    {
        return $this->scalar(<<<'SQL'
            SELECT extract(epoch FROM (clock_timestamp() - min(received_at))) AS value
              FROM inbox_messages
             WHERE status = 'pending'
        SQL);
    }

    /**
     * Kurtarmaya ADAY bekleyen mesaj sayısı.
     *
     * §11 bu metriği "Inbox kurtarma sayısı" diye adlandırıyor ama
     * kurtarma sayısının KALICI bir kaynağı yoktur: `RecoverPendingInbox`
     * yalnızca uygulama günlüğüne yazar ve günlükten metrik türetmek
     * gözlemlenebilirliği log altyapısına bağımlı kılardı. AYNI SİNYALİ
     * veren veritabanı kaynağı ölçülür — kurtarma çalışıyorsa bu sayı
     * düşer, çalışmıyorsa birikir.
     *
     * Eşik `RecoverPendingInbox`'ınkiyle AYNIDIR (2 dk); ayrışsalardı
     * metrik "on mesaj kurtarılacak" derken tarama hiçbirini almazdı.
     */
    private function inboxRecoveryBacklog(): float
    {
        return (float) $this->scalar(<<<'SQL'
            SELECT count(*) AS value
              FROM inbox_messages
             WHERE status = 'pending'
               AND received_at < clock_timestamp() - ?::interval
        SQL, [self::RECOVERY_CANDIDATE_MINUTES.' minutes']);
    }

    /**
     * Worker'ın hiç almadığı operasyon sayısı — seviye 2'nin ölçümü.
     *
     * `attempt_count = 0` ve 5 dakikadan eski (§11). Tarama bunları
     * kurtarır; metrik "ne sıklıkta kurtarmak gerekiyor" sorusunu
     * cevaplar. Sürekli sıfırdan büyükse Redis iş kaybediyordur ve bu,
     * taramanın gizlediği bir altyapı sorunudur.
     *
     * `sync_ops_never_attempted_idx` kısmi indeksi bu sorguyu besler.
     */
    private function syncDeliveryGap(): float
    {
        return (float) $this->scalar(<<<'SQL'
            SELECT count(*) AS value
              FROM sync_operations
             WHERE status = 'pending'
               AND attempt_count = 0
               AND created_at < clock_timestamp() - ?::interval
        SQL, [self::DELIVERY_GAP_MINUTES.' minutes']);
    }

    /**
     * Sürüklenen listing'lerin kontrol edilenlere ORANI.
     *
     * §11 eşiği "günlük > %1". Ham sayı ölçülseydi elli listing'de üç
     * sürüklenme ile elli binde üç aynı görünürdü.
     *
     * `REMOTE_UNREACHABLE` SÜRÜKLENME SAYILMAZ (§10 · değişmez kural):
     * fark KANITLANMAMIŞTIR ve altyapı sorunudur. Sayılsaydı tek bir ağ
     * kesintisi oranı tavana vurdurur ve gerçek sürüklenme sinyalini
     * boğardı. Paydada KALIR: kontrol edilmiştir.
     */
    private function driftRate(): ?float
    {
        return $this->scalar(<<<'SQL'
            SELECT 100.0
                     * count(*) FILTER (WHERE status IN ('DRIFT_DETECTED', 'REPAIR_QUEUED', 'MANUAL_REVIEW'))
                     / NULLIF(count(*), 0) AS value
              FROM reconciliation_items
             WHERE checked_at > clock_timestamp() - ?::interval
        SQL, [self::DAY_WINDOW]);
    }

    // ─────────────────────────────────────────────────── kiracı başına

    /**
     * Kiracı başına ölçülen metrikler.
     *
     * §11'in eşikleri kiracı başınadır ("kiracı başına > 5 adet").
     * Sistem geneli toplansaydı yüz kiracılı bir kurulumda tek bir
     * kiracının ciddi fazla satışı gürültüde kaybolur ve eşik hiçbir
     * zaman uygulanamazdı.
     *
     * SIFIR OLAN KİRACI İÇİN SATIR YAZILMAZ: sorunsuz kiracılar tabloyu
     * saatlik doldurur, geçmiş sorguları yavaşlar ve panel binlerce
     * "0" satırı arasında gerçek sinyali kaybederdi.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function collectTenantMetrics(array &$rows): void
    {
        // Fazla satış: negatif `available`'ın KENDİSİ eksik miktardır.
        // Ayrı bir `oversold_qty` sayacı YOKTUR (§5 · değişmez kural) ve
        // bu sorgu tek gerçek kaynaktır. `inventory_levels_oversold_idx`
        // kısmi indeksini kullanır.
        $oversold = DB::select(<<<'SQL'
            SELECT tenant_id,
                   count(*)        AS variants,
                   sum(-available) AS units
              FROM inventory_levels
             WHERE available < 0
             GROUP BY tenant_id
        SQL);

        foreach ($oversold as $row) {
            $scope = MetricScope::tenant($row->tenant_id);

            $this->push($rows, Metric::OVERSOLD_UNITS, (float) $row->units, $scope);
            $this->push($rows, Metric::OVERSOLD_VARIANTS, (float) $row->variants, $scope);
        }

        // Ölü işler — §12'nin eşiği de kiracı başınadır
        // ("kiracı başına 10'dan fazla ölü iş → e-posta bildirimi").
        $dead = DB::select(<<<'SQL'
            SELECT tenant_id, count(*) AS total
              FROM sync_operations
             WHERE status = 'dead'
             GROUP BY tenant_id
        SQL);

        foreach ($dead as $row) {
            $this->push(
                $rows,
                Metric::DEAD_OPERATIONS,
                (float) $row->total,
                MetricScope::tenant($row->tenant_id),
            );
        }
    }

    // ──────────────────────────────────────────────────── kanal başına

    /**
     * Kanal başına ölçülen metrikler.
     *
     * §11'in eşikleri kanal başınadır ("kanal başına > 5 sn").
     * Kanallar birleştirilseydi yavaş bir kanal hızlı olanın arkasına
     * saklanır ve "hangi kanal yavaş" sorusu cevapsız kalırdı — oysa
     * eylem tam olarak o kanala özgüdür.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function collectConnectionMetrics(array &$rows): void
    {
        $latency = DB::select(<<<'SQL'
            SELECT channel_connection_id,
                   percentile_cont(0.95) WITHIN GROUP (ORDER BY duration_ms) AS p95,
                   count(*) FILTER (WHERE status_code = 429)                 AS rate_limited
              FROM api_calls
             WHERE called_at > clock_timestamp() - ?::interval
               AND duration_ms IS NOT NULL
             GROUP BY channel_connection_id
        SQL, [self::HOUR_WINDOW]);

        foreach ($latency as $row) {
            $scope = MetricScope::connection($row->channel_connection_id);

            $this->push($rows, Metric::API_LATENCY_P95, $this->toFloat($row->p95), $scope);

            // Sıfır 429 GÜRÜLTÜDÜR: sağlıklı kanal her saat bir "0"
            // satırı yazsaydı tablo kanal sayısı × 24 satırla dolardı.
            if ((int) $row->rate_limited > 0) {
                $this->push($rows, Metric::RATE_LIMIT_HITS, (float) $row->rate_limited, $scope);
            }
        }
    }

    // ─────────────────────────────────────────────────────── yardımcı

    /**
     * Ölçülen değeri satır listesine ekler.
     *
     * NULL DEĞER SATIR ÜRETMEZ — ölçüm yapılamadı demektir ve sıfır
     * yazmak "her şey mükemmel" iddiasında bulunurdu.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function push(array &$rows, Metric $metric, ?float $value, ?string $scope = null): void
    {
        if ($value === null) {
            return;
        }

        $rows[] = [
            'metric' => $metric->value,
            'scope' => $scope ?? MetricScope::SYSTEM,
            'value' => $value,
            'captured_at' => now(),
        ];
    }

    /**
     * Tek değerli sorgu — sonuç yoksa veya NULL'sa NULL döner.
     *
     * @param  list<mixed>  $bindings
     */
    private function scalar(string $sql, array $bindings = []): ?float
    {
        $row = DB::selectOne($sql, $bindings);

        return $this->toFloat($row?->value ?? null);
    }

    private function toFloat(mixed $value): ?float
    {
        return $value === null ? null : (float) $value;
    }
}
