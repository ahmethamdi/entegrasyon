<?php

declare(strict_types=1);

namespace App\Support\LoadTest;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Variant;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Actions\ApplyMovement;
use App\Domain\Inventory\Actions\LockInventoryRows;
use App\Domain\Inventory\Enums\MovementType;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\Support\MovementKey;
use App\Domain\Messaging\Jobs\ConsumeOutboxEvent;
use App\Domain\Messaging\Support\OutboxRelay;
use App\Domain\Sync\Models\Listing;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Uid\UuidV7;

/**
 * SENKRON HATTI YÜK TESTİ — §11 · "yük testi" · §15 (ölçek sinyalleri).
 *
 * ─────────────────────────────────────────────────────────────────────
 * NEDEN HTTP DEĞİL SENKRON HATTI ÖLÇÜLÜYOR
 * ─────────────────────────────────────────────────────────────────────
 * Bu ürünün darboğazı panel istekleri DEĞİLDİR. Satıcı panele günde
 * birkaç kez bakar; senkron hattı ise HER SİPARİŞTE ve HER STOK
 * DEĞİŞİMİNDE çalışır ve tek bir kampanya günü onu on kat yükler.
 * `ab`/`k6` ile ölçülen "saniyede kaç istek" sayısı bu ürün için
 * yanıltıcı bir sağlık işaretidir: HTTP tarafı rahatken outbox
 * kuyruğunun saatlerce geride kalması MÜMKÜNDÜR ve o durumda kanaldaki
 * stok yanlıştır — yani ürünün TEMEL İDDİASI çalışmaz.
 *
 * Ölçülen üç şey bu yüzden §11'in ve §15'in gerçekten sorduğu sorulardır:
 *   1. ÜRETİM     — ledger saniyede kaç hareket yazabiliyor
 *   2. YAYIN       — outbox relay kuyruğu eritebiliyor mu (`consume_gap`)
 *   3. FAN-OUT     — 1 olay → N operasyon dönüşümü ölçekleniyor mu
 *
 * ─────────────────────────────────────────────────────────────────────
 * KANALA GERÇEK İSTEK ATILMAZ
 * ─────────────────────────────────────────────────────────────────────
 * Yük testi `PushInventory` işini KUYRUĞA KADAR sürer, kanala göndermez.
 * Gerçek istek atsaydı ölçülen şey kanalın gecikmesi olurdu — bizim
 * kodumuz değil — ve test her koşuda farklı sonuç verirdi. Ayrıca
 * pazaryerinin hız sınırını yakmak, üretim bağlantısını devre kesiciye
 * düşürebilirdi.
 *
 * ─────────────────────────────────────────────────────────────────────
 * ÜRETTİĞİ VERİYİ TEMİZLER — AMA KİRACI BAZINDA
 * ─────────────────────────────────────────────────────────────────────
 * Test kendi kiracılarını yaratır ve sonunda siler. Var olan kiracıların
 * verisine DOKUNMAZ: yük testi bir ölçüm aracıdır, veri imha aracı
 * değil. Komut yine de üretimde çalıştırılmamalıdır ve komut kabuğu
 * bunu açıkça sorar.
 */
