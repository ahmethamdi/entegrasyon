<?php

declare(strict_types=1);

namespace Tests\Feature\Observability;

use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\User;
use App\Support\Analytics\VisitStats;
use Database\Seeders\PlanSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Çerezsiz ziyaret sayacı (6 Kas 2026).
 *
 * Canlıda erişim kaydı yoktu, kaç kişinin geldiğini bilmiyorduk. Bu dosya
 * sayacın üç sözünü sınar: insanın gördüğü sayfa sayılır (bot, yönetici,
 * ön yükleme, hata sayfası sayılmaz); aynı ziyaretçi iki kez sayılmaz ve
 * IP hiçbir satırda yoktur; sayaç bozulsa da sayfa açılır.
 */
final class VisitStatsTest extends TestCase
{
    use RefreshDatabase;

    private const BROWSER = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_0) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36';

    private const ADMIN = 'admin@34devs.test';

    protected function setUp(): void
    {
        parent::setUp();

        config(['entegrasyon.super_admin_emails' => self::ADMIN]);
        $this->withHeader('User-Agent', self::BROWSER);
    }

    /** Aynı ziyaretçi iki sayfa: görüntüleme 2, tekil ziyaretçi 1; IP saklanmaz. */
    #[Test]
    public function the_same_visitor_counts_twice_as_views_but_once_as_visitor(): void
    {
        $this->get('/')->assertOk();
        $this->get('/?utm_source=instagram&e=gizli@example.com')->assertOk();

        $this->assertSame(2, (int) DB::table('page_views_daily')->where('path', '/')->sum('views'));
        $this->assertSame(1, DB::table('page_views_daily')->count(), 'Sorgu dizgisi ayrı satır açmamalı.');
        $this->assertSame(1, DB::table('visitors_daily')->count());

        // Başka tarayıcı = başka ziyaretçi.
        $this->withHeader('User-Agent', self::BROWSER.' Edg/130.0')->get('/fiyatlar')->assertOk();
        $this->assertSame(2, DB::table('visitors_daily')->count());

        // Ham IP ve sorgu dizgisi hiçbir satırda yok.
        $dump = json_encode([DB::table('page_views_daily')->get(), DB::table('visitors_daily')->get()]);
        $this->assertStringNotContainsString('127.0.0.1', (string) $dump);
        $this->assertStringNotContainsString('gizli', (string) $dump);
        $this->assertTrue(DB::table('visitors_daily')
            ->where('visitor', VisitStats::visitorId(now()->toDateString(), '127.0.0.1', self::BROWSER))
            ->exists());
    }

    /** Ertesi gün aynı kişi yeni özet üretir — günler arası iz sürülmez. */
    #[Test]
    public function the_visitor_digest_changes_every_day(): void
    {
        $today = VisitStats::visitorId('2026-11-06', '1.2.3.4', self::BROWSER);

        $this->assertSame(32, strlen($today));
        $this->assertNotSame($today, VisitStats::visitorId('2026-11-07', '1.2.3.4', self::BROWSER));
    }

    /** Kaynak: dış alan adı yazılır, kendi alan adımızdan gezinme '' sayılır. */
    #[Test]
    public function the_referrer_host_is_kept_only_for_external_sites(): void
    {
        $this->withHeader('Referer', 'https://l.instagram.com/?u=https%3A%2F%2F34pazar.com')->get('/')->assertOk();
        $this->withHeader('Referer', 'http://localhost/fiyatlar')->get('/ozellikler')->assertOk();

        $this->assertSame('l.instagram.com', DB::table('page_views_daily')->where('path', '/')->value('referrer_host'));
        $this->assertSame('', DB::table('page_views_daily')->where('path', '/ozellikler')->value('referrer_host'));
    }

    /** Bot, tarayıcısız istemci, ön yükleme, POST, 404 ve yönetici SAYILMAZ. */
    #[Test]
    public function non_human_and_non_page_requests_are_not_counted(): void
    {
        $this->seed(PlanSeeder::class);

        foreach (['Googlebot/2.1 (+http://www.google.com/bot.html)', 'curl/8.4.0', 'python-requests/2.31', 'Mozilla/5.0 HeadlessChrome/120.0', 'UptimeRobot/2.0', ''] as $agent) {
            $this->withHeader('User-Agent', $agent)->get('/')->assertOk();
        }

        $this->withHeader('Purpose', 'prefetch')->get('/')->assertOk();
        $this->withHeader('Sec-Purpose', 'prefetch;prerender')->get('/')->assertOk();
        $this->withHeader('User-Agent', self::BROWSER)->get('/yok-boyle-bir-sayfa')->assertNotFound();
        $this->post('/locale', ['locale' => 'en']);
        $this->get('/up');

        $admin = User::factory()->create(['email' => self::ADMIN]);
        (new CreateTenant)->run(name: 'Yönetim '.uniqid(), owner: $admin);
        $this->actingAs($admin)->get('/')->assertOk();
        $this->actingAs($admin)->get('/admin')->assertOk();

        $this->assertSame(0, DB::table('page_views_daily')->count(), json_encode(DB::table('page_views_daily')->get()));
        $this->assertSame(0, DB::table('visitors_daily')->count());
    }

    /** Giriş yapmış satıcının panel sayfaları kendi yoluyla sayılır (Inertia). */
    #[Test]
    public function seller_panel_views_are_counted_under_their_own_path(): void
    {
        $seller = User::factory()->create();
        (new CreateTenant)->run(name: 'Mağaza '.uniqid(), owner: $seller);

        $version = $this->actingAs($seller)->get('/panel')->assertOk()->viewData('page')['version'];

        // Panel içi gezinme: Inertia JSON yanıtı (HTML değil) de sayfa bakışıdır.
        $this->actingAs($seller)
            ->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => (string) $version])
            ->get('/panel')
            ->assertOk()
            ->assertHeader('X-Inertia', 'true');

        // Kısmi yeniden yükleme (yoklama, filtre) yeni bakış değildir.
        $this->actingAs($seller)
            ->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => (string) $version, 'X-Inertia-Partial-Component' => 'Dashboard', 'X-Inertia-Partial-Data' => 'stats'])
            ->get('/panel')
            ->assertOk();

        $this->assertSame(2, (int) DB::table('page_views_daily')->where('path', '/panel')->value('views'));
    }

    /** Sayaç patlarsa sayfa yine açılır; hata raporlanır. */
    #[Test]
    public function a_broken_counter_never_breaks_the_page(): void
    {
        Exceptions::fake();
        $this->app->bind(VisitStats::class, fn () => new class
        {
            public function record(mixed ...$args): void
            {
                throw new RuntimeException('sayaç bozuk');
            }
        });

        $this->get('/')->assertOk();

        Exceptions::assertReported(RuntimeException::class);
    }

    /** Yönetim özeti: 7/30 gün toplamları, boş günler 0, en çok 8 sayfa/kaynak. */
    #[Test]
    public function the_admin_dashboard_shows_the_visit_summary(): void
    {
        $this->seed(PlanSeeder::class);
        $stats = app(VisitStats::class);
        $today = now()->toDateString();
        $tenDaysAgo = now()->subDays(10)->toDateString();

        $stats->record('/', 'google.com', 'a', $today);
        $stats->record('/', 'google.com', 'a', $today);
        $stats->record('/fiyatlar', '', 'b', $today);
        $stats->record('/blog', 'l.instagram.com', 'c', $tenDaysAgo);
        $stats->record('/', '', 'a', now()->subDays(40)->toDateString());   // pencere dışı

        foreach (range(1, 9) as $i) {
            $stats->record("/sayfa-{$i}", "kaynak{$i}.example", 'd', $tenDaysAgo);
        }

        $admin = User::factory()->create(['email' => self::ADMIN]);
        (new CreateTenant)->run(name: 'Yönetim '.uniqid(), owner: $admin);

        $this->actingAs($admin)->get('/admin')->assertOk()->assertInertia(fn ($page) => $page
            ->component('Admin/Dashboard')
            ->where('visits.views7', 3)
            ->where('visits.views30', 13)
            ->where('visits.visitors7', 2)
            ->where('visits.visitors30', 4)
            ->has('visits.daily', 30)
            ->where('visits.daily.29', ['date' => $today, 'views' => 3, 'visitors' => 2])
            ->where('visits.daily.28', ['date' => now()->subDay()->toDateString(), 'views' => 0, 'visitors' => 0])
            ->has('visits.topPages', 8)
            ->where('visits.topPages.0', ['path' => '/', 'views' => 2])
            ->has('visits.topReferrers', 8)
            ->where('visits.topReferrers.0', ['host' => 'google.com', 'views' => 2]));
    }

    /** 90 günden eski günler silinir; tarama her gün zamanlanmıştır. */
    #[Test]
    public function old_days_are_pruned_on_schedule(): void
    {
        $stats = app(VisitStats::class);
        $stats->record('/', '', 'a', now()->subDays(91)->toDateString());
        $stats->record('/', '', 'a', now()->subDays(89)->toDateString());

        $this->artisan('visits:prune')->assertSuccessful();

        $this->assertSame([now()->subDays(89)->toDateString()], DB::table('page_views_daily')->pluck('day')->all());
        $this->assertSame(1, DB::table('visitors_daily')->count());

        $event = collect(app(Schedule::class)->events())
            ->first(fn ($e): bool => str_contains((string) $e->command, 'visits:prune'));

        $this->assertNotNull($event, 'visits:prune zamanlanmamış.');
        $this->assertSame('10 4 * * *', $event->expression);
    }
}
