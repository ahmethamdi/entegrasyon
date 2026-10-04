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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tanıtım sitesi (Blade) — herkese açık sayfalar.
 *
 * DEĞİŞMEZ KURAL — SİTE VERİ UYDURMAZ (SiteController):
 *   Fiyatlar `plans` tablosundan, kanallar `channel_types`'tan gelir.
 *   Testler sayfanın SABİT bir metin değil veritabanını yansıttığını
 *   doğrular: gizli plan sitede görünmemeli, kapalı kanal "Yakında"
 *   olarak durmalı, tanımsız kanalın sayfası hiç açılmamalı.
 */
final class HomePageTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{0: string, 1: string}> */
    public static function marketingPages(): array
    {
        return [
            'ana sayfa' => ['/', 'Sık sorulanlar'],
            'özellikler' => ['/ozellikler', 'Tek stok, bütün kanallar.'],
            'fiyatlar' => ['/fiyatlar', 'Fiyatlar aylıktır.'],
            'entegrasyonlar' => ['/entegrasyonlar', 'Bir panel.'],
            'hakkımızda' => ['/hakkimizda', "34Pazar'ı 34Devs yapıyor."],
            'iletişim' => ['/iletisim', 'Bize'],
        ];
    }

    /** Her tanıtım sayfası misafire açılır ve kendi içeriğini taşır. */
    #[Test]
    #[DataProvider('marketingPages')]
    public function marketing_page_renders_for_guest(string $url, string $text): void
    {
        $this->seedChannels();

        $this->get($url)
            ->assertOk()
            // Kaçışsız karşılaştırma: şablondaki düz kesme işareti (34Pazar'ı) birebir aranır.
            ->assertSee($text, false)
            // Misafire kayıt çağrısı gider, "Panele git" değil.
            ->assertSee('Ücretsiz başla')
            ->assertDontSee('Panele git');
    }

    /**
     * Fiyatlar veritabanından gelir, Türk biçimiyle yazılır ve YALNIZ
     * herkese açık planlar listelenir.
     *
     * Gizli plan (ör. tek müşteriye özel fiyat) sitede görünseydi her
     * ziyaretçi o fiyatı isterdi.
     */
    #[Test]
    public function home_shows_public_plan_prices_from_the_database(): void
    {
        (new PlanSeeder)->run();

        Plan::query()->create([
            'code' => 'gizli-ozel',
            'name' => 'Gizli Özel Teklif',
            'price_monthly' => 77,
            'limits' => [],
            'is_public' => false,
        ]);

        $response = $this->get('/')->assertOk();

        // Seed değerleri: 499 / 1499 / 3999 → "1.499 ₺" biçimi; 0 → "Ücretsiz".
        $response->assertSee('499 ₺')
            ->assertSee('1.499 ₺')
            ->assertSee('3.999 ₺')
            ->assertSee('Ücretsiz')
            // null limit = sınırsız (Kurumsal).
            ->assertSee('Sınırsız')
            ->assertDontSee('Gizli Özel Teklif')
            ->assertDontSee('77 ₺');
    }

    /** Fiyat sayfası da aynı tabloyu okur ve aylık olduğunu söyler. */
    #[Test]
    public function pricing_page_lists_plans_from_the_database(): void
    {
        (new PlanSeeder)->run();

        $this->get('/fiyatlar')
            ->assertOk()
            ->assertSee('Başlangıç')
            ->assertSee('Profesyonel')
            ->assertSee('Kurumsal')
            ->assertSee('1.499 ₺')
            ->assertSee('Kart bilgisi gerekmez')
            ->assertSee('Fiyatlar aylıktır.');
    }

    /**
     * Kapalı kanal listede kalır ama "Yakında" diye durur.
     *
     * Bayrak yanlış okunsaydı desteklemediğimiz bir kanalı satmış olurduk.
     */
    #[Test]
    public function inactive_channel_is_shown_as_coming_soon(): void
    {
        $this->seedChannels();

        $this->get('/entegrasyonlar')
            ->assertOk()
            ->assertSee('WooCommerce')
            ->assertSee('Kapalı Kanal')
            ->assertSee('Yakında')
            ->assertSee('Bağlanabilir');
    }

    /** Açık kanalın sayfası neyin çalıştığını yazar; kargo notu kanala göre. */
    #[Test]
    public function active_channel_page_describes_what_works(): void
    {
        $this->seedChannels();

        $this->get('/entegrasyonlar/woocommerce')
            ->assertOk()
            ->assertSee('WooCommerce')
            ->assertSee('neler çalışır.')
            // WooCommerce takip numarasını kanala geri gönderebilen kanallardan.
            ->assertSee('numara bu kanala iletilir')
            ->assertSee('consumer key');
    }

    /** Kapalı kanalın sayfası açılır ama hiçbir yetenek iddia etmez. */
    #[Test]
    public function inactive_channel_page_says_it_is_being_prepared(): void
    {
        $this->seedChannels();

        $this->get('/entegrasyonlar/site-test-kapali')
            ->assertOk()
            ->assertSee('Bu entegrasyon hazırlanıyor.')
            ->assertDontSee('neler çalışır.');
    }

    /**
     * Sistemde tanımlı olmayan kanalın sayfası YOKTUR.
     *
     * Açılsaydı desteklemediğimiz bir kanal arama sonucunda
     * "entegrasyon" diye görünürdü.
     */
    #[Test]
    public function undefined_channel_page_is_404(): void
    {
        $this->seedChannels();

        $this->get('/entegrasyonlar/amazon')->assertNotFound();
    }

    /** Giriş yapmış kullanıcı da siteyi görür; başlıkta "Panele git" durur. */
    #[Test]
    public function logged_in_user_sees_go_to_panel(): void
    {
        $user = User::factory()->create();
        (new CreateTenant)->run(name: 'Site '.uniqid(), owner: $user);

        $this->actingAs($user)
            ->get('/')
            ->assertOk()
            ->assertSee('Panele git')
            ->assertDontSee('Ücretsiz başla');
    }

    /** Bir açık, bir kapalı kanal tanımlar. */
    private function seedChannels(): void
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
    }
}
