<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Jobs;

use App\Domain\Invoicing\Exceptions\InvoiceDataUnavailable;
use App\Domain\Invoicing\Exceptions\InvoiceProviderException;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceAccount;
use App\Domain\Invoicing\Support\InvoiceProviders;
use App\Domain\Invoicing\Support\ReturnDraftBuilder;
use App\Domain\Returns\Models\ReturnRequest;
use App\Domain\Sync\Enums\ErrorClass;
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
 * Satış faturasının düzeltmesini entegratörde işler — iptal ya da iade.
 *
 * `IssueInvoice`'un iskeleti, KANAL OLMADAN: düzeltme kanaldan hiçbir şey
 * okumaz (iade kalemleri kanonik kayıttan, alıcı satışın carisinden) —
 * kanal devre kesicisi ve hız sınırı burada yoktur.
 *
 * Tek evre: iki işlem de entegratörde SENKRONDUR; dönen çağrı = işlendi.
 * Hata sınıfı `RetryPolicy`'ye gider: geçici olan beklenir, kalıcı olan
 * (süresi geçmiş e-arşiv, reddedilen gövde) satıcıya metniyle gösterilir.
 */
final class IssueInvoiceCorrection implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $invoiceId,
        public readonly string $tenantId,
    ) {}

    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours(24);
    }

    public function handle(InvoiceProviders $providers): void
    {
        TenantContext::set($this->tenantId);

        try {
            $this->process($providers);
        } finally {
            TenantContext::clear();
        }
    }

    public function failed(?Throwable $e): void
    {
        TenantContext::set($this->tenantId);

        try {
            Invoice::query()
                ->whereKey($this->invoiceId)
                ->where('status', Invoice::STATUS_PENDING)
                ->update([
                    'status' => Invoice::STATUS_FAILED,
                    'error' => 'Düzeltme işi tamamlanamadı: '.mb_substr($e?->getMessage() ?? 'bilinmeyen sebep', 0, 500),
                ]);
        } finally {
            TenantContext::clear();
        }
    }

    private function process(InvoiceProviders $providers): void
    {
        $correction = Invoice::query()->with('parent')->find($this->invoiceId);

        if ($correction === null || $correction->isSale() || $correction->status !== Invoice::STATUS_PENDING) {
            return;
        }

        $sale = $correction->parent;

        if ($sale === null || $sale->status !== Invoice::STATUS_ISSUED) {
            $this->fail($correction, 'Bağlı satış faturası kesilmiş değil.');

            return;
        }

        $account = InvoiceAccount::query()->first();

        if ($account === null || ! $account->isUsable()) {
            $this->fail($correction, 'Paraşüt bağlantısı yok ya da yenilenmeli; e-fatura ayarlarından bağlayın, sonra yeniden deneyin.');

            return;
        }

        $provider = $providers->for($account);

        try {
            if ($correction->kind === Invoice::KIND_CANCEL) {
                $provider->cancel($correction, $sale);
            } else {
                $request = ReturnRequest::query()->find($correction->ref);

                if ($request === null) {
                    $this->fail($correction, 'İade talebi bulunamadı.');

                    return;
                }

                $provider->recordReturn($correction, $sale, ReturnDraftBuilder::build($request));
            }
        } catch (InvoiceDataUnavailable $e) {
            $this->fail($correction, $e->getMessage());

            return;
        } catch (InvoiceProviderException $e) {
            $this->retryOrFail($correction, $e->class, $e->getMessage(), $e->retryAfterSeconds());

            return;
        }

        $correction->forceFill([
            'status' => Invoice::STATUS_ISSUED,
            'issued_at' => now(),
            'error' => null,
        ])->save();
    }

    private function retryOrFail(Invoice $correction, ErrorClass $class, ?string $message, ?int $retryAfter): void
    {
        $correction->increment('attempts');

        $delay = RetryPolicy::delayFor($class, $correction->attempts, $retryAfter);

        if ($delay !== null) {
            $correction->forceFill(['error' => $message])->save();
            $this->release($delay);

            return;
        }

        $this->fail($correction, $message);
    }

    private function fail(Invoice $correction, ?string $message): void
    {
        $correction->forceFill([
            'status' => Invoice::STATUS_FAILED,
            'error' => $message === null ? null : mb_substr($message, 0, 1000),
        ])->save();
    }
}
