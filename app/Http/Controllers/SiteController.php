<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Billing\Models\Plan;
use App\Domain\Channels\Models\ChannelType;
use App\Support\Site\Blog;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

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
    ];

    public function home(Request $request): View
    {
        return $this->page('site.home', $request);
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
        $type = ChannelType::query()->where('code', $channel)->first(['code', 'name', 'is_active']);

        abort_if($type === null, 404);

        return $this->page('site.channel', $request, [
            'channel' => ['code' => $type->code, 'name' => $type->name, 'available' => (bool) $type->is_active],
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

    public function legal(Request $request, string $page): View
    {
        abort_unless(array_key_exists($page, self::LEGAL_PAGES), 404);

        return $this->page("site.legal.{$page}", $request, ['legalTitle' => self::LEGAL_PAGES[$page]]);
    }

    /**
     * Site haritası — yalnız herkese açık, dizine girmesi istenen adresler.
     * Panel ve giriş sayfaları BİLEREK yoktur.
     */
    public function sitemap(Blog $blog): Response
    {
        $urls = [
            ['loc' => route('home'), 'lastmod' => null],
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
            'legalPages' => self::LEGAL_PAGES,
            ...$data,
        ]);
    }

    /** @return list<array{code: string, name: string, priceMonthly: string, currency: string, productLimit: int|null, channelLimit: int|null}> */
    private function planList(): array
    {
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
            ])
            ->all();
    }

    /** @return list<array{code: string, name: string, available: bool}> */
    private function channelList(): array
    {
        return ChannelType::query()
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get(['code', 'name', 'is_active'])
            ->map(fn (ChannelType $type): array => [
                'code' => $type->code,
                'name' => $type->name,
                'available' => (bool) $type->is_active,
            ])
            ->all();
    }
}
