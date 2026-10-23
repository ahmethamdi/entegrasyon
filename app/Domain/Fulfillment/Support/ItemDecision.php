<?php

declare(strict_types=1);

namespace App\Domain\Fulfillment\Support;

use App\Domain\Fulfillment\Enums\FulfillmentReason;
use App\Domain\Fulfillment\Models\FulfillmentBatchItem;

/** Toplu işlemde bir siparişin KABUL anındaki kararı. */
final readonly class ItemDecision
{
    private function __construct(
        public string $outcome,
        public ?FulfillmentReason $reason = null,
        public ?string $labelId = null,
        public ?string $shipmentId = null,
    ) {}

    public static function queued(?string $labelId = null, ?string $shipmentId = null): self
    {
        return new self(FulfillmentBatchItem::QUEUED, null, $labelId, $shipmentId);
    }

    public static function succeeded(?string $labelId = null, ?string $shipmentId = null): self
    {
        return new self(FulfillmentBatchItem::SUCCEEDED, null, $labelId, $shipmentId);
    }

    public static function actionRequired(FulfillmentReason $reason): self
    {
        return new self(FulfillmentBatchItem::ACTION_REQUIRED, $reason);
    }

    public static function failed(FulfillmentReason $reason): self
    {
        return new self(FulfillmentBatchItem::FAILED, $reason);
    }
}
