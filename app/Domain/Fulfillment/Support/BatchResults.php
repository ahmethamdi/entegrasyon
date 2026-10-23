<?php

declare(strict_types=1);

namespace App\Domain\Fulfillment\Support;

use App\Domain\Fulfillment\Enums\FulfillmentReason;
use App\Domain\Fulfillment\Models\FulfillmentBatch;
use App\Domain\Fulfillment\Models\FulfillmentBatchItem;
use App\Domain\Fulfillment\Models\ShippingLabel;
use App\Domain\Shipping\Models\CarrierShipment;

/**
 * Toplu işlemin kalem başına GÜNCEL sonucu.
 *
 * Kuyruğa giden kalemin sonucu KAYNAĞINDAN okunur (etiket / fatura satırı);
 * kalem satırına geri yazılmaz — iki kopya ayrışamaz. Her kalem şu dört
 * sonuçtan birindedir: succeeded · in_progress · action_required · failed.
 */
final class BatchResults
{
    public const OUTCOMES = ['succeeded', 'in_progress', 'action_required', 'failed'];

    /** @return array{items: list<array<string, mixed>>, counts: array<string, int>} */
    public function for(FulfillmentBatch $batch): array
    {
        $items = $batch->items()
            ->with(['order:id,external_id,external_number,state,channel_connection_id', 'order.invoice', 'label', 'shipment'])
            ->orderBy('created_at')
            ->get();

        $counts = array_fill_keys(self::OUTCOMES, 0);
        $out = [];

        foreach ($items as $item) {
            [$outcome, $reason, $extra] = $this->resolve($batch->action, $item);
            $counts[$outcome]++;

            $out[] = [
                'orderId' => $item->order_id,
                'number' => $item->order?->external_number,
                'package' => $item->order?->external_id,
                'outcome' => $outcome,
                'reasonCode' => $reason?->value,
                'reason' => $reason?->label(),
                ...$extra,
            ];
        }

        return ['items' => $out, 'counts' => $counts];
    }

    /** @return array{0: string, 1: ?FulfillmentReason, 2: array<string, mixed>} */
    private function resolve(string $action, FulfillmentBatchItem $item): array
    {
        $stored = $item->reason_code === null ? null : FulfillmentReason::tryFrom($item->reason_code);

        if ($action === FulfillmentBatch::ACTION_INVOICE_DOCUMENTS || $action === FulfillmentBatch::ACTION_ISSUE_INVOICES) {
            if ($item->outcome === FulfillmentBatchItem::ACTION_REQUIRED && $stored !== FulfillmentReason::INVOICE_DOCUMENT_MISSING) {
                return ['action_required', $stored, ['downloadable' => false]];
            }

            $status = InvoiceDocuments::status($item->order?->invoice);

            return [$status['outcome'], $status['reason'], ['downloadable' => $status['downloadable']]];
        }

        if ($action === FulfillmentBatch::ACTION_LABELS && $item->label !== null) {
            $label = $item->label;

            return match ($label->status) {
                ShippingLabel::STATUS_READY => ['succeeded', null, ['labelFormat' => $label->format, 'cargoProvider' => $label->cargo_provider]],
                ShippingLabel::STATUS_FAILED => [
                    $label->reason()?->requiresAction() ?? false ? 'action_required' : 'failed',
                    $label->reason() ?? FulfillmentReason::LABEL_CREATION_FAILED,
                    ['cargoProvider' => $label->cargo_provider],
                ],
                default => ['in_progress', FulfillmentReason::LABEL_NOT_READY, []],
            };
        }

        if (isset(FulfillmentBatch::CARRIER_ACTIONS[$action]) && $item->shipment !== null) {
            $shipment = $item->shipment;
            $extra = ['carrier' => $shipment->carrierName(), 'trackingNumber' => $shipment->tracking_number, 'hasLabel' => $shipment->hasLabel()];

            return match ($shipment->status) {
                CarrierShipment::STATUS_CREATED, CarrierShipment::STATUS_SHIPPED, CarrierShipment::STATUS_DELIVERED => ['succeeded', null, $extra],
                CarrierShipment::STATUS_FAILED => [
                    $shipment->reason()?->requiresAction() ?? false ? 'action_required' : 'failed',
                    $shipment->reason() ?? FulfillmentReason::CARRIER_UNAVAILABLE,
                    [...$extra, 'detail' => $shipment->error_detail],
                ],
                CarrierShipment::STATUS_CANCELLED => ['action_required', FulfillmentReason::INVALID_ORDER_STATE, $extra],
                default => ['in_progress', null, $extra],
            };
        }

        return match ($item->outcome) {
            FulfillmentBatchItem::SUCCEEDED => ['succeeded', null, []],
            FulfillmentBatchItem::FAILED => ['failed', $stored, []],
            FulfillmentBatchItem::QUEUED => ['in_progress', $stored, []],
            default => ['action_required', $stored, []],
        };
    }
}