final class SyncPipelineLoadTest
{
    /**
     * @param  Closure(string): void|null  $progress  Adım bildirimi.
     * @return array<string, mixed>
     */
    public function run(
        int $tenants,
        int $variantsPerTenant,
        int $movements,
        ?Closure $progress = null,
    ): array {
        $progress ??= static fn (string $m): null => null;

        $progress("Hazırlık: {$tenants} kiracı × {$variantsPerTenant} varyant…");

        $fixtures = $this->seed($tenants, $variantsPerTenant, $progress);

        // ÖLÇÜM YALNIZCA BU TURUN KİRACILARINI GÖRÜR.
        //
        // İlk gerçek çalıştırmada bulundu: sorgular tüm tabloyu tarayınca
        // veritabanındaki DEMO satırları ölçüme karışıyordu ve yayın
        // gecikmesi p95 **19566 saniye** çıkıyordu (günler önce yazılmış
        // bir olayın gecikmesi). Fan-out oranı da 0.22 gibi imkânsız bir
        // değere düşüyordu — bir olay en az bir operasyon üretir.
        //
        // Ders projenin kendi kuralının aynısı: ölçüm sorgusu KAPSAMINI
        // açıkça yazmalıdır, `DB::table()` hiçbir şeyi kendiliğinden
        // daraltmaz.
        $this->tenantIds = array_column($fixtures, 'tenant_id');

        // TEMİZLİK `finally` İÇİNDE — İLK GERÇEK ÇALIŞTIRMADA BULUNDU.
        //
        // Turun ortasında bir istisna çıktığında (o turda gerçekten oldu:
        // yanlış namespace) temizlik HİÇ çalışmıyordu ve üretilen kiracılar
        // ile stok hareketleri veritabanında kalıyordu. Yük testi kendi
        // çöpünü bırakan bir araca dönüşür ve o çöp bir SONRAKİ turun
        // ölçümüne karışırdı.
        //
        // Bu, projenin `TenantContext`'i `finally` ile bırakma kuralının
        // aynısı: geri alma yolu, mutlu yola değil HER yola bağlanmalıdır.
        try {
            $progress("Üretim: {$movements} stok hareketi…");

            $produced = $this->produceMovements($fixtures, $movements);

            // SANİYE SINIRI BEKLENİR — YOKSA KUYRUK EKSİK ÖLÇÜLÜR.
            //
            // `outbox_events` zaman damgaları SANİYE hassasiyetlidir ve
            // `available_at` yazıldığı andan bir saniyeye kadar İLERİYE
            // yuvarlanır. Relay sorgusu `available_at <= clock_timestamp()`
            // kullandığı için taze olaylar HAKLI OLARAK elenir.
            //
            // Gerçek çalıştırmada bulundu: 1000 hareket üretildi ama relay
            // yalnızca 11 olay gördü ve rapor kuyruk derinliğini 11
            // gösterdi. Sistem doğru davranıyordu — ÖLÇÜM yanlıştı.
            // Beklemeden ölçmek, kuyruk derinliğini olduğundan yüz kat
            // küçük raporlayan bir yük testi demektir.
            usleep(1_100_000);

            $progress('Yayın: outbox relay…');

            $relay = $this->drainRelay();

            $progress('Fan-out: olay → operasyon…');

            $fanOut = $this->measureFanOut();

            // BÜTÜNLÜK KONTROLÜ `return`'DEN ÖNCE ÇAĞRILIR.
            //
            // `finally` içindeki temizliğe bırakılamaz: PHP `return`
            // ifadesini `finally` çalışmadan ÖNCE değerlendirir, yani
            // dizideki `$this->integrity` HENÜZ BOŞ olurdu. Gerçek
            // çalıştırmada tam olarak bu oldu — rapor "BÜTÜNLÜK BOZUK"
            // dedi ve satır sayısını `?` bastı, çünkü bozukluk YOKTU,
            // ölçüm HİÇ YAPILMAMIŞTI.
            //
            // Silmeden önce koşması da zorunlu (bkz. `verifyIntegrity`).
            $this->verifyIntegrity($fixtures);

            return [
                'production' => $produced,
                'relay' => $relay,
                'fan_out' => $fanOut,
                'integrity' => $this->integrity,
            ];
        } finally {
            $progress('Temizlik…');

            $this->cleanUp($fixtures);
        }
    }

    /** @var array<string, mixed> */
    private array $integrity = [];

    /**
     * Bu turun kiracıları — HER ölçüm sorgusunun kapsamı.
     *
     * `seed()` bitene kadar boştur ve o aşamada ölçüm yapılmaz; hazırlık
     * turunun relay çağrısı yalnızca kuyruğu boşaltır, rapor üretmez.
     *
     * @var list<string>
     */
    private array $tenantIds = [];

