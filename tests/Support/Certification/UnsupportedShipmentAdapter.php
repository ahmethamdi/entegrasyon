<?php

declare(strict_types=1);

namespace Tests\Support\Certification;

use App\Domain\Channels\Contracts\DeclaresUnsupportedCapabilities;

/** S15 · NOT_SUPPORTED beyanının sınanması için en küçük sahte adaptör. */
final class UnsupportedShipmentAdapter implements DeclaresUnsupportedCapabilities
{
    public static function unsupportedCapabilities(): array
    {
        return ['shipment' => 'Kanal takip numarası kabul etmiyor (sahte).'];
    }
}
