<?php

declare(strict_types=1);

namespace App\Domain\Orders\Jobs;

use App\Domain\Channels\Contracts\SupportsFulfillment;
use App\Domain\Channels\Registry\AdapterRegistry;
use App\Domain\Channels\Support\ChannelRateLimiter;
use App\Domain\Channels\Support\CircuitBreaker;
use App\Domain\Orders\Models\Fulfillment;
use App\Domain\Sync\Enums\ErrorClass;
use App\Domain\Sync\Support\AdapterReportedFailure;
use App\Domain\Sync\Support\ChannelErrorText;
use App\Domain\Sync\Support\RetryPolicy;
use App\Support\Tenancy\TenantContext;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Panelden girilen kargo bildirimini siparişin geldiği kanala gönderir.
 *
 * Mimari Karar Dokümanı v2.2 · §7 · SupportsFulfillment, §12.
 *
 * KAPATILAN BOŞLUK: `pushFulfillment` Shopify ve Woo'da ilk günden beri
 * yazılıydı ama HİÇBİR AKIŞTAN ÇAĞRILMIYORDU — satıcının takip numarasını
 * kanala iletmenin yolu yoktu.
 *
 * Push işlerinin iskeletini izler (devre kesici, hız sınırı, `RetryPolicy`,
 * 24 saatlik `retryUntil`) ama durumu `sync_operations`'a DEĞİL satırın
 * kendisine yazar: o tablo listing × alan × sürüm üçlüsüdür ve kargo bir
 * listing'e bağlı değildir.
 *
 * TEKRAR ZARARSIZDIR: Shopify açık parça kalmadıysa istek atmaz, Woo'da
 * durum mutlak değerdir. Yanıtı kaybolan başarılı istek yeniden denenince
 * çift kargo açılmaz.
 */
