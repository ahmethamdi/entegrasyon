<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Ai\Support\AiFeature;
use App\Domain\Billing\Enums\QuotaMetric;
use App\Domain\Billing\Models\Plan;
use App\Domain\Channels\Contracts\SupportsFulfillment;
use App\Domain\Channels\Models\ChannelType;
use App\Domain\Channels\Support\ChannelConnectForm;
use App\Domain\Channels\Support\ChannelLogo;
use App\Domain\Identity\Support\DemoAccount;
use App\Support\Site\Blog;
use App\Support\Site\Solutions;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

/**
 * Tanıtım sitesi — 34pazar.com, herkese açık, Blade ile sunucuda üretilir.
 *
 * DEĞİŞMEZ KURAL — SİTE VERİ UYDURMAZ:
 *   Fiyatlar `plans` tablosundan, kanallar `channel_types`'tan okunur.
 *   Sayfaya elle fiyat yazılsaydı panel ile site ayrışır ve satıcı
 *   sitede gördüğünden farklı bir fiyatla karşılaşırdı. Kapalı kanal
 *   "yakında" diye gösterilir, "destekleniyor" diye değil.
 *
 * Görünüm sözleşmesi: her sayfa `site.layout`'u genişletir ve şu
 * bölümleri doldurur — `title`, `description`, (isteğe bağlı)
 * `canonical`, `og_image`, `jsonld`, `content`. Ortak veriler
 * (`$plans`, `$channels`, `$isLoggedIn`) her görünüme bu sınıftan gider.
 */
final class SiteController extends Controller
{
    /** Yasal sayfalar — izin listesi; listede olmayan adres 404. */
    public const LEGAL_PAGES = [
        'kunye' => 'Künye',
        'gizlilik' => 'Gizlilik ve KVKK aydınlatma metni',
        'cerez' => 'Çerez politikası',
        'kullanim-kosullari' => 'Kullanım koşulları',
        'mesafeli-satis' => 'Mesafeli hizmet sözleşmesi',
        // Gizlilik metninin İngilizce çevirisi — Shopify App Store
        // incelemesi ve İngilizce panel kullanıcıları için.
        'privacy' => 'Privacy Policy (English)',
    ];

    public function home(Request $request): View
    {
        return $this->page('site.home', $request);
    }

    /** Ana sayfanın İngilizcesi — aynı şablon, `lang` = en. */
    public function homeEn(Request $request): View
    {
        return $this->page('site.home', $request, ['lang' => 'en']);
    }

    public function features(Request $request): View
    {
        return $this->page('site.features', $request);
    }

    public function pricing(Request $request): View
    {
        return $this->page('site.pricing', $request);
    }

    public function channels(Request $request): View
    {
        return $this->page('site.channels', $request);
    }

    /**
     * Kanal sayfası — yalnız sistemde TANIMLI kanal için açılır.
     *
     * Tanımsız bir adres ("/entegrasyonlar/amazon") 404 verir: o kanal
     * için sayfa açmak desteklemediğimiz bir şeyi destekliyormuş gibi
     * arama sonucuna çıkarırdı.
     */
    public function channel(Request $request, string $channel): View
    {
        $type = ChannelType::query()->where('code', $channel)->first(['code', 'name', 'kind', 'adapter_class', 'is_active', 'is_beta']);

        abort_if($type === null, 404);

        return $this->page('site.channel', $request, [
            'channel' => $this->presentChannel($type),
        ]);
    }

    public function about(Request $request): View
    {
        return $this->page('site.about', $request);
    }

    public function contact(Request $request): View
    {
        return $this->page('site.contact', $request);
    }

    public function blogIndex(Request $request, Blog $blog): View
    {
        return $this->page('site.blog.index', $request, ['posts' => $blog->all()]);
    }

    public function blogShow(Request $request, Blog $blog, string $slug): View
    {
        $post = $blog->find($slug);

        abort_if($post === null, 404);

        return $this->page('site.blog.show', $request, ['post' => $post, 'related' => $blog->related($post, 3)]);
    }

    /**
     * İngilizce yardım sayfaları (Shopify App Store listelemesindeki FAQ,
     * Changelog, Tutorial, Documentation bağlantıları). Tanıtım sitesi
     * Türkçedir; bu sayfalar yalnız uygulamayı kullanan satıcı içindir.
     */
    public const ENGLISH_PAGES = [
        'getting-started' => 'Getting started',
        'docs' => 'Documentation',
        'faq' => 'FAQ',
        'changelog' => 'Changelog',
    ];

    /** Çözüm sayfası (TR) — yalnız `resources/content/solutions.php`'deki çözümler. */
    public function solution(Request $request, string $slug): View
    {
        return $this->solutionPage($request, $slug, 'tr');
    }

