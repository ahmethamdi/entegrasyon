<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;

/**
 * REST/JSON kargo firmalarının (HepsiJet, MNG) sahte cevapları.
 *
 * ⚠️ Gövdeler firmaların BELGESİNDEKİ örnek yanıtlardır — gerçek sunucudan
 * alınmadı (Yurtiçi/Aras fixture'larının aksine). Bu yüzden bu testler
 * yalnız MOCK_TESTED kanıtıdır.
 *
 * Cevap URL yolunun parçasına göre seçilir (`/auth/getToken`,
 * `sendDeliveryOrderEnhanced` …); aynı parçaya sırayla birden çok cevap
 * verilebilir, son cevap tekrar eder. Kargo dışı istekler (kanal)
 * `$channelResponder`'a düşer.
 */
trait FakesRestCarriers
{
    /** @var array<string, list<mixed>> yol parçası → sıradaki cevaplar */
    protected array $restReplies = [];

    /** @var list<array{path: string, method: string, url: string, headers: array<string, list<string>>, json: array<mixed>|null, raw: string}> */
    protected array $restCalls = [];

    /** @var (\Closure(HttpRequest): mixed)|null */
    protected ?\Closure $channelResponder = null;

    protected bool $restFaked = false;

    /** Kargo firmasına ait sayılan alan adları. */
    protected array $carrierHosts = ['hepsijet.com', 'mngkargo.com.tr'];

    /**
     * @param  mixed  ...$replies  dizi (200 JSON), `Http::response(...)` ya da Closure
     */
    protected function restReply(string $pathPart, mixed ...$replies): void
    {
        $this->restReplies[$pathPart] = $replies;
        $this->fakeRest();
    }

    protected function fakeRest(): void
    {
        if ($this->restFaked) {
            return;
        }

        $this->restFaked = true;

        Http::fake(function (HttpRequest $request) {
            $host = (string) parse_url($request->url(), PHP_URL_HOST);
            $isCarrier = false;

            foreach ($this->carrierHosts as $carrierHost) {
                $isCarrier = $isCarrier || str_ends_with($host, $carrierHost);
            }

            if (! $isCarrier) {
                return $this->channelResponder !== null
                    ? ($this->channelResponder)($request)
                    : Http::response(['message' => 'beklenmeyen istek '.$request->url()], 599);
            }

            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            $json = json_decode($request->body(), true);
            $this->restCalls[] = [
                'path' => $path,
                'method' => $request->method(),
                'url' => $request->url(),
                'headers' => $request->headers(),
                'json' => is_array($json) ? $json : null,
                'raw' => $request->body(),
            ];

            // En uzun eşleşen parça kazanır (`/token` ile `/refresh` karışmasın).
            $match = null;

            foreach (array_keys($this->restReplies) as $part) {
                if (str_contains($path, $part) && ($match === null || strlen($part) > strlen($match))) {
                    $match = $part;
                }
            }

            if ($match === null) {
                return Http::response('beklenmeyen kargo isteği '.$path, 599);
            }

            $queue = $this->restReplies[$match];
            $next = count($queue) > 1 ? array_shift($this->restReplies[$match]) : $queue[0];

            if ($next instanceof \Closure) {
                return $next($request);
            }

            return is_array($next) ? Http::response($next, 200) : $next;
        });
    }

    /** @return list<array{path: string, method: string, url: string, headers: array<string, list<string>>, json: array<mixed>|null}> */
    protected function restCallsTo(string $pathPart): array
    {
        return array_values(array_filter($this->restCalls, static fn (array $c): bool => str_contains($c['path'], $pathPart)));
    }
}
