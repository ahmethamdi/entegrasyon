<?php

declare(strict_types=1);

namespace Tests\Feature\Reconciliation;

use App\Domain\Catalog\Models\Variant;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Models\ChannelType;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Actions\ApplyMovement;
use App\Domain\Inventory\Enums\MovementType;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Reconciliation\Actions\ReconcileConnection;
use App\Domain\Reconciliation\Enums\ReconciliationScope;
use App\Domain\Reconciliation\Models\ReconciliationItem;
use App\Domain\Reconciliation\Models\ReconciliationRun;
use App\Domain\Reconciliation\Support\SampledCandidates;
use App\Domain\Sync\Enums\SyncDomain;
use App\Domain\Sync\Models\Listing;
use App\Domain\Sync\Models\ListingSyncState;
use App\Domain\Sync\Models\SyncOperation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Channels\ProgrammableInventoryAdapter;
use Tests\TestCase;

/**
 * §10 · ILIK VE SOĞUK MUTABAKAT KATMANLARI.
 *
 * Mimari Karar Dokümanı v2.2 · §10 · bütçe tablosu, §15 · zamanlanmış işler.
 *
 *   KATMAN   SIKLIK    KAPSAM                                BÜTÇE
 *   Sıcak    5 dakika  Son 30 dk satış · geçici hata ·       ≤ 50 / bağlantı
 *                      1 saattir bekleyen
 *   Ilık     Saatlik   Son 24 saat satış · 24 saattir        ≤ 300 / bağlantı
 *                      bakılmamış
 *   Soğuk    Günlük    Rastgele örneklem — uzun kuyruk       %2, üst sınır 500
 *
 * BU DOSYANIN VARLIK NEDENİ — SICAK KATMANIN GÖREMEDİĞİ SATIR:
 *   Sıcak katman DÖRT tetikleyiciye bakar: taze satış, geçici hata, bekleyen
 *   iş, sürüklenme geçmişi. Bir listing bunların HİÇBİRİNE takılmadan
 *   sürüklenebilir — satıcı kanal panelinden stoğu elle değiştirir ve o ürün
 *   aylardır satmıyordur. O satır sıcak katmanda SONSUZA KADAR görünmez.
 *   Soğuk katmanın örneklemi tam olarak o satırı yakalamak için vardır ve
 *   `sync_states_observed_idx` indeksinin TEK varlık sebebidir.
 *
 * DEĞİŞMEZ KURAL — TAM KATALOG TARAMASI HİÇBİR KATMANDA YOKTUR:
 *   Soğuk katmanın bütçesi ORANSALDIR (aktif listing'lerin %2'si), 500 ise
 *   yalnızca üst sınır. Sabit 500 kullanılsaydı 50 listing'i olan bir
 *   bağlantıda günlük tur katalogun TAMAMINI okurdu.
 *
 * DEĞİŞMEZ KURAL — `error_permanent` HİÇBİR KATMANDA ADAY DEĞİLDİR:
 *   Kural sıcak katmana özgü değildir; katman genişledikçe unutulması en
 *   kolay yer tam da burasıdır. Soğuk katmanın örneklemi sıralamayı
 *   `last_observed_at`'e göre yapar ve hiç bakılmamış satır BAŞA gelir —
 *   yani filtresiz bir örneklem, düzeltilemeyen satırları öncelikle seçerdi.
 */