    /** Çözüm sayfası (EN). */
    public function solutionEn(Request $request, string $slug): View
    {
        return $this->solutionPage($request, $slug, 'en');
    }

    private function solutionPage(Request $request, string $slug, string $lang): View
    {
        $solution = Solutions::find($slug, $lang);

        abort_if($solution === null, 404);

        return $this->page('site.solution', $request, [
            'lang' => $lang,
            'solution' => $solution,
            'copy' => $solution[$lang],
            'solutionChannels' => Solutions::channels($solution),
            'alternate' => [
                'tr' => route('site.solution', $solution['slug']),
                'en' => route('site.en.solution', $solution['en_slug']),
            ],
        ]);
    }

    public function english(Request $request, string $page): View
    {
        abort_unless(array_key_exists($page, self::ENGLISH_PAGES), 404);

        return $this->page("site.en.{$page}", $request, [
            'pageTitle' => self::ENGLISH_PAGES[$page],
            'englishPages' => self::ENGLISH_PAGES,
        ]);
    }

    public function legal(Request $request, string $page): View
    {
        abort_unless(array_key_exists($page, self::LEGAL_PAGES), 404);

        return $this->page("site.legal.{$page}", $request, ['legalTitle' => self::LEGAL_PAGES[$page]]);
    }

    /**
     * Site haritası — yalnız herkese açık, dizine girmesi istenen adresler.
     * Panel ve giriş sayfaları BİLEREK yoktur.
     */
    /**
     * S12 · /yardim — kanal bağlama rehberleri.
     *
     * İÇERİK İKİNCİ KEZ YAZILMAZ: "neler istenir, nereden alınır" metni
     * bağlanma formunun tanımından (`ChannelConnectForm`) okunur — panelde
     * satıcının gördüğü alanların ve ipuçlarının AYNISI. Ayrı yazılsaydı
     * form değişince rehber sessizce eski kalırdı. Ortak sorular panel
     * yardımından (`HelpController`, "kanallar") gelir.
     */
    public function helpIndex(Request $request): View
    {
        return $this->page('site.help.index', $request, [
            'guides' => array_values(array_filter($this->channelList(), fn (array $c): bool => $this->hasGuide($c['code']))),
        ]);
    }

    public function helpChannel(Request $request, string $channel): View
    {
        $type = ChannelType::query()->where('code', $channel)->first(['code', 'name', 'kind', 'adapter_class', 'is_active', 'is_beta']);

        abort_if($type === null || ! $this->hasGuide($type->code), 404);

        return $this->page('site.help.channel', $request, [
            'channel' => $this->presentChannel($type),
            'form' => ChannelConnectForm::present($type->code),
            'installOnly' => ChannelConnectForm::installOnly($type->code),
            'installPublic' => (bool) config("services.{$type->code}.listing_public", false),
            'faqs' => HelpController::channelQuestions(),
        ]);
    }

    /** Rehber yalnız AÇIK ve panelden bağlanma tanımı olan kanal için. */
    private function hasGuide(string $code): bool
    {
        return ChannelConnectForm::isDefined($code)
            && (bool) ChannelType::query()->where('code', $code)->value('is_active');
    }

    public function sitemap(Blog $blog): Response
    {
        $urls = [
            ['loc' => route('home'), 'lastmod' => null],
            ['loc' => route('site.en.home'), 'lastmod' => null],
            ['loc' => route('site.features'), 'lastmod' => null],
            ['loc' => route('site.pricing'), 'lastmod' => null],
            ['loc' => route('site.channels'), 'lastmod' => null],
            ['loc' => route('site.about'), 'lastmod' => null],
            ['loc' => route('site.contact'), 'lastmod' => null],
            ['loc' => route('site.blog'), 'lastmod' => null],
        ];

        foreach ($this->channelList() as $channel) {
            $urls[] = ['loc' => route('site.channel', $channel['code']), 'lastmod' => null];
        }

        $urls[] = ['loc' => route('site.help'), 'lastmod' => null];
        foreach ($this->channelList() as $channel) {
            if ($this->hasGuide($channel['code'])) {
                $urls[] = ['loc' => route('site.help.channel', $channel['code']), 'lastmod' => null];
            }
        }

        foreach (Solutions::all() as $slug => $solution) {
            $urls[] = ['loc' => route('site.solution', $slug), 'lastmod' => null];
            $urls[] = ['loc' => route('site.en.solution', $solution['en_slug']), 'lastmod' => null];
        }

        foreach ($blog->all() as $post) {
            $urls[] = ['loc' => route('site.blog.show', $post->slug), 'lastmod' => $post->updatedAt ?? $post->publishedAt];
        }

        foreach (array_keys(self::LEGAL_PAGES) as $legal) {
            $urls[] = ['loc' => route('site.legal', $legal), 'lastmod' => null];
        }

        return response()
            ->view('site.sitemap', ['urls' => $urls])
            ->header('Content-Type', 'application/xml; charset=UTF-8');
    }

