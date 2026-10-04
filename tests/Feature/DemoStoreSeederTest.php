<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Orders\Models\Order;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\ChannelTypeSeeder;
use Database\Seeders\DemoStoreSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Demo mağaza — tanıtım sitesi ekran görüntüleri bundan çekilir.
 *
 * Yerelde elle çalıştırılan bir kurucu sessizce bozulursa ancak bir
 * sonraki ekran görüntüsü turunda fark edilirdi; test onu temiz
 * veritabanında koşturur.
 */
final class DemoStoreSeederTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_builds_a_complete_demo_store_once(): void
    {
        $this->seed(ChannelTypeSeeder::class);
        $this->seed(DemoStoreSeeder::class);
        $this->seed(DemoStoreSeeder::class); // ikinci kez: tekrar yazmaz

        $orders = TenantContext::runAsSystem(fn () => Order::query()->count());

        $this->assertSame(9, $orders);

        // Panelde görülsün diye kurulmuş üç durum: fazla satış, tanınmayan
        // ürün, kargo bekleyen sipariş.
        $this->post('/login', ['email' => DemoStoreSeeder::EMAIL, 'password' => 'Demo-34Pazar-2026'])
            ->assertRedirect('/panel');

        $keys = array_column($this->get('/panel')->viewData('page')['props']['todos'], 'key');

        $this->assertContains('oversold', $keys);
        $this->assertContains('unmatched', $keys);
        $this->assertContains('awaiting_shipment', $keys);
    }
}
