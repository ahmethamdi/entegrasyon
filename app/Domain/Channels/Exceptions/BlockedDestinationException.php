<?php

declare(strict_types=1);

namespace App\Domain\Channels\Exceptions;

use InvalidArgumentException;

/**
 * Kanal isteği iç ağı hedefliyor — gönderilmedi (SSRF, B3).
 *
 * `InvalidArgumentException`'dan türer: kayıt anında StoreUrl'ün mevcut
 * "adres geçersiz" yolu (form hatası) onu olduğu gibi yakalar.
 */
final class BlockedDestinationException extends InvalidArgumentException {}
