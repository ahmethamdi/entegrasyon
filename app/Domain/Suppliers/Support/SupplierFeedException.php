<?php

declare(strict_types=1);

namespace App\Domain\Suppliers\Support;

use App\Domain\Suppliers\Enums\SupplierReason;
use RuntimeException;

/**
 * Tur düzeyinde durduran hata — kararlı sebep kodu + ham ayrıntı.
 * Ham ayrıntı (uzak sunucunun metni, ayrıştırıcı iletisi) çevrilmez;
 * ekran sebebi `SupplierReason::label()` ile gösterir.
 */
final class SupplierFeedException extends RuntimeException
{
    public function __construct(public readonly SupplierReason $reason, string $detail = '')
    {
        parent::__construct($detail !== '' ? $detail : $reason->value);
    }
}
