<?php

declare(strict_types=1);

namespace Tests\Feature\Site;

use App\Domain\Billing\Models\Plan;
use App\Domain\Channels\Adapters\WooCommerce\WooCommerceAdapter;
use App\Domain\Channels\Models\ChannelType;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tanıtım sitesi ana sayfası (`/`) — herkese açık.
 *
 * DEĞİŞMEZ KURAL — SİTE VERİ UYDURMAZ (SiteController):
 *   Fiyatlar `plans` tablosundan, kanallar `channel_types`'tan gelir.
 *   Testler bu yüzden sayfanın SABİT bir liste değil veritabanını
 *   yansıttığını doğrular: gizli plan sitede görünmemeli, kapalı kanal
 *   "destekleniyor" diye sunulmamalı.
 */
final class HomePageTest extends TestCase
{
    use RefreshDatabase;

    /** Misafir ana sayfayı görür; giriş sayfasına yönlendirilmez. */
    #[Test]
    public function guest_sees_the_home_page(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Site/Home')
                ->where('isLoggedIn', false));
    }

    /**
     * Planlar veritabanından gelir ve YALNIZ herkese açık olanlar listelenir.
     *
     * Gizli plan (ör. tek müşteriye özel fiyat) sitede görünseydi her
     * ziyaretçi o fiyatı isterdi.
     */
    #[Test]
    public function plans_come_from_the_database_and_only_public_ones_are_listed(): void
    {
        (new PlanSeeder)->run();

        Plan::query()->create([
            'code' => 'gizli-ozel',
            'name' => 'Özel teklif',
            'price_monthly' => 99,
            'limits' => [],
            'is_public' => false,
        ]);

        $plans = $this->props($this->get('/'))['plans'];
        $codes = array_column($plans, 'code');

        $this->assertNotContains('gizli-ozel', $codes);
        $this->assertSame(
            Plan::query()->where('is_public', true)->orderBy('price_monthly')->pluck('code')->all(),
            $codes,
        );

        // Fiyat ve limit seed'den okunur; `null` limit = sınırsız.
        $free = $plans[array_search('free', $codes, true)];
        $this->assertSame(0.0, (float) $free['priceMonthly']);
        $this->assertSame(25, $free['productLimit']);
        $this->assertSame(1, $free['channelLimit']);

        $business = $plans[array_search('business', $codes, true)];
        $this->assertSame(3999.0, (float) $business['priceMonthly']);
        $this->assertNull($business['productLimit']);
        $this->assertNull($business['channelLimit']);
    }

    /**
     * Kapalı kanal listede kalır ama `available = false` taşır.
     *
     * Sayfa onu "Yakında" diye gösterir; bayrak yanlış gelseydi
     * desteklemediğimiz bir kanalı satmış olurduk.
     */
    #[Test]
    public function inactive_channels_are_marked_as_unavailable(): void
    {
        $this->asSystem(function (): void {
            ChannelType::query()->firstOrCreate(
                ['code' => 'woocommerce'],
                [
                    'name' => 'WooCommerce',
                    'kind' => 'storefront',
                    'adapter_class' => WooCommerceAdapter::class,
                    'is_active' => true,
                ],
            );
            ChannelType::query()->firstOrCreate(
                ['code' => 'site-test-kapali'],
                [
                    'name' => 'Kapalı Kanal',
                    'kind' => 'marketplace',
                    // Kapalı kanal hiç çağrılmaz; adaptör yalnız sütun dolsun diye.
                    'adapter_class' => WooCommerceAdapter::class,
                    'is_active' => false,
                ],
            );
        });

        $channels = collect($this->props($this->get('/'))['channels'])->keyBy('code');

        $this->assertTrue($channels['woocommerce']['available']);
        $this->assertFalse($channels['site-test-kapali']['available']);
    }

    /** Giriş yapmış kullanıcı da sayfayı görür; başlık "Panele git" gösterir. */
    #[Test]
    public function logged_in_user_sees_the_home_page_with_is_logged_in_flag(): void
    {
        $user = User::factory()->create();
        (new CreateTenant)->run(name: 'Site '.uniqid(), owner: $user);

        $this->actingAs($user)
            ->get('/')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Site/Home')
                ->where('isLoggedIn', true));
    }

    /** @return array<string, mixed> */
    private function props(TestResponse $response): array
    {
        $response->assertOk();

        return $response->viewData('page')['props'];
    }
}
