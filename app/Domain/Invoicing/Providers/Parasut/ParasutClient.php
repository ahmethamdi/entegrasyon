<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Providers\Parasut;

use App\Domain\Invoicing\Exceptions\InvoiceProviderException;
use App\Domain\Invoicing\Models\InvoiceAccount;
use App\Domain\Sync\Enums\ErrorClass;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Paraşüt API v4 istemcisi — bir satıcı hesabına bağlı.
 *
 * Adres `{taban}/v4/{firma}/{uç}`; gövde JSON:API.
 *
 * TOKEN YENİLEME KİLİTLİDİR: aynı satıcının iki faturası aynı anda
 * kesilirken ikisi de süresi dolmuş token'ı görür. Kilit yokken ikisi de
 * yenilerdi; Paraşüt yenileme anahtarını döndürüyorsa ikincisi artık
 * geçersiz anahtarla `invalid_grant` alır ve hesap "koptu"ya düşerdi.
 * Kilit alan yeniler, bekleyen hesabı tazeden okur.
 *
 * 401'de BİR KEZ yenilenip tekrar denenir (token süresinden önce iptal
 * edilmiş olabilir); ikinci 401 kalıcı kimlik hatasıdır.
 *
 * `ChannelHttpClient` kullanılmaz: o bir kanal bağlantısına bağlıdır
 * (kasa, `api_calls` kaydı, devre kesici) ve entegratör bir kanal değildir.
 */
final class ParasutClient
{
    private const TIMEOUT_SECONDS = 30;

    private const MAX_ACCOUNT_PAGES = 10;

    public function __construct(private InvoiceAccount $account) {}

    /** @param array<string, mixed> $query */
    public function get(string $endpoint, array $query = []): array
    {
        return $this->send('get', $endpoint, $query);
    }

    /** @param array<string, mixed> $body */
    public function post(string $endpoint, array $body): array
    {
        return $this->send('post', $endpoint, $body);
    }

