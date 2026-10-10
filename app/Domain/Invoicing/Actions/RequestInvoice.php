<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Actions;

use App\Domain\Invoicing\Jobs\IssueInvoice;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceAccount;
use App\Domain\Orders\Models\Order;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Siparişe fatura isteği — satır önce bizde yazılır, kesim kuyrukta yapılır.
 *
 * Kargo bildirimiyle aynı kural (`RecordPanelShipment`): entegratör ya da
 * kanal o an cevap vermese de istek KAYBOLMAZ ve satıcı durumu sipariş
 * ekranında görür.
 *
 * SİPARİŞ BAŞINA TEK FATURA veritabanı kısıtıyla korunur
 * (`invoices_order_unique`): iki sekmeden aynı anda basılan düğme ikinci
 * satırı açamaz, var olan satır döner.
 */
final class RequestInvoice
{
    public function run(Order $order, InvoiceAccount $account, ?string $actorId = null): Invoice
    {
        $tenantId = TenantContext::idOrFail();

        try {
            $invoice = Invoice::query()->create([
                'tenant_id' => $tenantId,
                'order_id' => $order->id,
                'provider' => $account->provider,
                'status' => Invoice::STATUS_PENDING,
                'requested_by' => $actorId,
            ]);
        } catch (UniqueConstraintViolationException) {
            return Invoice::query()->where('order_id', $order->id)->firstOrFail();
        }

        IssueInvoice::dispatch($invoice->id, $tenantId)->onQueue('orders:high');

        return $invoice;
    }

    /**
     * Kesilemeyen faturayı yeniden dener.
     *
     * ⚠️ e-belge işi kimliği VARSA önce O SORULUR (durum `issuing`): iş
     * zaman aşımıyla düşmüş ama belge entegratörde sonradan oluşmuş
     * olabilir. Yeni istek gönderilseydi aynı siparişe İKİ RESMÎ e-belge
     * kesilirdi; GİB'de iptali ayrı süreçtir. Entegratör belgeyi
     * reddettiyse iş kimliğini iş zaten silmiştir ve bu deneme yeni istek
     * gönderir.
     */
    public function retry(Invoice $invoice): void
    {
        $invoice->forceFill([
            'status' => $invoice->provider_job_id === null ? Invoice::STATUS_PENDING : Invoice::STATUS_ISSUING,
            'attempts' => 0,
            'error' => null,
        ])->save();

        IssueInvoice::dispatch($invoice->id, TenantContext::idOrFail())->onQueue('orders:high');
    }
}
