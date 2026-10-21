<?php

declare(strict_types=1);

namespace Tests\Feature\Site;

use App\Domain\Billing\Models\Plan;
use App\Domain\Channels\Adapters\Trendyol\TrendyolAdapter;
use App\Domain\Channels\Adapters\WooCommerce\WooCommerceAdapter;
use App\Domain\Channels\Contracts\SupportsFulfillment;
use App\Domain\Channels\Models\ChannelType;
use App\Domain\Channels\Support\ChannelLogo;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\User;
use App\Support\Site\Solutions;
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
            'entegrasyonlar' => ['/entegrasyonlar', 'Bir panel, bütün satış kanalların.'],
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
            ->assertSee('Ücretsiz dene')
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

    /**
     * Ana sayfa girişi: stok diyagramı erişilebilir adla ve "örnek ürün"
     * notuyla gelir (12 → 11 uydurma veri sanılmasın); hareketsiz son kare
     * şablonda durur. Üst çubukta telefon YOK (kullanıcı kararı).
     */
    #[Test]
    public function home_hero_diagram_is_labelled_as_an_example(): void
    {
        $this->seedChannels();

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('aria-labelledby="dg-title dg-desc"', $html);
        $this->assertStringContainsString('Örnek ürün ile stok akışı', $html);
        $this->assertStringContainsString('örnek ürün', $html);
        $this->assertStringContainsString('class="dg-final"', $html);
        $this->assertStringContainsString('class="topbar"', $html);
        $this->assertStringNotContainsString('tel:', explode('<header', $html, 2)[0]);
    }

    /** /en ana sayfası İngilizce, hreflang ile TR'ye bağlı ve site haritasında. */
    #[Test]
    public function english_home_renders_and_links_back(): void
    {
        $this->seedChannels();
        (new PlanSeeder)->run();

        $this->get('/en')
            ->assertOk()
            ->assertSee('<html lang="en">', false)
            ->assertSee('Sold on Trendyol, stock dropped on every channel.')
            ->assertSee('E-invoices / month')
            ->assertSee('hreflang="tr"', false);

        $this->get('/')->assertSee('E-fatura / ay')->assertSee('hreflang="en"', false);
        $this->assertStringContainsString('/en</loc>', $this->get('/sitemap.xml')->getContent());
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
            // Rozet birebir: "Aktif kanallar" başlığı tek başına testi geçirmesin.
            ->assertSee('<span class="badge badge--active">Aktif</span>', false);
    }

    /**
     * Kanal kartı yerel logoyu taşır (CDN yok) ve alt metni markayı adlandırır;
     * yazı-logosunda ad ikinci kez görünmez (yalnız ekran okuyucuya).
     */
    #[Test]
    public function channel_cards_show_local_brand_logos(): void
    {
        $this->seedChannels();

        $html = $this->get('/entegrasyonlar')->assertOk()->getContent();

        // Kartlarda AYNI boyutlu kare ikon kutusu; ad her zaman yazı.
        $this->assertStringContainsString('/images/channels/icon/woocommerce.svg', $html);
        $this->assertStringContainsString('channel-icon size-10', $html);
        $this->assertStringNotContainsString('logo-strip', $this->get('/')->getContent());
        $this->assertStringNotContainsString('cdn.jsdelivr', $html);
        $this->assertTrue(ChannelLogo::isWordmark('hepsiburada'));
        $this->assertFalse(ChannelLogo::isWordmark('woocommerce'));
        $this->assertNull(ChannelLogo::url('../etc/passwd'));
    }

    /**
     * Çözüm sayfaları TR ve EN açılır, birbirine hreflang ile bağlanır, SSS
     * JSON-LD taşır ve site haritasında durur; bilinmeyen çözüm 404.
     */
    #[Test]
    public function solution_pages_render_in_both_languages_and_are_in_the_sitemap(): void
    {
        $this->seedChannels();

        foreach (Solutions::all() as $slug => $solution) {
            $this->get("/cozumler/{$slug}")
                ->assertOk()
                ->assertSee($solution['tr']['h1'])
                ->assertSee('hreflang="en"', false)
                ->assertSee('"@type":"FAQPage"', false);

            $this->get("/en/solutions/{$solution['en_slug']}")
                ->assertOk()
                ->assertSee('<html lang="en">', false)
                ->assertSee($solution['en']['title']);
        }

        $this->get('/cozumler/muhasebe')->assertNotFound();

        $sitemap = $this->get('/sitemap.xml')->assertOk()->getContent();
        $this->assertStringContainsString('/cozumler/stok-senkronu', $sitemap);
        $this->assertStringContainsString('/en/solutions/inventory-sync', $sitemap);
    }

    /** Kanal listesi yetenekten gelir: kargo çözümü yalnız SupportsFulfillment kanallarını sayar. */
    #[Test]
    public function solution_channels_come_from_adapter_capabilities(): void
    {
        $this->seedChannels();

        $codes = array_column(Solutions::channels(Solutions::find('kargo-bildirimi', 'tr')), 'code');

        foreach ($codes as $code) {
            $class = ChannelType::query()->whereKey($code)->value('adapter_class');
            $this->assertTrue(is_a($class, SupportsFulfillment::class, true), $code);
        }
    }

    /** Menü ve sayfalar olmayan özelliği vaat etmez. */
    #[Test]
    public function no_unbuilt_feature_is_promised(): void
    {
        $this->seedChannels();

        foreach (['/', '/ozellikler', '/cozumler/kargo-bildirimi'] as $url) {
            $html = $this->get($url)->assertOk()->getContent();
            $this->assertStringNotContainsString('Muhasebe modülü', $html, $url);
            $this->assertStringNotContainsString('Yurtiçi', $html, $url);
            $this->assertStringNotContainsString('Aras', $html, $url);
        }
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

    /**
     * Kargo bildirimi adaptörün YETENEĞİNDEN okunur (SupportsFulfillment),
     * elle tutulan listeden değil: uygulamayan adaptörün kanal sayfası
     * "kanala iletilir" DEMEZ.
     */
    #[Test]
    public function cargo_note_follows_adapter_capability(): void
    {
        $this->asSystem(function (): void {
            ChannelType::query()->firstOrCreate(
                ['code' => 'site-test-kargosuz'],
                [
                    'name' => 'Kargosuz Kanal',
                    'kind' => 'marketplace',
                    'adapter_class' => TrendyolAdapter::class,
                    'is_active' => true,
                ],
            );
        });

        $this->get('/entegrasyonlar/site-test-kargosuz')
            ->assertOk()
            ->assertSee('neler çalışır.')
            ->assertSee('Şimdilik numarayı')
            ->assertDontSee('numara bu kanala iletilir');
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
            ->assertDontSee('Ücretsiz dene');
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