    /**
     * Kiracı, ürün, varyant ve bağlantı üretir.
     *
     * AÇILIŞ STOĞU LEDGER ÜZERİNDEN GİRER — `CreateProduct` ile aynı
     * kural. `inventory_levels` satırını doğrudan yazmak
     * `on_hand = Σ on_hand_delta` eşitliğini daha testin başında bozar ve
     * turun sonundaki bütünlük kontrolü HAKLI olarak kırmızıya dönerdi.
     *
     * @return list<array{tenant_id: string, warehouse_id: string, variant_ids: list<string>}>
     */
    private function seed(int $tenants, int $variantsPerTenant, Closure $progress): array
    {
        $fixtures = [];

        for ($t = 0; $t < $tenants; $t++) {
            $tenant = app(CreateTenant::class)->run(
                name: 'yuk-testi-'.uniqid(),
                owner: User::factory()->create(),
            );

            $warehouseId = TenantContext::runAsSystem(
                fn (): string => Warehouse::query()
                    ->where('tenant_id', $tenant->id)
                    ->where('is_default', true)
                    ->value('id'),
            );

            $connection = $this->makeConnection($tenant->id);

            $variantIds = TenantContext::runFor($tenant->id, function () use ($variantsPerTenant, $warehouseId, $connection, $tenant): array {
                $ids = [];

                for ($v = 0; $v < $variantsPerTenant; $v++) {
                    $product = Product::factory()->create();
                    $variant = Variant::factory()->for($product)->create();

                    // CANLI LISTING ZORUNLUDUR — yoksa fan-out HİÇBİR
                    // operasyon üretmez ve o aşama ölçülemez.
                    //
                    // İlk gerçek çalıştırmada bulundu: listing seed
                    // edilmediği için fan-out `0 operasyon` raporladı.
                    // Bu, sistemin DOĞRU davranışıdır (tüketici yalnızca
                    // `lifecycle_status = 'live'` satırları hedefler) ama
                    // ölçüm anlamsızdı — yük testi hiçbir şey ölçmeyen bir
                    // aşamayı "başarılı" gösteriyordu.
                    Listing::query()->create([
                        'tenant_id' => $tenant->id,
                        'channel_connection_id' => $connection,
                        'variant_id' => $variant->id,
                        'external_id' => 'load-'.$variant->id,
                        'lifecycle_status' => 'live',
                        'listed_at' => now(),
                    ]);

                    // Açılış stoğu IMPORT hareketiyle — gerekçe metotta.
                    DB::transaction(function () use ($warehouseId, $variant): void {
                        app(LockInventoryRows::class)->run($warehouseId, [$variant->id]);

                        app(ApplyMovement::class)->run(
                            warehouseId: $warehouseId,
                            variantId: $variant->id,
                            type: MovementType::IMPORT,
                            quantity: 1_000_000,
                            idempotencyKey: MovementKey::import((string) new UuidV7),
                            sourceType: 'load_test',
                        );
                    });

                    $ids[] = $variant->id;
                }

                return $ids;
            });

            $fixtures[] = [
                'tenant_id' => $tenant->id,
                'warehouse_id' => $warehouseId,
                'variant_ids' => $variantIds,
            ];

            $progress('  kiracı '.($t + 1)."/{$tenants} hazır");
        }

        // Açılış hareketlerinin ürettiği outbox olayları ölçümü kirletmesin:
        // yayın gecikmesi ÜRETİM turunun olaylarıyla ölçülmelidir.
        //
        // TÜKETİM YAPILMAZ (`consume: false`): hazırlık turunun amacı
        // kuyruğu boşaltmaktır, ölçmek değil. Tüketilseydi açılış IMPORT
        // olayları da operasyon açar ve fan-out oranı şişerdi.
        $this->drainRelay(consume: false);

        return $fixtures;
    }

