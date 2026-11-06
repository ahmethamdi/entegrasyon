<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Ai\Support\AiFeature;
use App\Domain\Billing\Enums\QuotaMetric;
use App\Domain\Billing\Models\Plan;
use App\Domain\Channels\Certification\CertificationStatus;
use App\Domain\Channels\Certification\ChannelCapability;
use App\Domain\Channels\Certification\ChannelCertification;
use App\Domain\Channels\Contracts\SupportsFulfillment;
use App\Domain\Channels\Models\ChannelType;
use App\Domain\Channels\Support\ChannelConnectForm;
use App\Domain\Channels\Support\ChannelLogo;
use App\Domain\Identity\Support\DemoAccount;
use App\Support\Security\FormShield;
use App\Support\Site\Blog;
use App\Support\Site\ContactForm;
use App\Support\Site\Faq;
use App\Support\Site\Services;
use App\Support\Site\SiteProof;
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

    public function pricingEn(Request $request): View
    {
        return $this->page('site.pricing', $request, ['lang' => 'en']);
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
        $type = ChannelType::query()->where('code', $channel)->first(['code', 'name', 'kind', 'adapter_class', 'is_active']);

        // Uygulama kaydı eksik kanal (`isOffered`) vitrinde YOK: sayfası
        // olsaydı satıcı bağlanamayacağı bir kanalı arayıp bulurdu.
        abort_if($type === null || ($type->is_active && ! $type->isOffered()), 404);

        // Site sprint 1 · bağlantı adımları panelin GERÇEK formundan
        // (`ChannelConnectForm`, /yardim rehberiyle aynı kaynak); ortak
        // sorular panel yardımından. Ayrı metin yazılmaz.
        $defined = ChannelConnectForm::isDefined($type->code);

        return $this->page('site.channel', $request, [
            'channel' => $this->presentChannel($type),
            'form' => $defined ? ChannelConnectForm::present($type->code) : null,
            'installOnly' => $defined && ChannelConnectForm::installOnly($type->code),
            'hasGuide' => $this->hasGuide($type->code),
            'channelFaqs' => HelpController::channelQuestions(),
            'legend' => SiteProof::legend(),
        ]);
    }

    public function about(Request $request): View
    {
        return $this->page('site.about', $request);
    }

    /**
     * /iletisim — bilgiler + form (site sprint 1). `?konu=` ve `?hizmet=`
     * formu ön seçili açar ("Görüşme planla", hizmetlerin "Teklif al"ı);
     * tanınmayan değer sessizce yok sayılır.
     */
    public function contact(Request $request): View
    {
        $topic = (string) $request->query('konu', '');
        $service = (string) $request->query('hizmet', '');

        return $this->page('site.contact', $request, [
            'topics' => ContactForm::TOPICS,
            'services' => Services::all(),
            'presetTopic' => array_key_exists($topic, ContactForm::TOPICS) ? $topic : null,
            'presetService' => array_key_exists($service, Services::all()) ? $service : null,
            'formStamp' => FormShield::stamp(ContactForm::FORM),
        ]);
    }

    /** /hizmetler — 34Devs'in 34Pazar çevresinde verdiği hizmetler. */
    public function services(Request $request): View
    {
        return $this->page('site.services.index', $request, ['services' => Services::all()]);
    }

    /** /hizmetler/{slug} — yalnız ayrı sayfası olan hizmet; diğerleri 404. */
    public function service(Request $request, string $slug): View
    {
        $service = Services::find($slug);

        abort_if($service === null || ! $service['detail'], 404);

        return $this->page("site.services.{$slug}", $request, ['service' => $service]);
    }

    /** /sss — genel sorular (ana sayfadaki kısa listenin tamamı, `Faq`). */
    public function faq(Request $request): View
    {
        return $this->page('site.faq', $request, [
            'faqs' => Faq::general($this->planList(), $this->channelList()),
            'channelFaqs' => HelpController::channelQuestions(),
        ]);
    }

    /** /guvenlik — yalnız kodda/altyapıda doğrulanan güvenlik ve KVKK olguları. */
    public function security(Request $request): View
    {
        return $this->page('site.security', $request);
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
        // Site sprint 1 · iş sorununa göre sayfalar aynı adres alanında (yalnız TR).
        $problem = $lang === 'tr' ? Solutions::findProblem($slug) : null;
        if ($problem !== null) {
            return $this->page('site.problem', $request, [
                'problem' => $problem,
                'proofRows' => $this->proofRows($problem['proof']),
                'proofServices' => array_values(array_filter(
                    app(SiteProof::class)->services(),
                    fn (array $s): bool => in_array($s['name'], $problem['proof']['services'], true),
                )),
            ]);
        }

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
        $type = ChannelType::query()->where('code', $channel)->first(['code', 'name', 'kind', 'adapter_class', 'is_active']);

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
            && (bool) ChannelType::query()->where('code', $code)->value('is_active')
            && ! ChannelType::isWithheld($code);
    }

    public function sitemap(Blog $blog): Response
    {
        $urls = [
            ['loc' => route('home'), 'lastmod' => null],
            ['loc' => route('site.en.home'), 'lastmod' => null],
            ['loc' => route('site.features'), 'lastmod' => null],
            ['loc' => route('site.pricing'), 'lastmod' => null],
            ['loc' => route('site.en.pricing'), 'lastmod' => null],
            ['loc' => route('site.channels'), 'lastmod' => null],
            ['loc' => route('site.about'), 'lastmod' => null],
            ['loc' => route('site.contact'), 'lastmod' => null],
            ['loc' => route('site.blog'), 'lastmod' => null],
            ['loc' => route('site.services'), 'lastmod' => null],
            ['loc' => route('site.faq'), 'lastmod' => null],
            ['loc' => route('site.security'), 'lastmod' => null],
        ];

        foreach (Services::all() as $slug => $service) {
            if ($service['detail']) {
                $urls[] = ['loc' => route('site.service', $slug), 'lastmod' => null];
            }
        }

        foreach (array_keys(Solutions::problems()) as $slug) {
            $urls[] = ['loc' => route('site.solution', $slug), 'lastmod' => null];
        }

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

    /**
     * Çözüm sayfasının "kanıt" tablosu: kanal × yetenek, matristen.
     *
     * @param  array{capabilities: list<string>, channels: list<string>|null}  $proof
     * @return list<array{code: string, name: string, available: bool, cells: array<string, CertificationStatus>}>
     */
    private function proofRows(array $proof): array
    {
        $proofSource = app(SiteProof::class);
        $channels = collect($this->channelList())->keyBy('code');
        $codes = $proof['channels'] ?? $channels->where('available', true)->keys()->all();

        $rows = [];
        foreach ($codes as $code) {
            $channel = $channels->get($code);
            if ($channel === null) {
                continue;
            }
            $cells = [];
            foreach ($proof['capabilities'] as $capability) {
                $cells[$capability] = $proofSource->cell($code, ChannelCapability::from($capability));
            }
            $rows[] = ['code' => $code, 'name' => $channel['name'], 'available' => $channel['available'], 'cells' => $cells];
        }

        // En güçlü kanıt üstte; eşitlikte ada göre.
        usort($rows, function (array $a, array $b): int {
            $rank = fn (array $r): int => min(array_map(fn (CertificationStatus $s): int => $s->evidenceRank(), $r['cells']));

            return [$rank($b), $a['available'] ? 0 : 1, $a['name']] <=> [$rank($a), $b['available'] ? 0 : 1, $b['name']];
        });

        return $rows;
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
                && Cache::remember(DemoAccount::SITE_CACHE_KEY, 600, fn (): bool => DemoAccount::user() !== null),
            'legalPages' => self::LEGAL_PAGES,
            'lang' => 'tr',
            'solutionMenu' => Solutions::menu(($data['lang'] ?? 'tr') === 'en' ? 'en' : 'tr'),
            'problemMenu' => Solutions::problemMenu(),
            'serviceMenu' => Services::menu(),
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

    /** @return list<array{code: string, name: string, available: bool, logo: ?string, marketplace: bool, cargoPush: bool}> */
    private function channelList(): array
    {
        return ChannelType::query()
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get(['code', 'name', 'kind', 'adapter_class', 'is_active'])
            // Açık ama sunulamayan kanal listede "Yakında" da görünmez:
            // tamamen çıkar (24 Eki kararı: "yakında" denmez).
            ->reject(fn (ChannelType $type): bool => $type->is_active && ! $type->isOffered())
            ->map(fn (ChannelType $type): array => $this->presentChannel($type))
            ->values()
            ->all();
    }

    /** Sitede kanal başına gösterilen yetenek satırları (kanal sayfası, ana sayfa tablosu). */
    public const SITE_CAPABILITIES = [
        ChannelCapability::INVENTORY,
        ChannelCapability::ORDER_IMPORT,
        ChannelCapability::PUBLISHING,
        ChannelCapability::PRICE,
        ChannelCapability::SHIPMENT,
    ];

    /**
     * Kanalın sitede gösterilen hâli.
     *
     * `cargoPush` ADAPTÖRÜN YETENEĞİNDEN okunur (SupportsFulfillment):
     * takip numarasını kanala geri gönderebilen kanallar listesi daha önce
     * üç görünümde elle tutuluyordu ve Etsy desteği eklendiğinde üçü de eskide kaldı.
     * Adaptör örneklenmez (bağlantı bilgisi gerekmez); yalnız sınıfın
     * arayüzü uygulayıp uygulamadığına bakılır.
     *
     * @return array{code: string, name: string, available: bool, logo: ?string, marketplace: bool, cargoPush: bool}
     */
    private function presentChannel(ChannelType $type): array
    {
        $certification = app(ChannelCertification::class);
        $works = [];
        $status = [];
        foreach (self::SITE_CAPABILITIES as $capability) {
            $cell = $certification->cell($type->code, $capability)['status'];
            $works[$capability->value] = $cell->works();
            $status[$capability->value] = $cell;
        }

        return [
            'code' => $type->code,
            'name' => $type->name,
            'available' => (bool) $type->is_active,
            'logo' => ChannelLogo::url($type->code),
            'wordmark' => ChannelLogo::isWordmark($type->code),
            'icon' => ChannelLogo::icon($type->code),
            'marketplace' => $type->kind === 'marketplace',
            // S15 · sitede "çalışır" denen her satır sertifikasyon matrisinden
            // (adaptör arayüzü + test + kanıt); elle yazılmaz.
            'cargoPush' => $works[ChannelCapability::SHIPMENT->value],
            'works' => $works,
            // Site sprint 1 · "çalışır" yerine doğrulama düzeyi (SiteProof):
            // hücre başına durum ve kanalın özeti (stok + siparişin zayıfı).
            'status' => $status,
            'level' => app(SiteProof::class)->channelLevel($type->code),
        ];
    }
}
