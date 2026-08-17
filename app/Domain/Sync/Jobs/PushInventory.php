<?php

declare(strict_types=1);

namespace App\Domain\Sync\Jobs;

use App\Domain\Channels\Contracts\SupportsInventory;
use App\Domain\Channels\Registry\AdapterRegistry;
use App\Domain\Channels\Support\ChannelRateLimiter;
use App\Domain\Channels\Support\CircuitBreaker;
use App\Domain\Sync\Enums\SyncOperationStatus;
use App\Domain\Sync\Models\SyncOperation;
use App\Domain\Sync\Support\InventoryBatchBuilder;
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

            // Devre sayacını sıfırla: "ardışık" hata sayılır, toplam değil.
            // Yarı açıktaysa bu başarı devreyi kapatır.
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
}
