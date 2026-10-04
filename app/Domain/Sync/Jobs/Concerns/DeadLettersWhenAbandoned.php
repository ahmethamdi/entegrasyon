<?php

declare(strict_types=1);

namespace App\Domain\Sync\Jobs\Concerns;

use App\Domain\Sync\Support\SyncResultRecorder;
use App\Support\Tenancy\TenantContext;
use DateTimeInterface;
use Throwable;

/**
 * Push işlerinin ölüm biçimi — deneme SAYISI değil SÜRE, ve görünür ölüm.
 *
 * Mimari Karar Dokümanı v2.2 · §12 · yeniden deneme politikaları.
 *
 * ═════════════════════════════════════════════════════════════════════
 * NEDEN `tries` DEĞİL `retryUntil`
 * ═════════════════════════════════════════════════════════════════════
 * Devre kesici ve hız sınırlayıcı işi `release()` ile ERTELER — kanal
 * denenmedi. Laravel her `release()`'i bir deneme sayar ve Horizon'un
 * `tries` değeri (4–5) birkaç ertelemede dolar: iş `MaxAttemptsExceeded`
 * ile ölür, kanal hiç denenmemiştir. Gerçek hata bütçesi zaten
 * `RetryPolicy::MAX_ATTEMPTS`'tadır ve `attempt_count` ile sayılır.
 *
 * `retryUntil` tanımlıysa Laravel `tries`'a BAKMAZ; ertelemeler süre
 * dolana kadar serbesttir.
 *
 * ═════════════════════════════════════════════════════════════════════
 * NEDEN `failed()`
 * ═════════════════════════════════════════════════════════════════════
 * İş öldüğünde operasyon `retrying`/`pending` KALIYORDU — ne yeniden
 * deneniyor ne `/failures` ekranında görünüyordu. Kanca operasyonu ölü
 * mektuba düşürür; satıcı onu görür ve tek tıkla yeniden dener.
 *
 * Kullanan sınıf `$operationId` ve `$tenantId` özelliklerini taşır.
 */
trait DeadLettersWhenAbandoned
{
    /**
     * Ertelemeler bu süre boyunca serbest. 24 saat: süresiz açık bir devre
     * (AUTHENTICATION) bile satıcıya ertesi gün GÖRÜNÜR bir ölü satır olarak
     * döner — sonsuza kadar sessizce beklemez.
     */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours(24);
    }

    public function failed(?Throwable $e): void
    {
        TenantContext::set($this->tenantId);

        try {
            app(SyncResultRecorder::class)->markAbandoned(
                $this->operationId,
                'Kuyruk işi tamamlanamadı: '.($e?->getMessage() ?? 'bilinmeyen sebep'),
            );
        } finally {
            TenantContext::clear();
        }
    }
}
