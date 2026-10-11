<?php

declare(strict_types=1);

namespace Tests\Unit\Orders;

use App\Domain\Orders\Enums\OrderState;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** Kanonik durum geçiş kuralı — tek kaynak (ADR-001). */
final class OrderStateTest extends TestCase
{
    /** @return iterable<string, array{0: ?OrderState, 1: OrderState, 2: bool}> */
    public static function transitions(): iterable
    {
        yield 'bilinmeyenden her yere' => [null, OrderState::SHIPPED, true];
        yield 'ödeme → kargo bekliyor' => [OrderState::AWAITING_PAYMENT, OrderState::AWAITING_SHIPMENT, true];
        yield 'kargo bekliyor → kargoda' => [OrderState::AWAITING_SHIPMENT, OrderState::SHIPPED, true];
        yield 'kargo bekliyor → teslim (atlama)' => [OrderState::AWAITING_SHIPMENT, OrderState::DELIVERED, true];
        yield 'teslim → kısmi iade' => [OrderState::DELIVERED, OrderState::PARTIALLY_RETURNED, true];
        yield 'kargoda → kargo bekliyor GERİ' => [OrderState::SHIPPED, OrderState::AWAITING_SHIPMENT, false];
        yield 'teslim → kargoda GERİ' => [OrderState::DELIVERED, OrderState::SHIPPED, false];
        yield 'kısmi iade → teslim GERİ' => [OrderState::PARTIALLY_RETURNED, OrderState::DELIVERED, false];
        yield 'kısmi iade → iade' => [OrderState::PARTIALLY_RETURNED, OrderState::RETURNED, true];
        yield 'kargoda → iptal' => [OrderState::SHIPPED, OrderState::CANCELLED, true];
        yield 'iptal → kargoda (terminal)' => [OrderState::CANCELLED, OrderState::SHIPPED, false];
        yield 'iptal → iade (terminal)' => [OrderState::CANCELLED, OrderState::RETURNED, false];
        yield 'bölünen paket → iptal (terminal)' => [OrderState::SUPERSEDED, OrderState::CANCELLED, false];
        yield 'iade → kısmi iade (terminal)' => [OrderState::RETURNED, OrderState::PARTIALLY_RETURNED, false];
        yield 'aynı durum etkisiz' => [OrderState::SHIPPED, OrderState::SHIPPED, false];
    }

    #[Test]
    #[DataProvider('transitions')]
    public function transitions_follow_the_single_rule(?OrderState $from, OrderState $to, bool $allowed): void
    {
        $this->assertSame($allowed, OrderState::canTransition($from, $to));
    }

    #[Test]
    public function terminal_states_are_exactly_the_three_closed_ones(): void
    {
        $terminal = array_values(array_map(
            static fn (OrderState $s): string => $s->value,
            array_filter(OrderState::cases(), static fn (OrderState $s): bool => $s->isTerminal()),
        ));

        $this->assertSame(['cancelled', 'returned', 'superseded'], $terminal);
        // Satış sayılmayanlar = terminal durumlar.
        $nonRevenue = OrderState::nonRevenue();
        sort($nonRevenue);
        $this->assertSame($terminal, $nonRevenue);
    }
}
