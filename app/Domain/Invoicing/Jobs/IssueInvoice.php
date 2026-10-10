<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Jobs;

use App\Domain\Channels\Contracts\SupportsInvoiceData;
use App\Domain\Channels\Registry\AdapterRegistry;
use App\Domain\Channels\Support\ChannelRateLimiter;
use App\Domain\Channels\Support\CircuitBreaker;
use App\Domain\Invoicing\Contracts\InvoiceProvider;
use App\Domain\Invoicing\Exceptions\InvoiceDataUnavailable;
use App\Domain\Invoicing\Exceptions\InvoiceProviderException;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceAccount;
use App\Domain\Invoicing\Support\InvoiceProviders;
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
use Throwable;

/**
 * Siparişin faturasını keser — kanaldan oku, entegratöre gönder, sonucu bekle.
 *
 * İKİ EVRE, aynı iş:
 *   pending  → alıcı + kalemler kanaldan ANLIK okunur, entegratöre
 *              gönderilir (`submit`), iş kısa süre sonra kendini yeniden
 *              kuyruğa koyar
 *   issuing  → entegratöre sonucu sorar (`poll`); sürüyorsa yine bekler
 *
 * ⚠️ İŞ YÜKÜNDE ALICI YOKTUR — yalnız fatura ve kiracı kimliği. Taslak
 * her `pending` turunda kanaldan yeniden okunur; kuyruğa (Redis) kişisel
 * veri yazılmaz (kullanıcı kararı: "anlık çek, saklama").
 *
 * Kargo işinin iskeletini izler (`PushFulfillment`): devre kesici ve hız
 * sınırı KANAL isteği için, `RetryPolicy` her iki taraf için, 24 saatlik
 * `retryUntil`, durum satırın kendisinde.
 */
