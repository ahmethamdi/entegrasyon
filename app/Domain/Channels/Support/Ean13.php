<?php

declare(strict_types=1);

namespace App\Domain\Channels\Support;

/** EAN-13 barkod doğrulaması (13 hane + GS1 kontrol hanesi). */
final class Ean13
{
    public static function valid(?string $code): bool
    {
        $code = trim((string) $code);

        if (preg_match('/^\d{13}$/', $code) !== 1) {
            return false;
        }

        $sum = 0;

        for ($i = 0; $i < 12; $i++) {
            $sum += (int) $code[$i] * ($i % 2 === 0 ? 1 : 3);
        }

        return (10 - $sum % 10) % 10 === (int) $code[12];
    }
}
