<?php

declare(strict_types=1);

namespace Tests\Feature\Observability;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Variant;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Models\ChannelType;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Actions\ApplyMovement;
use App\Domain\Inventory\Enums\MovementType;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Sync\Models\Listing;
use App\Support\Observability\CaptureMetrics;
use App\Support\Observability\Metric;
use App\Support\Observability\MetricScope;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Metrik toplama — saatlik anlık görüntüler.
 *
 * Mimari Karar Dokümanı v2.2 · §11 · Ölçülecek metrikler,
 * §13 · Faz 3 · madde 2, §15 · CaptureMetrics (saatlik, maintenance),
 * §17 · P0 · "On bir metrik ve uyarı — ölçülmeyen güvenilirlik iddia
 * edilemez", §4 · metric_snapshots.
 *
 * MADDENİN VARLIK SEBEBİ: sistem çalışıyor ama NE KADAR İYİ çalıştığı
 * hiçbir yerde görünmüyor. §17 bunu P0 listesine koyuyor çünkü ürünün
 * TEMEL İDDİASI güvenilirliktir ve ölçülmeyen bir iddia kanıtlanamaz:
 * senkron gecikmesi bir haftada iki katına çıksa kimse fark etmez.
 *
 * DEĞİŞMEZ KURAL — ANLIK GÖRÜNTÜ ALINIR, CANLI SORGU YAPILMAZ.
 *   Panel her açılışta on üç ağır toplama sorgusu koşturmaz: `p95`
 *   hesabı `sync_operations` üzerinde tam tarama demektir ve grafik
 *   GEÇMİŞ ister — canlı sorgu yalnızca ŞU ANI verir ve "gecikme
 *   artıyor mu" sorusunu asla cevaplayamaz.
 *
 * DEĞİŞMEZ KURAL — `metric_snapshots` KİRACIYA AİT DEĞİLDİR.
 *   §4 tabloyu `tenant_id` kolonu OLMADAN tanımlar; kapsam `scope`
 *   kolonunda metin olarak yaşar (`system` · `tenant:{id}` ·
 *   `connection:{id}`). Sebep: metriklerin çoğu SİSTEM GENELİDİR ve
 *   `tenant_id` zorunlu olsaydı onlara uydurma bir kiracı yazmak
 *   gerekirdi. Kiracıya özgü olanlar (fazla satış) kapsamı `scope`'ta
 *   taşır.
 *
 * DEĞİŞMEZ KURAL — TARAMA `runAsSystem()` İLE TÜM KİRACILARI GÖRÜR.
 *   Bağlam altında koşsaydı yalnızca bir kiracının verisini ölçer ve
 *   sistem geneli metrikler sessizce yanlış çıkardı.
 *
 * DEĞİŞMEZ KURAL — ÖLÇÜLEMEYEN METRİK SIFIR YAZMAZ.
 *   Veri yoksa (hiç operasyon tamamlanmamışsa p95 hesaplanamaz) satır
 *   HİÇ YAZILMAZ. Sıfır yazılsaydı grafik "gecikme sıfır, her şey
 *   mükemmel" derdi — oysa ölçüm YAPILAMADI. İkisi farklı şeydir ve
 *   sıfır, sessizce en iyi durumu iddia eder.
 */
