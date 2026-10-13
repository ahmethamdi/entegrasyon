<?php

declare(strict_types=1);

namespace App\Domain\Returns\Support;

/**
 * Kanalın iade talebi KALEMİ — ham olgu. Müşteri adı taşınmaz.
 *
 * `externalItemId` EKONOMİK İADE KİMLİĞİDİR (Trendyol claimItem.id: tek
 * birim; HB: talep numarası, `quantity` adet).
 */
final readonly class RemoteReturnItem
{
    public function __construct(
        public string $externalItemId,
        public int $quantity,
        public ?string $channelStatus,
        public ?string $externalOrderLineId = null,
        public ?string $sku = null,
        public ?string $barcode = null,
        public ?string $productName = null,
        public ?string $reasonCode = null,
        public ?string $reasonText = null,
        public ?string $customerNote = null,
        public ?string $acceptDetail = null,
    ) {}
}
