<?php

declare(strict_types=1);

namespace App\Domain\Shipping\Support;

/**
 * Firmada açılmış gönderinin sonucu.
 *
 * `trackingNumber` müşterinin firmada sorgulayacağı numaradır; firma onu
 * gönderiyi açarken vermiyorsa (Yurtiçi — kargo şubede işlem görünce
 * oluşur) null'dır. `labelZpl`/`labelPdf` firmanın verdiği belgedir;
 * vermiyorsa (Yurtiçi) etiketi biz üretiriz.
 */
final readonly class CreatedShipment
{
    public function __construct(
        public ?string $trackingNumber = null,
        public ?string $labelZpl = null,
        public ?string $labelPdf = null,
    ) {}
}