final class CaptureMetricsTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------------------ şema

    /** Tablo kiracıya AİT DEĞİLDİR — §4 birebir. */
    #[Test]
    public function the_snapshot_table_has_no_tenant_column(): void
    {
        $columns = Schema::getColumnListing('metric_snapshots');

        sort($columns);

        $this->assertSame(['captured_at', 'id', 'metric', 'scope', 'value'], $columns);
    }

    /**
     * KAPSAM BİÇİMİ SÖZLEŞMEDİR VE DEĞİŞMEZ — VERİTABANINDA DONAR.
     *
     * `MetricScope::tenant()` hem YAZAN hem OKUYAN tarafta kullanılıyor;
     * önek değişse ikisi BİRLİKTE kayar ve davranış testleri yeşil
     * kalır. Ama tablo bir ZAMAN SERİSİDİR: eski satırlar eski önekle
     * yazılmıştır ve yeni okuyucu onları HİÇ BULAMAZ — grafik sessizce
     * sıfırlanır, üstelik kimse fark etmez çünkü yeni ölçümler gelmeye
     * devam eder.
     *
     * Bu yüzden biçim BEKLENEN METİNLE sınanır: mutasyon ancak burada
     * yakalanır. (Aynı gerekçe kuyruk adlarının Horizon yapılandırmasıyla
     * karşılaştırılmasında da var.)
     */
    #[Test]
    public function the_scope_format_is_a_frozen_contract(): void
    {
        $this->assertSame('system', MetricScope::SYSTEM);
        $this->assertSame('tenant:abc-123', MetricScope::tenant('abc-123'));
        $this->assertSame('connection:def-456', MetricScope::connection('def-456'));

        // Kimlik geri okunabilmeli: panel satırı hangi kanalı gösterdiğini
        // bu ayrıştırmayla bulur.
        $this->assertSame('abc-123', MetricScope::idOf(MetricScope::tenant('abc-123')));
        $this->assertNull(MetricScope::idOf(MetricScope::SYSTEM));
    }

    // ------------------------------------------------- senkron gecikmesi

    /**
     * STOK SENKRON GECİKMESİ p95 — §11'in ilk metriği.
     *
     * `completed_at - created_at`, yalnızca TAMAMLANMIŞ `INVENTORY_PUSH`
     * ve yalnızca SON BİR SAAT (§11 sorgusu birebir). Pencere olmasaydı
     * aylık geçmiş p95'i düzler ve bugünkü bozulma görünmezdi.
     */
    #[Test]
    public function it_captures_inventory_sync_latency_p95(): void
    {
        [$tenant, $listing] = $this->makeListing();

        // 1 sn · 2 sn · 60 sn — p95 en yüksek değere yaklaşır.
        $this->completedOperation($tenant, $listing, seconds: 1);
        $this->completedOperation($tenant, $listing, seconds: 2);
        $this->completedOperation($tenant, $listing, seconds: 60);

        $this->capture();

        $value = $this->snapshot(Metric::INVENTORY_SYNC_LATENCY_P95);

        $this->assertGreaterThan(50_000, $value, 'p95 milisaniye cinsinden en yüksek gecikmeye yaklaşmalı.');
        $this->assertLessThanOrEqual(60_000, $value);
    }

    /**
     * PENCERE DIŞI OPERASYON SAYILMAZ.
     *
     * Bir saatten eski satırlar dahil olsaydı metrik geçmişin
     * ortalamasına dönüşür ve bugünkü bozulmayı gizlerdi.
     */
    #[Test]
    public function latency_ignores_operations_older_than_an_hour(): void
    {
        [$tenant, $listing] = $this->makeListing();

        $this->completedOperation($tenant, $listing, seconds: 1);
        $this->completedOperation($tenant, $listing, seconds: 600, ageMinutes: 120);

        $this->capture();

        $this->assertLessThan(
            10_000,
            $this->snapshot(Metric::INVENTORY_SYNC_LATENCY_P95),
            'Bir saatten eski operasyon p95 hesabına girdi — geçmiş bugünü gizler.',
        );
    }

    /** Fiyat gönderimi stok gecikmesine karışmaz — §11 sorgusu türü filtreler. */
    #[Test]
    public function latency_only_counts_inventory_pushes(): void
    {
        [$tenant, $listing] = $this->makeListing();

        $this->completedOperation($tenant, $listing, seconds: 600, type: 'PRICE_PUSH');

        $this->capture();

        $this->assertNull(
            $this->snapshot(Metric::INVENTORY_SYNC_LATENCY_P95),
            'PRICE_PUSH stok gecikmesine karıştı.',
        );
    }

    /**
     * ÖLÇÜLEMEYEN METRİK SIFIR YAZMAZ — SATIR HİÇ YAZILMAZ.
     *
     * Sıfır yazılsaydı grafik "gecikme sıfır" derdi; oysa ölçüm
     * YAPILAMADI. Sessizce en iyi durumu iddia etmek, ölçmemekten
     * KÖTÜDÜR.
     */
    #[Test]
    public function a_metric_without_data_is_not_written_as_zero(): void
    {
        $this->capture();

        $this->assertNull(
            $this->snapshot(Metric::INVENTORY_SYNC_LATENCY_P95),
            'Veri yokken sıfır yazıldı — grafik "her şey mükemmel" der.',
        );
    }

    // ------------------------------------------------------- hata oranı

    /**
     * SENKRON HATA ORANI — başarısız denemelerin YÜZDESİ.
     *
     * Ham sayı değil ORAN ölçülür (§11 eşiği "%5 saatlik"): yüz
     * denemede beş hata ile on binde beş hata tamamen farklı sağlık
     * durumlarıdır ve ham sayı ikisini ayırt edemez.
     */
    #[Test]
    public function it_captures_the_sync_error_rate_as_a_percentage(): void
    {
        [$tenant, $listing] = $this->makeListing();

        $operation = $this->completedOperation($tenant, $listing, seconds: 1);

        $this->attempt($tenant, $operation, 1, 'success');
        $this->attempt($tenant, $operation, 2, 'success');
        $this->attempt($tenant, $operation, 3, 'transient');
        $this->attempt($tenant, $operation, 4, 'permanent');

        $this->capture();

        $this->assertSame(
            50.0,
            $this->snapshot(Metric::SYNC_ERROR_RATE),
            'Dört denemenin ikisi başarısız — oran %50 olmalı.',
        );
    }

    // ------------------------------------------------------------ outbox

    /**
     * OUTBOX YAYIN BİRİKMESİ — EN ESKİ yayınlanmamış olayın YAŞI.
     *
     * Sayı değil YAŞ ölçülür: bin olay bir saniyede yayınlanabiliyorsa
     * sorun yoktur, ama TEK bir olay altmış saniyedir bekliyorsa relay
     * durmuştur. Eşik de yaş cinsindendir (§11: "en eski yayınlanmamış
     * > 60 sn").
     */
    #[Test]
    public function it_captures_the_age_of_the_oldest_unpublished_event(): void
    {
        $tenant = $this->makeTenant();

        $this->outboxEvent($tenant, publishedAgo: null, createdAgo: 90);
        $this->outboxEvent($tenant, publishedAgo: null, createdAgo: 10);

        $this->capture();

        $this->assertGreaterThanOrEqual(
            89,
            $this->snapshot(Metric::OUTBOX_PUBLISH_LAG),
            'EN ESKİ yayınlanmamış olayın yaşı ölçülmeli, en yenisininki değil.',
        );
    }

    /** Yayınlanmış olay birikme metriğine girmez. */
    #[Test]
    public function published_events_do_not_count_as_publish_lag(): void
    {
        $tenant = $this->makeTenant();

        $this->outboxEvent($tenant, publishedAgo: 5, createdAgo: 300);

        $this->capture();

        $this->assertNull($this->snapshot(Metric::OUTBOX_PUBLISH_LAG));
    }

    /**
     * OUTBOX TÜKETİM BOŞLUĞU — yayınlandı ama tüketilmedi.
     *
     * Yayın birikmesinden AYRI bir metriktir ve ayrı bir arızayı
     * gösterir: relay çalışıyor ama tüketici çalışmıyor. Tek metriğe
     * sıkıştırılsalardı "hangi halka koptu" sorusu cevapsız kalırdı.
     */
    #[Test]
    public function it_captures_unconsumed_events_separately_from_publish_lag(): void
    {
        $tenant = $this->makeTenant();

        $this->outboxEvent($tenant, publishedAgo: 600, createdAgo: 600, consumed: false);

        $this->capture();

        $this->assertSame(1.0, $this->snapshot(Metric::OUTBOX_CONSUME_GAP));
        $this->assertNull(
            $this->snapshot(Metric::OUTBOX_PUBLISH_LAG),
            'Yayınlanmış olay yayın birikmesine sayıldı — iki arıza karıştı.',
        );
    }

    /**
     * TÜKETİLMİŞ OLAY BOŞLUĞA GİRMEZ — ama SIFIR YAZILIR.
     *
     * Bu metrikte sıfır ÖLÇÜMDÜR, ölçüm yapılamaması değil: "boşluk yok"
     * gerçek bir sağlık bilgisidir ve eşiği zaten sıfırdır (§11 · "> 0").
     * Yazılmasaydı grafikte boşluk oluşur ve satıcı sistemin ölçülmediği
     * mi yoksa sağlıklı mı olduğunu ayırt edemezdi — bu, p95'in tam
     * TERSİ durumdur ve ayrım bilinçlidir.
     */
    #[Test]
    public function consumed_events_do_not_count_as_a_gap(): void
    {
        $tenant = $this->makeTenant();

        $this->outboxEvent($tenant, publishedAgo: 600, createdAgo: 600, consumed: true);

        $this->capture();

        $this->assertSame(
            0.0,
            $this->snapshot(Metric::OUTBOX_CONSUME_GAP),
            'Tüketilmiş olay boşluk sayıldı.',
        );
    }

    // ------------------------------------------------------------- inbox

    /**
     * INBOX İŞLEME GECİKMESİ — bekleyen EN ESKİ mesajın yaşı.
     *
     * KAYBEDİLEN ŞEY SİPARİŞTİR: webhook 202 döndüğü için kanal yeniden
     * göndermez ve takılı bir mesaj sessizce sipariş kaybettirir.
     */
    #[Test]
    public function it_captures_the_age_of_the_oldest_pending_inbox_message(): void
    {
        $tenant = $this->makeTenant();

        $this->inboxMessage($tenant, status: 'pending', receivedAgo: 420);
        $this->inboxMessage($tenant, status: 'processed', receivedAgo: 9000);

        $this->capture();

        $value = $this->snapshot(Metric::INBOX_PROCESSING_LAG);

        $this->assertGreaterThanOrEqual(419, $value);
        $this->assertLessThan(
            9000,
            $value,
            'İşlenmiş mesaj gecikme hesabına girdi — metrik sonsuza kadar kırmızı kalırdı.',
        );
    }

    /**
     * INBOX KURTARMA ADAYI SAYISI.
     *
     * §11 bu metriği "Inbox kurtarma sayısı · günlük · saatte > 10"
     * diye tanımlıyor. Kurtarma sayısının KALICI bir kaynağı YOKTUR —
     * `RecoverPendingInbox` yalnızca uygulama günlüğüne yazar ve
     * günlükten metrik türetmek gözlemlenebilirliği log altyapısına
     * bağımlı kılardı. Bunun yerine AYNI SİNYALİ veren veritabanı
     * kaynağı ölçülür: kurtarmaya ADAY mesaj sayısı. Kurtarma
     * çalışıyorsa bu sayı düşer, çalışmıyorsa birikir; eşik ("saatte >
     * 10") aynı arızayı yakalar.
     */
    #[Test]
    public function it_captures_the_inbox_recovery_backlog(): void
    {
        $tenant = $this->makeTenant();

        $this->inboxMessage($tenant, status: 'pending', receivedAgo: 600);
        $this->inboxMessage($tenant, status: 'pending', receivedAgo: 600);
        // Taze bekleyen mesaj aday DEĞİLDİR: kuyrukta sırasını bekliyor
        // olabilir ve onu kurtarma gerektiren bir arıza saymak metrikleri
        // normal trafikte bile kırmızı gösterirdi.
        $this->inboxMessage($tenant, status: 'pending', receivedAgo: 5);

        $this->capture();

        $this->assertSame(2.0, $this->snapshot(Metric::INBOX_RECOVERY_BACKLOG));
    }

    // ------------------------------------------------------- teslim boşluğu

    /**
     * SYNC TESLİM BOŞLUĞU — operasyon var, worker hiç çalışmadı.
     *
     * §11: `attempt_count = 0` ve 5 dakikadan eski. Seviye 2 taramasının
     * ölçülen hâlidir: tarama kurtarır, metrik "ne sıklıkta kurtarmak
     * gerekiyor" sorusunu cevaplar.
     */
    #[Test]
    public function it_captures_operations_the_worker_never_picked_up(): void
    {
        [$tenant, $listing] = $this->makeListing();

        $this->pendingOperation($tenant, $listing, attempts: 0, ageMinutes: 10);
        // Denenmişse worker çalışmıştır: boşluk DEĞİLDİR.
        $this->pendingOperation($tenant, $listing, attempts: 2, ageMinutes: 10);
        // Taze operasyon henüz sırasını bekliyor olabilir.
        $this->pendingOperation($tenant, $listing, attempts: 0, ageMinutes: 1);

        $this->capture();

        $this->assertSame(1.0, $this->snapshot(Metric::SYNC_DELIVERY_GAP));
    }

    // --------------------------------------------------------- fazla satış

    /**
     * FAZLA SATIŞ KİRACI BAŞINA ÖLÇÜLÜR — §11 eşiği kiracı başınadır.
     *
     * Sistem geneli toplansaydı yüz kiracılı bir kurulumda tek bir
     * kiracının ciddi fazla satışı gürültüde kaybolurdu; eşik de
     * ("kiracı başına > 5 adet") uygulanamazdı.
     *
     * MİKTAR NEGATİF `available`'IN KENDİSİDİR — ayrı `oversold_qty`
     * sayacı YOKTUR (§5 · değişmez kural).
     */
    #[Test]
    public function oversell_is_measured_per_tenant(): void
    {
        [$tenantA, , $variantA] = $this->makeOversoldTenant(sell: 8, stock: 5);   // −3
        [$tenantB, , $variantB] = $this->makeOversoldTenant(sell: 12, stock: 2);  // −10

        $this->capture();

        $this->assertSame(
            3.0,
            $this->snapshot(Metric::OVERSOLD_UNITS, MetricScope::tenant($tenantA->id)),
        );
        $this->assertSame(
            10.0,
            $this->snapshot(Metric::OVERSOLD_UNITS, MetricScope::tenant($tenantB->id)),
        );

        $this->assertSame(
            1.0,
            $this->snapshot(Metric::OVERSOLD_VARIANTS, MetricScope::tenant($tenantA->id)),
        );

        // Sistem kapsamında yazılmaz: eşik kiracı başınadır.
        $this->assertNull($this->snapshot(Metric::OVERSOLD_UNITS));
    }

    /** Fazla satışı olmayan kiracı için satır yazılmaz — sıfır gürültüdür. */
    #[Test]
    public function a_tenant_without_oversell_gets_no_row(): void
    {
        $tenant = $this->makeTenant();

        $this->capture();

        $this->assertNull($this->snapshot(Metric::OVERSOLD_UNITS, MetricScope::tenant($tenant->id)));
    }

    // ------------------------------------------------------------- api

    /**
     * API GECİKMESİ p95 KANAL BAŞINA ölçülür — §11 eşiği kanal başınadır.
     *
     * Kanallar birleştirilseydi yavaş bir kanal hızlı olanın arkasına
     * saklanır ve "hangi kanal yavaş" sorusu cevapsız kalırdı.
     */
    #[Test]
    public function api_latency_is_measured_per_connection(): void
    {
        [$tenant, $listing] = $this->makeListing();
        $connectionId = $listing->channel_connection_id;

        $this->apiCall($tenant, $connectionId, durationMs: 100);
        $this->apiCall($tenant, $connectionId, durationMs: 8_000);

        $this->capture();

        $value = $this->snapshot(Metric::API_LATENCY_P95, MetricScope::connection($connectionId));

        $this->assertGreaterThan(7_000, $value);
    }

    /**
     * HIZ SINIRI İSABETİ — 429 sayısı, kanal başına.
     *
     * Ayrı bir metriktir: gecikme normalken 429 patlaması kotanın
     * dolduğunu söyler ve çözümü tamamen farklıdır (istek hızını
     * düşürmek, gecikmeyi kovalamak değil).
     */
    #[Test]
    public function it_counts_rate_limit_hits_per_connection(): void
    {
        [$tenant, $listing] = $this->makeListing();
        $connectionId = $listing->channel_connection_id;

        $this->apiCall($tenant, $connectionId, durationMs: 50, status: 429);
        $this->apiCall($tenant, $connectionId, durationMs: 50, status: 429);
        $this->apiCall($tenant, $connectionId, durationMs: 50, status: 200);

        $this->capture();

        $this->assertSame(
            2.0,
            $this->snapshot(Metric::RATE_LIMIT_HITS, MetricScope::connection($connectionId)),
        );
    }

    /** Bir saatten eski 429'lar sayılmaz — eşik "saatte > 100". */
    #[Test]
    public function rate_limit_hits_are_windowed_to_the_last_hour(): void
    {
        [$tenant, $listing] = $this->makeListing();
        $connectionId = $listing->channel_connection_id;

        $this->apiCall($tenant, $connectionId, durationMs: 50, status: 429, ageMinutes: 120);

        $this->capture();

        $this->assertNull($this->snapshot(Metric::RATE_LIMIT_HITS, MetricScope::connection($connectionId)));
    }

    // ------------------------------------------------------- sürüklenme

    /**
     * SÜRÜKLENME ORANI — kontrol edilenlerin YÜZDESİ.
     *
     * Ham sayı değil oran (§11 eşiği "günlük > %1"): elli listing'de üç
     * sürüklenme ile elli binde üç tamamen farklı sağlık durumlarıdır.
     */
    #[Test]
    public function it_captures_the_drift_rate_as_a_percentage(): void
    {
        [$tenant, $listing] = $this->makeListing();

        $this->reconciliationItem($tenant, $listing, 'MATCHED');
        $this->reconciliationItem($tenant, $listing, 'MATCHED');
        $this->reconciliationItem($tenant, $listing, 'MATCHED');
        $this->reconciliationItem($tenant, $listing, 'DRIFT_DETECTED');

        $this->capture();

        $this->assertSame(25.0, $this->snapshot(Metric::DRIFT_RATE));
    }

    /**
     * `REMOTE_UNREACHABLE` SÜRÜKLENME SAYILMAZ (§10 · değişmez kural).
     *
     * Fark KANITLANMAMIŞTIR; altyapı sorunudur. Sürüklenme sayılsaydı
     * bir ağ kesintisi oranı tavana vurdurur ve gerçek sürüklenme
     * sinyalini boğardı.
     */
    #[Test]
    public function unreachable_channels_do_not_count_as_drift(): void
    {
        [$tenant, $listing] = $this->makeListing();

        $this->reconciliationItem($tenant, $listing, 'MATCHED');
        $this->reconciliationItem($tenant, $listing, 'REMOTE_UNREACHABLE');

        $this->capture();

        $this->assertSame(
            0.0,
            $this->snapshot(Metric::DRIFT_RATE),
            'Okunamayan kanal sürüklenme sayıldı — ağ kesintisi oranı tavana vurdurur.',
        );
    }

    // ---------------------------------------------------------- ölü işler

    /**
     * ÖLÜ İŞ SAYISI KİRACI BAŞINA — §12'nin eşiği kiracı başınadır
     * ("kiracı başına 10'dan fazla ölü iş → e-posta bildirimi").
     */
    #[Test]
    public function dead_operations_are_counted_per_tenant(): void
    {
        [$tenantA, $listingA] = $this->makeListing();
        [$tenantB, $listingB] = $this->makeListing();

        $this->deadOperation($tenantA, $listingA);
        $this->deadOperation($tenantA, $listingA);
        $this->deadOperation($tenantB, $listingB);

        $this->capture();

        $this->assertSame(2.0, $this->snapshot(Metric::DEAD_OPERATIONS, MetricScope::tenant($tenantA->id)));
        $this->assertSame(1.0, $this->snapshot(Metric::DEAD_OPERATIONS, MetricScope::tenant($tenantB->id)));
    }

    // ------------------------------------------------------------ tarama

    /**
     * TARAMA TÜM KİRACILARI GÖRÜR.
     *
     * Bağlam altında koşsaydı yalnızca bir kiracının verisini ölçer ve
     * sistem geneli metrikler SESSİZCE yanlış çıkardı — üstelik hata
     * hiçbir yerde görünmezdi, yalnızca sayılar küçük olurdu.
     */
    #[Test]
    public function the_scan_sees_every_tenant(): void
    {
        [$tenantA, $listingA] = $this->makeListing();
        [$tenantB, $listingB] = $this->makeListing();

        $this->completedOperation($tenantA, $listingA, seconds: 5);
        $this->completedOperation($tenantB, $listingB, seconds: 5);

        // Bağlam KURULMADAN çalıştırılır: tarama kendi erişimini açar.
        $this->capture();

        $this->assertNotNull($this->snapshot(Metric::INVENTORY_SYNC_LATENCY_P95));
    }

    /** Aynı tur iki kez koşarsa iki AYRI anlık görüntü yazılır — geçmiş budur. */
    #[Test]
    public function each_run_appends_a_new_snapshot(): void
    {
        [$tenant, $listing] = $this->makeListing();

        $this->completedOperation($tenant, $listing, seconds: 5);

        $this->capture();
        $this->capture();

        $this->assertSame(
            2,
            DB::table('metric_snapshots')
                ->where('metric', Metric::INVENTORY_SYNC_LATENCY_P95->value)
                ->count(),
            'Anlık görüntü ÜZERİNE YAZILDI — geçmiş kayboldu ve grafik çizilemez.',
        );
    }

    /** Tur kaç satır yazdığını döner — komut kabuğu bunu basar. */
    #[Test]
    public function the_run_reports_how_many_snapshots_it_wrote(): void
    {
        [$tenant, $listing] = $this->makeListing();

        $this->completedOperation($tenant, $listing, seconds: 5);

        $written = TenantContext::runAsSystem(fn () => app(CaptureMetrics::class)->run());

        $this->assertSame(
            DB::table('metric_snapshots')->count(),
            $written,
        );
    }

    // --------------------------------------------------------------- komut

    /** Komut kayıtlı ve sıfırla çıkıyor. */
    #[Test]
    public function the_command_is_registered_and_runs(): void
    {
        $this->artisan('metrics:capture')->assertSuccessful();
    }

    /**
     * KOMUT GERÇEKTEN YAZIYOR — "var olduğunu" değil "ne yaptığını" sına.
     *
     * Bu projede bir komut kayıtlı, zamanlı ve sıfırla çıkıyor olduğu
     * hâlde YANLIŞ KATMANI sürüyordu ve üç test de bunu göremedi
     * (`reconcile:cold`). Komutun yazdığı satır okunur.
     */
    #[Test]
    public function the_command_actually_writes_snapshots(): void
    {
        [$tenant, $listing] = $this->makeListing();

        $this->completedOperation($tenant, $listing, seconds: 5);

        $this->artisan('metrics:capture')->assertSuccessful();

        $this->assertNotNull($this->snapshot(Metric::INVENTORY_SYNC_LATENCY_P95));
    }

    // -------------------------------------------------------------- yardım

    private function capture(): void
    {
        TenantContext::runAsSystem(fn () => app(CaptureMetrics::class)->run());
    }

    /** En son yazılan anlık görüntünün değeri. */
    private function snapshot(Metric $metric, ?string $scope = null): ?float
    {
        $value = DB::table('metric_snapshots')
            ->where('metric', $metric->value)
            ->where('scope', $scope ?? MetricScope::SYSTEM)
            ->orderByDesc('id')
            ->value('value');

        return $value === null ? null : (float) $value;
    }

    // --------------------------------------------------------- kurulum

    private function makeTenant(): Tenant
    {
        return (new CreateTenant)->run(
            name: 'Metrik '.uniqid(),
            owner: User::factory()->create(),
        );
    }

    /** Kiracının bir bağlantısı — yoksa yaratılır (inbox mesajı zorunlu tutar). */
    private function connectionFor(Tenant $tenant): string
    {
        return $this->asTenant($tenant, function () {
            $this->asSystem(fn () => ChannelType::query()->firstOrCreate(
                ['code' => 'woocommerce'],
                [
                    'name' => 'WooCommerce',
                    'kind' => 'storefront',
                    'adapter_class' => 'App\\Domain\\Channels\\Adapters\\WooCommerceAdapter',
                    'is_active' => true,
                ],
            ));

            return ChannelConnection::query()->value('id')
                ?? ChannelConnection::factory()->create()->id;
        });
    }

    /** @return array{0: Tenant, 1: Listing} */
    private function makeListing(): array
    {
        $tenant = $this->makeTenant();

        $listing = $this->asTenant($tenant, function () {
            $this->asSystem(fn () => ChannelType::query()->firstOrCreate(
                ['code' => 'woocommerce'],
                [
                    'name' => 'WooCommerce',
                    'kind' => 'storefront',
                    'adapter_class' => 'App\\Domain\\Channels\\Adapters\\WooCommerceAdapter',
                    'is_active' => true,
                ],
            ));

            $product = Product::factory()->create();

            return Listing::factory()->create([
                'channel_connection_id' => ChannelConnection::factory()->create()->id,
                'variant_id' => Variant::factory()->create(['product_id' => $product->id])->id,
                'lifecycle_status' => 'live',
            ]);
        });

        return [$tenant, $listing];
    }

    /**
     * Fazla satışı olan kiracı — bakiye LEDGER üzerinden eksiye düşürülür.
     *
     * `inventory_levels` doğrudan yazılsaydı `on_hand = Σ on_hand_delta`
     * eşitliği bozulur ve metrik gerçekte oluşamayacak bir durumu
     * ölçerdi.
     *
     * @return array{0: Tenant, 1: Listing, 2: Variant}
     */
    private function makeOversoldTenant(int $sell, int $stock): array
    {
        [$tenant, $listing] = $this->makeListing();

        $variant = $this->asTenant($tenant, function () use ($listing, $stock, $sell) {
            $variant = Variant::query()->findOrFail($listing->variant_id);
            $warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();

            $movement = app(ApplyMovement::class);

            $movement->run(
                warehouseId: $warehouse->id,
                variantId: $variant->id,
                type: MovementType::IMPORT,
                quantity: $stock,
                idempotencyKey: 'metrics:seed:'.$variant->id,
                sourceType: 'test_seed',
            );

            $movement->run(
                warehouseId: $warehouse->id,
                variantId: $variant->id,
                type: MovementType::SALE,
                quantity: $sell,
                idempotencyKey: 'metrics:sale:'.$variant->id,
                sourceType: 'test_sale',
            );

            return $variant;
        });

        return [$tenant, $listing, $variant];
    }

    private function completedOperation(
        Tenant $tenant,
        Listing $listing,
        int $seconds,
        int $ageMinutes = 0,
        string $type = 'INVENTORY_PUSH',
    ): string {
        $completedAt = now()->subMinutes($ageMinutes);

        return $this->insertOperation($tenant, $listing, [
            'operation_type' => $type,
            'status' => 'completed',
            'attempt_count' => 1,
            'created_at' => $completedAt->copy()->subSeconds($seconds),
            'completed_at' => $completedAt,
        ]);
    }

    private function pendingOperation(Tenant $tenant, Listing $listing, int $attempts, int $ageMinutes): string
    {
        return $this->insertOperation($tenant, $listing, [
            'status' => 'pending',
            'attempt_count' => $attempts,
            'created_at' => now()->subMinutes($ageMinutes),
        ]);
    }

    private function deadOperation(Tenant $tenant, Listing $listing): string
    {
        return $this->insertOperation($tenant, $listing, [
            'status' => 'dead',
            'attempt_count' => 5,
            'created_at' => now(),
        ]);
    }

    /** @param  array<string, mixed>  $overrides */
    private function insertOperation(Tenant $tenant, Listing $listing, array $overrides): string
    {
        $id = (string) Str::uuid7();

        DB::table('sync_operations')->insert(array_merge([
            'id' => $id,
            'tenant_id' => $tenant->id,
            'channel_connection_id' => $listing->channel_connection_id,
            'operation_type' => 'INVENTORY_PUSH',
            'intent' => 'NORMAL_SYNC',
            'entity_type' => 'listing',
            'entity_id' => $listing->id,
            'entity_version' => 1,
            'idempotency_key' => 'metric:'.uniqid('', true),
            'priority' => 0,
            'updated_at' => now(),
        ], $overrides));

        return $id;
    }

    private function attempt(Tenant $tenant, string $operationId, int $number, string $outcome): void
    {
        DB::table('sync_attempts')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenant->id,
            'sync_operation_id' => $operationId,
            'attempt_number' => $number,
            'outcome' => $outcome,
            'started_at' => now(),
            'finished_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function outboxEvent(
        Tenant $tenant,
        ?int $publishedAgo,
        int $createdAgo,
        bool $consumed = false,
    ): void {
        DB::table('outbox_events')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenant->id,
            'aggregate_type' => 'variant',
            'aggregate_id' => (string) Str::uuid7(),
            'event_type' => 'StockLevelChanged',
            'payload' => json_encode(['x' => 1]),
            'available_at' => now()->subSeconds($createdAgo),
            'published_at' => $publishedAgo === null ? null : now()->subSeconds($publishedAgo),
            'consumed_at' => $consumed ? now() : null,
            'publish_attempts' => 0,
            'created_at' => now()->subSeconds($createdAgo),
            'updated_at' => now(),
        ]);
    }

    private function inboxMessage(Tenant $tenant, string $status, int $receivedAgo): void
    {
        DB::table('inbox_messages')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenant->id,
            'channel_connection_id' => $this->connectionFor($tenant),
            'source' => 'webhook',
            'external_event_id' => uniqid('evt', true),
            'event_type' => 'order.created',
            'payload' => json_encode(['x' => 1]),
            'payload_hash' => hash('sha256', uniqid('', true)),
            'signature_valid' => true,
            'received_at' => now()->subSeconds($receivedAgo),
            'processed_at' => $status === 'processed' ? now() : null,
            'status' => $status,
            'attempt_count' => 0,
            'created_at' => now()->subSeconds($receivedAgo),
            'updated_at' => now(),
        ]);
    }

    private function apiCall(
        Tenant $tenant,
        string $connectionId,
        int $durationMs,
        int $status = 200,
        int $ageMinutes = 0,
    ): void {
        DB::table('api_calls')->insert([
            'tenant_id' => $tenant->id,
            'channel_connection_id' => $connectionId,
            'method' => 'POST',
            'endpoint' => '/products/batch',
            'status_code' => $status,
            'duration_ms' => $durationMs,
            'called_at' => now()->subMinutes($ageMinutes),
            'expires_at' => now()->addDays(7),
        ]);
    }

    private function reconciliationItem(Tenant $tenant, Listing $listing, string $status): void
    {
        $runId = (string) Str::uuid7();

        DB::table('reconciliation_runs')->insert([
            'id' => $runId,
            'tenant_id' => $tenant->id,
            'channel_connection_id' => $listing->channel_connection_id,
            'scope' => 'hot',
            'status' => 'completed',
            'started_at' => now(),
            'finished_at' => now(),
            'checked_count' => 1,
            'drift_count' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('reconciliation_items')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenant->id,
            'reconciliation_run_id' => $runId,
            'listing_id' => $listing->id,
            'domain' => 'INVENTORY',
            'priority_reason' => 'recently_sold',
            'status' => $status,
            'checked_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