    /**
     * Stok hareketi üretir ve ledger yazma hızını ölçer.
     *
     * SATIŞ (`SALE`) KULLANILIR: gerçek yükün baskın biçimi budur ve
     * `available` düşüren tek yol odur. IMPORT ölçülseydi test, üretimde
     * nadiren çalışan bir yolu ölçerdi.
     *
     * @param  list<array{tenant_id: string, warehouse_id: string, variant_ids: list<string>}>  $fixtures
     * @return array<string, mixed>
     */
    private function produceMovements(array $fixtures, int $movements): array
    {
        $latencies = [];
        $startedAt = microtime(true);

        for ($i = 0; $i < $movements; $i++) {
            $fixture = $fixtures[$i % count($fixtures)];
            $variantId = $fixture['variant_ids'][$i % count($fixture['variant_ids'])];

            $movementStartedAt = microtime(true);

            TenantContext::runFor($fixture['tenant_id'], function () use ($fixture, $variantId): void {
                DB::transaction(function () use ($fixture, $variantId): void {
                    app(LockInventoryRows::class)->run(
                        $fixture['warehouse_id'],
                        [$variantId],
                    );

                    app(ApplyMovement::class)->run(
                        warehouseId: $fixture['warehouse_id'],
                        variantId: $variantId,
                        type: MovementType::SALE,
                        quantity: 1,
                        idempotencyKey: MovementKey::manualAdjustment((string) new UuidV7),
                        sourceType: 'load_test',
                    );
                });
            });

            $latencies[] = (microtime(true) - $movementStartedAt) * 1000;
        }

        $elapsed = microtime(true) - $startedAt;

        return [
            'movements' => $movements,
            'seconds' => round($elapsed, 2),
            'per_second' => $elapsed > 0 ? round($movements / $elapsed, 1) : 0.0,
            'p50_ms' => $this->percentile($latencies, 50),
            'p95_ms' => $this->percentile($latencies, 95),
            'p99_ms' => $this->percentile($latencies, 99),
        ];
    }

    /**
     * Outbox kuyruğunu eritir ve yayın gecikmesini ölçer.
     *
     * KUYRUK DERİNLİĞİ TEPE NOKTASI, ORTALAMA DEĞİL: §11'in
     * `outbox_consume_gap` metriği "şu an kaç olay bekliyor" sorusunu
     * sorar ve ortalaması iyi görünen bir tur, tepe noktasında yine de
     * kanaldaki stoğu dakikalarca yanlış bırakabilir.
     *
     * @return array<string, mixed>
     */
    private function drainRelay(bool $consume = true): array
    {
        $depthBefore = $this->pendingOutboxCount();

        // TÜKETİCİ İNLİNE ÇALIŞTIRILIR — İLK GERÇEK ÇALIŞTIRMADA BULUNDU.
        //
        // Varsayılan dispatcher `ConsumeOutboxEvent`'i REDIS'e atar ve bu
        // komutun içinde onu çalıştıran kimse yoktur. Fan-out aşaması bu
        // yüzden HER TURDA `0 operasyon` raporluyordu — yani ölçmediği bir
        // şeyi ölçüyormuş gibi gösteriyordu.
        //
        // Enjekte edilen dispatcher işi kuyruğa atmak yerine DOĞRUDAN
        // çağırır. Ölçülen şey böylece gerçek fan-out maliyeti olur:
        // 1 olay → N operasyon dönüşümü ve o dönüşümün süresi.
        //
        // İş `PushInventory`'yi yine kuyruğa atar ve orada durur — kanala
        // gerçek istek atılmaz (sınıf başlığındaki kural).
        $consumeLatencies = [];

        $relay = $consume
            ? new OutboxRelay(function (string $tenantId, string $eventId) use (&$consumeLatencies): void {
                $startedAt = microtime(true);

                (new ConsumeOutboxEvent($tenantId, $eventId))->handle();

                $consumeLatencies[] = (microtime(true) - $startedAt) * 1000;
            })
            : app(OutboxRelay::class);

        $startedAt = microtime(true);
        $published = 0;
        $rounds = 0;

        // Tur BİTENE KADAR döner ama üst sınır vardır: bozuk bir kurulumda
        // (dispatcher hiç damgalamıyor) sonsuz döngü, yük testini asla
        // bitmeyen bir komuta çevirirdi.
        while ($rounds < 10_000) {
            $count = $relay->run(batchSize: 100);
            $rounds++;

            if ($count === 0) {
                break;
            }

            $published += $count;
        }

        $elapsed = microtime(true) - $startedAt;

        return [
            'queue_depth_peak' => $depthBefore,
            'published' => $published,
            'seconds' => round($elapsed, 2),
            'per_second' => $elapsed > 0 ? round($published / $elapsed, 1) : 0.0,
            // YAYIN GECİKMESİ: olay yazıldıktan yayınlanana kadar geçen süre.
            // §11'in `outbox_consume_gap` metriğinin ta kendisi.
            'publish_lag_p95_s' => $this->publishLagP95(),
            // TÜKETİM: fan-out'un olay başına maliyeti.
            'consume_p50_ms' => $this->percentile($consumeLatencies, 50),
            'consume_p95_ms' => $this->percentile($consumeLatencies, 95),
        ];
    }