    /**
     * Kasa ve banka hesapları — tahsilat eşlemesinin seçenekleri.
     *
     * YALNIZ AYAR EKRANI AÇILINCA çağrılır (ve kaydederken, seçilen hesabın
     * gerçekten satıcının olduğunu doğrulamak için). Saklanmaz: satıcı
     * Paraşüt'te hesap ekleyip sildikçe eski bir kopya yanlış hesabı
     * önerirdi.
     *
     * Sayfalı okunur; sınır `MAX_ACCOUNT_PAGES` — sonsuz döngüye düşen bir
     * `meta` yanıtı ekranı kilitlemesin.
     *
     * @return list<array{id: string, name: string, type: string|null}>
     */
    public function accounts(): array
    {
        $accounts = [];

        for ($page = 1; $page <= self::MAX_ACCOUNT_PAGES; $page++) {
            $response = $this->get('accounts', ['page[number]' => $page, 'page[size]' => 25]);

            foreach ((array) ($response['data'] ?? []) as $item) {
                if (! is_array($item) || ! isset($item['id'])) {
                    continue;
                }

                $type = $item['attributes']['account_type'] ?? null;

                $accounts[] = [
                    'id' => (string) $item['id'],
                    'name' => (string) ($item['attributes']['name'] ?? $item['id']),
                    'type' => is_string($type) && $type !== '' ? $type : null,
                ];
            }

            $totalPages = (int) ($response['meta']['total_pages'] ?? 1);

            if ($page >= $totalPages) {
                break;
            }
        }

        return $accounts;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function send(string $method, string $endpoint, array $data, bool $retried = false): array
    {
        $companyId = $this->account->company_id
            ?? throw new InvoiceProviderException(ErrorClass::VALIDATION, 'Paraşüt firması seçilmedi; e-fatura ayarlarından firmayı seçin.');

        $url = ParasutApp::baseUrl()."/v4/{$companyId}/".ltrim($endpoint, '/');

        try {
            $request = Http::acceptJson()
                ->timeout(self::TIMEOUT_SECONDS)
                ->withToken($this->accessToken());

            $response = $method === 'get'
                ? $request->get($url, $data)
                : $request->asJson()->post($url, $data);
        } catch (ConnectionException) {
            throw new InvoiceProviderException(ErrorClass::NETWORK, 'Paraşüt\'e ulaşılamadı.');
        }

        if ($response->status() === 401 && ! $retried) {
            $this->refresh(force: true);

            return $this->send($method, $endpoint, $data, retried: true);
        }

        if ($response->failed()) {
            throw self::errorFrom($response);
        }

        return (array) ($response->json() ?? []);
    }

    private function accessToken(): string
    {
        $expiresAt = $this->account->token_expires_at;

        if ($expiresAt !== null && $expiresAt->isPast()) {
            $this->refresh(force: false);
        }

        $token = $this->account->credentials['access_token'] ?? null;

        if (! is_string($token) || $token === '') {
            throw new InvoiceProviderException(ErrorClass::AUTHENTICATION, 'Paraşüt bağlantısı yok; e-fatura ayarlarından yeniden bağlayın.');
        }

        return $token;
    }

    /**
     * @param  bool  $force  true = 401 aldık, süreye bakmadan yenile
     */
    private function refresh(bool $force): void
    {
        $seen = $this->account->credentials['access_token'] ?? null;

        Cache::lock("invoice-account:{$this->account->id}:refresh", 30)->block(20, function () use ($force, $seen): void {
            $this->account->refresh();

            // Kilidi beklerken başkası yenilediyse tekrar yenilenmez.
            $current = $this->account->credentials['access_token'] ?? null;
            $stillExpired = $this->account->token_expires_at?->isPast() ?? false;

            if ($current !== $seen || (! $force && ! $stillExpired)) {
                return;
            }

            $refreshToken = $this->account->credentials['refresh_token'] ?? null;

            if (! is_string($refreshToken) || $refreshToken === '') {
                $this->markRevoked('Paraşüt yenileme anahtarı yok.');

                throw new InvoiceProviderException(ErrorClass::AUTHENTICATION, 'Paraşüt bağlantısının süresi doldu; e-fatura ayarlarından yeniden bağlayın.');
            }

            try {
                $token = ParasutAuth::refresh($refreshToken);
            } catch (InvoiceProviderException $e) {
                if ($e->class === ErrorClass::AUTHENTICATION) {
                    $this->markRevoked($e->getMessage());
                }

                throw $e;
            }

            $this->account->forceFill([
                'credentials' => [
                    'access_token' => $token['credentials']['access_token'],
                    // Yanıt yeni anahtar taşımıyorsa eskisi geçerlidir.
                    'refresh_token' => $token['credentials']['refresh_token'] ?? $refreshToken,
                ],
                'token_expires_at' => $token['expires_at'],
                'last_error' => null,
            ])->save();
        });
    }

    private function markRevoked(string $reason): void
    {
        $this->account->forceFill([
            'status' => InvoiceAccount::STATUS_REVOKED,
            'last_error' => mb_substr($reason, 0, 500),
        ])->save();
    }

    /**
     * JSON:API `errors[].detail` satıcıya gösterilir ("Vergi numarası
     * geçersiz" gibi) — ne yapacağını oradan anlar.
     */
    private static function errorFrom(Response $response): InvoiceProviderException
    {
        $details = [];

        foreach ((array) $response->json('errors', []) as $error) {
            if (is_array($error)) {
                $text = trim((string) ($error['detail'] ?? $error['title'] ?? ''));

                if ($text !== '') {
                    $details[] = $text;
                }
            }
        }

        $message = 'Paraşüt: '.($details === [] ? 'HTTP '.$response->status() : implode(' · ', array_unique($details)));

        $retryAfter = $response->header('Retry-After');

        $class = match (true) {
            $response->status() === 429 => ErrorClass::RATE_LIMITED,
            $response->status() === 401, $response->status() === 403 => ErrorClass::AUTHENTICATION,
            $response->status() === 404 => ErrorClass::NOT_FOUND,
            $response->status() === 409 => ErrorClass::CONFLICT,
            $response->serverError() => ErrorClass::SERVER_ERROR,
            default => ErrorClass::VALIDATION,
        };

        return new InvoiceProviderException(
            $class,
            mb_substr($message, 0, 500),
            is_numeric($retryAfter) ? (int) $retryAfter : null,
        );
    }
}
