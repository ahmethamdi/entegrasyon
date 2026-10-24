<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Providers\Nilvera;

use App\Domain\Invoicing\Exceptions\InvoiceProviderException;
use App\Domain\Invoicing\Models\InvoiceAccount;
use App\Domain\Sync\Enums\ErrorClass;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Nilvera API istemcisi — bir satıcı hesabına bağlı.
 *
 * Kaynak: https://developer.nilvera.com (24.10.2026). Adres
 * `{taban}/{api}/{uç}` — `api` `general`, `einvoice` ya da `earchive`.
 * Yetki satıcının Nilvera portalında kendi ürettiği kalıcı API
 * anahtarıyla: `Authorization: Bearer {anahtar}`. Token yenileme YOKTUR
 * (Paraşüt'ün aksine) — anahtar portalda iptal edilene ya da süresi
 * dolana kadar geçerlidir; 401/403 kalıcı kimlik hatasıdır.
 *
 * TABAN ADRES SATICIDAN GELMEZ: yalnız iki sabit arasında seçim yapılır
 * (canlı / test). Serbest adres alınsaydı API anahtarı satıcının yazdığı
 * herhangi bir sunucuya giderdi (`ParasutApp::baseUrl` kuralı).
 *
 * `ChannelHttpClient` kullanılmaz: entegratör bir kanal değildir
 * (`ParasutClient` ile aynı gerekçe).
 */
final class NilveraClient
{
    public const LIVE_BASE_URL = 'https://api.nilvera.com';

    public const TEST_BASE_URL = 'https://apitest.nilvera.com';

    private const TIMEOUT_SECONDS = 30;

    public function __construct(private readonly InvoiceAccount $account) {}

    /**
     * Hesabın kayıtlı anahtarı yerine verilen anahtarla — bağlanırken
     * anahtarı KAYDETMEDEN önce denemek için.
     */
    public static function probe(string $apiKey, string $environment): array
    {
        $account = new InvoiceAccount([
            'provider' => InvoiceAccount::PROVIDER_NILVERA,
            'credentials' => ['api_key' => $apiKey],
            'settings' => [InvoiceAccount::SETTING_ENVIRONMENT => $environment],
        ]);

        return (new self($account))->get('general/Company');
    }

    public static function baseUrl(?string $environment): string
    {
        return $environment === InvoiceAccount::ENVIRONMENT_TEST ? self::TEST_BASE_URL : self::LIVE_BASE_URL;
    }

    /** @param array<string, mixed> $query */
    public function get(string $endpoint, array $query = []): array
    {
        return (array) ($this->send('get', $endpoint, $query)->json() ?? []);
    }

    /**
     * Gönderim — 409 (ETTN zaten kayıtlı) İSTİSNA DEĞİL, sonuç olarak döner:
     * yeniden denemede aynı ETTN'nin ikinci kez gitmesi beklenen durumdur
     * ve sağlayıcı onu "zaten gönderildi" diye okur.
     *
     * @param  array<string, mixed>  $body
     * @return array{status: int, body: array<string, mixed>, codes: list<int>}
     */
    public function post(string $endpoint, array $body): array
    {
        $response = $this->send('post', $endpoint, $body, allow: [409]);

        return ['status' => $response->status(), 'body' => (array) ($response->json() ?? []), 'codes' => self::codes($response)];
    }

    /**
     * @return array{status: int, codes: list<int>}
     */
    public function put(string $endpoint, array $allow = []): array
    {
        $response = $this->send('put', $endpoint, [], allow: $allow);

        return ['status' => $response->status(), 'codes' => self::codes($response)];
    }

    /** Ham dosya (PDF) — gövde JSON değildir. */
    public function download(string $endpoint): string
    {
        return $this->send('get', $endpoint, [], accept: 'application/pdf')->body();
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<int>  $allow  istisna sayılmayan hata kodları
     */
    private function send(string $method, string $endpoint, array $data, array $allow = [], string $accept = 'application/json'): Response
    {
        $key = $this->account->credentials['api_key'] ?? null;

        if (! is_string($key) || $key === '') {
            throw new InvoiceProviderException(ErrorClass::AUTHENTICATION, 'Nilvera API anahtarı yok; e-fatura ayarlarından yeniden bağlayın.');
        }

        $url = self::baseUrl($this->account->setting(InvoiceAccount::SETTING_ENVIRONMENT)).'/'.ltrim($endpoint, '/');

        try {
            $request = Http::accept($accept)->timeout(self::TIMEOUT_SECONDS)->withToken($key);

            $response = match ($method) {
                'get' => $request->get($url, $data),
                'put' => $request->put($url),
                default => $request->asJson()->post($url, $data),
            };
        } catch (ConnectionException) {
            throw new InvoiceProviderException(ErrorClass::NETWORK, 'Nilvera\'ya ulaşılamadı.');
        }

        if ($response->failed() && ! in_array($response->status(), $allow, true)) {
            throw self::errorFrom($response);
        }

        return $response;
    }

    /**
     * Nilvera hata gövdesi `{Message, Errors: [{Code, Description, Detail}]}`;
     * 400 bazı uçlarda düz metin listesi döner (OpenAPI: `string[]`). İkisi
     * de satıcıya gösterilir — "Yeterli kontörünüz bulunmamaktadır" gibi
     * ne yapacağını oradan anlar.
     */
    private static function errorFrom(Response $response): InvoiceProviderException
    {
        $details = [];
        $json = $response->json();

        foreach ((array) (is_array($json) && array_is_list($json) ? $json : ($json['Errors'] ?? [])) as $error) {
            if (is_array($error)) {
                $detail = trim((string) ($error['Detail'] ?? ''));
                $text = $detail !== '' ? $detail : trim((string) ($error['Description'] ?? ''));
            } else {
                $text = trim((string) $error);
            }

            if ($text !== '') {
                $details[] = $text;
            }
        }

        if ($details === [] && is_array($json) && is_string($json['Message'] ?? null)) {
            $details[] = $json['Message'];
        }

        $message = 'Nilvera: '.($details === [] ? 'HTTP '.$response->status() : implode(' · ', array_unique($details)));
        $retryAfter = $response->header('Retry-After');

        $class = match (true) {
            $response->status() === 429 => ErrorClass::RATE_LIMITED,
            $response->status() === 401, $response->status() === 403 => ErrorClass::AUTHENTICATION,
            $response->status() === 404 => ErrorClass::NOT_FOUND,
            $response->status() === 409 => ErrorClass::CONFLICT,
            $response->serverError() => ErrorClass::SERVER_ERROR,
            default => ErrorClass::VALIDATION,
        };

        return new InvoiceProviderException($class, mb_substr($message, 0, 500), is_numeric($retryAfter) ? (int) $retryAfter : null);
    }

    /** @return list<int> */
    private static function codes(Response $response): array
    {
        $json = $response->json();

        if (! is_array($json) || ! is_array($json['Errors'] ?? null)) {
            return [];
        }

        return array_values(array_map(
            static fn (mixed $e): int => (int) (is_array($e) ? ($e['Code'] ?? 0) : 0),
            $json['Errors'],
        ));
    }
}
