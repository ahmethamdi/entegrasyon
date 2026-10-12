<?php

declare(strict_types=1);

namespace App\Domain\Sync\Jobs;

use App\Domain\Channels\Contracts\AdapterResult;
use App\Domain\Channels\Contracts\GuardsAmbiguousCreate;
use App\Domain\Channels\Contracts\SupportsApprovalWorkflow;
use App\Domain\Channels\Contracts\SupportsCatalog;
use App\Domain\Channels\Enums\OutboundWrite;
use App\Domain\Channels\Registry\AdapterRegistry;
use App\Domain\Channels\Support\ChannelRateLimiter;
use App\Domain\Channels\Support\CircuitBreaker;
use App\Domain\Channels\Support\OutboundWriteGate;
use App\Domain\Sync\Enums\ErrorClass;
use App\Domain\Sync\Exceptions\RemoteListingStateUnknown;
use App\Domain\Sync\Jobs\Concerns\DeadLettersWhenAbandoned;
use App\Domain\Sync\Models\Listing;
use App\Domain\Sync\Models\SyncOperation;
use App\Domain\Sync\Support\AdapterReportedFailure;
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
 * Ürün içeriğini kanala gönderir — YALNIZCA ORKESTRASYON.
 *
 * Mimari Karar Dokümanı v2.2 · §13 · faz 1.5, §8, §12 · İş tarafı.
 *
 * Bu sınıf iş mantığı taşımaz: yükü ListingPayloadBuilder, kanal konuşmasını
 * adapter, durum yazımını SyncResultRecorder, yeniden deneme kararını
 * RetryPolicy yapar. İş yalnızca sırayı kurar — PushInventory ile aynı iskelet.
 *
 * CREATE Mİ UPDATE Mİ — SORU external_id İLE CEVAPLANIR:
 *   external_id NULL ise ürün kanalda yoktur. Ama doğrudan create ETMEZ:
 *   önce findExistingListing() ile kanalda aynı SKU aranır. Bu adım olmadan
 *   daha önce elle açılmış ürünler yeniden yaratılır ve kanalda KOPYA
 *   listeler oluşur — geri alınamaz ve satıcının yorumları, sıralaması
 *   ilk üründe kalır (§7 · SupportsCatalog).
 *
 * GRUPLAMA YOK: içerik yükü listing başınadır (bkz. ListingPayloadBuilder).
 *
 * KİMLİK YAZIMI ÇEKİRDEĞİN İŞİ: adapter yan etkisizdir ve veritabanına
 * yazmaz; kanaldan dönen external_id AdapterResult ile taşınır ve burada
 * yazılır. Yazma BAŞARIDAN SONRA yapılır — kanal ürünü yaratmadıysa kimlik
 * yazmak, sonraki turda var olmayan ürüne update çağırtırdı.
 *
 * ID TAŞINIR, MODEL DEĞİL: iş serileştirildiğinde model kopyası bayat kalır.
 *
 * KİRACI BAĞLAMINI İŞ KENDİ KURAR (§11 · P0 güvenlik):
 *   Bu iş panelden DOĞRUDAN atılır; `PushInventory` gibi bir TenantAwareJob
 *   (`ConsumeOutboxEvent`) içinden çağrılmaz. Gerçek worker'da `Queue::looping`
 *   kancası her iş sınırında bağlamı TEMİZLER, bu yüzden handle() bağlamsız
 *   başlar ve ilk tenant-scoped sorgu istisna fırlatır — korumanın doğru
 *   davranışı. Bağlam yükte taşınır, başta kurulur, `finally` ile bırakılır:
 *   bırakılmazsa sonraki işe sızar ve kiracı A'nın bağlamıyla kiracı B'nin
 *   verisi yazılırdı.
 *
 *   `TenantAwareJob` genişletilemez: onun `handle()` metodu `final` ve
 *   parametresizdir, bu iş ise bağımlılıklarını enjeksiyonla alır.
 */
final class PushListing implements ShouldQueue
{
    use DeadLettersWhenAbandoned;
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

        // Operasyon silinmiş, tamamlanmış veya bayat kalmış: gönderme.
        // Superseded burada elenir — kuyrukta beklerken yeni sürüm istenmişse
        // bayat içeriği göndermek kanalda doğru olan veriyi eskisiyle ezerdi.
        if ($operation === null || $operation->status->isTerminal()) {
            return;
        }

