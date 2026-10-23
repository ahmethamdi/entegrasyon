<?php

declare(strict_types=1);

namespace App\Domain\Shipping\Support;

/**
 * Firmanın gönderi hakkında söylediği — çekirdeğin anladığı dile çevrilmiş.
 *
 * `state`:
 *   waiting   = firmada kayıtlı, henüz işlem görmedi (kargoya verilmedi)
 *   shipped   = firma teslim aldı / yolda
 *   delivered = alıcıya teslim edildi
 *   cancelled = firmada iptal
 *   unknown   = firmanın cevabı tanınmadı — hiçbir şey değiştirilmez
 */
final readonly class TrackingResult
{
    public const WAITING = 'waiting';

    public const SHIPPED = 'shipped';

    public const DELIVERED = 'delivered';

    public const CANCELLED = 'cancelled';

    public const UNKNOWN = 'unknown';

    public function __construct(
        public string $state,
        public ?string $carrierStatus = null,
        public ?string $carrierText = null,
        public ?string $trackingNumber = null,
        public ?string $trackingUrl = null,
    ) {}
}
