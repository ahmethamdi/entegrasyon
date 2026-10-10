<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Jobs;

use App\Domain\Channels\Contracts\SupportsInvoiceUpload;
use App\Domain\Channels\Registry\AdapterRegistry;
use App\Domain\Channels\Support\ChannelRateLimiter;
use App\Domain\Channels\Support\CircuitBreaker;
use App\Domain\Invoicing\Exceptions\InvoiceProviderException;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceAccount;
use App\Domain\Invoicing\Support\InvoicePdfFetcher;
use App\Domain\Invoicing\Support\InvoiceProviders;
use App\Domain\Orders\Models\Order;
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
 * Kesilen faturanın PDF'ini siparişin geldiği kanala yükler.
 *
 * ADIMLAR: entegratörden TAZE PDF bağlantısı (`pdfUrl`) → dosya belleğe
 * (`InvoicePdfFetcher`, ≤ kanal sınırı) → kanala (`uploadInvoice`).
 *
 * ⚠️ BAĞLANTI SAKLANMAZ, HER DENEMEDE YENİDEN İSTENİR: Paraşüt'ün PDF
 * bağlantısı sürelidir (~1 sa). Saklanıp yeniden denemede kullanılsaydı
 * 24 saatlik deneme penceresinin büyük kısmı "erişim reddedildi" ile
 * geçerdi. Bağlantı henüz yoksa (`null`, belge imzalanıyor) iş kısa süre
 * sonra yeniden bakar — sınır `retryUntil`'dir.
 *
 * ⚠️ PDF İŞ YÜKÜNDE DE YOKTUR: kuyruğa (Redis) yalnız fatura ve kiracı
 * kimliği gider; dosya alıcının adını ve adresini taşır (kullanıcı kararı:
 * "anlık çek, saklama").
 *
 * Kargo işinin iskeletini izler (`PushFulfillment`): devre kesici ve hız
 * sınırı KANAL isteği için, `RetryPolicy` iki taraf için, 24 saatlik
 * `retryUntil`, durum satırın kendisinde (`upload_*`). Yükleme hatası
 * faturanın kendi durumuna DOKUNMAZ — fatura kesilmiştir ve resmîdir.
 */
