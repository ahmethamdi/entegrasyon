<?php

declare(strict_types=1);

namespace Tests\Feature\Sync;

use App\Domain\Catalog\Models\Variant;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Models\ChannelType;
use App\Domain\Channels\Registry\AdapterRegistry;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use App\Domain\Messaging\Models\OutboxEvent;
use App\Domain\Sync\Enums\SyncDomain;
use App\Domain\Sync\Enums\SyncIntent;
use App\Domain\Sync\Enums\SyncOperationStatus;
use App\Domain\Sync\Jobs\PushInventory;
use App\Domain\Sync\Models\Listing;
use App\Domain\Sync\Models\SyncOperation;
use App\Domain\Sync\Support\DetectStuckSyncOperations;
use App\Domain\Sync\Support\InventoryBatchBuilder;
use App\Domain\Sync\Support\SyncResultRecorder;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\UuidV7;
use Tests\Support\Channels\ProgrammableInventoryAdapter;
use Tests\TestCase;

/**
 * T6 · Seviye 2 bütünlük taraması — operasyon yaratıldı, worker çalışmadı.
 *
 * Mimari Karar Dokümanı v2.2 · §6 · İki bütünlük taraması, §18 · T6.
 *
 * SEVİYE 1 BUNU GÖREMEZ. Fan-out çalıştı: operasyonlar açıldı ve olay
 * consumed damgalandı. O zincir tamamlandı. Kayıp bir sonraki halkada:
 * PushInventory işi kuyruğa girdi ve Redis onu düşürdü. Olaya bakan tarama
 * hiçbir sorun görmez — consumed_at dolu, published_at dolu, her şey yolunda.
 * Operasyon veritabanında bekler ama kuyrukta karşılığı yoktur.
 *
 * İMZA: status = 'pending' AND attempt_count = 0. attempt_count İLK denemede
 * artırıldığı için (SyncResultRecorder::openAttempt) sıfır kalması yalnızca
 * tek şey demektir: worker bu operasyona HİÇ dokunmadı.
 *
 * DEVRE KESİCİ VE HIZ SINIRI BU İMZAYI KORUR: ikisi de erteleme yaparken
 * deneme AÇMAZ ve durumu DEĞİŞTİRMEZ. Bu kasıtlıdır — ertelenen operasyon
 * kuyrukta durur, tarama onu da bulur ve yeniden dispatch eder; dispatch
 * idempotenttir, iş yine devre kesiciye takılıp ertelenir. Maliyet bir boş
 * turdur; alternatif, gerçekten kaybolmuş işi hiç görmemektir.
 *
 * OUTBOX OLAYI YENİDEN YAYINLANMAZ. Yayınlamak fan-out'u tekrarlatırdı;
 * o zincir zaten başarıyla tamamlandı ve operasyonlar duruyor.
 */
