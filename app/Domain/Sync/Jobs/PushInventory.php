<?php

declare(strict_types=1);

namespace App\Domain\Sync\Jobs;

use App\Domain\Channels\Contracts\AdapterResult;
use App\Domain\Channels\Contracts\SupportsInventory;
use App\Domain\Channels\Registry\AdapterRegistry;
use App\Domain\Channels\Support\ChannelRateLimiter;
use App\Domain\Channels\Support\CircuitBreaker;
use App\Domain\Sync\Enums\ErrorClass;
use App\Domain\Sync\Enums\SyncOperationStatus;
use App\Domain\Sync\Models\SyncOperation;
use App\Domain\Sync\Support\InventoryBatchBuilder;
use App\Domain\Sync\Support\InventoryPushBatch;
use App\Domain\Sync\Support\RetryPolicy;
use App\Domain\Sync\Support\SyncResultRecorder;
use App\Support\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Stok yükünü kanala gönderir — YALNIZCA ORKESTRASYON.
 *
 * Mimari Karar Dokümanı v2.2 · §12 · İş tarafı, §8.
 *
 * Bu sınıf iş mantığı taşımaz: gruplamayı InventoryBatchBuilder, kanal
 * konuşmasını adapter, durum yazımını SyncResultRecorder, yeniden deneme
 * kararını RetryPolicy yapar. İş yalnızca sırayı kurar.
 *
 * ERKEN ÇIKIŞ — SUPERSEDED GÖNDERİLMEZ:
 *   İş kuyrukta beklerken daha yeni bir sürüm istenmiş olabilir. Bayat
 *   sürümü göndermek, kanalda doğru olan veriyi eskisiyle ezmek demektir.
 *   Eski operasyon zaten OpenSyncOperation'da superseded işaretlenir;
 *   burada yapılan iş onu SAYGIYLA KARŞILAMAK.
 *
 * DEĞİŞMEZ KURAL — YENİDEN DENEME YENİ OPERASYON YARATMAZ:
 *   Aynı operasyon aynı entity_version ile tekrar denenir. Stok MUTLAK
 *   değer olarak gönderildiği için tekrar zararsızdır.
 *
 * ID TAŞINIR, MODEL DEĞİL: iş serileştirildiğinde model kopyası bayat
 * kalırdı; operasyon durumu tam da kuyrukta beklerken değişir.
 *
 * KİRACI BAĞLAMINI İŞ KENDİ KURAR (§11 · P0 güvenlik):
 *   Bu iş İKİ yoldan atılır: fan-out tüketicisinden (kiracı bağlamı vardır)
 *   ve seviye 2 bütünlük taramasından (`runAsSystem` içinde, bağlam YOKTUR).
 *   Gerçek worker'da `Queue::looping` kancası her iş sınırında bağlamı
 *   temizler, bu yüzden handle() her koşulda bağlamsız başlar. Bağlam yükte
 *   taşınır, başta kurulur, `finally` ile bırakılır — bırakılmazsa sonraki
 *   işe sızar ve kiracı A'nın bağlamıyla kiracı B'nin verisi yazılırdı.
 *
 *   Bu olmadan seviye 2 taraması hiçbir şey KURTARMAZ: iş atılır, ilk
 *   tenant-scoped sorguda düşer ve kurtarma mekanizması sessizce ölür.
 */