final class UploadInvoiceToChannel implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** PDF bağlantısı hazır değilse (belge imzalanıyor) tekrar bakma aralığı. */
    public const PDF_WAIT_SECONDS = 30;

    public function __construct(
        public readonly string $invoiceId,
        public readonly string $tenantId,
    ) {}

    /**
     * Siparişin kanalı fatura dosyası alıyor mu — adapter KURULMADAN sınıftan.
     *
     * Kesim anında (`IssueInvoice`) ve ekranda aynı soru sorulur; adapter
     * kurmak kanal kimlik bilgisini çözmeyi gerektirirdi (panel kuralı,
     * `OrderController::channelSupportsInvoicing`).
     */
    public static function channelAccepts(?Order $order): bool
    {
        $class = $order?->connection?->channelType?->adapter_class;

        return is_string($class) && $class !== '' && is_subclass_of($class, SupportsInvoiceUpload::class);
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours(24);
    }

    public function handle(
        AdapterRegistry $registry,
        InvoiceProviders $providers,
        InvoicePdfFetcher $fetcher,
        ChannelErrorText $errorText,
        ?CircuitBreaker $breaker = null,
        ?ChannelRateLimiter $limiter = null,
    ): void {
        TenantContext::set($this->tenantId);

        try {
            $this->upload($registry, $providers, $fetcher, $errorText, $breaker ?? app(CircuitBreaker::class), $limiter ?? app(ChannelRateLimiter::class));
        } finally {
            TenantContext::clear();
        }
    }

    /**
     * Süre doldu ya da iş çöktü — yükleme "başarısız" olur ve panelde
     * "tekrar yükle" çıkar. Dokunulmasaydı sonsuza dek "yükleniyor" kalırdı.
     */
    public function failed(?Throwable $e): void
    {
        TenantContext::set($this->tenantId);

        try {
            Invoice::query()
                ->whereKey($this->invoiceId)
                ->where('upload_status', Invoice::UPLOAD_PENDING)
                ->update([
                    'upload_status' => Invoice::UPLOAD_FAILED,
                    'upload_error' => 'Yükleme işi tamamlanamadı: '.mb_substr($e?->getMessage() ?? 'bilinmeyen sebep', 0, 500),
                ]);
        } finally {
            TenantContext::clear();
        }
    }

    private function upload(
        AdapterRegistry $registry,
        InvoiceProviders $providers,
        InvoicePdfFetcher $fetcher,
        ChannelErrorText $errorText,
        CircuitBreaker $breaker,
        ChannelRateLimiter $limiter,
    ): void {
        $invoice = Invoice::query()->with('order.connection')->find($this->invoiceId);

        // Yalnız KESİLMİŞ ve yüklemesi BEKLEYEN fatura: yüklenmiş olan
        // ikinci kez gitmez, kesilmemiş olanın dosyası yoktur.
        if ($invoice === null || $invoice->status !== Invoice::STATUS_ISSUED || $invoice->upload_status !== Invoice::UPLOAD_PENDING) {
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

        if (! $adapter instanceof SupportsInvoiceUpload) {
            $this->fail($invoice, 'Bu kanal fatura dosyası almıyor; faturayı kanalın kendi panelinden ekleyin.');

            return;
        }

        $account = InvoiceAccount::query()->first();

        if ($account === null || ! $account->isUsable()) {
            $this->fail($invoice, 'Paraşüt bağlantısı yok ya da yenilenmeli; e-fatura ayarlarından bağlayın, sonra yeniden deneyin.');

            return;
        }

        try {
            $url = $providers->for($account)->pdfUrl($invoice);
        } catch (InvoiceProviderException $e) {
            $this->retryOrFail($invoice, $e->class, 'PDF bağlantısı alınamadı. '.$e->getMessage(), $e->retryAfterSeconds());

            return;
        }

        if ($url === null) {
            // Belge imzalanıyor — deneme SAYILMAZ, pencere `retryUntil`.
            $this->release(self::PDF_WAIT_SECONDS);

            return;
        }

        // Kota PDF indirilmeden ÖNCE sorulur: sırası gelmeyen deneme dosyayı
        // boşuna indirip atardı.
        if (! $limiter->attempt($connection->id, $adapter->rateLimitProfile())) {
            $this->release(max($limiter->secondsUntilAvailable($connection->id, $adapter->rateLimitProfile()), 1));

            return;
        }

        try {
            $pdf = $fetcher->fetch($url, $adapter->maxInvoiceFileBytes());
        } catch (InvoiceProviderException $e) {
            $this->retryOrFail($invoice, $e->class, $e->getMessage(), $e->retryAfterSeconds());

            return;
        }

        $invoice->increment('upload_attempts');

        try {
            AdapterReportedFailure::throwIfFailed($adapter->uploadInvoice($order, $invoice, $pdf, self::filename($invoice)));

            $invoice->forceFill([
                'upload_status' => Invoice::UPLOAD_SENT,
                'upload_error' => null,
                'uploaded_at' => now(),
            ])->save();

            $breaker->recordSuccess($connection->id);
        } catch (Throwable $e) {
            $class = AdapterReportedFailure::classify($e, $adapter);

            $breaker->recordFailure($connection->id, $class);

            $message = $errorText->redact($connection, $e->getMessage());

            $delay = RetryPolicy::delayFor($class, $invoice->upload_attempts, AdapterReportedFailure::retryAfterOf($e));

            if ($delay !== null) {
                $invoice->forceFill(['upload_error' => $message])->save();
                $this->release($delay);

                return;
            }

            $this->fail($invoice, $this->explain($class, $message));
        }
    }

    /** Entegratör / indirme tarafının hatası — deneme sayılır. */
    private function retryOrFail(Invoice $invoice, ErrorClass $class, ?string $message, ?int $retryAfter): void
    {
        $invoice->increment('upload_attempts');

        $delay = RetryPolicy::delayFor($class, $invoice->upload_attempts, $retryAfter);

        if ($delay !== null) {
            $invoice->forceFill(['upload_error' => $message])->save();
            $this->release($delay);

            return;
        }

        $this->fail($invoice, $message);
    }

    private function fail(Invoice $invoice, ?string $message): void
    {
        $invoice->forceFill([
            'upload_status' => Invoice::UPLOAD_FAILED,
            'upload_error' => $message === null ? null : mb_substr($message, 0, 1000),
        ])->save();
    }

    /** Satıcının ne yapacağını söyleyen kısa ön ek + kanalın metni. */
    private function explain(ErrorClass $class, ?string $message): string
    {
        $prefix = match ($class) {
            ErrorClass::AUTHENTICATION => 'Kanal bağlantısının yetkisi yok veya süresi doldu.',
            ErrorClass::NOT_FOUND => 'Paket kanalda bulunamadı.',
            ErrorClass::VALIDATION => 'Kanal fatura dosyasını reddetti.',
            default => 'Kanala ulaşılamadı.',
        };

        return $message === null || $message === '' ? $prefix : $prefix.' '.$message;
    }

    /** Dosya adı numaradan; numara yoksa satır kimliği. Yol karakteri taşımaz. */
    private static function filename(Invoice $invoice): string
    {
        $base = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($invoice->invoice_number ?? '')) ?: $invoice->id;

        return "fatura-{$base}.pdf";
    }
}
