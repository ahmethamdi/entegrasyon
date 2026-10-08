<?php

declare(strict_types=1);

namespace App\Domain\Channels\Adapters\N11;

use RuntimeException;

/**
 * N11 SOAP hatası — Fault ya da `result.status = failure`.
 *
 * N11 SOAP kimlik reddini HTTP 200 içinde döner:
 * `<status>failure</status><errorCode>SELLER_API.authenticationFailed</errorCode>`
 * (8 Eki 2026 ölçümü, `docs/YENI-KANALLAR-API-NOTLARI.md` §4 · Sağlık).
 * HTTP durumuna bakan kod bunu başarı sanardı.
 */
final class N11SoapFault extends RuntimeException
{
    public function __construct(string $message, public readonly string $errorCode = '')
    {
        parent::__construct($message);
    }

    /** Kimlik reddi mi? Hata kodundan; kod yoksa mesajdan. */
    public function isAuthentication(): bool
    {
        $text = mb_strtolower($this->errorCode.' '.$this->getMessage());

        return str_contains($text, 'authenticationfailed') || str_contains($text, 'authentication failed');
    }
}
