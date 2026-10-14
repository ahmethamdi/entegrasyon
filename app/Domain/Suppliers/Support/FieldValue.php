<?php

declare(strict_types=1);

namespace App\Domain\Suppliers\Support;

use App\Domain\Suppliers\Enums\FieldState;

/**
 * Tedarikçinin sayısal değeri + durumu. `value` yalnız PRESENT/ZERO'da
 * doludur; stokta int, fiyatta "12.34" biçiminde dize.
 */
final class FieldValue
{
    private function __construct(
        public readonly FieldState $state,
        public readonly int|string|null $value,
        public readonly ?string $rawText,
    ) {}

    public static function missing(): self
    {
        return new self(FieldState::MISSING, null, null);
    }

    public static function invalid(string $raw): self
    {
        return new self(FieldState::INVALID, null, $raw);
    }

    public static function of(int|string $value, string $raw): self
    {
        $isZero = is_int($value) ? $value === 0 : bccomp($value, '0', 2) === 0;

        return new self($isZero ? FieldState::ZERO : FieldState::PRESENT, $value, $raw);
    }

    /** Stokta 0 geçerli bir değerdir; fiyatta değildir. */
    public function hasValue(): bool
    {
        return $this->state === FieldState::PRESENT || $this->state === FieldState::ZERO;
    }

    public function isPositive(): bool
    {
        return $this->state === FieldState::PRESENT;
    }
}
