<?php

declare(strict_types=1);

namespace App\Domain\Fulfillment\Support;

use App\Domain\Orders\Enums\OrderState;

/**
 * Kanaldan ANLIK okunan paket kargo bilgisi (adapter üretir).
 *
 * Ham taşıyıcı adı ve durum adapter sınırında yorumlanır: çekirdek yalnız
 * `state` (kanonik) ve `commonLabelEligible` (adapter kararı) okur.
 */
final readonly class ShipmentInfo
{
    public function __construct(
        public string $packageId,
        public ?string $trackingNumber,
        public ?string $cargoProvider,
        public bool $commonLabelEligible,
        public ?OrderState $state,
        public int $boxQuantity = 1,
    ) {}
}
