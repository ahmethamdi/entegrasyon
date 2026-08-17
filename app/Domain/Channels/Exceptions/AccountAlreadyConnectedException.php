<?php

declare(strict_types=1);

namespace App\Domain\Channels\Exceptions;

use RuntimeException;

/**
 * Bir mağaza yalnızca TEK kiracıya bağlanabilir.
 *
 * Mimari Karar Dokümanı v2.2 · §4 · channel_connections, §11.
 *
 * Kısıt veritabanında da vardır (`channel_connections_account_unique`) ve
 * güvenlik amaçlıdır: aynı alan adı iki kiracıya bağlanabilseydi, alan
 * adından kiracı çözen servis çağrıları belirsiz kalırdı.
 *
 * Bu istisna kısıtın yerini ALMAZ — onu kullanıcıya anlatılabilir bir hataya
 * çevirir. Son söz veritabanındadır; yarış durumunda kısıt devreye girer.
 */
final class AccountAlreadyConnectedException extends RuntimeException
{
    public static function for(string $channelTypeCode, string $accountId): self
    {
        return new self(
            "Bu mağaza başka bir hesaba bağlı: {$accountId} ({$channelTypeCode}). ".
            'Bir mağaza yalnızca tek bir kiracıya bağlanabilir.'
        );
    }
}
