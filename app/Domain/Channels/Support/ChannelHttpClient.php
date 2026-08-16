<?php

declare(strict_types=1);

namespace App\Domain\Channels\Support;

use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Sync\Enums\ErrorClass;
use App\Support\Logging\PayloadRedactor;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Kanal HTTP istemcisi — istek yürütme ve api_calls yazımı.
 *
 * Mimari Karar Dokümanı v2.2 · §4 · api_calls, §7 · Sorumluluk dağılımı,
 * §11 · maskeleme, §13 · faz 1.4.
 *
 * DEĞİŞMEZ KURAL — GÜNLÜKLEME TEK YERDE:
 *   Her adapter kendi istek kodunu yazsaydı "bu çağrı gitti mi" sorusunun
 *   cevabı kanal başına değişirdi. Yürütme burada toplanır; adapter yalnızca
 *   yolu ve yükü söyler.
 *
 * DEĞİŞMEZ KURAL — HER ÇAĞRI KAYDEDİLİR:
 *   Başarı, hata, zaman aşımı — hepsi satır yazar. api_calls şikayet ve
 *   destek soruşturmasının dayanağıdır.
 *
 * DEĞİŞMEZ KURAL — SINIFLANDIRMAYI ADAPTER YAPAR:
 *   Bu sınıf HTTP hatasını yorumlamaz ve yeniden deneme kararı VERMEZ.
 *   İstisnayı yükseltir, yanıtı olduğu gibi döner. `classifyError()` adapter'ın,
 *   `RetryPolicy` çekirdeğin işidir. Tek istisna ağ hatasıdır: yanıt hiç
 *   gelmediği için günlüğe NETWORK yazılır — bu bir sınıflandırma değil,
 *   "yanıt yok" olgusunun kaydıdır.
 *
 * DEĞİŞMEZ KURAL — SIR LOGLARA SIZMAZ:
 *   Maskeleme iki katmanlıdır ve PayloadRedactor'a devredilir. Kimlik
 *   bilgisi değerleri kasadan okunup ikinci katmana verilir: kanal, sırrı
 *   bir hata mesajının içinde düz metin geri verebilir.
 */
final class ChannelHttpClient
{
    /** Başarılı çağrı gövdeleri büyüktür; kısa tutulur. */
    private const SUCCESS_RETENTION_DAYS = 7;

    /** Hata kayıtlarına soruşturma dayanır; uzun yaşar. */
    private const ERROR_RETENTION_DAYS = 90;

    /** Devasa yanıt gövdesi günlüğü şişirir; kesilir. */
    private const MAX_LOGGED_BODY_BYTES = 16384;

    private const DEFAULT_TIMEOUT_SECONDS = 30;

    public function __construct(
        private readonly ChannelConnection $connection,
        private readonly CredentialVault $vault,
        private readonly PayloadRedactor $redactor,
    ) {}

    /**
     * İsteği yürütür ve api_calls satırı yazar.
     *
     * @param  array<string, mixed>|null  $body
     * @param  array<string, mixed>  $query
     * @param  string|null  $attemptId  Hangi denemeye ait — FK yok, indeks var
     *
     * @throws ConnectionException Ağ hatası çağırana yükseltilir
     */
    public function request(
        string $method,
        string $endpoint,
        ?array $body = null,
        array $query = [],
        ?string $attemptId = null,
    ): Response {
        $method = strtoupper($method);
        $url = $this->urlFor($endpoint);

        $startedAt = hrtime(true);

        try {
            $response = $this->pendingRequest()->send($method, $url, array_filter([
                'query' => $query,
                'json' => $body,
            ], static fn (mixed $v): bool => $v !== null && $v !== []));
        } catch (ConnectionException $e) {
            // Yanıt HİÇ gelmedi. Kayıt yine de yazılır: sonuç belirsizdir
            // ("istek gitti mi, işlendi mi") ve tam bu yüzden iz gerekir.
            $this->record(
                method: $method,
                url: $url,
                body: $body,
                response: null,
                durationMs: $this->elapsedMs($startedAt),
                attemptId: $attemptId,
                errorClass: ErrorClass::NETWORK,
                errorText: $e->getMessage(),
            );

            throw $e;
        }

        $this->record(
            method: $method,
            url: $url,
            body: $body,
            response: $response,
            durationMs: $this->elapsedMs($startedAt),
            attemptId: $attemptId,
        );

        return $response;
    }

