<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Support;

use App\Domain\Invoicing\Exceptions\InvoiceDataUnavailable;
use App\Domain\Orders\Models\OrderLine;
use App\Domain\Returns\Enums\ReturnStatus;
use App\Domain\Returns\Models\ReturnRequest;
use App\Domain\Returns\Models\ReturnRequestLine;
use App\Support\Money\Money;
use Brick\Math\RoundingMode;

/**
 * Kabul edilen iade talebinden iade kaydı içeriği (ADR-007).
 *
 * YALNIZ SONUÇLANMIŞ TALEP: kararı bekleyen ya da ihtilaflı kalem varken
 * kayıt açılmaz. Açılsaydı sonradan kabul edilen kalem kayda HİÇ girmez
 * (talep başına tek kayıt — `invoices_order_kind_ref_unique`), satıcının
 * defterinde iade eksik görünürdü.
 *
 * FİYAT SATIŞ ANI GÖRÜNTÜSÜNDEN: `net_line_total` (müşterinin ödediği),
 * `vat_rate`, `amounts_include_vat`. Biri bilinmiyorsa (ADR-002 öncesi
 * sipariş) kayıt AÇILMAZ — 0 TL ya da tahminî tutarlı bir iade muhasebeyi
 * sessizce bozardı.
 */
final class ReturnDraftBuilder
{
    /** Karar bekleyen durumlar — biri varsa talep sonuçlanmamıştır. */
    private const OPEN_STATUSES = [
        ReturnStatus::REQUESTED,
        ReturnStatus::AWAITING_ACTION,
        ReturnStatus::UNDER_REVIEW,
        ReturnStatus::DISPUTED,
        ReturnStatus::UNKNOWN,
    ];

    /**
     * @throws InvoiceDataUnavailable satıcıya gösterilecek sebeple
     */
    public static function build(ReturnRequest $request): ReturnDraft
    {
        $request->loadMissing(['lines.orderLine', 'order.connection.channelType']);

        $order = $request->order ?? throw new InvoiceDataUnavailable(__('İade talebi bir siparişle eşleşmedi.'));

        $open = $request->lines->contains(static fn (ReturnRequestLine $line): bool => in_array($line->statusEnum(), self::OPEN_STATUSES, true));

        if ($open || $request->lines->isEmpty()) {
            throw new InvoiceDataUnavailable(__('İade talebi henüz sonuçlanmadı; tüm kalemler karara bağlanınca kaydedin.'));
        }

        $accepted = $request->lines->filter(static fn (ReturnRequestLine $line): bool => $line->statusEnum() === ReturnStatus::ACCEPTED);

        if ($accepted->isEmpty()) {
            throw new InvoiceDataUnavailable(__('Bu talepte kabul edilen iade kalemi yok.'));
        }

        $lines = [];

        foreach ($accepted as $line) {
            $orderLine = $line->orderLine ?? throw new InvoiceDataUnavailable(__('İade kalemi :item sipariş kalemiyle eşleşmedi.', ['item' => $line->sku ?? $line->external_item_id]));

            $lines[] = new InvoiceLine(
                description: (string) $orderLine->title,
                quantity: $line->quantity,
                grossUnitPrice: self::grossUnitPrice($orderLine),
                vatRate: (int) round((float) $orderLine->vat_rate),
                sku: $orderLine->sku,
            );
        }

        return new ReturnDraft(
            lines: $lines,
            orderNumber: (string) ($order->external_number ?? $order->external_id),
            platformName: (string) ($order->connection?->channelType?->name ?? $order->connection?->channel_type_code ?? ''),
            currency: (string) ($order->currency ?? 'TRY'),
        );
    }

    /** Müşterinin ödediği KDV DAHİL birim fiyat, 4 basamak. */
    private static function grossUnitPrice(OrderLine $line): string
    {
        if ($line->net_line_total === null || $line->vat_rate === null || $line->amounts_include_vat === null || (int) $line->quantity <= 0) {
            throw new InvoiceDataUnavailable(__('":title" kaleminin satış anı tutarı ya da KDV oranı bilinmiyor; iadeyi Paraşüt üzerinden elle girin.', ['title' => $line->title]));
        }

        $unit = Money::decimal((string) $line->net_line_total)->dividedBy((int) $line->quantity, 4, RoundingMode::HALF_UP);

        if (! $line->amounts_include_vat) {
            $unit = $unit->multipliedBy(Money::decimal((string) $line->vat_rate)->dividedBy(100, 6)->plus(1))->toScale(4, RoundingMode::HALF_UP);
        }

        return (string) $unit;
    }
}
