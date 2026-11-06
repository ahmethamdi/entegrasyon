<?php

declare(strict_types=1);

namespace Tests\Feature\Site;

use App\Domain\Channels\Certification\CertificationStatus;
use App\Support\Site\Services;
use App\Support\Site\SiteProof;
use App\Support\Site\Solutions;
use Database\Seeders\ChannelTypeSeeder;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Site sprint 1 (6 Kas 2026): yeni sayfalar, site haritası ve DÜRÜSTLÜK.
 *
 * Dürüstlük testleri RENDER ÇIKTISINI ölçer: kanal/kargo/e-fatura durumu
 * sertifikasyon matrisinden gelmeli; sahte testli kanal "canlı" diye,
 * Shopify uygulaması reddedildikten sonra "Shopify faturası" diye bir
 * ödeme yolu anlatılmamalı.
 */
final class SiteSprintOneTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ChannelTypeSeeder::class);
        $this->seed(PlanSeeder::class);
        // Hepsiburada sayfası açık kanal olarak da sınansın (seeder kapalı yazar).
        DB::table('channel_types')->where('code', 'hepsiburada')->update(['is_active' => true]);
    }

    /** @return array<string, array{0: string, 1: list<string>}> */
    public static function newPages(): array
    {
        return [
            'hizmetler' => ['/hizmetler', ['Paneli kurmayı bize bırak.', 'Kurulum ve veri taşıma', 'Shopify mağaza kurulumu', 'WooCommerce mağaza ve entegrasyon', 'Özel entegrasyon', 'Eğitim', 'Shopify Partner', 'Teklif al']],
            'kurulum' => ['/hizmetler/kurulum-ve-veri-tasima', ['Excel\'den ya da eski entegratöründen', 'İlk siparişe kadar eşlik', 'Kanalları birlikte bağlama', 'konu=teklif&amp;hizmet=kurulum-ve-veri-tasima']],
            'sss' => ['/sss', ['Sık sorulanlar', 'Hangi kanallarla çalışıyor?', 'Kanal bağlama', '"@type":"FAQPage"']],
            'güvenlik' => ['/guvenlik', ['Güvenlik ve KVKK', 'Sunucu Almanya\'da', 'Her gece şifreli yedek', 'Geri yükleme her hafta denenir', 'Şifreli saklanır', 'Her firma ayrı', 'Çerezsiz ziyaret sayacı', 'Bot kalkanı']],
            'stok çözümü' => ['/cozumler/cok-kanalli-stok-yonetimi', ['Tek ürünün var, üç kanalda satılıyor.', 'Nasıl çalışır?', 'Hangi kanalda ne kadar doğrulandı?', 'images/site/panel-stok.png']],
            'sipariş çözümü' => ['/cozumler/siparis-ve-kargo-operasyonu', ['Siparişler beş ayrı panelde', 'Yurtiçi Kargo', 'Aras Kargo', 'Test ortamında doğrulandı']],
            'pazaryeri çözümü' => ['/cozumler/pazaryerine-acilma', ['Siten hazır, pazaryerine açılmak istiyorsun.', 'Trendyol', 'Hepsiburada', 'Ürün gönderme']],
            'trendyol' => ['/entegrasyonlar/trendyol', ['Trendyol\'da neler çalışır.', 'Canlıda doğrulandı', 'Nasıl bağlanır?', 'API key', 'API secret', 'Satıcı ID (Cari ID)', 'Hesap Bilgilerim', 'Trendyol hakkında sık sorulanlar', '"@type":"FAQPage"']],
            'hepsiburada' => ['/entegrasyonlar/hepsiburada', ['Hepsiburada\'da neler çalışır.', 'Test ediliyor', 'Nasıl bağlanır?', 'Servis Anahtarı', 'Merchant ID', 'Entegratör kullanıcı adı', 'API Entegratör', 'Hepsiburada hakkında sık sorulanlar']],
        ];
    }

    #[Test]
    #[DataProvider('newPages')]
    public function new_pages_render_with_their_content(string $url, array $texts): void
    {
        $html = $this->get($url)->assertOk()->getContent();

        foreach ($texts as $text) {
            // Kesme işareti Blade'de &#039; olur; ham (JSON-LD, adres) ya da kaçışlı biçim.
            $this->assertTrue(str_contains($html, $text) || str_contains($html, e($text)), "{$url}: \"{$text}\" yok");
        }
    }

    #[Test]
    public function a_service_without_its_own_page_is_404_and_links_to_its_section(): void
    {
        $this->get('/hizmetler/egitim')->assertNotFound();
        $this->get('/hizmetler/yok')->assertNotFound();
        $this->assertStringEndsWith('/hizmetler#egitim', Services::url('egitim'));
    }

    #[Test]
    public function problem_and_module_solution_slugs_never_collide(): void
    {
        $this->assertSame([], array_intersect(array_keys(Solutions::problems()), array_keys(Solutions::all())));

        // Eski modül sayfaları bozulmadı.
        foreach (array_keys(Solutions::all()) as $slug) {
            $this->get("/cozumler/{$slug}")->assertOk()->assertSee('Özellikler');
        }
    }

    #[Test]
    public function the_sitemap_lists_the_new_pages(): void
    {
        $xml = $this->get('/sitemap.xml')->assertOk()->getContent();

        foreach (['/hizmetler', '/hizmetler/kurulum-ve-veri-tasima', '/sss', '/guvenlik', '/cozumler/cok-kanalli-stok-yonetimi', '/cozumler/siparis-ve-kargo-operasyonu', '/cozumler/pazaryerine-acilma'] as $path) {
            $this->assertStringContainsString('<loc>'.url($path).'</loc>', $xml, $path);
        }
        $this->assertStringNotContainsString(url('/hizmetler/egitim'), $xml);
    }

    #[Test]
    public function menus_link_to_the_new_pages_and_the_faq_no_longer_points_to_the_home_anchor(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        foreach (['/hizmetler', '/sss', '/guvenlik', '/cozumler/pazaryerine-acilma', 'konu=gorusme'] as $needle) {
            $this->assertStringContainsString($needle, $html, $needle);
        }
        $this->assertStringContainsString('Görüşme planla', $html);
        $this->assertStringNotContainsString('/#sss', $html);
    }

    // ─────────────────────────────────────────────── dürüstlük

    #[Test]
    public function the_pricing_page_no_longer_mentions_shopify_billing_and_states_the_tax_fact(): void
    {
        foreach (['/fiyatlar', '/en/pricing', '/'] as $url) {
            $html = $this->get($url)->assertOk()->getContent();
            $this->assertStringNotContainsString('Shopify faturası', $html, $url);
            $this->assertStringNotContainsString('Shopify bill', $html, $url);
        }

        $this->get('/fiyatlar')->assertSee('ödeme sırasında üzerine ayrıca vergi eklenmez');
        $this->get('/en/pricing')->assertSee('no tax is added at checkout');
    }

    #[Test]
    public function a_mock_tested_channel_is_never_shown_as_live(): void
    {
        $proof = app(SiteProof::class);

        // Ön koşul: matris Hepsiburada'yı gerçek hesapta doğrulanmış SAYMIYOR.
        $this->assertNotSame(CertificationStatus::PRODUCTION_VERIFIED, $proof->channelLevel('hepsiburada'));
        $this->assertSame(CertificationStatus::PRODUCTION_VERIFIED, $proof->channelLevel('trendyol'));

        $hb = $this->get('/entegrasyonlar/hepsiburada')->assertOk()->getContent();
        $rows = $this->rowsOf($hb);
        $this->assertNotSame('', $rows);
        $this->assertStringNotContainsString('Canlıda doğrulandı', $rows);
        $this->assertStringNotContainsString('badge--live', $rows);

        // Ana sayfa şeridi: canlı denilen kanal yalnız matristeki canlı kanal(lar).
        $home = $this->get('/')->getContent();
        $this->assertStringContainsString('Canlı hesapta doğrulandı: Trendyol', $home);
        $this->assertStringNotContainsString('Canlı hesapta doğrulandı: Trendyol, Hepsiburada', $home);

        // Ana sayfa kanal bölümü yalnız logo + ad: durum rozeti yok (6 Kas kararı).
        $logos = substr($home, (int) strpos($home, 'class="home-logos'));
        $logos = substr($logos, 0, (int) strpos($logos, '</ul>'));
        $this->assertStringContainsString('/entegrasyonlar/hepsiburada', $logos);
        $this->assertStringNotContainsString('badge', $logos);
        $this->assertStringNotContainsString('home-table', $home);
    }

    #[Test]
    public function e_invoicing_is_presented_at_its_verified_level_only(): void
    {
        $status = app(SiteProof::class)->service('Nilvera e-fatura');
        $this->assertNotNull($status);
        $this->assertNotSame(CertificationStatus::PRODUCTION_VERIFIED, $status, 'Ön koşul: Nilvera gerçek hesapta doğrulanmadı');

        $this->get('/')->assertDontSee('Nilvera ile e-fatura')->assertDontSee('Evet, kendi Nilvera hesabından');
        $this->get('/sss')->assertSee('E-fatura kesebiliyor muyum?')->assertSee($status->publicNote());
        $this->get('/cozumler/e-fatura')->assertSee('data-status="'.$status->value.'"', false)->assertSee($status->publicLabel());
        $this->get('/fiyatlar')->assertSee('e-arşiv — '.mb_strtolower($status->publicLabel()));
    }

    #[Test]
    public function cargo_firms_show_their_sandbox_level_from_the_evidence_record(): void
    {
        $html = $this->get('/cozumler/siparis-ve-kargo-operasyonu')->getContent();

        foreach (app(SiteProof::class)->services() as $service) {
            if (in_array($service['name'], ['Yurtiçi Kargo', 'Aras Kargo'], true)) {
                $this->assertStringContainsString('data-status="'.$service['status']->value.'"', $html);
            }
        }
    }

    #[Test]
    public function the_site_has_no_dark_full_width_band_and_no_shadow_or_glass_effect(): void
    {
        $css = (string) file_get_contents(resource_path('css/site.css'));

        foreach (['box-shadow', 'drop-shadow', 'backdrop-filter', 'marquee', '--color-navy', '.on-dark', '.on-navy', '.on-brand'] as $banned) {
            $this->assertStringNotContainsString($banned, $css, $banned);
        }

        foreach (['/', '/hizmetler', '/iletisim', '/guvenlik', '/entegrasyonlar/trendyol', '/cozumler/pazaryerine-acilma'] as $url) {
            $html = $this->get($url)->getContent();
            $this->assertDoesNotMatchRegularExpression('#class="(?:[^"]*\s)?(on-dark|on-navy|on-brand|shadow-\w+|bg-ink|bg-navy)(?:\s[^"]*)?"#', $html, $url);
        }
    }

    #[Test]
    public function a_channel_without_an_official_icon_shows_no_letter_logo(): void
    {
        DB::table('channel_types')->where('code', 'ticimax')->update(['is_active' => true]);

        $html = $this->get('/entegrasyonlar')->assertOk()->getContent();

        $this->assertStringContainsString('Ticimax', $html);
        $this->assertStringNotContainsString('channel-icon__letter', $html);
        $this->assertStringContainsString('channel-icon--none', $html);
    }

    #[Test]
    public function the_footer_shows_the_imprint_facts_and_nothing_invented(): void
    {
        $html = $this->get('/')->getContent();
        $footer = explode('<footer', $html, 2)[1] ?? '';

        $this->assertStringContainsString(e(config('site.operator')), $footer);
        $this->assertStringContainsString(config('site.street'), $footer);
        $this->assertStringContainsString('Künye', $footer);
        $this->assertStringNotContainsString('Vergi', $footer);
        $this->assertStringNotContainsString('MERSİS', $footer);
    }

    /** Kanal sayfasındaki yetenek satırları (durum tablosu) — açıklama listesi hariç. */
    private function rowsOf(string $html): string
    {
        $start = strpos($html, 'id="calisan-h2"');
        $end = strpos($html, '<dl class="mt-6 grid', (int) $start);

        return $start === false || $end === false ? '' : substr($html, $start, $end - $start);
    }
}
