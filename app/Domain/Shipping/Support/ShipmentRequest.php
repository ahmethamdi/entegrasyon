<?php

declare(strict_types=1);

namespace App\Domain\Shipping\Support;

/** Firmaya gidecek gönderi — bellekte; alıcı `ShipmentRecipient` kuralına tabi. */
final readonly class ShipmentRequest
{
    public function __construct(
        public string $cargoKey,
        public ShipmentRecipient $recipient,
        public string $orderNumber,
        public int $packageCount = 1,
    ) {}
}