final class ReconciliationLayersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        ProgrammableInventoryAdapter::reset();
    }

    protected function tearDown(): void
    {
        ProgrammableInventoryAdapter::reset();

        parent::tearDown();
    }

    // ---------------------------------------------------------------- ılık

    /**
     * ILIK KATMAN SICAK KATMANIN GÖRMEDİĞİ SATIŞI GÖRÜR.
     *
     * Altı saat önce satılan bir varyant sıcak katmanın 30 dakikalık
     * penceresinin DIŞINDADIR. Ilık katmanın 24 saatlik penceresi onu
     * kapsar; kapsamasaydı o satır ancak günlük soğuk turun rastgele
     * örneklemine düşerse görülürdü.
     */
    #[Test]
    public function warm_layer_sees_sales_older_than_the_hot_window(): void
    {
        [$tenant, $variant, $connection] = $this->makeContext();

        $this->listing($tenant, $variant, $connection, externalId: '10');

        $this->seedStock($tenant, $variant, 20);
        $this->sellAgo($tenant, $variant, hoursAgo: 6);

        ProgrammableInventoryAdapter::remoteQuantity('woocommerce', '10', 19);

        $hot = $this->reconcile($tenant, $connection, ReconciliationScope::HOT);

        $this->assertSame(
            0,
            $hot->candidates_count,
            'Altı saat önceki satış SICAK katmanın 30 dakikalık penceresine girmemeli.',
        );

        $warm = $this->reconcile($tenant, $connection, ReconciliationScope::WARM);

        $this->assertSame(
            1,
            $warm->candidates_count,
            'Altı saat önceki satış ILIK katmanın 24 saatlik penceresine girmeli.',
        );
    }

    /**
     * ILIK KATMAN, SICAK KATMANIN ESKİ SAYDIĞI BEKLEYEN İŞİ ELER.
     *
     * `stale_sync` eşiği sıcakta 1 saat, ılıkta 24 saattir. İki saattir
     * bekleyen bir satır sıcak katmanda ADAYDIR (her beş dakikada bir
     * görülür) ama ılıkta değildir: aynı eşik kullanılsaydı ılık turun
     * 300'lük bütçesi sıcak katmanın çoktan baktığı satırlarla dolar ve
     * ılık katman hiçbir şey EKLEMEZDİ.
     */
    #[Test]
    public function warm_layer_uses_a_wider_pending_threshold_than_hot(): void
    {
        [$tenant, $variant, $connection] = $this->makeContext();

        $listing = $this->listing($tenant, $variant, $connection, externalId: '10');

        $this->seedStock($tenant, $variant, 9);
        $this->staleState($tenant, $listing, pendingSinceHours: 2);

        ProgrammableInventoryAdapter::remoteQuantity('woocommerce', '10', 9);

        $hot = $this->reconcile($tenant, $connection, ReconciliationScope::HOT);

        $this->assertSame(
            1,
            $hot->candidates_count,
            'İki saattir bekleyen satır SICAK katmanın 1 saatlik eşiğini geçer.',
        );

        $warm = $this->reconcile($tenant, $connection, ReconciliationScope::WARM);

        $this->assertSame(
            0,
            $warm->candidates_count,
            'İki saattir bekleyen satır ILIK katmanın 24 saatlik eşiğini GEÇMEZ.',
        );
    }

    /**
     * Ilık katman bütçesi 300'dür — sıcak katmanın 50'si değil.
     */
    #[Test]
    public function warm_layer_budget_is_three_hundred(): void
    {
        $this->assertSame(50, ReconciliationScope::HOT->budget());
        $this->assertSame(300, ReconciliationScope::WARM->budget());
        $this->assertSame(500, ReconciliationScope::COLD->budget());
    }

    /**
     * Ilık katman da sürüklenmeyi ONARIR — beş adım YENİDEN KULLANILIR.
     *
     * Katmanlar arasında değişen tek şey ADAY SEÇİMİ ve BÜTÇEDİR;
     * DETECT/RECORD/CLASSIFY/REPAIR/VERIFY akışı aynıdır. Ilık katman
     * yalnızca "tespit edip rapor etseydi" sürüklenme bulunur ama
     * düzeltilmezdi.
     */
    #[Test]
    public function warm_layer_repairs_drift_like_the_hot_layer(): void
    {
        [$tenant, $variant, $connection] = $this->makeContext();

        $listing = $this->listing($tenant, $variant, $connection, externalId: '10');

        $this->seedStock($tenant, $variant, 30);
        $this->sellAgo($tenant, $variant, hoursAgo: 6);          // 30 → 29

        ProgrammableInventoryAdapter::remoteQuantity('woocommerce', '10', 99);

        $run = $this->reconcile($tenant, $connection, ReconciliationScope::WARM);

        $this->assertSame(1, $run->drift_count);

        $item = $this->itemFor($tenant, $listing);

        $this->assertSame('REPAIR_QUEUED', $item->status);

        $this->assertSame(
            1,
            $this->asTenant($tenant, fn (): int => SyncOperation::query()->count()),
            'Ilık katman da onarım operasyonu AÇAR.',
        );
    }

    /** Tur `scope` alanına katman adını yazar — denetim izinin tek kaynağı. */
    #[Test]
    public function the_run_records_which_layer_produced_it(): void
    {
        [$tenant, , $connection] = $this->makeContext();

        $warm = $this->reconcile($tenant, $connection, ReconciliationScope::WARM);
        $cold = $this->reconcile($tenant, $connection, ReconciliationScope::COLD);

        $this->assertSame('warm', $warm->scope);
        $this->assertSame('cold', $cold->scope);
    }

    /**
     * HER KOMUT KENDİ KATMANINI SÜRER.
     *
     * BU TEST BİR MUTASYONUN ARDINDAN EKLENDİ: `reconcile:cold` komutunun
     * gövdesindeki scope `WARM`'a çevrildiğinde HİÇBİR TEST KIRILMIYORDU.
     * Komut kayıtlıydı, zamanlanmıştı, sıfırla çıkıyordu ve sweeper'ı
     * gerçekten çağırıyordu — yalnızca YANLIŞ KATMANI sürüyordu. Sonuç:
     * uzun kuyruk hiç taranmaz, tetikleyicisi olmayan sürüklenme sonsuza
     * kadar görünmez ve `schedule:list` kusursuz görünür.
     *
     * Kayıt testi, frekans testi ve "başarıyla çalışır" testi bunu
     * GÖREMEZ: üçü de komutun NE YAPTIĞINI değil, VAR OLDUĞUNU sınar.
     * Katmanın kimliği yalnızca yazılan turun `scope` alanında görünür.
     *
     * Komut GERÇEKTEN çalıştırılır (reflection'a sapılmaz) ve tüm
     * kiracıları gezen sweeper üzerinden yazılan tura bakılır.
     *
     * SIRALAMA `started_at` DEĞİL `id` ÜZERİNDEN YAPILIR. İlk hâli
     * `latest('started_at')` kullanıyordu ve rastgele sırada AŞTIRMALI
     * olarak düşüyordu: `reconciliation_runs.started_at` SANİYE
     * hassasiyetlidir ve üç komut aynı saniye içinde koştuğunda ikisi
     * AYNI damgayı taşır; hangisinin "son" olduğu belirsiz kalır ve sorgu
     * bazen ılık turu döndürür. `id` UUIDv7'dir — zaman sıralı ve saniye
     * içinde de ayırt edicidir. Bu, projenin zaman damgası hassasiyeti
     * tuzağının bir kez daha tekrarı.
     */
    #[Test]
    public function each_command_drives_its_own_layer(): void
    {
        [$tenant, $variant, $connection] = $this->makeContext();

        $this->listing($tenant, $variant, $connection, externalId: '10');
        $this->seedStock($tenant, $variant, 5);

        ProgrammableInventoryAdapter::remoteQuantity('woocommerce', '10', 5);

        foreach ([
            'reconcile:hot' => 'hot',
            'reconcile:warm' => 'warm',
            'reconcile:cold' => 'cold',
        ] as $command => $expectedScope) {
            $this->artisan($command)->assertSuccessful();

            $run = $this->asTenant($tenant, fn () => ReconciliationRun::query()
                ->where('channel_connection_id', $connection->id)
                ->orderByDesc('id')
                ->firstOrFail());

            $this->assertSame(
                $expectedScope,
                $run->scope,
                "{$command} yanlış katmanı sürüyor — o katmanın kapsamı hiç taranmaz.",
            );
        }
    }

    // ---------------------------------------------------------------- soğuk

    /**
     * SOĞUK KATMAN HİÇBİR TETİKLEYİCİSİ OLMAYAN SATIRI YAKALAR.
     *
     * BU TESTİN VARLIK NEDENİ TÜM MADDENİN GEREKÇESİDİR: satılmayan, hata
     * almamış, bekleyen işi olmayan ve sürüklenme geçmişi bulunmayan bir
     * listing SICAK VE ILIK katmanların DÖRT sorgusunun HİÇBİRİNE takılmaz.
     * Satıcı kanal panelinden stoğu elle değiştirdiyse o sürüklenme
     * SONSUZA KADAR görünmez. Soğuk katmanın örneklemi tam olarak bunun
     * içindir.
     */
    #[Test]
    public function cold_layer_finds_a_listing_no_reason_query_would_ever_select(): void
    {
        [$tenant, $variant, $connection] = $this->makeContext();

        $listing = $this->listing($tenant, $variant, $connection, externalId: '10');

        // Açılış stoğu var ama SATIŞ YOK, hata yok, bekleyen iş yok,
        // sürüklenme geçmişi yok — dört sebebin hiçbiri.
        $this->seedStock($tenant, $variant, 12);

        ProgrammableInventoryAdapter::remoteQuantity('woocommerce', '10', 4);

        $hot = $this->reconcile($tenant, $connection, ReconciliationScope::HOT);
        $warm = $this->reconcile($tenant, $connection, ReconciliationScope::WARM);

        $this->assertSame(0, $hot->candidates_count, 'Sıcak katman bu satırı GÖREMEZ.');
        $this->assertSame(0, $warm->candidates_count, 'Ilık katman da GÖREMEZ.');

        $cold = $this->reconcile($tenant, $connection, ReconciliationScope::COLD);

        $this->assertSame(1, $cold->candidates_count, 'Soğuk katman örneklemi onu YAKALAMALI.');
        $this->assertSame(1, $cold->drift_count, '12 ≠ 4 — sürüklenme.');

        $item = $this->itemFor($tenant, $listing);

        $this->assertSame('REPAIR_QUEUED', $item->status);
        $this->assertSame('sampled', $item->priority_reason);
    }

    /**
     * SOĞUK KATMAN DÖRT SEBEP SORGUSUNU ÇALIŞTIRMAZ.
     *
     * Kapsam sütunu soğuk için tek şey diyor: "Rastgele örneklem — uzun
     * kuyruk". Dört sorgu burada da koşsaydı soğuk katman ılık katmanın
     * günlük bir kopyası olur ve bütçesinin çoğunu ılık turun bir saat önce
     * zaten baktığı satırlar yerdi.
     *
     * Sebep etiketi bunun GÖZLENEBİLİR kanıtıdır: taze satışı olan bir
     * listing soğuk turda `recently_sold` DEĞİL `sampled` sebebiyle
     * seçilmelidir.
     */
    #[Test]
    public function cold_layer_labels_every_candidate_as_sampled(): void
    {
        [$tenant, $variant, $connection] = $this->makeContext();

        $listing = $this->listing($tenant, $variant, $connection, externalId: '10');

        $this->seedStock($tenant, $variant, 6);
        $this->sellAgo($tenant, $variant, hoursAgo: 0);          // taze satış

        ProgrammableInventoryAdapter::remoteQuantity('woocommerce', '10', 5);

        $hot = $this->reconcile($tenant, $connection, ReconciliationScope::HOT);

        $this->assertSame(
            'recently_sold',
            $this->itemFor($tenant, $listing)->priority_reason,
            'Sıcak katmanda sebep taze satıştır.',
        );

        $this->reconcile($tenant, $connection, ReconciliationScope::COLD);

        $this->assertSame(
            'sampled',
            $this->itemFor($tenant, $listing)->priority_reason,
            'Soğuk katman sebep sorgularını çalıştırmaz; her aday ÖRNEKLEMDİR.',
        );

        $this->assertSame(1, $hot->candidates_count);
    }

    /**
     * HİÇ GÖZLENMEMİŞ SATIR ÖRNEKLEMDE ÖNCE GELİR (`NULLS FIRST`).
     *
     * `last_observed_at` NULL olan listing, uzak durumu HİÇ okunmamış
     * olandır ve tam da sürüklenmeye en açık satırdır. Sıralama
     * `NULLS LAST` olsaydı hiç bakılmamış satırlar örneklemin SONUNA düşer
     * ve dar bütçede ASLA seçilmezlerdi — yani soğuk katman yalnızca zaten
     * baktığı satırlara tekrar bakardı.
     */
    #[Test]
    public function never_observed_listings_are_sampled_first(): void
    {
        [$tenant, , $connection] = $this->makeContext();

        // Yakın zamanda gözlenmiş satır.
        $observedVariant = $this->asTenant($tenant, fn () => Variant::factory()->create());
        $observed = $this->listing($tenant, $observedVariant, $connection, externalId: '200');
        $this->seedStock($tenant, $observedVariant, 5);
        $this->observedState($tenant, $observed, minutesAgo: 5);

        // Hiç gözlenmemiş satır — sync state satırı YOK.
        $freshVariant = $this->asTenant($tenant, fn () => Variant::factory()->create());
        $fresh = $this->listing($tenant, $freshVariant, $connection, externalId: '201');
        $this->seedStock($tenant, $freshVariant, 5);

        $selected = $this->asTenant($tenant, fn (): array => app(SampledCandidates::class)
            ->for($connection, budget: 1));

        $this->assertCount(1, $selected);

        $this->assertSame(
            $fresh->id,
            $selected[0]['listing_id'],
            'Hiç gözlenmemiş satır örneklemde BAŞA gelmeli (NULLS FIRST).',
        );
    }

    /**
     * SOĞUK BÜTÇE ORANSALDIR — 500 yalnızca ÜST SINIRDIR.
     *
     * "Aktif listing'lerin %2'si, üst sınır 500". Sabit 500 kullanılsaydı
     * 50 listing'i olan bir bağlantıda günlük tur katalogun TAMAMINI okur
     * ve "tam katalog taraması hiçbir katmanda yok" kuralı sessizce
     * çiğnenirdi.
     *
     * Küçük kataloglarda oran sıfıra yuvarlanır; bu durumda ALT SINIR 1
     * uygulanır — yoksa küçük satıcının uzun kuyruğuna HİÇ bakılmazdı ve
     * soğuk katman onlar için hiç çalışmamış olurdu.
     */
    #[Test]
    public function cold_budget_is_two_percent_of_active_listings_capped_at_five_hundred(): void
    {
        [$tenant, , $connection] = $this->makeContext();

        $sampler = app(SampledCandidates::class);

        // 300 aktif listing → %2 = 6
        $this->assertSame(6, $sampler->budgetFor(activeListings: 300, cap: 500));

        // 100.000 aktif listing → %2 = 2.000 ama ÜST SINIR 500
        $this->assertSame(500, $sampler->budgetFor(activeListings: 100_000, cap: 500));

        // 10 aktif listing → %2 = 0,2 → ALT SINIR 1
        $this->assertSame(1, $sampler->budgetFor(activeListings: 10, cap: 500));

        // Hiç listing yoksa iş de yoktur.
        $this->assertSame(0, $sampler->budgetFor(activeListings: 0, cap: 500));
    }

    /**
     * Oransal bütçe GERÇEK TURDA da uygulanır — yalnızca hesapta değil.
     *
     * `budgetFor()` doğru hesaplayıp çağıran onu kullanmasaydı test yine
     * yeşil kalırdı; bu bu projede defalarca yaşanan biçimdir ("sınıfın var
     * olması onu kimsenin çağırdığı anlamına gelmez").
     */
    #[Test]
    public function the_cold_run_actually_applies_the_proportional_budget(): void
    {
        [$tenant, , $connection] = $this->makeContext();

        // 10 aktif listing → %2 = 0,2 → alt sınır 1. Üst sınır 500 olmasına
        // rağmen turda YALNIZCA BİR listing okunmalı.
        for ($i = 0; $i < 10; $i++) {
            $variant = $this->asTenant($tenant, fn () => Variant::factory()->create());

            $this->listing($tenant, $variant, $connection, externalId: (string) (300 + $i));
            $this->seedStock($tenant, $variant, 5);

            ProgrammableInventoryAdapter::remoteQuantity('woocommerce', (string) (300 + $i), 5);
        }

        $run = $this->reconcile($tenant, $connection, ReconciliationScope::COLD);

        $this->assertSame(
            1,
            $run->candidates_count,
            'Soğuk tur ORANSAL bütçeyi uygulamalı (10 listing → 1), 500 üst sınırı değil.',
        );
    }

    /**
     * `error_permanent` SOĞUK KATMANDA DA ADAY DEĞİLDİR.
     *
     * Bu kuralın en kolay unutulacağı yer burasıdır: örneklem sıralaması
     * `last_observed_at NULLS FIRST`'tür ve düzeltilemeyen satırlar
     * genellikle hiç gözlenmemiştir — yani filtresiz bir örneklem onları
     * ÖNCELİKLE seçerdi.
     */
    #[Test]
    public function permanently_failed_listing_is_not_sampled_by_the_cold_layer(): void
    {
        [$tenant, $variant, $connection] = $this->makeContext();

        $listing = $this->listing($tenant, $variant, $connection, externalId: '10');

        $this->seedStock($tenant, $variant, 5);

        $this->asTenant($tenant, fn () => ListingSyncState::query()->create([
            'tenant_id' => $tenant->id,
            'listing_id' => $listing->id,
            'domain' => SyncDomain::INVENTORY->value,
            'desired_version' => 3,
            'synced_version' => 1,
            'status' => 'error_permanent',
            'last_error' => 'kimlik doğrulama başarısız',
        ]));

        ProgrammableInventoryAdapter::remoteQuantity('woocommerce', '10', 99);

        $run = $this->reconcile($tenant, $connection, ReconciliationScope::COLD);

        $this->assertSame(0, $run->candidates_count, 'error_permanent örneklenmemeli.');

        $this->assertSame(
            0,
            $this->asTenant($tenant, fn (): int => ReconciliationItem::query()->count()),
        );
    }

    /**
     * BÜTÇE TABANI ÖRNEKLEM HAVUZUYLA AYNI KÜMEDİR.
     *
     * BU TEST GERÇEK ÇALIŞTIRMADA BULUNAN BİR BOŞLUK İÇİN YAZILDI: sayım
     * `error_permanent` satırlarını İÇERİYOR, örneklem ise onları HARİÇ
     * tutuyordu (dev veritabanında sayım 3 dedi, örneklem 2 satır döndü).
     *
     * İki küme ayrıştığında oran anlamını kaybeder: kalıcı hataya düşmüş
     * satırı çok olan bir bağlantıda bütçe, gerçekte taranabilecek satır
     * sayısının ÜSTÜNE çıkar ve "aktif listing'lerin %2'si" kuralı sessizce
     * daha büyük bir orana dönüşür. Sapma tam da oranın en çok korumak
     * istediği yerde — büyük katalog, çok hatalı satır — en büyük olur.
     *
     * Testler bunu GÖREMEZDİ çünkü küçük kataloglarda alt sınır 1 her iki
     * hesabı da aynı sayıya indiriyor.
     */
    #[Test]
    public function the_budget_base_excludes_permanently_failed_listings(): void
    {
        [$tenant, $healthyVariant, $connection] = $this->makeContext();

        $this->listing($tenant, $healthyVariant, $connection, externalId: '10');
        $this->seedStock($tenant, $healthyVariant, 5);

        // Kalıcı hataya düşmüş İKİ satır — örneklenemezler, o hâlde
        // bütçenin tabanına da girmemeliler.
        foreach (['11', '12'] as $externalId) {
            $variant = $this->asTenant($tenant, fn () => Variant::factory()->create());
            $listing = $this->listing($tenant, $variant, $connection, externalId: $externalId);
            $this->seedStock($tenant, $variant, 5);

            $this->asTenant($tenant, fn () => ListingSyncState::query()->create([
                'tenant_id' => $tenant->id,
                'listing_id' => $listing->id,
                'domain' => SyncDomain::INVENTORY->value,
                'desired_version' => 2,
                'synced_version' => 1,
                'status' => 'error_permanent',
                'last_error' => 'kimlik doğrulama başarısız',
            ]));
        }

        $counted = $this->asTenant($tenant, fn (): int => app(SampledCandidates::class)
            ->activeListingCount($connection));

        $sampled = $this->asTenant($tenant, fn (): array => app(SampledCandidates::class)
            ->for($connection, budget: 100));

        $this->assertSame(
            1,
            $counted,
            'Bütçe tabanı yalnızca ÖRNEKLENEBİLİR satırları saymalı.',
        );

        $this->assertCount(
            $counted,
            $sampled,
            'Sayım ile örneklem havuzu AYNI kümeyi tarif etmeli.',
        );
    }

    /** Canlı olmayan listing örneklenmez — kanalda karşılığı yok. */
    #[Test]
    public function non_live_listing_is_not_sampled(): void
    {
        [$tenant, $variant, $connection] = $this->makeContext();

        $this->listing($tenant, $variant, $connection, externalId: '10', lifecycle: 'draft');
        $this->seedStock($tenant, $variant, 5);

        $run = $this->reconcile($tenant, $connection, ReconciliationScope::COLD);

        $this->assertSame(0, $run->candidates_count);
    }

    /**
     * KARIŞIK KATALOGDA DA canlı olmayan satır örneklenmez.
     *
     * BU TEST BİR MUTASYONUN ARDINDAN EKLENDİ. Yalnızca draft satır
     * içeren bir bağlantıda `activeListingCount()` sıfır döner, bütçe
     * sıfır olur ve `for()` SQL'e hiç gelmeden çıkar — yani sorgunun
     * `lifecycle_status = 'live'` yüklemi o senaryoda HİÇ ÇALIŞMAZ ve
     * kaldırılması testi kırmazdı.
     *
     * Gerçek katalog karışıktır: yanında canlı satırlar olduğu anda
     * bütçe sıfırdan büyür, sorgu gerçekten koşar ve yüklem TEK
     * savunma hattı hâline gelir. Kaldırılsaydı taslak satır örneklenir,
     * kanalda karşılığı olmadığı için REMOTE_MISSING yazılır ve satıcı
     * hiç yayınlamadığı ürün için "kanalda bulunamadı" uyarısı görürdü.
     *
     * TASLAK SATIR ÖNCE YARATILIR ve bu SIRALAMA BİLİNÇLİDİR: her iki satır
     * da hiç gözlenmemiştir (`last_observed_at` NULL), yani sıralama
     * `l.id ASC` tie-breaker'ına düşer ve listing kimlikleri UUIDv7 —
     * ZAMAN SIRALI — olduğu için önce yaratılan başa gelir. Canlı satır
     * önce yaratılsaydı bir kişilik bütçe onu seçer, taslağa hiç sıra
     * gelmez ve yüklem kaldırıldığında test YİNE YEŞİL KALIRDI (bu tam
     * olarak yaşandı).
     */
    #[Test]
    public function non_live_listing_is_not_sampled_even_when_budget_is_positive(): void
    {
        [$tenant, $draftVariant, $connection] = $this->makeContext();

        // TASLAK ÖNCE: sıralamada başa gelir ve filtre kalkarsa BU seçilir.
        $draft = $this->listing($tenant, $draftVariant, $connection, externalId: '11', lifecycle: 'draft');
        $this->seedStock($tenant, $draftVariant, 5);

        // Canlı satır — bütçeyi sıfırdan yukarı çeker.
        $liveVariant = $this->asTenant($tenant, fn () => Variant::factory()->create());
        $live = $this->listing($tenant, $liveVariant, $connection, externalId: '10');
        $this->seedStock($tenant, $liveVariant, 5);
        ProgrammableInventoryAdapter::remoteQuantity('woocommerce', '10', 5);

        $run = $this->reconcile($tenant, $connection, ReconciliationScope::COLD);

        $this->assertSame(
            1,
            $this->asTenant($tenant, fn (): int => ReconciliationItem::query()
                ->where('listing_id', $live->id)
                ->count()),
            'Canlı satır için kalem yazılmalı — bütçe gerçekten harcandı.',
        );

        $this->assertSame(1, $run->candidates_count, 'Yalnızca CANLI satır örneklenmeli.');

        $this->assertSame(
            0,
            $this->asTenant($tenant, fn (): int => ReconciliationItem::query()
                ->where('listing_id', $draft->id)
                ->count()),
            'Taslak satır için kalem YAZILMAMALI — kanalda karşılığı yok.',
        );
    }

    /**
     * ÖRNEKLEM BAŞKA KİRACIYA SIZMAZ.
     *
     * `SampledCandidates` ham `DB::select()` kullanır ve `DB::table()` gibi
     * ham sorgu GLOBAL SCOPE'A TABİ DEĞİLDİR: kiracı filtresi AÇIKÇA
     * yazılmalıdır. Bu projede aynı boşluk DÖRT ayrı turda bulundu.
     */
    #[Test]
    public function sampling_never_crosses_tenants(): void
    {
        [$tenantA, $variantA, $connectionA] = $this->makeContext();
        [$tenantB, $variantB, $connectionB] = $this->makeContext();

        $this->listing($tenantA, $variantA, $connectionA, externalId: '10');
        $this->listing($tenantB, $variantB, $connectionB, externalId: '20');

        $this->seedStock($tenantA, $variantA, 5);
        $this->seedStock($tenantB, $variantB, 5);

        ProgrammableInventoryAdapter::remoteQuantity('woocommerce', '10', 5);

        $run = $this->reconcile($tenantA, $connectionA, ReconciliationScope::COLD);

        $this->assertSame(1, $run->candidates_count);

        $this->assertSame(
            0,
            $this->asTenant($tenantB, fn (): int => ReconciliationItem::query()->count()),
            'Başka kiracının kalemi yazılmamalı.',
        );
    }

    /** Örneklem başka BAĞLANTIYA sızmaz — bütçe bağlantı başınadır. */
    #[Test]
    public function sampling_never_crosses_connections(): void
    {
        [$tenant, $variant, $connection] = $this->makeContext();

        $other = $this->connection($tenant, 'trendyol');

        $this->listing($tenant, $variant, $connection, externalId: '10');
        $this->seedStock($tenant, $variant, 5);

        $otherVariant = $this->asTenant($tenant, fn () => Variant::factory()->create());
        $this->listing($tenant, $otherVariant, $other, externalId: '55');
        $this->seedStock($tenant, $otherVariant, 5);

        ProgrammableInventoryAdapter::remoteQuantity('woocommerce', '10', 5);

        $run = $this->reconcile($tenant, $connection, ReconciliationScope::COLD);

        $this->assertSame(1, $run->candidates_count);
    }

    // ---------------------------------------------------------------- yardımcılar

    /** @return array{0: Tenant, 1: Variant, 2: ChannelConnection} */
    private function makeContext(): array
    {
        $tenant = (new CreateTenant)->run(
            name: 'Katman '.uniqid(),
            owner: User::factory()->create(),
        );

        $variant = $this->asTenant($tenant, fn () => Variant::factory()->create());

        return [$tenant, $variant, $this->connection($tenant, 'woocommerce')];
    }

    private function connection(Tenant $tenant, string $code): ChannelConnection
    {
        $this->asSystem(fn () => ChannelType::query()->updateOrCreate(
            ['code' => $code],
            [
                'name' => ucfirst($code),
                'kind' => 'marketplace',
                'adapter_class' => ProgrammableInventoryAdapter::class,
                'is_active' => true,
            ],
        ));

        return $this->asTenant($tenant, fn () => ChannelConnection::factory()->create([
            'channel_type_code' => $code,
        ]));
    }

    private function listing(
        Tenant $tenant,
        Variant $variant,
        ChannelConnection $connection,
        string $externalId,
        string $lifecycle = 'live',
    ): Listing {
        return $this->asTenant($tenant, fn () => Listing::factory()->create([
            'channel_connection_id' => $connection->id,
            'variant_id' => $variant->id,
            'external_id' => $externalId,
            'lifecycle_status' => $lifecycle,
        ]));
    }

    /** Açılış stoğu LEDGER üzerinden — doğrudan yazmak eşitliği bozar. */
    private function seedStock(Tenant $tenant, Variant $variant, int $quantity): void
    {
        $this->asTenant($tenant, fn () => app(ApplyMovement::class)->run(
            warehouseId: $this->warehouse($tenant)->id,
            variantId: $variant->id,
            type: MovementType::IMPORT,
            quantity: $quantity,
            idempotencyKey: 'import:'.$variant->id,
            sourceType: 'test',
        ));
    }

    /**
     * Geçmişte kalmış bir SALE hareketi yaratır.
     *
     * Hareket normal yoldan yazılır (ledger bütünlüğü korunur), sonra
     * `occurred_at` geriye çekilir: aday sorgusu O kolona bakar ve
     * hareketi "şimdi" yazmak ılık/sıcak farkını hiç göstermezdi.
     */
    private function sellAgo(Tenant $tenant, Variant $variant, int $hoursAgo): void
    {
        $this->asTenant($tenant, fn () => app(ApplyMovement::class)->run(
            warehouseId: $this->warehouse($tenant)->id,
            variantId: $variant->id,
            type: MovementType::SALE,
            quantity: 1,
            idempotencyKey: 'sale:'.$variant->id.':'.$hoursAgo,
            sourceType: 'test',
        ));

        if ($hoursAgo === 0) {
            return;
        }

        $this->asTenant($tenant, fn () => DB::table('inventory_movements')
            ->where('tenant_id', $tenant->id)
            ->where('variant_id', $variant->id)
            ->where('type', MovementType::SALE->value)
            ->update(['occurred_at' => now()->subHours($hoursAgo)]));
    }

    /**
     * Belirtilen süredir bekleyen (kirli) bir sync state yaratır.
     *
     * `is_dirty` ÜRETİLMİŞ KOLONDUR: `desired_version > synced_version`
     * olduğunda kendiliğinden true olur ve doğrudan yazılamaz.
     */
    private function staleState(Tenant $tenant, Listing $listing, int $pendingSinceHours): void
    {
        $this->asTenant($tenant, fn () => ListingSyncState::query()->create([
            'tenant_id' => $tenant->id,
            'listing_id' => $listing->id,
            'domain' => SyncDomain::INVENTORY->value,
            'desired_version' => 4,
            'synced_version' => 2,
            'status' => 'pending',
            'last_requested_at' => now()->subHours($pendingSinceHours),
        ]));
    }

    /** Yakın zamanda gözlenmiş bir sync state — örneklem sıralamasını sınar. */
    private function observedState(Tenant $tenant, Listing $listing, int $minutesAgo): void
    {
        $this->asTenant($tenant, fn () => ListingSyncState::query()->create([
            'tenant_id' => $tenant->id,
            'listing_id' => $listing->id,
            'domain' => SyncDomain::INVENTORY->value,
            'desired_version' => 1,
            'synced_version' => 1,
            'status' => 'synced',
            'last_observed_at' => now()->subMinutes($minutesAgo),
        ]));
    }

    private function warehouse(Tenant $tenant): Warehouse
    {
        return $this->asTenant($tenant, fn () => Warehouse::query()
            ->where('is_default', true)
            ->firstOrFail());
    }

    private function reconcile(
        Tenant $tenant,
        ChannelConnection $connection,
        ReconciliationScope $scope,
    ): ReconciliationRun {
        return $this->asTenant($tenant, fn () => app(ReconcileConnection::class)->run(
            connection: $connection,
            scope: $scope,
        ));
    }

    private function itemFor(Tenant $tenant, Listing $listing): ReconciliationItem
    {
        return $this->asTenant($tenant, fn () => ReconciliationItem::query()
            ->where('listing_id', $listing->id)
            ->latest('checked_at')
            ->firstOrFail());
    }
}