    /**
     * Fan-out sonucunu ölçer — 1 olay → N operasyon.
     *
     * @return array<string, mixed>
     */
    private function measureFanOut(): array
    {
        return TenantContext::runAsSystem(function (): array {
            // KAPSAM: yalnızca bu turun kiracıları — gerekçe `run()` içinde.
            //
            // `consumed_at` ARANIR, `published_at` DEĞİL: hazırlık turunda
            // yayınlanan açılış IMPORT olayları TÜKETİLMEDİ
            // (`drainRelay(consume: false)`) ve paydaya girselerdi oran
            // yapay olarak düşerdi — ilk gerçek çalıştırmada 20 operasyon
            // 30 olaya bölünüp `0.67` çıkıyordu, oysa gerçek oran 1.0'dır.
            $events = (int) DB::table('outbox_events')
                ->whereIn('tenant_id', $this->tenantIds)
                ->whereNotNull('consumed_at')
                ->count();

            $operations = (int) DB::table('sync_operations')
                ->whereIn('tenant_id', $this->tenantIds)
                ->count();

            return [
                'events' => $events,
                'operations' => $operations,
                'ratio' => $events > 0 ? round($operations / $events, 2) : 0.0,
            ];
        });
    }

    /**
     * Yük testi için kanal bağlantısı.
     *
     * `active` + `healthy` OLMALIDIR: fan-out yalnızca canlı listing'leri
     * hedefler ve sağlıksız bağlantıya iş atılmaz. Sağlık kontrolü
     * ÇALIŞTIRILMAZ — gerçek bir kanala istek atmak yük testini kanalın
     * gecikmesini ölçen bir araca çevirirdi (sınıf başlığındaki kural).
     */
    private function makeConnection(string $tenantId): string
    {
        return TenantContext::runFor($tenantId, function (): string {
            $connection = ChannelConnection::factory()->create();

            return $connection->id;
        });
    }

    private function pendingOutboxCount(): int
    {
        return TenantContext::runAsSystem(function (): int {
            $query = DB::table('outbox_events')->whereNull('published_at');

            // Hazırlık turunda kapsam henüz boştur ve o çağrı yalnızca
            // kuyruğu boşaltır; ölçüm raporu üretmez.
            if ($this->tenantIds !== []) {
                $query->whereIn('tenant_id', $this->tenantIds);
            }

            return (int) $query->count();
        });
    }

    /**
     * Yayın gecikmesinin p95'i, saniye cinsinden.
     *
     * KAPSAM ZORUNLUDUR: ilk gerçek çalıştırmada bu sorgu tüm tabloyu
     * tarıyordu ve günler önce yazılmış bir demo olayının gecikmesini
     * ölçüp **19566 saniye** raporluyordu.
     */
    private function publishLagP95(): float
    {
        if ($this->tenantIds === []) {
            return 0.0;
        }

        $value = TenantContext::runAsSystem(fn () => DB::selectOne(<<<'SQL'
            SELECT percentile_cont(0.95) WITHIN GROUP (
                       ORDER BY EXTRACT(EPOCH FROM (published_at - created_at))
                   ) AS lag
              FROM outbox_events
             WHERE published_at IS NOT NULL
               AND tenant_id = ANY(?)
        SQL, ['{'.implode(',', $this->tenantIds).'}']));

        return round((float) ($value->lag ?? 0.0), 3);
    }

