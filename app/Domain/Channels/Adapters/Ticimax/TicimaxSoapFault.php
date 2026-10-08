<?php

declare(strict_types=1);

namespace App\Domain\Channels\Adapters\Ticimax;

use RuntimeException;

/**
 * Ticimax SOAP Fault'u ya da `WebServisResponse.IsError`.
 *
 * WCF hatayı çoğu zaman HTTP 500 + Fault gövdesiyle döner; HTTP durumuna
 * bakan kod mesajı kaybederdi. `faultCode` sınıflandırmada kullanılır.
 */
final class TicimaxSoapFault extends RuntimeException
{
    public function __construct(string $message, public readonly string $faultCode = '')
    {
        parent::__construct($message);
    }

    /** Yetki kodu reddi mi? Mesaj metninden (WCF kod vermez). */
    public function isAuthentication(): bool
    {
        $text = mb_strtolower($this->getMessage());

        foreach (['yetki', 'uyekodu', 'üye kodu', 'uye kodu', 'unauthorized', 'yetkisiz'] as $needle) {
            if (str_contains($text, $needle)) {
                return true;
            }
        }

        return false;
    }
}
