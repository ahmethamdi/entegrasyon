<?php

declare(strict_types=1);

namespace App\Domain\Sync\Jobs;

use App\Domain\Channels\Contracts\AdapterResult;
use App\Domain\Channels\Contracts\SupportsApprovalWorkflow;
use App\Domain\Channels\Contracts\SupportsOfferLifecycle;
use App\Domain\Channels\Registry\AdapterRegistry;
use App\Domain\Channels\Support\ChannelRateLimiter;
use App\Domain\Channels\Support\CircuitBreaker;
use App\Domain\Sync\Models\Listing;
use App\Domain\Sync\Models\SyncOperation;
use App\Domain\Sync\Support\ListingPayloadBuilder;
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
 * Çok adımlı yayın zinciri — `PushListing`'in ARA KİMLİKLİ kardeşi.
 *
 * V3.0 · §03 · Delta 1 · §13.1 · §13.2 · v2.2 §8 · §12.
 *
 * ═════════════════════════════════════════════════════════════════════
 * NEDEN AYRI BİR İŞ — `PushListing` GENİŞLETİLMEDİ
 * ═════════════════════════════════════════════════════════════════════
 * O iş TEK çağrılık bir yayın varsayar ve `create` mi `update` mi
 * sorusunu `external_id` ile cevaplar. Burada soru ÜÇ CEVAPLIDIR ve
 * `channel_metadata`'dan okunur; aynı metoda sıkıştırılsalardı
 * `PushListing` kanal şekline bakan dallarla dolar ve beş kanalın
 * çalışan yolu, altıncı kanal için yazılan bir `if` yüzünden riske
 * girerdi.
 *
 * İSKELET AYNIDIR ve bilinçlidir: devre kesici → yetenek kapısı → hız
 * sınırı → deneme aç → gönder → sonucu yaz. Kopyalanan şey MANTIK değil
 * SIRADIR; her adım aynı çekirdek sınıflara devreder
 * (`SyncResultRecorder`, `RetryPolicy`, `CircuitBreaker`).
 *
 * ═════════════════════════════════════════════════════════════════════
 * ⚠️ ZİNCİR KALDIĞI YERDEN DEVAM EDER — BU İŞİN VARLIK SEBEBİ (§13.2)
 * ═════════════════════════════════════════════════════════════════════
 *     if (metadata.listing_id)   → offer'ı GÜNCELLE (yayın zaten var)
 *     elseif (metadata.offer_id) → publishOffer      ← KALDIĞI YER
 *     else                       → tam zincir
 *
 * Ara başarısızlıkta (offer yaratıldı, publish 429 aldı) `offer_id`
 * SAKLANIR. Saklanmasaydı sonraki tur `POST /offer`'ı İKİNCİ KEZ çağırır
 * ve kanal `25002` (duplicate offer) döner — `VALIDATION`, yani KALICI
 * hata; listing "düzeltilemez" damgasıyla ölür.
 *
 * ⚠️ HER ADIM KENDİ SONUCUNU HEMEN YAZAR ve bu, "kimlik BAŞARIDAN SONRA
 * yazılır" kuralının ÇOK ADIMLI biçimidir. `PushListing`'de tek bir
 * başarı anı vardır; burada ÜÇ vardır ve ikincisinin çıktısı
 * üçüncüsünün ÖN KOŞULUDUR. Yazım sona bırakılsaydı zincirin ortasında
 * düşen tur hiçbir iz bırakmazdı — tam olarak Delta 1'in önlemek için
 * var olduğu durum.
 *
 * ⚠️ `channel_metadata` BİRLEŞTİRİLİR, EZİLMEZ. `offer_id` ilk adımda,
 * `listing_id` üçüncüde yazılır; ezilseydi ikinci yazım birincisini
 * götürür ve kurtarma çıpası kaybolurdu.
 */
