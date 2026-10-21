<?php

declare(strict_types=1);

namespace App\Domain\Sync\Support;

use App\Domain\Channels\Contracts\DeclaresChannelCurrency;
use Throwable;

/**
 * Kanalın fiyat para birimi — bilinmiyorsa null (denetim yapılmaz).
 *
 * Okuma hatası fiyat işini düşürmez: para birimi okunamayan kanal (Etsy
 * önbelleği boşken API'ye sorar) için denetim atlanır, eski davranış sürer.
 */
final class ChannelCurrency
{
    public static function of(object $adapter): ?string
    {
        if (! $adapter instanceof DeclaresChannelCurrency) {
            return null;
        }

        try {
            $currency = $adapter->channelCurrency();
        } catch (Throwable) {
            return null;
        }

        return is_string($currency) && trim($currency) !== '' ? strtoupper(trim($currency)) : null;
    }
}
