<?php

declare(strict_types=1);

namespace App\Domain\Fulfillment\Support;

use App\Domain\Billing\Exceptions\QuotaExceededException;
use App\Domain\Channels\Contracts\SupportsInvoiceData;
use App\Domain\Fulfillment\Enums\FulfillmentReason;
use App\Domain\Invoicing\Actions\RequestInvoice;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceAccount;
use App\Domain\Orders\Models\Order;

/**
 * Siparişin FATURA BELGESİ durumu ve (açık istekle) mevcut kesim akışı.
 *
 * TERİM: buradaki belge satıcının entegratörde (Paraşüt) kestiği e-Arşiv /
 * e-Fatura'dır — pazaryeri belgesi DEĞİL. Trendyol paketinde indirilebilir
 * bir fatura dosyası yok (`invoiceLink` canlı pakette gelmedi, 11 Eki).
 * Belge bizde saklanmaz; indirme her tıklamada entegratörden taze süreli
 * bağlantıyla yapılır (`orders.invoice.pdf`).
 *
 * Yeni bir muhasebe yazımı yok: kesim mevcut `RequestInvoice` ile, aynı
 * sipariş başına tek fatura kuralıyla (S6 kapsamı genişletilmez).
 */
final class InvoiceDocuments
{
    public function __construct(private readonly RequestInvoice $requestInvoice) {}

    /**
     * Belge durumu — yan etkisiz.
     *
     * @return array{outcome: string, reason: ?FulfillmentReason, downloadable: bool}
     */
    public static function status(?Invoice $invoice): array
    {
        return match (true) {
            $invoice === null => self::result('action_required', FulfillmentReason::INVOICE_DOCUMENT_MISSING),
            $invoice->status === Invoice::STATUS_FAILED => self::result('failed', FulfillmentReason::INVOICE_FAILED),
            $invoice->status !== Invoice::STATUS_ISSUED => self::result('in_progress', FulfillmentReason::INVOICE_PENDING),
            $invoice->document_type === null => self::result('action_required', FulfillmentReason::INVOICE_BOOKS_ONLY),
            default => ['outcome' => 'succeeded', 'reason' => null, 'downloadable' => true],
        };
    }

    /** Toplu "belgeleri hazırla": yan etkisiz durum kararı. */
    public function decide(Order $order): ItemDecision
    {
        if (! FulfillmentPolicy::allows($order, FulfillmentPolicy::INVOICE_DOCUMENT)) {
            return ItemDecision::actionRequired(FulfillmentReason::INVALID_ORDER_STATE);
        }

        return self::toDecision(self::status($order->invoice));
    }

    /**
     * Toplu "fatura kes": faturası olmayan uygun siparişe mevcut kesim
     * akışını başlatır; faturası varsa YENİSİ AÇILMAZ, durumu döner.
     */
    public function issue(Order $order, ?InvoiceAccount $account, ?string $actorId): ItemDecision
    {
        if (! FulfillmentPolicy::allows($order, FulfillmentPolicy::INVOICE_DOCUMENT)) {
            return ItemDecision::actionRequired(FulfillmentReason::INVALID_ORDER_STATE);
        }

        if ($order->invoice !== null) {
            return self::toDecision(self::status($order->invoice));
        }

        $class = $order->connection?->channelType?->adapter_class;

        if (! is_string($class) || ! is_subclass_of($class, SupportsInvoiceData::class)) {
            return ItemDecision::actionRequired(FulfillmentReason::CHANNEL_UNSUPPORTED);
        }

        if ($account === null || ! $account->isUsable()) {
            return ItemDecision::actionRequired(FulfillmentReason::INVOICE_ACCOUNT_MISSING);
        }

        try {
            $this->requestInvoice->run($order, $account, $actorId);
        } catch (QuotaExceededException) {
            return ItemDecision::actionRequired(FulfillmentReason::INVOICE_QUOTA_EXCEEDED);
        }

        return ItemDecision::queued();
    }

    /** @param array{outcome: string, reason: ?FulfillmentReason, downloadable: bool} $status */
    private static function toDecision(array $status): ItemDecision
    {
        return match ($status['outcome']) {
            'succeeded' => ItemDecision::succeeded(),
            'in_progress' => ItemDecision::queued(),
            'failed' => ItemDecision::failed($status['reason'] ?? FulfillmentReason::INVOICE_FAILED),
            default => ItemDecision::actionRequired($status['reason'] ?? FulfillmentReason::INVOICE_DOCUMENT_MISSING),
        };
    }

    /** @return array{outcome: string, reason: FulfillmentReason, downloadable: bool} */
    private static function result(string $outcome, FulfillmentReason $reason): array
    {
        return ['outcome' => $outcome, 'reason' => $reason, 'downloadable' => false];
    }
}