    /** @param array<string, mixed> $query */
    public function get(string $endpoint, array $query = [], ?string $attemptId = null): Response
    {
        return $this->request('GET', $endpoint, query: $query, attemptId: $attemptId);
    }

    /** @param array<string, mixed> $body */
    public function post(string $endpoint, array $body, ?string $attemptId = null): Response
    {
        return $this->request('POST', $endpoint, body: $body, attemptId: $attemptId);
    }

    /** @param array<string, mixed> $body */
    public function put(string $endpoint, array $body, ?string $attemptId = null): Response
    {
        return $this->request('PUT', $endpoint, body: $body, attemptId: $attemptId);
    }

    // ---------------------------------------------------------------- iç

    private function pendingRequest(): PendingRequest
    {
        $secrets = $this->secrets();

        $request = Http::timeout(self::DEFAULT_TIMEOUT_SECONDS)
            ->acceptJson()
            ->asJson();

        // WooCommerce HTTPS üzerinde Basic auth kabul eder; anahtar çifti
        // kasadan gelir ve hiçbir yerde loglanmaz.
        if (isset($secrets['consumer_key'], $secrets['consumer_secret'])) {
            return $request->withBasicAuth(
                (string) $secrets['consumer_key'],
                (string) $secrets['consumer_secret'],
            );
        }

        if (isset($secrets['access_token'])) {
            return $request->withToken((string) $secrets['access_token']);
        }

        return $request;
    }

    /**
     * Kasadaki kimlik bilgileri; yoksa boş.
     *
     * Kimlik bilgisi olmadan da çağrı denenebilir (sağlık kontrolü, açık uç
     * noktalar); eksikliği burada hata değildir. Kanal 401 dönerse adapter
     * bunu AUTHENTICATION olarak sınıflandırır ve karar çekirdeğe kalır.
     *
     * @return array<string, mixed>
     */
    private function secrets(): array
    {
        try {
            return $this->vault->read($this->connection);
        } catch (Throwable) {
            return [];
        }
    }

    /** @return list<string> */
    private function secretValues(): array
    {
        $values = [];

        foreach ($this->secrets() as $value) {
            if (is_string($value) && $value !== '') {
                $values[] = $value;
            }
        }

        return $values;
    }

    private function urlFor(string $endpoint): string
    {
        if (str_starts_with($endpoint, 'http://') || str_starts_with($endpoint, 'https://')) {
            return $endpoint;
        }

        $base = (string) ($this->connection->settings['base_url'] ?? '');

        return rtrim($base, '/').'/'.ltrim($endpoint, '/');
    }

