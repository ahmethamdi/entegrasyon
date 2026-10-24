<?php

declare(strict_types=1);

namespace App\Support\Backup;

use DateTimeImmutable;
use DateTimeZone;

/**
 * AWS Signature V4 (S3) — yalnız off-site yedeğin ihtiyacı kadar.
 *
 * NEDEN SDK DEĞİL: dört istek (PUT/GET/LIST/DELETE) için aws-sdk-php
 * onlarca megabaytlık bir bağımlılık getirirdi. İmza algoritması küçük ve
 * belgelidir; doğruluğu AWS'nin yayımladığı örnek isteklerle sınanır
 * (`S3SignerTest`). IONOS S3 aynı imzayı kabul eder.
 */
final class S3Signer
{
    public function __construct(
        private readonly string $accessKey,
        private readonly string $secretKey,
        private readonly string $region,
    ) {}

    /**
     * @param  array<string, string>  $query  imzalanacak sorgu parametreleri (kodlanmamış)
     * @param  array<string, string>  $headers  imzalanacak başlıklar (host + x-amz-content-sha256 dahil)
     * @return array<string, string> gönderilecek başlıklar (Authorization + x-amz-date eklenmiş)
     */
    public function sign(string $method, string $path, array $query, array $headers, string $payloadHash, DateTimeImmutable $now): array
    {
        $now = $now->setTimezone(new DateTimeZone('UTC'));
        $amzDate = $now->format('Ymd\THis\Z');
        $date = $now->format('Ymd');

        $headers['x-amz-date'] = $amzDate;
        $headers['x-amz-content-sha256'] = $payloadHash;

        $normalized = [];
        foreach ($headers as $name => $value) {
            $normalized[strtolower($name)] = trim(preg_replace('/\s+/', ' ', $value) ?? $value);
        }
        ksort($normalized);

        $canonicalHeaders = '';
        foreach ($normalized as $name => $value) {
            $canonicalHeaders .= $name.':'.$value."\n";
        }
        $signedHeaders = implode(';', array_keys($normalized));

        $canonicalRequest = implode("\n", [
            strtoupper($method),
            self::encodePath($path),
            self::encodeQuery($query),
            $canonicalHeaders,
            $signedHeaders,
            $payloadHash,
        ]);

        $scope = "{$date}/{$this->region}/s3/aws4_request";
        $stringToSign = implode("\n", [
            'AWS4-HMAC-SHA256',
            $amzDate,
            $scope,
            hash('sha256', $canonicalRequest),
        ]);

        $key = hash_hmac('sha256', $date, 'AWS4'.$this->secretKey, true);
        $key = hash_hmac('sha256', $this->region, $key, true);
        $key = hash_hmac('sha256', 's3', $key, true);
        $key = hash_hmac('sha256', 'aws4_request', $key, true);
        $signature = hash_hmac('sha256', $stringToSign, $key);

        $headers['Authorization'] = sprintf(
            'AWS4-HMAC-SHA256 Credential=%s/%s, SignedHeaders=%s, Signature=%s',
            $this->accessKey,
            $scope,
            $signedHeaders,
            $signature,
        );

        return $headers;
    }

    /** S3 yolu: her parça ayrı kodlanır, `/` korunur (S3 çift kodlama yapmaz). */
    public static function encodePath(string $path): string
    {
        $path = '/'.ltrim($path, '/');

        return implode('/', array_map('rawurlencode', explode('/', $path)));
    }

    /** @param array<string, string> $query */
    public static function encodeQuery(array $query): string
    {
        $pairs = [];
        foreach ($query as $name => $value) {
            $pairs[rawurlencode((string) $name)] = rawurlencode($value);
        }
        ksort($pairs, SORT_STRING);

        $out = [];
        foreach ($pairs as $name => $value) {
            $out[] = $name.'='.$value;
        }

        return implode('&', $out);
    }
}