final class PushOfferListing implements ShouldQueue
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
        ListingPayloadBuilder $builder,
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
        ListingPayloadBuilder $builder,
        SyncResultRecorder $recorder,
        AdapterRegistry $registry,
        ?CircuitBreaker $breaker,
        ?ChannelRateLimiter $limiter,
    ): void {
        $breaker ??= app(CircuitBreaker::class);
        $limiter ??= app(ChannelRateLimiter::class);

        $operation = SyncOperation::query()->find($this->operationId);

        if ($operation === null || $operation->status->isTerminal()) {
            return;
        }

        $connectionId = $operation->channel_connection_id;

        if (! $breaker->allows($connectionId)) {
            $this->release(CircuitBreaker::PAUSE_SECONDS);

            return;
        }

        $adapter = $registry->for($operation->connection);

        // Yetenek `instanceof` ile okunur (§7).
        if (! $adapter instanceof SupportsOfferLifecycle) {
            $recorder->recordSkipped($operation, 'channel_lacks_offer_lifecycle_capability');

            return;
        }

        $listing = $operation->listing;

        if ($listing === null) {
            $recorder->recordSkipped($operation, 'listing_missing');

            return;
        }

        if (! $limiter->attempt($connectionId, $adapter->rateLimitProfile())) {
            $this->release(max(
                $limiter->secondsUntilAvailable($connectionId, $adapter->rateLimitProfile()),
                1,
            ));

            return;
        }

        $attempt = $recorder->openAttempt($operation);

        try {
            $result = $this->runChain($adapter, $listing, $builder, $operation->entity_version);

            $recorder->recordSuccess([$operation], $attempt, $result);

            $breaker->recordSuccess($connectionId);
        } catch (Throwable $e) {
            $class = $adapter->classifyError($e);

            $recorder->recordFailure([$operation], $attempt, $class, $e);

            $breaker->recordFailure($connectionId, $class);

            $delay = RetryPolicy::delayFor($class, $operation->fresh()->attempt_count);

            if ($delay !== null) {
                $this->release($delay);

                return;
            }

            $recorder->markDead([$operation], $class);
        }
    }

    /**
     * Üç adımlı zinciri KALDIĞI YERDEN yürütür (§13.2).
     *
     * ⚠️ HER ADIMDAN SONRA KİMLİK HEMEN KALICILAŞIR. Bir sonraki adım
     * patlarsa istisna yükselir ve `push()` onu sınıflandırır — ama
     * yazılan kimlik SATIRDA KALIR ve sonraki tur oradan devam eder.
     *
     * ⚠️ ZİNCİRİN SON SONUCU DÖNER, İLKİ DEĞİL. `SyncResultRecorder`
     * dönen `AdapterResult`'ı işler; ilk adımınki dönseydi `external_id`
     * hiç görünmez ve satır yayınlanmış olmasına rağmen kimliksiz
     * kalırdı.
     */
    private function runChain(
        SupportsOfferLifecycle $adapter,
        Listing $listing,
        ListingPayloadBuilder $builder,
        int $version,
    ): AdapterResult {
        $payload = $builder->build($listing, $version);

        // Onay süreci YETENEKTEN okunur, kanal adı SORULMAZ (§7).
        // eBay'de onay süreci yoktur ama arayüz kanal-agnostiktir ve
        // ikinci uygulayıcı (Amazon) onu taşıyabilir.
        $awaitsApproval = $adapter instanceof SupportsApprovalWorkflow;

        // ① ENVANTER KALEMİ — HER TURDA çağrılır ve bu DOĞRUDUR.
        //
        // PUT idempotenttir (§13.1): ikinci çağrı kopya YARATMAZ, içeriği
        // günceller. Atlanabilseydi başlık/açıklama değişiklikleri kanala
        // HİÇ ulaşmazdı — `content_version` artmış olmasına rağmen.
        $this->persist($listing, $adapter->upsertInventoryItem($listing, $payload), $awaitsApproval);

        // ② OFFER — yoksa yaratır, varsa günceller.
        //
        // Dönen `offer_id` KURTARMA ÇIPASIDIR ve hemen yazılır; üçüncü
        // adım patlarsa sonraki tur bu satırdan devam eder.
        $offerResult = $adapter->upsertOffer($listing, $payload);
        $this->persist($listing, $offerResult, $awaitsApproval);

        // ③ YAYIN — YALNIZCA henüz yayınlanmamışsa.
        //
        // ⚠️ `listing_id` VARSA PUBLISH TEKRAR ÇAĞRILMAZ. Çağrılsaydı
        // eBay yayında olan bir offer için hata döndürür ve her içerik
        // güncellemesi başarısız olurdu — oysa ② adımı zaten yayındaki
        // ilanı güncelledi.
        if ($listing->external_id === null) {
            return $this->persist($listing, $adapter->publishOffer($listing), $awaitsApproval);
        }

        return $offerResult;
    }

    /**
     * Adımın döndürdüğü kimlikleri HEMEN kalıcılaştırır.
     *
     * `PushListing::adoptRemoteIdentity()`'nin çok adımlı karşılığıdır ve
     * aynı kuralları taşır:
     *
     *   · `channel_metadata` BİRLEŞTİRİLİR, EZİLMEZ — `offer_id` ilk
     *     adımda, `listing_id` üçüncüde yazılır ve ezilseydi kurtarma
     *     çıpası kaybolurdu (§13.2).
     *   · Yaşam döngüsü ancak GERÇEK yayından sonra `live` olur; canlı
     *     işareti fan-out hedefidir ve kanalda karşılığı olmayan satıra
     *     stok göndermek her turda hata alırdı.
     *   · Onay süreci olan kanalda `pending_approval` yazılır — yetenek
     *     `instanceof` ile okunur, kanal adı SORULMAZ.
     *
     * ⚠️ KİMLİK GELMEYEN ADIM SATIRA DOKUNMAZ. ① envanter kalemi hiçbir
     * uzak kimlik döndürmez (kimlik SKU'nun KENDİSİDİR) ve o adımdan
     * sonra yazılacak bir şey yoktur; koşulsuz `save()` çağrılsaydı her
     * turda gereksiz bir UPDATE atılırdı.
     */
    private function persist(
        Listing $listing,
        AdapterResult $result,
        bool $awaitsApproval = false,
    ): AdapterResult {
        $attributes = [];

        $externalId = $result->data['external_id'] ?? null;

        if ($externalId !== null) {
            $attributes['external_id'] = (string) $externalId;
            $attributes['lifecycle_status'] = $awaitsApproval ? 'pending_approval' : 'live';
            // Yayına giriş tarihi ancak GERÇEKTEN yayındayken anlamlıdır;
            // onay bekleyen satırda yazılsaydı ürünün kanaldaki yaşı
            // olduğundan eski görünürdü (`PushListing` kuralının aynısı).
            $attributes['listed_at'] = $awaitsApproval
                ? $listing->listed_at
                : ($listing->listed_at ?? now());
        }

        foreach (['external_url', 'external_parent_id'] as $key) {
            if (isset($result->data[$key])) {
                $attributes[$key] = $result->data[$key];
            }
        }

        $metadata = $result->data['channel_metadata'] ?? null;

        if (is_array($metadata) && $metadata !== []) {
            $attributes['channel_metadata'] = [
                ...($listing->channel_metadata ?? []),
                ...$metadata,
            ];
        }

        if ($attributes !== []) {
            $listing->forceFill($attributes)->save();
        }

        return $result;
    }
}
