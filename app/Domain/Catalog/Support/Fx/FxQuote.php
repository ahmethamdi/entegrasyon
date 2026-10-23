<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Support\Fx;

use Carbon\CarbonImmutable;

/** Fiyatlamada kullanılabilir bir kur: çapa + en son gelen bülten tarihi. */
final class FxQuote
{
    public function __construct(
        public readonly string $currency,
        public readonly string $rate,
        public readonly CarbonImmutable $anchorDate,
        public readonly CarbonImmutable $latestDate,
    ) {}
}