        // ADR-010 · YAZMA İZNİ — kimlik bilgisi ve adapter yeteneği izin
        // DEĞİLDİR. Kapı İŞİN ÇALIŞTIĞI ANDA veritabanından okunur: atıldıktan
        // sonra kapatılan izin, bayat kuyruk işi ve yeniden deneme de burada
        // durur. Devre kesici ve hız sınırından ÖNCE: kapalı izinde kanala
        // tek istek (arama dahil) gitmez.
        if (! app(OutboundWriteGate::class)->allows($operation->channel_connection_id, $this->tenantId, OutboundWrite::PRODUCT_PUBLISH)) {
            $recorder->markOutboundDisabled($operation, OutboundWrite::PRODUCT_PUBLISH);

            return;
        }

        $connectionId = $operation->channel_connection_id;

        // DEVRE KESİCİ — kanal ölü sayılıyorsa hiç deneme.
        // Deneme AÇILMAZ ve durum DEĞİŞMEZ: bu operasyon denenmedi, ertelendi.
        if (! $breaker->allows($connectionId)) {
            $this->release(CircuitBreaker::PAUSE_SECONDS);

            return;
        }

        // Her çağrıda YENİ örnek — paylaşılan adapter kiracı A'nın kimlik
        // bilgisini kiracı B'nin işinde kullanırdı (§7, P0 güvenlik).
        $adapter = $registry->for($operation->connection);

        // Yetenek instanceof ile okunur; `if type === '...'` yazılmaz (§7).
        if (! $adapter instanceof SupportsCatalog) {
            $recorder->recordSkipped($operation, 'channel_lacks_catalog_capability');

            return;
        }

        $listing = $operation->listing;

        if ($listing === null) {
            // Listing silinmiş; gönderilecek bir şey yok. Deneme AÇILMAZ.
            $recorder->recordSkipped($operation, 'listing_missing');

            return;
        }

        // ZARAR KORUMASI — ilan açma ve içerik güncellemesi de fiyat taşır
        // (Shopify, Trendyol, Etsy eşleyicileri `effectivePrice()` yazar).
        // Yalnız fiyat işinde durdurulsaydı maliyet altı fiyat ilk açılışta
        // ya da bir başlık düzeltmesinde kanala yine giderdi.
        $violation = $listing->priceFloorViolation();

        if ($violation !== null) {
            $recorder->markBlocked($operation, $violation);

            return;
        }

        // HIZ SINIRI — kota tükendiyse kanalı hiç dövme. 429 almak da kotayı
        // harcar; sınıra biz uyarsak kanal hiç reddetmek zorunda kalmaz.
        if (! $limiter->attempt($connectionId, $adapter->rateLimitProfile())) {
            $this->release(max(
                $limiter->secondsUntilAvailable($connectionId, $adapter->rateLimitProfile()),
                1,
            ));

            return;
        }

        $attempt = $recorder->openAttempt($operation);          // attempt_count++ BURADA