    // ─────────────────────────────────────────────────── ortak veri

    /** @param array<string, mixed> $data */
    private function page(string $view, Request $request, array $data = []): View
    {
        return view($view, [
            'plans' => $this->planList(),
            'channels' => $this->channelList(),
            'isLoggedIn' => $request->user() !== null,
            // S12 · "Demoyu incele" yalnız demo kuruluysa (bağlantı 404 vermesin).
            'demoAvailable' => (bool) config('entegrasyon.demo.enabled')
                && Cache::remember('site:demo-available', 600, fn (): bool => DemoAccount::user() !== null),
            'legalPages' => self::LEGAL_PAGES,
            'lang' => 'tr',
            'solutionMenu' => Solutions::menu(($data['lang'] ?? 'tr') === 'en' ? 'en' : 'tr'),
            ...$data,
        ]);
    }

    /**
     * Paket satırları. Bir limit satırı ancak planın limitlerinde o anahtar
     * VARSA basılır — eksik veri "Sınırsız" okunmasın. AI öneri hakkı
     * yalnız özellik açıkken (S8, anahtar girilmiş) gösterilir: kapalı bir
     * özelliği pakette satmak yanlış vaat olurdu.
     *
     * @return list<array{code: string, name: string, priceMonthly: string, currency: string, productLimit: int|null, channelLimit: int|null, hasInvoiceLimit: bool, invoiceLimit: int|null, hasUserLimit: bool, userLimit: int|null, showAi: bool, aiLimit: int|null}>
     */
    private function planList(): array
    {
        $ai = AiFeature::enabled();

        return Plan::query()
            ->where('is_public', true)
            ->orderBy('price_monthly')
            ->get(['code', 'name', 'price_monthly', 'currency', 'limits'])
            ->map(fn (Plan $plan): array => [
                'code' => $plan->code,
                'name' => $plan->name,
                'priceMonthly' => (string) $plan->price_monthly,
                'currency' => $plan->currency ?? 'TRY',
                'productLimit' => $plan->limits['products'] ?? null,
                'channelLimit' => $plan->limits['channels'] ?? null,
                // Anahtar yoksa satır hiç basılmaz: eksik veri "Sınırsız" okunmasın.
                'hasInvoiceLimit' => array_key_exists(QuotaMetric::INVOICES->value, $plan->limits ?? []),
                'invoiceLimit' => $plan->limits[QuotaMetric::INVOICES->value] ?? null,
                'hasUserLimit' => array_key_exists(QuotaMetric::USERS->value, $plan->limits ?? []),
                'userLimit' => $plan->limits[QuotaMetric::USERS->value] ?? null,
                'showAi' => $ai && array_key_exists(QuotaMetric::AI_SUGGESTIONS->value, $plan->limits ?? []),
                'aiLimit' => $plan->limits[QuotaMetric::AI_SUGGESTIONS->value] ?? null,
            ])
            ->all();
    }

    /** @return list<array{code: string, name: string, available: bool, beta: bool, logo: ?string, marketplace: bool, cargoPush: bool}> */
    private function channelList(): array
    {
        return ChannelType::query()
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get(['code', 'name', 'kind', 'adapter_class', 'is_active', 'is_beta'])
            ->map(fn (ChannelType $type): array => $this->presentChannel($type))
            ->all();
    }

    /**
     * Kanalın sitede gösterilen hâli.
     *
     * `cargoPush` ADAPTÖRÜN YETENEĞİNDEN okunur (SupportsFulfillment):
     * takip numarasını kanala geri gönderebilen kanallar listesi daha önce
     * üç görünümde elle tutuluyordu ve Etsy desteği eklendiğinde üçü de eskide kaldı.
     * Adaptör örneklenmez (bağlantı bilgisi gerekmez); yalnız sınıfın
     * arayüzü uygulayıp uygulamadığına bakılır.
     *
     * @return array{code: string, name: string, available: bool, beta: bool, logo: ?string, marketplace: bool, cargoPush: bool}
     */
    private function presentChannel(ChannelType $type): array
    {
        return [
            'code' => $type->code,
            'name' => $type->name,
            'available' => (bool) $type->is_active,
            // Beta: açık ama gerçek satıcı hesabıyla henüz doğrulanmadı (21 Eki kararı).
            'beta' => (bool) $type->is_active && (bool) $type->is_beta,
            'logo' => ChannelLogo::url($type->code),
            'wordmark' => ChannelLogo::isWordmark($type->code),
            'icon' => ChannelLogo::icon($type->code),
            'marketplace' => $type->kind === 'marketplace',
            'cargoPush' => is_string($type->adapter_class) && is_a($type->adapter_class, SupportsFulfillment::class, true),
        ];
    }
}