final class PushInventory implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $operationId,
        public readonly string $tenantId,
    ) {}

    public function handle(
        InventoryBatchBuilder $builder,
        SyncResultRecorder $recorder,
        AdapterRegistry $registry,
        ?CircuitBreaker $breaker = null,
        ?ChannelRateLimiter $limiter = null,
    ): void {
        TenantContext::set($this->tenantId);

        try {
            $this->push($builder, $recorder, $registry, $breaker, $limiter);
        } finally {
            TenantContext::clear();
        }
    }

    private function push(
        InventoryBatchBuilder $builder,
        SyncResultRecorder $recorder,
        AdapterRegistry $registry,
        ?CircuitBreaker $breaker,
        ?ChannelRateLimiter $limiter,
    ): void {
        $breaker ??= app(CircuitBreaker::class);
        $limiter ??= app(ChannelRateLimiter::class);

        $operation = SyncOperation::query()->find($this->operationId);

        // Operasyon silinmiş, tamamlanmış veya bayat kalmış: gönderme.
        if ($operation === null || $operation->status->isTerminal()) {
            return;
        }

        if ($operation->status === SyncOperationStatus::DEAD) {
            return;
        }

        $connectionId = $operation->channel_connection_id;

        // DEVRE KESİCİ — kanal ölü sayılıyorsa hiç deneme.
        //
        // Deneme AÇILMAZ ve durum DEĞİŞMEZ: bu operasyon denenmedi,
        // ertelendi. Sayacı artırmak yeniden deneme bütçesini boşa harcar
        // ve seviye 2 taramasının ("worker hiç çalışmadı") anlamını bozar.
        if (! $breaker->allows($connectionId)) {
            $this->release(CircuitBreaker::PAUSE_SECONDS);

            return;
        }

        // Her çağrıda YENİ örnek — paylaşılan adapter kiracı A'nın kimlik
        // bilgisini kiracı B'nin işinde kullanırdı (§7, P0 güvenlik).
        $adapter = $registry->for($operation->connection);

        if (! $adapter instanceof SupportsInventory) {
            $recorder->recordSkipped($operation, 'channel_lacks_inventory_capability');

            return;
        }

        // HIZ SINIRI — kota tükendiyse kanalı hiç dövme.
        //
        // 429 almak da kotayı harcar ve bazı kanallarda ceza süresi
        // başlatır; sınıra biz uyarsak kanal hiç reddetmek zorunda kalmaz.
        // Profili ADAPTER bildirir, uygulamayı çekirdek yapar.
        if (! $limiter->attempt($connectionId, $adapter->rateLimitProfile())) {
            $this->release(max(
                $limiter->secondsUntilAvailable($connectionId, $adapter->rateLimitProfile()),
                1,
            ));

            return;
        }

        // GRUPLAMA: aynı bağlantıda bekleyen diğer operasyonları da topla.
        // Fan-out YAPMAZ — yalnızca var olan operasyonları birleştirir.
        $batch = $builder->build($operation);

        if ($batch->isEmpty()) {
            // Listing delist edilmiş veya stok satırı yok. Deneme AÇILMAZ:
            // hiçbir şey denenmedi ve attempt_count = 0 kalması, seviye 2
            // taramasının anlamını korur.
            $recorder->recordSkipped($operation, 'nothing_to_push');

            return;
        }

        $attempt = $recorder->openAttempt($operation);          // attempt_count++ BURADA

        try {
            $result = $adapter->pushInventory($batch);

            $recorder->recordSuccess($batch->operations(), $attempt, $result);

            // ⚠️ KISMİ BAŞARIDA BAŞARISIZ KALEMLER ÖLDÜRÜLÜR (§13.4).
            //
            // `recordSuccess` onları `retrying` bırakır — istisna
            // fırlamadığı için `catch` bloğu HİÇ çalışmaz ve
            // `RetryPolicy`/`release` yolu da işlemez. Dokunulmasaydı o
            // satırlar `retrying` durumunda ve `attempt_count > 0` ile
            // SONSUZA KADAR asılı kalırdı: seviye 2 taraması yalnızca
            // `attempt_count = 0` olanları kurtarır (§6) ve bu satırlar o
            // filtreye TAKILMAZ — hiçbir mekanizma onları bir daha
            // görmezdi.
            //
            // Ölü satır `/failures` ekranında GÖRÜNÜR ve tek tıkla
            // yeniden denenebilir (§12); asılı satır GÖRÜNMEZ.
            if ($result->hasFailedOperations()) {
                $recorder->markDead(
                    $this->failedOperationsIn($batch, $result),
                    $result->errorClass ?? ErrorClass::VALIDATION,
                );
            }

            // Devre sayacını sıfırla: "ardışık" hata sayılır, toplam değil.
            // Yarı açıktaysa bu başarı devreyi kapatır.
            //
            // ⚠️ KISMİ BAŞARIDA DA BAŞARI YAZILIR: kanal cevap verdi ve
            // çağrıların çoğu geçti — altyapı SAĞLIKLIDIR. Başarısızlık
            // KALEM seviyesindedir ve devreyi açmak çalışan bir kanalı
            // kapatmak olurdu.
            $breaker->recordSuccess($connectionId);
        } catch (Throwable $e) {
            // Sınıflandırmayı ADAPTER yapar (kanal gövdesini yalnızca o
            // anlar), ne yapılacağına ÇEKİRDEK karar verir.
            $class = $adapter->classifyError($e);

            $recorder->recordFailure($batch->operations(), $attempt, $class, $e);

            // Devre kesici hatayı SAYAR; eşiğe ulaşınca kanalı duraklatır.
            // AUTHENTICATION eşiği beklemez, tek hatada süresiz açar.
            $breaker->recordFailure($connectionId, $class);

            $delay = RetryPolicy::delayFor($class, $operation->fresh()->attempt_count);

            if ($delay !== null) {
                $this->release($delay);

                return;
            }

            $recorder->markDead($batch->operations(), $class);
        }
    }

    /**
     * Kısmi başarıda GEÇMEYEN operasyonlar.
     *
     * ⚠️ EŞLEŞTİRME KİMLİKLEDİR, SIRAYLA DEĞİL — kanalın yanıt dizisi
     * gönderim sırasını KORUMAYABİLİR ve konumla eşleştirme bir kalemin
     * hatasını BAŞKA bir operasyona yazardı.
     *
     * ⚠️ YALNIZCA YÜKTE OLAN OPERASYONLAR DÖNER. Adapter tanımadığı bir
     * kimlik bildirirse (kanal gövdesi bozuksa) o kimlik SESSİZCE
     * atlanır: yükte olmayan operasyona dokunmak v2.2'nin açık yasağıdır
     * ve başka bir bağlantının satırını öldürebilirdi.
     *
     * @return list<SyncOperation>
     */
    private function failedOperationsIn(InventoryPushBatch $batch, AdapterResult $result): array
    {
        $failed = [];

        foreach ($batch->operations() as $operation) {
            if (isset($result->failedOperations[$operation->id])) {
                $failed[] = $operation;
            }
        }

        return $failed;
    }
}
