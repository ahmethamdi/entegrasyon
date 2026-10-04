<?php

declare(strict_types=1);

namespace Tests\Feature\Site;

use App\Http\Controllers\SiteController;
use App\Support\Site\Blog;
use Database\Seeders\PlanSeeder;
use DOMDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tanıtım sitesinin içerik + SEO katmanı: blog, yasal sayfalar, site
 * haritası, robots.txt ve panelin dizine kapalı olması.
 *
 * Blog testleri iki kaynakla çalışır: gerçek içerik (resources/content/blog)
 * "yayımlanan yazılar gerçekten açılıyor mu" sorusu için; fikstür dizini
 * (tests/Fixtures/blog) "gelecek tarihli yazı gizli mi" sorusu için —
 * gerçek içerikte gelecek tarihli yazı olup olmaması zamana bağlıdır.
 */
final class ContentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Derlenmiş varlık manifestine bağımlı kalmasın: test içeriği
        // sınar, `npm run build` yapılmış olmasını değil.
        $this->withoutVite();

        // Yasal sayfalar ve SoftwareApplication JSON-LD planları okur.
        $this->seed(PlanSeeder::class);
    }

    private function useFixtureBlog(): void
    {
        config(['site.blog_path' => base_path('tests/Fixtures/blog')]);
    }

    // ─────────────────────────────────────────────────── blog

    #[Test]
    public function blog_index_lists_published_posts(): void
    {
        $posts = app(Blog::class)->all();

        $this->assertNotEmpty($posts, 'resources/content/blog altında yayımlanmış yazı yok.');

        $response = $this->get('/blog')->assertOk();

        foreach ($posts as $post) {
            $response->assertSee($post->title);
            $response->assertSee(route('site.blog.show', $post->slug), false);
        }
    }

    #[Test]
    public function post_page_renders_with_blogposting_json_ld(): void
    {
        $post = app(Blog::class)->all()[0];

        $this->get(route('site.blog.show', $post->slug))
            ->assertOk()
            ->assertSee($post->title)
            ->assertSee('"@type":"BlogPosting"', false)
            ->assertSee('"@type":"BreadcrumbList"', false)
            ->assertSee('class="prose-site', false);
    }

    #[Test]
    public function unknown_post_slug_returns_404(): void
    {
        $this->get('/blog/boyle-bir-yazi-yok')->assertNotFound();
    }

    /**
     * Önceden hazırlanıp deploy edilen yazı, tarihi gelene kadar ne listede
     * ne kendi adresinde ne de site haritasında görünmemeli.
     */
    #[Test]
    public function future_dated_post_is_hidden_everywhere(): void
    {
        $this->useFixtureBlog();

        $this->get('/blog')
            ->assertOk()
            ->assertSee('Fikstür: yayımlanmış yazı')
            ->assertDontSee('Fikstür: gelecek tarihli yazı');

        $this->get('/blog/gelecek-yazi')->assertNotFound();
        $this->get('/blog/yayimlanmis-yazi')->assertOk();

        $this->get('/sitemap.xml')->assertDontSee('/blog/gelecek-yazi', false);
    }

    /** Markdown içindeki ham HTML sayfaya geçmez (html_input: strip). */
    #[Test]
    public function raw_html_in_markdown_is_stripped(): void
    {
        $this->useFixtureBlog();

        $this->get('/blog/yayimlanmis-yazi')
            ->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('<h2 id="giris">', false);
    }

    // ─────────────────────────────────────────────────── yasal sayfalar

    #[Test]
    public function each_legal_page_renders_with_owner_name(): void
    {
        foreach (array_keys(SiteController::LEGAL_PAGES) as $page) {
            $this->get(route('site.legal', $page))
                ->assertOk()
                ->assertSee(config('site.owner'))
                ->assertSee('Son güncelleme');
        }
    }

    #[Test]
    public function unknown_legal_page_returns_404(): void
    {
        $this->get('/yasal/yok-boyle-sayfa')->assertNotFound();
        // Ortak iskelet dosyası bir sayfa değildir.
        $this->get('/yasal/layout')->assertNotFound();
    }

    // ─────────────────────────────────────────────────── site haritası, robots, noindex

    #[Test]
    public function sitemap_is_valid_xml_with_blog_urls_and_without_panel(): void
    {
        $response = $this->get('/sitemap.xml')->assertOk();
        $xml = $response->getContent();

        $dom = new DOMDocument;
        $this->assertTrue(@$dom->loadXML((string) $xml), 'Site haritası geçerli XML değil.');
        $this->assertSame('urlset', $dom->documentElement?->localName);

        $post = app(Blog::class)->all()[0];
        $this->assertStringContainsString(route('site.blog.show', $post->slug), (string) $xml);
        $this->assertStringContainsString('<lastmod>'.$post->modifiedAt()->format('Y-m-d').'</lastmod>', (string) $xml);
        $this->assertStringNotContainsString('/panel', (string) $xml);
    }

    #[Test]
    public function robots_txt_disallows_panel_and_points_to_sitemap(): void
    {
        $robots = (string) file_get_contents(public_path('robots.txt'));

        $this->assertMatchesRegularExpression('/^Disallow: \/panel$/m', $robots);
        $this->assertMatchesRegularExpression('/^Sitemap: https:\/\/34pazar\.com\/sitemap\.xml$/m', $robots);
    }

    /** Inertia kök şablonu (panel + giriş) dizine girmemeli. */
    #[Test]
    public function inertia_pages_carry_noindex(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('<meta name="robots" content="noindex">', false);
    }
}
