<?php

declare(strict_types=1);

namespace App\Domain\Sync\Jobs;

use App\Domain\Channels\Contracts\SupportsPricing;
use App\Domain\Channels\Registry\AdapterRegistry;
use App\Domain\Channels\Support\ChannelRateLimiter;
use App\Domain\Channels\Support\CircuitBreaker;
use App\Domain\Sync\Enums\SyncOperationStatus;
use App\Domain\Sync\Models\SyncOperation;
use App\Domain\Sync\Support\PriceBatchBuilder;
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
 * Fiyat yükünü kanala gönderir — YALNIZCA ORKESTRASYON.
 *
 * Mimari Karar Dokümanı v2.2 · §7 · SupportsPricing, §12 · İş tarafı, §8.
 *
 * `PushInventory`'nin kardeşidir ve aynı iskeleti izler: gruplamayı
 * `PriceBatchBuilder`, kanal konuşmasını adapter, durum yazımını
 * `SyncResultRecorder`, yeniden deneme kararını `RetryPolicy` yapar.
 *
 * KAPATILAN BOŞLUK: `pushPrices` gövdeleri (Woo VE Trendyol) ilk günden beri
 * hazırdı ama ÇEKİRDEKTE ÇAĞIRANI YOKTU — `SyncDomain::PRICE` ve `PRICE_PUSH`
 * şemada vardı, fiyat operasyonu açan ya da dispatch eden hiçbir kod yoktu.
 * Panelden fiyat düzeltmek kanala HİÇ yansımıyordu.
 *
 * ERKEN ÇIKIŞ — SUPERSEDED GÖNDERİLMEZ: iş kuyrukta beklerken daha yeni bir
 * fiyat istenmiş olabilir ve bayat fiyatı göndermek kanalda doğru olan veriyi
 * eskisiyle ezmek demektir. Fiyatta bunun bedeli stoktan AĞIRDIR: yanlış
 * fiyatla satış yapılır ve satış geri alınamaz.
 *
 * YENİDEN DENEME YENİ OPERASYON YARATMAZ: aynı operasyon aynı
 * `entity_version` ile tekrar denenir. Fiyat MUTLAK değer olarak gönderildiği
 * için tekrar zararsızdır (§7).
 *
 * KİRACI BAĞLAMINI İŞ KENDİ KURAR (§11 · P0): gerçek worker'da
 * `Queue::looping` kancası her iş sınırında bağlamı temizler ve `handle()` her
 * koşulda bağlamsız başlar. Bu iş İKİ yoldan atılır: fan-out tüketicisinden
 * (bağlam vardır) ve seviye 2 bütünlük taramasından (`runAsSystem`, bağlam
 * YOKTUR). Bağlam yükte taşınır, başta kurulur, `finally` ile bırakılır.
 */
final class PushPrices implements ShouldQueue
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
        PriceBatchBuilder $builder,
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
        PriceBatchBuilder $builder,
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

        // DEVRE KESİCİ — kanal ölü sayılıyorsa hiç deneme. Deneme AÇILMAZ ve
        // durum DEĞİŞMEZ: bu operasyon denenmedi, ERTELENDİ. Sayacı artırmak
        // yeniden deneme bütçesini boşa harcar ve seviye 2 taramasının
        // ("worker hiç çalışmadı") anlamını bozar.
        if (! $breaker->allows($connectionId)) {
            $this->release(CircuitBreaker::PAUSE_SECONDS);

            return;
        }

        // Her çağrıda YENİ örnek — paylaşılan adapter kiracı A'nın kimlik
        // bilgisini kiracı B'nin işinde kullanırdı (§7 · P0 güvenlik).
        $adapter = $registry->for($operation->connection);

        if (! $adapter instanceof SupportsPricing) {
            $recorder->recordSkipped($operation, 'channel_lacks_pricing_capability');

            return;
        }

        // HIZ SINIRI — kota tükendiyse kanalı hiç dövme. 429 almak da kotayı
        // harcar ve bazı kanallarda ceza süresi başlatır.
        if (! $limiter->attempt($connectionId, $adapter->rateLimitProfile())) {
            $this->release(max(
                $limiter->secondsUntilAvailable($connectionId, $adapter->rateLimitProfile()),
                1,
            ));

            return;
        }

        // GRUPLAMA: aynı bağlantıda bekleyen diğer fiyat operasyonlarını da
        // topla. Fan-out YAPMAZ — yalnızca var olanları birleştirir.
        $batch = $builder->build($operation);

        if ($batch->isEmpty()) {
            // Listing delist edilmiş veya dış kimliği yok. Deneme AÇILMAZ:
            // `attempt_count = 0` kalması seviye 2 taramasının anlamını korur.
            $recorder->recordSkipped($operation, 'nothing_to_push');

            return;
        }

        $attempt = $recorder->openAttempt($operation);          // attempt_count++ BURADA

        try {
            $result = $adapter->pushPrices($batch);

            $recorder->recordSuccess($batch->operations(), $attempt, $result);

            // Devre sayacını sıfırla: *ardışık* hata sayılır, toplam değil.
            $breaker->recordSuccess($connectionId);
        } catch (Throwable $e) {
            // Sınıflandırmayı ADAPTER yapar (kanal gövdesini yalnızca o
            // anlar), ne yapılacağına ÇEKİRDEK karar verir.
            $class = $adapter->classifyError($e);

            $recorder->recordFailure($batch->operations(), $attempt, $class, $e);

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
