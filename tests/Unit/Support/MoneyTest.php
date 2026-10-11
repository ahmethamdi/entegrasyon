<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\Money\Money;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** Para yardımcısı — kayan nokta yok, kuruş kaybı yok (ADR-002). */
final class MoneyTest extends TestCase
{
    #[Test]
    public function minor_units_round_trip_without_float_drift(): void
    {
        $this->assertSame(1990, Money::toMinor('19.90'));
        $this->assertSame(1990, Money::toMinor('19.9'));
        $this->assertSame(1900, Money::toMinor(19));
        // float'ta 0.1 + 0.2 tuzağı burada yok: ondalık metin.
        $this->assertSame(30, Money::toMinor(Money::add('0.1', '0.2')));
        $this->assertSame('19.90', Money::fromMinor(1990));
        $this->assertSame('-0.05', Money::fromMinor(-5));
    }

    #[Test]
    public function half_up_rounding_is_used(): void
    {
        $this->assertSame('10.01', Money::normalize('10.005'));
        $this->assertSame('10.00', Money::normalize('10.004'));
    }

    #[Test]
    public function vat_is_extracted_from_a_gross_amount(): void
    {
        // 120,00 KDV dahil, %20 → 20,00 KDV.
        $this->assertSame('20.00', Money::vatIncluded('120.00', '20'));
        // 10,00 × 1000 adet, %20 — kuruşta kayma yok.
        $this->assertSame('1666.67', Money::vatIncluded('10000.00', '20'));
        $this->assertSame('0.00', Money::vatIncluded('50.00', '0'));
    }

    #[Test]
    public function allocation_never_loses_a_cent(): void
    {
        $parts = Money::allocate(1000, [1, 1, 1]);

        $this->assertSame(1000, array_sum($parts));
        $this->assertSame([334, 333, 333], $parts);
        $this->assertSame([0, 500], Money::allocate(500, [0, 3]));
    }

    #[Test]
    public function floats_from_json_are_read_through_their_short_decimal_form(): void
    {
        $this->assertSame('19.90', Money::normalizeOrNull(19.9));
        $this->assertSame('0.30', Money::normalizeOrNull(0.1 + 0.2));
        $this->assertNull(Money::normalizeOrNull(null));
        $this->assertNull(Money::normalizeOrNull(''));
    }

    #[Test]
    public function invalid_amounts_are_rejected_not_zeroed(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::toMinor('12,50');
    }
}