        try {
            $result = AdapterReportedFailure::throwIfFailed(
                $this->send($adapter, $listing, $builder, $operation->entity_version)
            );

            // Kimlik ve yaşam döngüsü BAŞARIDAN SONRA yazılır.
            $this->adoptRemoteIdentity($listing, $result, $adapter);

            $recorder->recordSuccess([$operation], $attempt, $result);

            // Devre sayacını sıfırla: "ardışık" hata sayılır, toplam değil.
            $breaker->recordSuccess($connectionId);
        } catch (Throwable $e) {
            // Sınıflandırmayı ADAPTER yapar, ne yapılacağına ÇEKİRDEK karar verir.
            $class = AdapterReportedFailure::classify($e, $adapter);

            $recorder->recordFailure([$operation], $attempt, $class, $e);

            $breaker->recordFailure($connectionId, $class);

            $delay = RetryPolicy::delayFor(
                $class,
                $operation->fresh()->attempt_count,
                AdapterReportedFailure::retryAfterOf($e),
            );

            if ($delay !== null) {
                $this->release($delay);

                return;
            }

            $recorder->markDead([$operation], $class);
        }
    }

    /**
     * Kanala gönderir — create mi update mi kararı burada.
     *
     * external_id boşken ÖNCE kanalda arama yapılır: satıcı ürünü daha önce
     * kanal panelinden açmış olabilir. Bulunursa o kimlik benimsenir ve
     * update yoluna girilir; yaratmak kopya listeleme üretirdi.
     */
    private function send(
        SupportsCatalog $adapter,
        Listing $listing,
        ListingPayloadBuilder $builder,
        int $version,
    ): AdapterResult {
        if ($listing->external_id === null) {
            $existing = $adapter->findExistingListing($listing->variant);

            if ($existing !== null && $adapter instanceof GuardsAmbiguousCreate && $listing->create_requested_at !== null) {
                // KENDİ belirsiz yaratmamızın UZLAŞTIRILMASI: kanal isteği
                // almış ve ürünü açmış. Güncelleme DEĞİL — gönderilen içerik
                // zaten oradadır; yalnız kimlik benimsenir.
                return AdapterResult::success([
                    'external_id' => $existing->externalId,
                    'external_parent_id' => $existing->parentExternalId,
                    'channel_metadata' => ['create_reconciled_at' => now()->toIso8601String()],
                ]);
            }

            if ($existing !== null) {
                // Kimlik BELLEKTE benimsenir; kalıcı yazma başarıdan sonra.
                // Çağrı patlarsa satır dokunulmamış kalmalı.
                $listing->external_id = $existing->externalId;

                // ⚠️ ÜST KİMLİK DE BENİMSENİR. Alınmasaydı iki seviyeli
                // kanalda (Shopify product → variant) update yolu hangi
                // ürünü yazacağını bilemez ve satıcının kanalda zaten açtığı
                // HER ürün kalıcı hataya düşerdi.
                $listing->external_parent_id ??= $existing->parentExternalId;
            }
        }

        if ($listing->external_id !== null) {
            return $adapter->updateListing($builder->build($listing, $version));
        }

        if (! $adapter instanceof GuardsAmbiguousCreate) {
            return $adapter->createListing($builder->build($listing, $version));
        }

        return $this->createOnce($adapter, $listing, $builder, $version);
    }

    /**
     * Asenkron yaratmalı kanalda (HB, N11) yaratma isteği listing başına
     * BİR KEZ gider.
     *
     * Talep istekten ÖNCE koşullu yazılır (`create_requested_at IS NULL`):
     * kuyruk tekrarı ya da eşzamanlı ikinci iş yarışı kaybeder. Talep
     * doluysa önceki istek kanala gitmiş OLABİLİR (zaman aşımı, 5xx) —
     * arama (`findExistingListing`) zaten yukarıda yapıldı ve bulamadı;
     * ikinci istek kopya ilan riskidir → `RemoteListingStateUnknown`
     * (geçici; sonraki denemeler yine ARAR). Kesin ret (doğrulama, kimlik,
     * hız sınırı) istek kabul edilmedi demektir; talep geri alınır.
     */
    private function createOnce(
        SupportsCatalog&GuardsAmbiguousCreate $adapter,
        Listing $listing,
        ListingPayloadBuilder $builder,
        int $version,
    ): AdapterResult {
        // Yük ÖNCE kurulur: eksik veri (ListingNotPublishable) talep almadan düşer.
        $payload = $builder->build($listing, $version);

        $claimed = Listing::query()
            ->whereKey($listing->id)
            ->whereNull('external_id')
            ->whereNull('create_requested_at')
            ->update(['create_requested_at' => now()]);

        if ($claimed !== 1) {
            throw new RemoteListingStateUnknown(
                "Listing {$listing->id}: önceki yaratma isteğinin sonucu belirsiz ve kanalda kayıt bulunamadı; yeniden gönderilmedi."
            );
        }

        try {
            return AdapterReportedFailure::throwIfFailed($adapter->createListing($payload));
        } catch (Throwable $e) {
            $class = AdapterReportedFailure::classify($e, $adapter);

            if (in_array($class, [ErrorClass::VALIDATION, ErrorClass::AUTHENTICATION, ErrorClass::RATE_LIMITED], true)) {
                Listing::query()->whereKey($listing->id)->update(['create_requested_at' => null]);
            }

            throw $e;
        }
    }

    /**
     * Kanaldan dönen kimliği ve yaşam döngüsünü yazar.
     *
     * Taslak satır ancak kanala GİRDİKTEN sonra canlı olur: canlı işareti
     * fan-out hedefidir ve kanalda karşılığı olmayan satıra stok göndermek
     * her turda hata alırdı.
     *
     * ONAY SÜRECİ OLAN KANALDA `live` DEĞİL `pending_approval` YAZILIR:
     *   Trendyol gönderilen ürünü hemen yayına almaz; onay bekler ve
     *   reddedebilir. Doğrudan canlı işaretlenseydi henüz yayında olmayan
     *   satır fan-out hedefi olur ve her stok turunda hata alırdı — üstelik
     *   panel "yayında" derken ürün kanalda görünmezdi. Gerçek canlı
     *   işaretini `TrackApprovalStatus` kanaldan öğrenerek yazar.
     *
     *   Yetenek `instanceof` ile okunur: Woo'da onay süreci yoktur ve
     *   satır eskisi gibi doğrudan canlı olur.
     *
     * ÜST ÜRÜN VE KANALA ÖZGÜ KİMLİKLER DE BURADA YAZILIR (V3.0 · §07):
     *   Bazı kanallar TEK bir kimlik döndürmez. Shopify variant + product +
     *   inventory item, Etsy product + listing + offering, eBay listing +
     *   offer taşır ve ikisi de KALICIDIR. `external_parent_id` v2.2'de
     *   tanımlı ama hiç kullanılmamıştı; `channel_metadata` Faz 0'da eklendi.
     *
     *   YAZIM KANAL-AGNOSTİKTİR: adapter hangi kimlikleri döndüreceğini
     *   bilir, çekirdek yalnızca taşır. `if ($channel === 'shopify')`
     *   YAZILMAZ — yeni kanal eklendiğinde bu metot DEĞİŞMEZ.
     *
     *   `channel_metadata` BİRLEŞTİRİLİR, EZİLMEZ: eBay'in üç adımlı yayını
     *   `offer_id`'yi ilk adımda, `listing_id`'yi üçüncüde yazar (§13.2).
     *   Ezilseydi ara başarısızlıktan sonraki tur `offer_id`'yi kaybeder,
     *   ikinci bir offer yaratır ve kanal `25002` duplicate döndürürdü —
     *   KALICI hata, listing "düzeltilemez" damgasıyla ölür.
     */
    private function adoptRemoteIdentity(
        Listing $listing,
        AdapterResult $result,
        SupportsCatalog $adapter,
    ): void {
        $externalId = $result->data['external_id'] ?? $listing->external_id;

        if ($externalId === null) {
            return;
        }

        // ⚠️ ZATEN CANLI SATIR GÜNCELLEMEDE CANLI KALIR (A11 ④b).
        //
        // Onaylı bir Trendyol ürününün içeriği güncellenince ürün kanalda
        // satışta KALIR. Satır `pending_approval`'a düşürülseydi fan-out
        // hedefinden çıkar ve onay takibi onu yeniden "onaylı" görene kadar
        // stok GİTMEZDİ — o arada satılan ürün kanalda eski stokla kalır,
        // fazla satış olurdu. Reddedilmiş ya da bekleyen satır ise yeniden
        // gönderimde onay beklemeye döner.
        $awaitsApproval = $adapter instanceof SupportsApprovalWorkflow
            && $listing->lifecycle_status !== 'live';

        $attributes = array_filter([
            'external_id' => (string) $externalId,
            'external_url' => $result->data['external_url'] ?? $listing->external_url,
            'external_parent_id' => $result->data['external_parent_id']
                ?? $listing->external_parent_id,
            'lifecycle_status' => $awaitsApproval ? 'pending_approval' : 'live',
            // Yayına giriş tarihi ancak GERÇEKTEN yayındayken anlamlıdır;
            // onay bekleyen satırda yazılsaydı ürünün kanaldaki yaşı
            // olduğundan eski görünürdü.
            'listed_at' => $awaitsApproval
                ? $listing->listed_at
                : ($listing->listed_at ?? now()),
        ], static fn (mixed $value): bool => $value !== null);

        // BİRLEŞTİRME: adapter yalnızca DEĞİŞEN anahtarları döndürür.
        $metadata = $result->data['channel_metadata'] ?? null;

        if (is_array($metadata) && $metadata !== []) {
            $attributes['channel_metadata'] = [
                ...($listing->channel_metadata ?? []),
                ...$metadata,
            ];
        }

        $listing->forceFill($attributes)->save();
    }
}