final class PushFulfillment implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $fulfillmentId,
        public readonly string $tenantId,
    ) {}

    /** `DeadLettersWhenAbandoned` ile aynı gerekçe: ertelemeler deneme sayılmaz. */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours(24);
    }

    public function handle(
        AdapterRegistry $registry,
        ChannelErrorText $errorText,
        ?CircuitBreaker $breaker = null,
        ?ChannelRateLimiter $limiter = null,
    ): void {
        TenantContext::set($this->tenantId);

        try {
            $this->push($registry, $errorText, $breaker ?? app(CircuitBreaker::class), $limiter ?? app(ChannelRateLimiter::class));
        } finally {
            TenantContext::clear();
        }
    }

    /**
     * Kuyruk işi tamamlanamadı — satır "gönderilemedi" olur ve ekranda
     * görünür. Dokunulmasaydı sonsuza kadar "gönderiliyor" kalırdı.
     */
    public function failed(?Throwable $e): void
    {
        TenantContext::set($this->tenantId);

        try {
            Fulfillment::query()
                ->whereKey($this->fulfillmentId)
                ->where('push_status', Fulfillment::PUSH_PENDING)
                ->update([
                    'push_status' => Fulfillment::PUSH_FAILED,
                    'push_error' => 'Kuyruk işi tamamlanamadı: '.mb_substr($e?->getMessage() ?? 'bilinmeyen sebep', 0, 500),
                ]);
        } finally {
            TenantContext::clear();
        }
    }

    private function push(
        AdapterRegistry $registry,
        ChannelErrorText $errorText,
        CircuitBreaker $breaker,
        ChannelRateLimiter $limiter,
    ): void {
        $fulfillment = Fulfillment::query()->with('order.connection')->find($this->fulfillmentId);

        // Yalnız BEKLEYEN satır gönderilir: kanaldan gelen satırın
        // gönderilecek bir şeyi yoktur, gönderilmiş olan ikinci kez gitmez.
        if ($fulfillment === null || $fulfillment->push_status !== Fulfillment::PUSH_PENDING) {
            return;
        }

        $connection = $fulfillment->order?->connection;

        if ($connection === null) {
            $this->fail($fulfillment, 'Siparişin kanal bağlantısı bulunamadı.');

            return;
        }

        if (! $breaker->allows($connection->id)) {
            $this->release(CircuitBreaker::PAUSE_SECONDS);

            return;
        }

        $adapter = $registry->for($connection);

        if (! $adapter instanceof SupportsFulfillment) {
            $this->fail($fulfillment, 'Bu kanal kargo bildirimini desteklemiyor; takip numarasını kanalın kendi panelinden girin.');

            return;
        }

        if (! $limiter->attempt($connection->id, $adapter->rateLimitProfile())) {
            $this->release(max($limiter->secondsUntilAvailable($connection->id, $adapter->rateLimitProfile()), 1));

            return;
        }

        $fulfillment->increment('push_attempts');

        try {
            $result = AdapterReportedFailure::throwIfFailed($adapter->pushFulfillment($fulfillment));

            $this->markSent($fulfillment, isset($result->data['external_id']) ? (string) $result->data['external_id'] : null);

            $breaker->recordSuccess($connection->id);
        } catch (Throwable $e) {
            $class = AdapterReportedFailure::classify($e, $adapter);

            $breaker->recordFailure($connection->id, $class);

            $message = $errorText->redact($connection, $e->getMessage());

            $delay = RetryPolicy::delayFor($class, $fulfillment->push_attempts, AdapterReportedFailure::retryAfterOf($e));

            if ($delay !== null) {
                // Geçici hata: satır BEKLİYOR kalır, son hata görünür.
                $fulfillment->forceFill(['push_error' => $message])->save();
                $this->release($delay);

                return;
            }

            $this->fail($fulfillment, $this->explain($class, $message));
        }
    }

    /**
     * Gönderildi — kanalın paket kimliği satıra yazılır.
     *
     * ⚠️ KİMLİK YAZILMASAYDI KANAL YANKISI İKİNCİ SATIR AÇARDI. Shopify
     * `fulfillments/create` webhook'unu bizim isteğimiz için de gönderir ve
     * tekillik `(order_id, external_id)` üzerindedir; kimliksiz satırımızla
     * eşleşmez, aynı kargo panelde İKİ KEZ görünürdü.
     *
     * ⚠️ YANKI BİZDEN ÖNCE GELMİŞ OLABİLİR: webhook işimizin yanıtı
     * kaydetmesinden önce işlenirse o kimlikle satır ZATEN vardır. Bu
     * durumda gönderim bilgisi o satıra taşınır ve bizimki silinir —
     * kimliği yazmaya çalışmak tekillik kısıtına çarpar ve iş BAŞARILI
     * gönderimi "başarısız" diye kaydederdi.
     */
    private function markSent(Fulfillment $fulfillment, ?string $externalId): void
    {
        DB::transaction(function () use ($fulfillment, $externalId): void {
            $echo = $externalId === null ? null : Fulfillment::query()
                ->where('order_id', $fulfillment->order_id)
                ->where('external_id', $externalId)
                ->whereKeyNot($fulfillment->id)
                ->lockForUpdate()
                ->first();

            $sent = [
                'source' => Fulfillment::SOURCE_PANEL,
                'push_status' => Fulfillment::PUSH_SENT,
                'push_attempts' => $fulfillment->push_attempts,
                'push_error' => null,
                'pushed_at' => now(),
            ];

            if ($echo !== null) {
                $echo->forceFill([
                    ...$sent,
                    'carrier' => $echo->carrier ?? $fulfillment->carrier,
                    'tracking_number' => $echo->tracking_number ?? $fulfillment->tracking_number,
                    'shipped_at' => $echo->shipped_at ?? $fulfillment->shipped_at,
                ])->save();

                $fulfillment->delete();

                return;
            }

            $fulfillment->forceFill([...$sent, 'external_id' => $externalId ?? $fulfillment->external_id])->save();
        });
    }

    private function fail(Fulfillment $fulfillment, ?string $message): void
    {
        $fulfillment->forceFill([
            'push_status' => Fulfillment::PUSH_FAILED,
            'push_error' => $message,
        ])->save();
    }

    /** Satıcının ne yapacağını söyleyen kısa ön ek + kanalın metni. */
    private function explain(ErrorClass $class, ?string $message): string
    {
        $prefix = match ($class) {
            ErrorClass::AUTHENTICATION => 'Kanal bağlantısının yetkisi yok veya süresi doldu.',
            ErrorClass::NOT_FOUND => 'Sipariş kanalda bulunamadı.',
            ErrorClass::VALIDATION => 'Kanal bildirimi reddetti.',
            default => 'Kanala ulaşılamadı.',
        };

        return $message === null || $message === '' ? $prefix : $prefix.' '.$message;
    }
}