    /**
     * LEDGER ↔ PROJEKSİYON EŞİTLİĞİ — yükün ALTINDA da korunmalı.
     *
     * Yük testinin en değerli çıktısı hız değil BU kontroldür: eşzamanlı
     * yazma altında `on_hand = Σ on_hand_delta` bozulursa kilit sırası
     * veya transaction sınırı yanlış demektir ve o hata üretimde ancak
     * bakiye tutmadığında — yani çok geç — fark edilir.
     *
     * @param  list<array{tenant_id: string, warehouse_id: string, variant_ids: list<string>}>  $fixtures
     */
    private function verifyIntegrity(array $fixtures): void
    {
        // KAPSAM: bu turun kiracıları. Tüm tabloyu taramak, var olan demo
        // verisindeki eski bir tutarsızlığı bu turun suçu gibi gösterirdi
        // — ya da tersine, bu turun bozduğu bir satırı binlerce doğru
        // satırın arasında sayı olarak kaybettirirdi.
        $tenantArray = '{'.implode(',', array_column($fixtures, 'tenant_id')).'}';

        $mismatches = TenantContext::runAsSystem(fn (): int => (int) DB::selectOne(<<<'SQL'
            SELECT COUNT(*) AS mismatches
              FROM inventory_levels il
              JOIN (
                    SELECT warehouse_id, variant_id, SUM(on_hand_delta) AS total
                      FROM inventory_movements
                     WHERE tenant_id = ANY(?)
                     GROUP BY warehouse_id, variant_id
                   ) m
                ON m.warehouse_id = il.warehouse_id
               AND m.variant_id = il.variant_id
             WHERE il.on_hand <> m.total
        SQL, [$tenantArray])->mismatches);

        $this->integrity = [
            'ledger_matches_projection' => $mismatches === 0,
            'mismatched_rows' => $mismatches,
        ];
    }

    /**
     * Üretilen kiracıları siler.
     *
     * YALNIZCA KENDİ ÜRETTİĞİ KİRACILAR: var olan verilere dokunulmaz.
     * Silme `tenants` üzerinden yapılır ve FK'lerdeki `cascadeOnDelete`
     * geri kalanı temizler.
     *
     * @param  list<array{tenant_id: string, warehouse_id: string, variant_ids: list<string>}>  $fixtures
     */
    private function cleanUp(array $fixtures): void
    {
        // Bütünlük kontrolü mutlu yolda `run()` içinde ZATEN yapıldı
        // (`return`'den önce, gerekçe orada). Burada yalnızca HATA
        // yolunda çalışır: tur yarıda kalmışsa da ledger'ın durumunu
        // öğrenmek isteriz — istisnanın sebebi bütünlük bozukluğu
        // OLABİLİR ve silmeden önce bakmak tek şansımızdır.
        if ($this->integrity === []) {
            $this->verifyIntegrity($fixtures);
        }

        $tenantIds = array_column($fixtures, 'tenant_id');

        TenantContext::runAsSystem(function () use ($tenantIds): void {
            // Outbox ve sync operasyonları `tenants`'a FK ile bağlı DEĞİL
            // olabilir; açıkça silinir.
            // SIRA ÖNEMLİ: `sync_attempts` operasyona, operasyon
            // listing'e bağlıdır. Ters sırada silmek FK ihlali verirdi.
            DB::table('sync_attempts')->whereIn('tenant_id', $tenantIds)->delete();
            DB::table('sync_operations')->whereIn('tenant_id', $tenantIds)->delete();
            DB::table('listing_sync_states')->whereIn('tenant_id', $tenantIds)->delete();
            DB::table('listings')->whereIn('tenant_id', $tenantIds)->delete();
            DB::table('outbox_events')->whereIn('tenant_id', $tenantIds)->delete();

            DB::table('tenants')->whereIn('id', $tenantIds)->delete();
        });
    }

    /** @param list<float> $values */
    private function percentile(array $values, int $percentile): float
    {
        if ($values === []) {
            return 0.0;
        }

        sort($values);

        $index = (int) ceil($percentile / 100 * count($values)) - 1;

        return round($values[max(0, $index)], 2);
    }
}
