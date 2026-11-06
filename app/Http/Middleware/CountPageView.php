<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Support\SuperAdmin;
use App\Support\Analytics\VisitStats;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Sayfa görüntüleme sayacı — web grubunda, YANIT GÖNDERİLDİKTEN SONRA yazar.
 *
 * `terminate()` php-fpm'de `fastcgi_finish_request()` sonrasında koşar:
 * ziyaretçi sayaç yazımını beklemez. Hata İSTEĞİ ASLA BOZMAZ — yanıt zaten
 * gitti; yakalanır ve raporlanır. Neyin, neden sayıldığı `VisitStats`'ta.
 *
 * NE SAYILIR: insanın gördüğü sayfa. Yalnız GET + 200 + HTML (Blade site)
 * ya da Inertia yanıtı. SAYILMAZ:
 *   - yönetim, webhook, API, sağlık (/up), Horizon ve derleme/varlık yolları;
 *   - ön yükleme (Inertia/tarayıcı `Purpose: prefetch`) ve Inertia kısmi
 *     yeniden yüklemesi — ikisi de yeni bir sayfa bakışı değil;
 *   - bot, tarayıcısız istemci ve izleme aracı (boş UA dahil);
 *   - oturum açmış süper admin: platformu işletenin kendi tıklamaları
 *     küçük trafikte rakamı tek başına şişirirdi.
 *
 * Satıcı paneli (giriş yapmış kiracı kullanıcıları) da sayılır; yol olduğu
 * gibi kaldığı için `/panel…` satırları tanıtım sitesinden ayrı okunur.
 * Sorgu dizgisi ATILIR: hem satır sayısını sınırlar hem de bağlantıya
 * eklenmiş e-posta/jeton gibi bir değerin tabloya düşmesini önler.
 */
final class CountPageView
{
    private const SKIP_PREFIXES = ['admin', 'webhooks', 'api', 'up', 'build', 'horizon', 'storage', 'vendor', '_'];

    private const ASSET = '/\.(?:js|mjs|css|map|png|jpe?g|gif|svg|webp|avif|ico|woff2?|ttf|otf|eot|txt|xml|json|pdf|webmanifest|mp4|webm)$/i';

    private const BOT_UA = '/bot|crawl|spider|slurp|curl|wget|python|go-http|headless|lighthouse|monitor|uptime|preview|scanner|java\/|okhttp|axios|node-fetch|httpclient|facebookexternalhit|embedly|pingdom|statuscake|phantom|selenium|puppeteer|playwright/i';

    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        try {
            if (! $this->countable($request, $response)) {
                return;
            }

            $day = now()->toDateString();

            app(VisitStats::class)->record(
                path: mb_substr('/'.ltrim($request->path(), '/'), 0, 255),
                referrerHost: $this->referrerHost($request),
                visitor: VisitStats::visitorId($day, (string) $request->ip(), (string) $request->userAgent()),
                day: $day,
            );
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function countable(Request $request, Response $response): bool
    {
        if (! $request->isMethod('GET') || $response->getStatusCode() !== 200) {
            return false;
        }

        $isInertia = $response->headers->get('X-Inertia') === 'true';
        $isHtml = str_starts_with(mb_strtolower((string) $response->headers->get('Content-Type', '')), 'text/html');

        if (! $isInertia && ! $isHtml) {
            return false;
        }

        $path = ltrim($request->path(), '/');
        $first = explode('/', $path, 2)[0];

        foreach (self::SKIP_PREFIXES as $prefix) {
            if ($first === $prefix || ($prefix === '_' && str_starts_with($first, '_'))) {
                return false;
            }
        }

        if (preg_match(self::ASSET, $path) === 1) {
            return false;
        }

        foreach (['Purpose', 'Sec-Purpose', 'X-Purpose', 'X-Moz'] as $header) {
            if (str_contains(mb_strtolower((string) $request->headers->get($header, '')), 'prefetch')) {
                return false;
            }
        }

        if ($request->headers->has('X-Inertia-Partial-Component')) {
            return false;
        }

        $agent = (string) $request->userAgent();

        if (trim($agent) === '' || preg_match(self::BOT_UA, $agent) === 1) {
            return false;
        }

        $user = $request->user();

        return ! ($user instanceof User && SuperAdmin::is($user));
    }

    /** Kaynak alan adı; kendi alan adımızdan gelen gezinme '' sayılır. */
    private function referrerHost(Request $request): string
    {
        $host = parse_url((string) $request->headers->get('referer', ''), PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return '';
        }

        $bare = static fn (string $h): string => preg_replace('/^www\./', '', mb_strtolower($h)) ?? $h;
        $own = array_filter([
            $request->getHost(),
            (string) parse_url((string) config('app.url'), PHP_URL_HOST),
        ]);

        foreach ($own as $ownHost) {
            if ($bare($host) === $bare($ownHost)) {
                return '';
            }
        }

        return mb_substr(mb_strtolower($host), 0, 255);
    }
}
