<?php

declare(strict_types=1);

namespace Tests\Feature;

use Database\Seeders\ChannelTypeSeeder;
use Database\Seeders\DemoStoreSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Ana sayfa (Yön A, 13 Kasım 2026) — KPI kartları, kanal durumu, son
 * siparişler ve menü rozetleri.
 *
 * Kart ile "Yapman gerekenler" maddesi ve menü rozeti AYNI tanımdan
 * beslenir; biri ayrışırsa satıcı aynı ekranda iki farklı sayı görür.
 * Test bu eşitliği kilitler.
 */
final class DashboardOverviewTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, mixed> */
    private function panelProps(): array
    {
        $this->seed(ChannelTypeSeeder::class);
        $this->seed(DemoStoreSeeder::class);

        $this->post('/login', ['email' => DemoStoreSeeder::EMAIL, 'password' => 'Demo-34Pazar-2026'])
            ->assertRedirect('/panel');

        return $this->get('/panel')->assertOk()->viewData('page')['props'];
    }

    #[Test]
    public function awaiting_shipment_is_the_same_number_everywhere(): void
    {
        $props = $this->panelProps();

        $todo = collect($props['todos'])->firstWhere('key', 'awaiting_shipment');

        $this->assertNotNull($todo);
        $this->assertSame($todo['count'], $props['today']['awaitingShipment']);
        $this->assertSame($todo['count'], $props['navBadges']['/orders']);
        $this->assertLessThanOrEqual($props['today']['awaitingShipment'], $props['today']['awaitingOverDay']);
    }

    #[Test]
    public function it_shows_recent_orders_without_customer_data(): void
    {
        $props = $this->panelProps();

        $this->assertNotEmpty($props['recentOrders']);
        $this->assertLessThanOrEqual(6, count($props['recentOrders']));

        foreach ($props['recentOrders'] as $order) {
            $this->assertSame(
                ['id', 'number', 'channel', 'status', 'state', 'hasShipment', 'currency', 'grandTotal', 'placedAt'],
                array_keys($order),
            );
        }

        // En yeni üstte.
        $times = array_column($props['recentOrders'], 'placedAt');
        $sorted = $times;
        rsort($sorted);
        $this->assertSame($sorted, $times);
    }

    #[Test]
    public function it_summarises_stock_channels_and_today(): void
    {
        $props = $this->panelProps();

        $this->assertSame(['low', 'out', 'threshold'], array_keys($props['stock']));
        $this->assertGreaterThanOrEqual(0, $props['stock']['low']);
        $this->assertSame(5, $props['stock']['threshold']);

        foreach ($props['connections'] as $connection) {
            $this->assertIsInt($connection['issues']);
            $this->assertIsInt($connection['pending']);
        }

        $this->assertIsArray($props['today']['byChannel']);
        $this->assertSame(
            $props['today']['orderCount'],
            array_sum(array_column($props['today']['byChannel'], 'count')),
        );
        $this->assertArrayHasKey('/questions', $props['navBadges']);
    }
}