final class DetectStuckSyncOperationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Tarama DISPATCH eder; gerçek worker'ı çalıştırmak bu testin
        // konusu değil. sync sürücü işi derhal çalıştırır ve kuyruk
        // kancaları kiracı bağlamını temizler.
        Queue::fake();
    }

    /**
     * Hiç denenmemiş eski operasyon bulunur ve yeniden dispatch edilir.
     */
    #[Test]
    public function stuck_operation_is_detected_and_redispatched(): void
    {
        [$tenant, $listing, $event] = $this->makeContext();

        $operation = $this->operation($tenant, $listing, $event, createdMinutesAgo: 10);

        $found = $this->scan();

        $this->assertTrue(
            $found->contains($operation->id),
            'pending + attempt_count = 0 + eski operasyon taramada görünmeli.',
        );

        Queue::assertPushed(
            PushInventory::class,
            fn (PushInventory $job): bool => $job->operationId === $operation->id,
        );

        // OUTBOX OLAYI YENİDEN YAYINLANMADI — o zincir zaten tamamlanmıştı.
        $freshEvent = $event->fresh();
        $this->assertNotNull($freshEvent->consumed_at, 'Olayın consumed damgası korunmalı.');
        $this->assertNotNull($freshEvent->published_at, 'Olay yeniden yayına ALINMAMALI.');
    }

    /**
     * Tarama DENEME AÇMAZ ve durumu DEĞİŞTİRMEZ.
     *
     * attempt_count'u artırmak yeniden deneme bütçesini boşa harcar ve bu
     * taramanın kendi imzasını yok eder: bir kez taranan operasyon artık
     * "hiç denenmedi" görünmez ve gerçekten kaybolduğunda bulunamaz.
     */
    #[Test]
    public function scan_does_not_open_an_attempt(): void
    {
        [$tenant, $listing, $event] = $this->makeContext();

        $operation = $this->operation($tenant, $listing, $event, createdMinutesAgo: 10);

        $this->scan();

        $fresh = $this->asTenant($tenant, fn () => SyncOperation::query()->find($operation->id));

        $this->assertSame(
            0,
            $fresh->attempt_count,
            'Tarama deneme açmaz — operasyon denenmedi, yalnızca yeniden dispatch edildi.',
        );
        $this->assertSame(
            SyncOperationStatus::PENDING,
            $fresh->status,
            'Tarama durumu değiştirmez.',
        );
        $this->assertSame(
            0,
            $this->asTenant($tenant, fn () => $fresh->attempts()->count()),
            'sync_attempts satırı yazılmaz.',
        );
    }

    /**
     * Denenmiş operasyon takılı SAYILMAZ.
     *
     * attempt_count > 0: worker bu operasyona dokundu. Başarısız olduysa
     * yeniden deneme zinciri (RetryPolicy) onu taşır; bu taramanın konusu
     * değildir. Buraya alınsaydı iki mekanizma aynı operasyonu paralel
     * dispatch eder ve deneme sayacı iki kat hızlı tükenirdi.
     */
    #[Test]
    public function attempted_operation_is_not_flagged_as_stuck(): void
    {
        [$tenant, $listing, $event] = $this->makeContext();

        $operation = $this->operation(
            $tenant,
            $listing,
            $event,
            createdMinutesAgo: 60,
            status: SyncOperationStatus::RETRYING,
            attemptCount: 2,
        );

        $this->assertCount(0, $this->scan());
        Queue::assertNothingPushed();

        // Mutasyon koruması: yalnızca durum değişse de (pending) sayaç
        // sıfırdan büyükse operasyon takılı değildir.
        $this->asSystem(fn () => DB::table('sync_operations')
            ->where('id', $operation->id)
            ->update(['status' => 'pending']));

        $this->assertCount(
            0,
            $this->scan(),
            'attempt_count > 0 tek başına yeterli: worker bu operasyona dokundu.',
        );
    }

    /**
     * Taze operasyon bekleme süresi dolmadan alınmaz.
     *
     * Dispatch ile worker'ın işi almasının arası saniyelerdir. Eşik
     * olmasaydı tarama her yeni operasyonu ikinci kez dispatch eder ve
     * normal akışta her yük iki kez gönderilirdi.
     */
    #[Test]
    public function recently_created_operation_is_within_grace_period(): void
    {
        [$tenant, $listing, $event] = $this->makeContext();

        $this->operation($tenant, $listing, $event, createdMinutesAgo: 1);

        $this->assertCount(0, $this->scan());
        Queue::assertNothingPushed();
    }

    /**
     * Terminal ve ölü durumlar taranmaz.
     *
     * completed / superseded / dead operasyonlar için yeni iş yaratılmaz.
     * dead özellikle önemlidir: kullanıcı müdahalesi bekler ve yeniden
     * dispatch etmek onu sonsuz döngüye çevirirdi.
     */
    #[Test]
    public function terminal_and_dead_operations_are_never_redispatched(): void
    {
        [$tenant, $listing, $event] = $this->makeContext();

        $statuses = [
            SyncOperationStatus::COMPLETED,
            SyncOperationStatus::SUPERSEDED,
            SyncOperationStatus::DEAD,
        ];

        foreach ($statuses as $index => $status) {
            $this->operation(
                $tenant,
                $listing,
                $event,
                createdMinutesAgo: 60,
                status: $status,
                version: $index + 2,
            );
        }

        $this->assertCount(0, $this->scan());
        Queue::assertNothingPushed();
    }

    /**
     * Tarama TÜM kiracıları görür ve bağlam KURULMADAN çalışır.
     *
     * İş, operasyonun kiracısıyla değil kimliğiyle taşınır; PushInventory
     * bağlamı kendi kurar. Ama tarama sorgusu tüm kiracıları görmek
     * zorundadır, aksi halde yalnızca birinin kayıp işleri kurtarılırdı.
     */
    #[Test]
    public function scan_spans_all_tenants_without_context(): void
    {
        [$tenantA, $listingA, $eventA] = $this->makeContext();
        [$tenantB, $listingB, $eventB] = $this->makeContext();

        $operationA = $this->operation($tenantA, $listingA, $eventA, createdMinutesAgo: 10);
        $operationB = $this->operation($tenantB, $listingB, $eventB, createdMinutesAgo: 10);

        $this->assertFalse(TenantContext::hasTenant());

        $found = $this->scan();

        $this->assertTrue($found->contains($operationA->id));
        $this->assertTrue($found->contains($operationB->id));

        Queue::assertPushed(PushInventory::class, 2);

        $this->assertFalse(TenantContext::hasTenant(), 'Tarama bağlam sızdırmamalı.');
    }

    /**
     * Sorgu clock_timestamp() kullanır, now() DEĞİL.
     *
     * Tarama transaction içinde çalışır ve "şu ana kadar eskimiş" olanları
     * arar; now() transaction başında donar ve sync_operations zaman
     * damgaları da saniye hassasiyetlidir.
     */
    #[Test]
    public function operations_stale_only_by_wall_clock_are_still_detected(): void
    {
        [$tenant, $listing, $event] = $this->makeContext();

        $operation = $this->operation($tenant, $listing, $event, createdMinutesAgo: 0);

        // Duvar saatini ilerlet, sonra created_at'i donmuş now()'a EŞİT yaz:
        //   created_at < now() - 1s              → YANLIŞ (taze görünür)
        //   created_at < clock_timestamp() - 1s  → DOĞRU  (gerçekten eskimiş)
        $this->asSystem(fn () => DB::select('SELECT pg_sleep(1.1)'));

        $this->asSystem(fn () => DB::statement(
            "UPDATE sync_operations
                SET created_at = date_trunc('second', now())
              WHERE id = ?",
            [$operation->id],
        ));

        $window = $this->asSystem(fn () => DB::selectOne(
            "SELECT (created_at >= now() - interval '1 second') AS fresh_to_frozen,
                    (created_at < clock_timestamp() - interval '1 second') AS stale_to_wall_clock
               FROM sync_operations WHERE id = ?",
            [$operation->id],
        ));

        $this->assertTrue(
            (bool) $window->fresh_to_frozen,
            'Pencere kurulamadı: operasyon donmuş now()\'a göre de eskimiş.',
        );
        $this->assertTrue(
            (bool) $window->stale_to_wall_clock,
            'Pencere kurulamadı: operasyon duvar saatine göre eskimemiş.',
        );

        $this->assertTrue(
            $this->scan(staleAfterSeconds: 1)->contains($operation->id),
            'Eşiği duvar saatine göre geçmiş operasyon elenmemeli — sorgu '.
            'now() değil clock_timestamp() kullanmalı.',
        );
    }

    /**
     * Bilinmeyen operasyon türü için İŞ ATILMAZ, uyarı yazılır.
     *
     * operation_type işin hangi sınıf olduğunu belirler. Yanlış iş atmak
     * yanlış yükü gönderirdi; henüz yazılmamış bir iş türünü sessizce yutmak
     * ise operasyonu sonsuza kadar takılı bırakırdı. Doğru davranış: bulundu
     * olarak SAYMA ve gürültü çıkar.
     *
     * ÖRNEK MEDIA_PUSH'TUR, ARTIK PRICE_PUSH DEĞİL: fiyat yolu §13 · Faz 3'te
     * yazıldı (`PushPrices`) ve tarama artık onu KURTARIR. Medya yolu ise
     * gerçekten hiç yazılmadı — örneğin dürüst kalması için tür değiştirildi.
     * Fiyat örneği bırakılsaydı test yeşil kalırdı ama sınadığı şey artık
     * var olmayan bir davranış olurdu.
     */
    #[Test]
    public function unknown_operation_type_is_logged_not_dispatched(): void
    {
        [$tenant, $listing, $event] = $this->makeContext();

        $operation = $this->operation($tenant, $listing, $event, createdMinutesAgo: 10);

        $this->asSystem(fn () => DB::table('sync_operations')
            ->where('id', $operation->id)
            ->update(['operation_type' => 'MEDIA_PUSH']));

        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(fn (string $message, array $context): bool => $message === 'sync.stuck_operation_no_job'
                && $context['operation'] === $operation->id
                && $context['operation_type'] === 'MEDIA_PUSH');

        $found = $this->scan();

        Queue::assertNothingPushed();
        $this->assertFalse(
            $found->contains($operation->id),
            'Dispatch edilemeyen operasyon kurtarılmış sayılmaz.',
        );
    }

    /**
     * Parti sınırı tek turu bağlar.
     *
     * TARAMA SATIRA YAZMADIĞI İÇİN TÜKENMEZ: seviye 1 taraması olayı
     * yeniden yayına alır ve kendi yükleminden çıkarır; bu tarama ise
     * damgalamaz — bilinçli bir karar, çünkü damgalamak attempt_count = 0
     * imzasını bozar. Sonuç: ikinci tur AYNI ilk iki operasyonu döner.
     *
     * Bu zararsızdır ve alternatifinden iyidir. Stok MUTLAK değer
     * gönderilir; ikinci dispatch aynı yükü tekrarlar, gruplamaya dahil
     * olan operasyonlar için ikinci iş boş yükle erken çıkar ve deneme
     * açmaz. Kaybolmuş işi hiç görmemek ise stoğun kanala hiç gitmemesi
     * demektir.
     */
    #[Test]
    public function limit_bounds_a_single_pass(): void
    {
        [$tenant, $listing, $event] = $this->makeContext();

        foreach (range(1, 3) as $version) {
            $this->operation($tenant, $listing, $event, createdMinutesAgo: 10, version: $version);
        }

        $first = $this->scan(limit: 2);
        $this->assertCount(2, $first, 'Tur başına en fazla limit kadar operasyon alınır.');

        // Tarama damgalamadığı için ikinci tur aynı satırları görür.
        $second = $this->scan(limit: 2);
        $this->assertSame(
            $first->all(),
            $second->all(),
            'Tarama satıra yazmaz: aynı sıralamayla aynı ilk parti döner.',
        );

        // Sınır kaldırıldığında üçü de görünür — hiçbiri elenmiş değil.
        $this->assertCount(3, $this->scan(limit: 10));
    }

    /**
     * YENİDEN ATILAN İŞ GERÇEKTEN ÇALIŞABİLMELİ — bağlamı kendi kurar.
     *
     * Tarama `runAsSystem()` içinde koşar ve işi kiracı bağlamı OLMADAN atar;
     * gerçek worker'da `Queue::looping` kancası zaten her iş sınırında bağlamı
     * temizler. İş bağlamı kendisi kurmazsa ilk tenant-scoped sorgu istisna
     * fırlatır ve seviye 2 taraması hiçbir şey KURTARMAZ — kurtarma mekanizması
     * sessizce ölür.
     *
     * "Dispatch edildi" iddiası bunu göstermez: iş atılmıştır ama çalışamaz.
     * Bu yüzden burada dispatch değil, ÇALIŞTIRMA sınanır.
     */
    #[Test]
    public function redispatched_job_can_run_without_tenant_context(): void
    {
        [$tenant, $listing, $event] = $this->makeContext();

        $operation = $this->operation($tenant, $listing, $event, createdMinutesAgo: 10);

        $this->scan();

        // Worker'daki gibi: BAĞLAM YOK.
        TenantContext::clear();

        (new PushInventory($operation->id, $tenant->id))->handle(
            app(InventoryBatchBuilder::class),
            app(SyncResultRecorder::class),
            app(AdapterRegistry::class),
        );

        // Bağlam İŞ BİTİNCE BIRAKILIR — sonraki işe sızmamalı.
        $this->assertFalse(
            TenantContext::hasTenant(),
            'İş bittiğinde bağlam bırakılmalı.',
        );
    }

    // ---------------------------------------------------------------- yardımcılar

    /** @return Collection<int, string> */
    private function scan(int $staleAfterSeconds = 300, int $limit = 500): Collection
    {
        return app(DetectStuckSyncOperations::class)->run(
            staleAfterSeconds: $staleAfterSeconds,
            limit: $limit,
        );
    }

    /** @return array{0: Tenant, 1: Listing, 2: OutboxEvent} */
    private function makeContext(): array
    {
        $tenant = (new CreateTenant)->run(
            name: 'Takılı '.uniqid(),
            owner: User::factory()->create(),
        );

        $variant = $this->asTenant($tenant, fn () => Variant::factory()->create());

        $this->asSystem(fn () => ChannelType::query()->firstOrCreate(
            ['code' => 'woocommerce'],
            [
                'name' => 'WooCommerce',
                'kind' => 'ecommerce',
                'adapter_class' => ProgrammableInventoryAdapter::class,
                'is_active' => true,
            ],
        ));

        $listing = $this->asTenant($tenant, fn () => Listing::factory()->create([
            'channel_connection_id' => ChannelConnection::factory()
                ->create(['channel_type_code' => 'woocommerce'])->id,
            'variant_id' => $variant->id,
        ]));

        // Fan-out zinciri TAMAMLANDI: olay yayınlandı ve tüketildi.
        $event = $this->asTenant($tenant, function () use ($tenant, $variant): OutboxEvent {
            $event = OutboxEvent::record(
                aggregateType: 'inventory_level',
                aggregateId: (string) new UuidV7,
                eventType: 'InventoryLevelChanged',
                payload: ['variant_id' => $variant->id, 'version' => 1],
                tenantId: $tenant->id,
            );

            $event->forceFill(['published_at' => now()->subMinutes(11)])->save();
            $event->markConsumed(operationsPlanned: 1);

            return $event->fresh();
        });

        return [$tenant, $listing, $event];
    }

    /**
     * Operasyon kurar — worker'ın hiç dokunmadığı hâliyle.
     *
     * created_at doğrudan SQL ile geri alınır: Eloquent timestamp'leri
     * kendi yazar ve modelin kaydı sırasında ezerdi.
     */
    private function operation(
        Tenant $tenant,
        Listing $listing,
        OutboxEvent $event,
        int $createdMinutesAgo,
        SyncOperationStatus $status = SyncOperationStatus::PENDING,
        int $attemptCount = 0,
        int $version = 1,
    ): SyncOperation {
        $operation = $this->asTenant($tenant, fn () => SyncOperation::query()->create([
            'tenant_id' => $tenant->id,
            'channel_connection_id' => $listing->channel_connection_id,
            'operation_type' => SyncDomain::INVENTORY->operationType(),
            'intent' => SyncIntent::NORMAL_SYNC,
            'entity_type' => 'listing',
            'entity_id' => $listing->id,
            'entity_version' => $version,
            'idempotency_key' => sprintf(
                '%s:%s:%d',
                SyncDomain::INVENTORY->keyPrefix(),
                $listing->id,
                $version,
            ),
            'status' => $status,
            'attempt_count' => $attemptCount,
            'outbox_event_id' => $event->id,
        ]));

        $this->asSystem(fn () => DB::table('sync_operations')
            ->where('id', $operation->id)
            ->update(['created_at' => now()->subMinutes($createdMinutesAgo)]));

        return $operation->fresh();
    }
}