    /**
     * api_calls satırını yazar.
     *
     * DEĞİŞMEZ KURAL — GÜNLÜKLEME ÇAĞRIYI DÜŞÜRMEZ:
     *   Günlükleme yan iştir. Dolu disk veya kilitli tablo tüm senkronu
     *   durdurmamalıdır; hata yutulur ve uygulama günlüğüne yazılır.
     *
     * @param  array<string, mixed>|null  $body
     */
    private function record(
        string $method,
        string $url,
        ?array $body,
        ?Response $response,
        int $durationMs,
        ?string $attemptId,
        ?ErrorClass $errorClass = null,
        ?string $errorText = null,
    ): void {
        try {
            $secrets = $this->secretValues();
            $status = $response?->status();

            DB::table('api_calls')->insert([
                'tenant_id' => $this->connection->tenant_id,
                'channel_connection_id' => $this->connection->id,
                'sync_attempt_id' => $attemptId,
                'method' => $method,
                'endpoint' => $this->redactUrl($url, $secrets),
                'status_code' => $status,
                'duration_ms' => $durationMs,
                'request_body' => $this->encodeBody($body, $secrets),
                'response_body' => $this->encodeResponseBody($response, $errorText, $secrets),
                'rate_limit_remaining' => $this->rateLimitRemaining($response),
                'error_class' => $errorClass?->value,
                'called_at' => now(),
                'expires_at' => $this->expiryFor($status, $errorClass),
            ]);
        } catch (Throwable $e) {
            Log::warning('api_calls yazılamadı', [
                'connection_id' => $this->connection->id,
                'method' => $method,
                'reason' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Saklama süresi — hata uzun, başarı kısa.
     *
     * Ağ hatası da hata sayılır: yanıt gelmemesi tam olarak soruşturulacak
     * durumdur.
     */
    private function expiryFor(?int $status, ?ErrorClass $errorClass): \DateTimeInterface
    {
        $isError = $errorClass !== null || $status === null || $status >= 400;

        return now()->addDays($isError ? self::ERROR_RETENTION_DAYS : self::SUCCESS_RETENTION_DAYS);
    }

    /**
     * @param  array<string, mixed>|null  $body
     * @param  list<string>  $secrets
     */
    private function encodeBody(?array $body, array $secrets): ?string
    {
        if ($body === null) {
            return null;
        }

        $redacted = $this->redactor->redact($body, $secrets);

        return $this->truncate(json_encode($redacted, JSON_UNESCAPED_UNICODE) ?: null);
    }

    /**
     * Yanıt gövdesi — JSON değilse ham metin sarmalanır.
     *
     * Kanal HTML hata sayfası dönebilir; jsonb kolonuna geçersiz JSON
     * yazmak insert'i düşürürdü ve o da tüm günlüğü kaybettirirdi.
     *
     * @param  list<string>  $secrets
     */
    private function encodeResponseBody(?Response $response, ?string $errorText, array $secrets): ?string
    {
        if ($response === null) {
            return $errorText === null
                ? null
                : $this->truncate(json_encode(
                    ['error' => $this->redactor->redact($errorText, $secrets)],
                    JSON_UNESCAPED_UNICODE
                ) ?: null);
        }

        $decoded = json_decode($response->body(), true);

        $payload = is_array($decoded)
            ? $this->redactor->redact($decoded, $secrets)
            : ['raw' => $this->redactor->redact($response->body(), $secrets)];

        return $this->truncate(json_encode($payload, JSON_UNESCAPED_UNICODE) ?: null);
    }

    /**
     * URL'deki sorgu dizesinde sır taşınabilir (bazı kanallar anahtarı
     * query string'e koyar); endpoint de maskelenir.
     *
     * @param  list<string>  $secrets
     */
    private function redactUrl(string $url, array $secrets): string
    {
        $redacted = $this->redactor->redact($url, $secrets);

        return is_string($redacted) ? $redacted : $url;
    }

    private function rateLimitRemaining(?Response $response): ?int
    {
        if ($response === null) {
            return null;
        }

        foreach (['X-RateLimit-Remaining', 'x-ratelimit-remaining', 'RateLimit-Remaining'] as $header) {
            $value = $response->header($header);

            if ($value !== '') {
                return (int) $value;
            }
        }

        return null;
    }

    private function truncate(?string $json): ?string
    {
        if ($json === null) {
            return null;
        }

        if (strlen($json) <= self::MAX_LOGGED_BODY_BYTES) {
            return $json;
        }

        // Kesilen gövde geçerli JSON kalmalı; jsonb kolonu bozuk metni reddeder.
        return json_encode([
            'truncated' => true,
            'bytes' => strlen($json),
            'preview' => mb_substr($json, 0, 1000),
        ], JSON_UNESCAPED_UNICODE) ?: null;
    }

    private function elapsedMs(int $startedAt): int
    {
        return (int) round((hrtime(true) - $startedAt) / 1_000_000);
    }
}
