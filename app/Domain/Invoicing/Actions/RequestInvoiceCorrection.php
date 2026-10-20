<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Actions;

use App\Domain\Invoicing\Exceptions\InvoiceCorrectionRefused;
use App\Domain\Invoicing\Exceptions\InvoiceDataUnavailable;
use App\Domain\Invoicing\Jobs\IssueInvoiceCorrection;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceAccount;
use App\Domain\Invoicing\Support\ReturnDraftBuilder;
use App\Domain\Orders\Enums\OrderState;
use App\Domain\Orders\Models\Order;
use App\Domain\Returns\Models\ReturnRequest;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Kesilmiş satış faturasına DÜZELTME — iptal ya da iade kaydı (S6, ADR-007).
 *
 * `RequestInvoice` ile aynı kalıp: satır önce bizde yazılır, entegratör işi
 * kuyrukta yapılır (`IssueInvoiceCorrection`), durum sipariş ekranında
 * görünür. Ön koşul tutmazsa satır AÇILMAZ (`InvoiceCorrectionRefused`).
 *
 * İPTAL ↔ İADE BİRBİRİNİ DIŞLAR: iptal edilen faturaya iade kaydı, iade
 * kaydı olan faturaya iptal açılmaz — ikisi birden olsaydı aynı satış
 * defterden İKİ KEZ düşerdi.
 *
 * e-FATURA ikisine de kapalıdır: API'den iptal edilemez ve iade
 * faturasını mükellef alıcı keser (Paraşüt gelen kutusuna kendiliğinden
 * düşer — burada da açılsaydı iade iki kez sayılırdı).
 *
 * Kota SORULMAZ (`EnforceQuota::currentUsage` yalnız satış sayar).
 */
final class RequestInvoiceCorrection
{
    /**
     * @throws InvoiceCorrectionRefused
     */
    public function cancel(Order $order, InvoiceAccount $account, ?string $actorId = null): Invoice
    {
        $sale = $this->issuedSale($order);

        if ($sale->document_type === Invoice::TYPE_E_INVOICE) {
            throw new InvoiceCorrectionRefused(__('e-Fatura buradan iptal edilemez; alıcının iade faturası kesmesi gerekir.'));
        }

        if (! self::isCancelled($order)) {
            throw new InvoiceCorrectionRefused(__('Sipariş iptal edilmedi; yalnız iptal edilen siparişin faturası iptal edilir.'));
        }

        $returns = Invoice::query()
            ->where('parent_invoice_id', $sale->id)
            ->where('kind', Invoice::KIND_RETURN)
            ->where('status', '!=', Invoice::STATUS_FAILED)
            ->exists();

        if ($returns) {
            throw new InvoiceCorrectionRefused(__('Bu faturaya iade kaydı açıldı; fatura artık iptal edilemez.'));
        }

        return $this->open($order, $sale, $account, Invoice::KIND_CANCEL, '', $actorId);
    }

    /**
     * @throws InvoiceCorrectionRefused
     */
    public function recordReturn(ReturnRequest $request, InvoiceAccount $account, ?string $actorId = null): Invoice
    {
        $order = $request->order ?? throw new InvoiceCorrectionRefused(__('İade talebi bir siparişle eşleşmedi.'));

        $sale = $this->issuedSale($order);

        if ($sale->document_type === Invoice::TYPE_E_INVOICE) {
            throw new InvoiceCorrectionRefused(__('Alıcı e-fatura mükellefi: iade faturasını alıcı keser ve Paraşüt gelen kutunuza düşer; ayrıca kaydedilmez.'));
        }

        $cancelled = Invoice::query()
            ->where('parent_invoice_id', $sale->id)
            ->where('kind', Invoice::KIND_CANCEL)
            ->where('status', '!=', Invoice::STATUS_FAILED)
            ->exists();

        if ($cancelled) {
            throw new InvoiceCorrectionRefused(__('Fatura iptal edildi; iade kaydı gerekmez.'));
        }

        // Ön koşul İSTEK ANINDA da denenir: sonuçlanmamış talep ya da satış
        // anı tutarı olmayan kalem satıcıya hemen söylenir.
        try {
            ReturnDraftBuilder::build($request);
        } catch (InvoiceDataUnavailable $e) {
            throw new InvoiceCorrectionRefused($e->getMessage());
        }

        return $this->open($order, $sale, $account, Invoice::KIND_RETURN, $request->id, $actorId);
    }

    /**
     * Başarısız düzeltmeyi yeniden dener. İade kaydının kimliği satırda
     * kaldıysa entegratör ikinci kayıt AÇMAZ (`recordReturn` erken döner).
     */
    public function retry(Invoice $correction): void
    {
        $correction->forceFill(['status' => Invoice::STATUS_PENDING, 'attempts' => 0, 'error' => null])->save();

        IssueInvoiceCorrection::dispatch($correction->id, TenantContext::idOrFail())->onQueue('orders:high');
    }

    /** Siparişin iptal sayılıp sayılmadığı — `MaybeAutoInvoice` ile aynı kural. */
    public static function isCancelled(Order $order): bool
    {
        if (in_array($order->state, [OrderState::CANCELLED->value, OrderState::SUPERSEDED->value], true)) {
            return true;
        }

        if ($order->state === null && in_array(strtolower((string) $order->status), MaybeAutoInvoice::CANCELLED_STATUSES, true)) {
            return true;
        }

        return ! $order->lines()->whereColumn('quantity_cancelled', '<', 'quantity')->exists();
    }

    private function issuedSale(Order $order): Invoice
    {
        $sale = $order->invoice()->first();

        if ($sale === null || $sale->status !== Invoice::STATUS_ISSUED) {
            throw new InvoiceCorrectionRefused(__('Bu siparişin kesilmiş bir faturası yok.'));
        }

        return $sale;
    }

    private function open(Order $order, Invoice $sale, InvoiceAccount $account, string $kind, string $ref, ?string $actorId): Invoice
    {
        $existing = Invoice::query()->where('order_id', $order->id)->where('kind', $kind)->where('ref', $ref)->first();

        if ($existing !== null) {
            if ($existing->status === Invoice::STATUS_FAILED) {
                $this->retry($existing);
            }

            return $existing;
        }

        try {
            $correction = Invoice::query()->create([
                'tenant_id' => TenantContext::idOrFail(),
                'order_id' => $order->id,
                'provider' => $account->provider,
                'status' => Invoice::STATUS_PENDING,
                'kind' => $kind,
                'parent_invoice_id' => $sale->id,
                'ref' => $ref,
                'requested_by' => $actorId,
            ]);
        } catch (UniqueConstraintViolationException) {
            return Invoice::query()->where('order_id', $order->id)->where('kind', $kind)->where('ref', $ref)->firstOrFail();
        }

        IssueInvoiceCorrection::dispatch($correction->id, TenantContext::idOrFail())->onQueue('orders:high');

        return $correction;
    }
}
