<?php

declare(strict_types=1);

namespace App\Support\Logging;

/**
 * İki katmanlı maskeleme.
 *
 * Mimari Karar Dokümanı v2.2 · §11.
 *
 * Katman 1: anahtar adına göre maskeleme (yapı korunur).
 * Katman 2: bilinen kimlik bilgisi değerlerinin gövdede arama-değiştirme ile
 *           maskelenmesi — sır bir hata mesajının İÇİNDE düz metin geçebilir
 *           ("Invalid API key: abc123...") ve katman 1 bunu yakalayamaz.
 *
 * Bağımsız test edilebilir bileşendir; HTTP istemcisinin parçası değildir.
 */
final class PayloadRedactor
{
    public const REDACTED = '[redacted]';

    /** Kısa değerler yanlış eşleşme üretir; bu eşiğin altı değiştirilmez. */
    private const MIN_SECRET_LENGTH = 8;

    /** @var list<string> */
    private const REDACT_KEYS = [
        // kimlik doğrulama
        'authorization', 'api_key', 'apikey', 'api_secret', 'secret',
        'access_token', 'refresh_token', 'token', 'password', 'signature',
        'x-shopify-hmac-sha256', 'x-wc-webhook-signature',
        // V3.0 · §19 — yeni kanalların kimlik başlıkları ve OAuth sırları.
        //
        // `x-api-key` Etsy'nin UYGULAMA anahtarını taşır: `settings`'te
        // kimlik olarak durur ama BAŞLIK olarak günlüğe düşerse üçüncü
        // taraf günlük toplayıcıya gider.
        //
        // `code_verifier` PKCE'nin tek kullanımlık sırrıdır ve asla
        // kalıcı bir yere yazılmaz; yine de bir istek gövdesi olarak
        // `api_calls`'a düşebilir ve orada maskelenmelidir.
        'x-shopify-access-token', 'x-hb-signature',
        'x-api-key', 'code_verifier',
        // kişisel veri
        'email', 'phone', 'address', 'address1', 'address2',
        'customer_name', 'first_name', 'last_name',
        'tax_number', 'tc_kimlik', 'identity_number',
    ];

    /**
     * @param  array<array-key, mixed>|string  $payload
     * @param  list<string>  $knownSecrets  Bağlantının çözülmüş kimlik bilgileri
     * @return array<array-key, mixed>|string
     */
    public function redact(array|string $payload, array $knownSecrets = []): array|string
    {
        $result = is_array($payload)
            ? $this->redactByKey($payload)
            : $payload;

        if ($knownSecrets === []) {
            return $result;
        }

        return $this->redactByValue($result, $knownSecrets);
    }

    /**
     * @param  array<array-key, mixed>  $payload
     * @return array<array-key, mixed>
     */
    private function redactByKey(array $payload): array
    {
        $out = [];

        foreach ($payload as $key => $value) {
            if (is_string($key) && $this->isSensitiveKey($key)) {
                $out[$key] = self::REDACTED;

                continue;
            }

            $out[$key] = is_array($value) ? $this->redactByKey($value) : $value;
        }

        return $out;
    }

    /**
     * @param  array<array-key, mixed>|string  $payload
     * @param  list<string>  $knownSecrets
     * @return array<array-key, mixed>|string
     */
    private function redactByValue(array|string $payload, array $knownSecrets): array|string
    {
        $secrets = array_values(array_filter(
            $knownSecrets,
            static fn (string $s): bool => mb_strlen($s) >= self::MIN_SECRET_LENGTH
        ));

        if ($secrets === []) {
            return $payload;
        }

        if (is_string($payload)) {
            return str_replace($secrets, self::REDACTED, $payload);
        }

        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($encoded === false) {
            return $payload;
        }

        $encoded = str_replace($secrets, self::REDACTED, $encoded);

        /** @var array<array-key, mixed>|null $decoded */
        $decoded = json_decode($encoded, true);

        return $decoded ?? $payload;
    }

    private function isSensitiveKey(string $key): bool
    {
        return in_array(mb_strtolower($key), self::REDACT_KEYS, true);
    }
}