final class IssueInvoice implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Entegratör e-belgeyi genelde saniyeler içinde imzalar. */
    public const POLL_SECONDS = 15;

    public function __construct(
        public readonly string $invoiceId,
        public readonly string $tenantId,
    ) {}

    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours(24);
    }

    public function handle(
        AdapterRegistry $registry,
        InvoiceProviders $providers,
        ChannelErrorText $errorText,
        ?CircuitBreaker $breaker = null,
        ?ChannelRateLimiter $limiter = null,
    ): void {
        TenantContext::set($this->tenantId);

        try {
            $this->issue($registry, $providers, $errorText, $breaker ?? app(CircuitBreaker::class), $limiter ?? app(ChannelRateLimiter::class));
        } finally {
            TenantContext::clear();
        }
    }

    /**
     * Süre doldu ya da iş çöktü — satır "kesilemedi" olur ve ekranda
     * görünür. e-belge işi kimliği KORUNUR: belge entegratörde sonradan
     * oluşmuş olabilir ve yeniden deneme önce onu sorar, ikinci resmî
     * belge açmaz (`RequestInvoice::retry`).
     */
    public function failed(?Throwable $e): void
    {
        TenantContext::set($this->tenantId);

        try {
            Invoice::query()
                ->whereKey($this->invoiceId)
                ->whereIn('status', [Invoice::STATUS_PENDING, Invoice::STATUS_ISSUING])
                ->update([
                    'status' => Invoice::STATUS_FAILED,
                    'error' => 'Fatura işi tamamlanamadı: '.mb_substr($e?->getMessage() ?? 'bilinmeyen sebep', 0, 500),
                ]);
        } finally {
            TenantContext::clear();
        }
    }

    private function issue(
        AdapterRegistry $registry,
        InvoiceProviders $providers,
        ChannelErrorText $errorText,
        CircuitBreaker $breaker,
        ChannelRateLimiter $limiter,
    ): void {
        $invoice = Invoice::query()->with('order.connection')->find($this->invoiceId);

        if ($invoice === null || ! $invoice->isOpen()) {
            return;
        }

        $account = InvoiceAccount::query()->first();

        if ($account === null || ! $account->isUsable()) {
            $this->fail($invoice, 'Paraşüt bağlantısı yok ya da yenilenmeli; e-fatura ayarlarından bağlayın, sonra yeniden deneyin.');

            return;
        }

        $provider = $providers->for($account);

        if ($invoice->status === Invoice::STATUS_ISSUING) {
            $this->poll($invoice, $provider);

            return;
        }

        $order = $invoice->order;
        $connection = $order?->connection;

        if ($order === null || $connection === null) {
            $this->fail($invoice, 'Siparişin kanal bağlantısı bulunamadı.');

            return;
        }

        if (! $breaker->allows($connection->id)) {
            $this->release(CircuitBreaker::PAUSE_SECONDS);

            return;
        }

        $adapter = $registry->for($connection);

        if (! $adapter instanceof SupportsInvoiceData) {
            $this->fail($invoice, 'Bu kanalın siparişine henüz fatura kesilemiyor.');

            return;
        }

        if (! $limiter->attempt($connection->id, $adapter->rateLimitProfile())) {
            $this->release(max($limiter->secondsUntilAvailable($connection->id, $adapter->rateLimitProfile()), 1));

            return;
        }

        try {
            $draft = $adapter->fetchInvoiceDraft($order);
            $breaker->recordSuccess($connection->id);
        } catch (InvoiceDataUnavailable $e) {
            $this->fail($invoice, $e->getMessage());

            return;
        } catch (Throwable $e) {
            $class = AdapterReportedFailure::classify($e, $adapter);
            $breaker->recordFailure($connection->id, $class);

            $this->retryOrFail($invoice, $class, 'Sipariş kanaldan okunamadı. '.$errorText->redact($connection, $e->getMessage()), AdapterReportedFailure::retryAfterOf($e));

            return;
        }

        try {
            $provider->submit($invoice, $draft);
        } catch (InvoiceProviderException $e) {
            $this->retryOrFail($invoice, $e->class, $e->getMessage(), $e->retryAfterSeconds());

            return;
        }

        $invoice->forceFill(['error' => null])->save();
        $this->release(self::POLL_SECONDS);
    }

    private function poll(Invoice $invoice, InvoiceProvider $provider): void
    {
        try {
            $outcome = $provider->poll($invoice);
        } catch (InvoiceProviderException $e) {
            $this->retryOrFail($invoice, $e->class, $e->getMessage(), $e->retryAfterSeconds());

            return;
        }

        if ($outcome->isPending()) {
            $this->release(self::POLL_SECONDS);

            return;
        }

        if ($outcome->isIssued()) {
            $invoice->forceFill([
                'status' => Invoice::STATUS_ISSUED,
                'provider_document_id' => $outcome->documentId,
                'invoice_number' => $outcome->invoiceNumber,
                'issued_at' => now(),
                'error' => null,
            ])->save();

            return;
        }

        // Entegratör belgeyi REDDETTİ: iş kimliği silinir ki yeniden
        // deneme yeni bir e-belge isteği göndersin (taslak fatura ve cari
        // korunur, ikinci kez açılmaz).
        $invoice->forceFill(['provider_job_id' => null, 'document_type' => null])->save();
        $this->fail($invoice, $outcome->error ?? 'e-belge oluşturulamadı.');
    }

    private function retryOrFail(Invoice $invoice, ErrorClass $class, ?string $message, ?int $retryAfter): void
    {
        $invoice->increment('attempts');

        $delay = RetryPolicy::delayFor($class, $invoice->attempts, $retryAfter);

        if ($delay !== null) {
            $invoice->forceFill(['error' => $message])->save();
            $this->release($delay);

            return;
        }

        $this->fail($invoice, $message);
    }

    private function fail(Invoice $invoice, ?string $message): void
    {
        $invoice->forceFill([
            'status' => Invoice::STATUS_FAILED,
            'error' => $message === null ? null : mb_substr($message, 0, 1000),
        ])->save();
    }
}
