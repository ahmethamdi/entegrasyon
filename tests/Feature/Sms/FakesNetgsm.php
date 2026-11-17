<?php

declare(strict_types=1);

namespace Tests\Feature\Sms;

use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;

/**
 * Netgsm sahtesi — cevaplar Netgsm'in herkese açık test ortamından
 * (ext-sandbox.netgsm.com.tr) 17 Kas 2026'da ALINMIŞ gerçek gövdelerdir
 * (`tests/Fixtures/netgsm/`; jeton değeri sadeleştirildi).
 *
 * Netgsm dışı istekler (Shopify/Woo alıcı sorgusu) `$channelResponder`'a düşer.
 */
trait FakesNetgsm
{
    /** @var list<array{method: string, path: string, body: array<string, mixed>, auth: string}> */
    protected array $netgsmCalls = [];

    /** yol → [dosya, HTTP kodu] ya da sıra: ilk çağrı ilk eleman */
    protected array $netgsmReplies = [];

    protected ?\Closure $channelResponder = null;

    protected function fakeNetgsm(array $replies = []): void
    {
        $this->netgsmReplies = $replies + [
            '/auth/v3/token' => ['token-ok', 200],
            '/sms/v3/senders' => ['senders', 200],
            '/sms/v3/balance' => ['balance', 200],
            '/sms/v3/send' => ['send-ok', 202],
        ];

        Http::fake(function (HttpRequest $request) {
            $host = (string) parse_url($request->url(), PHP_URL_HOST);

            if (str_contains($host, 'netgsm.com.tr')) {
                $path = (string) parse_url($request->url(), PHP_URL_PATH);
                $this->netgsmCalls[] = [
                    'method' => $request->method(),
                    'url' => $request->url(),
                    'path' => $path,
                    'body' => is_array($request->data()) ? $request->data() : [],
                    'auth' => $request->header('Authorization')[0] ?? '',
                ];

                $reply = $this->netgsmReplies[$path] ?? ['send-ok', 202];

                // Sıralı cevap: [[dosya, kod], [dosya, kod]] — her çağrı birini tüketir.
                if (is_array($reply[0] ?? null)) {
                    $next = array_shift($this->netgsmReplies[$path]);
                    $reply = $next ?? end($reply);
                }

                return Http::response((string) file_get_contents(base_path("tests/Fixtures/netgsm/{$reply[0]}.json")), $reply[1], ['Content-Type' => 'application/json']);
            }

            if ($this->channelResponder !== null) {
                return ($this->channelResponder)($request);
            }

            return Http::response(['errors' => 'beklenmeyen istek: '.$request->url()], 500);
        });
    }

    /** @return list<array<string, mixed>> */
    protected function netgsmSends(): array
    {
        return array_values(array_filter($this->netgsmCalls, fn (array $c): bool => $c['path'] === '/sms/v3/send'));
    }
}
