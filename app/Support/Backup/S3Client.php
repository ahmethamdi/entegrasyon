<?php

declare(strict_types=1);

namespace App\Support\Backup;

use DateTimeImmutable;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use SimpleXMLElement;

/**
 * Off-site yedek için en küçük S3 istemcisi (path-style: endpoint/bucket/key).
 *
 * Yalnız dört işlem: yükle, indir, listele, sil. Hata 2xx dışı her yanıtta
 * `BackupException` olur — yarım yüklenmiş bir yedek "tamam" sayılmasın.
 * Hata metnine anahtar/imza GİRMEZ (yalnız işlem, anahtar adı, HTTP kodu).
 */
final class S3Client
{
    private readonly S3Signer $signer;

    public function __construct(private readonly BackupConfig $config)
    {
        $this->signer = new S3Signer($config->s3AccessKey(), $config->s3SecretKey(), $config->s3Region());
    }

    public function put(string $key, string $filePath, string $contentType = 'application/octet-stream'): void
    {
        $hash = hash_file('sha256', $filePath);
        $stream = Utils::streamFor(fopen($filePath, 'rb'));

        $response = $this->request('PUT', $key, [], [
            'Content-Type' => $contentType,
            'Content-Length' => (string) filesize($filePath),
        ], $hash, fn ($http, string $url) => $http->withBody($stream, $contentType)->put($url));

        $this->ensureOk($response, 'yükleme', $key);
    }

    public function putString(string $key, string $body, string $contentType): void
    {
        $response = $this->request('PUT', $key, [], [
            'Content-Type' => $contentType,
            'Content-Length' => (string) strlen($body),
        ], hash('sha256', $body), fn ($http, string $url) => $http->withBody($body, $contentType)->put($url));

        $this->ensureOk($response, 'yükleme', $key);
    }

    public function download(string $key, string $targetPath): void
    {
        $response = $this->request('GET', $key, [], [], hash('sha256', ''),
            fn ($http, string $url) => $http->withOptions(['sink' => $targetPath])->get($url));

        $this->ensureOk($response, 'indirme', $key);
    }

    public function getString(string $key): string
    {
        $response = $this->request('GET', $key, [], [], hash('sha256', ''), fn ($http, string $url) => $http->get($url));

        $this->ensureOk($response, 'indirme', $key);

        return $response->body();
    }

    public function delete(string $key): void
    {
        $response = $this->request('DELETE', $key, [], [], hash('sha256', ''), fn ($http, string $url) => $http->delete($url));

        $this->ensureOk($response, 'silme', $key);
    }

    /** @return list<string> önek altındaki bütün anahtarlar (sayfalı ListObjectsV2) */
    public function listKeys(string $prefix): array
    {
        $keys = [];
        $token = null;

        do {
            $query = ['list-type' => '2', 'prefix' => $prefix];
            if ($token !== null) {
                $query['continuation-token'] = $token;
            }

            $response = $this->request('GET', '', $query, [], hash('sha256', ''), fn ($http, string $url) => $http->get($url));
            $this->ensureOk($response, 'listeleme', $prefix);

            $xml = new SimpleXMLElement($response->body());
            foreach ($xml->Contents as $item) {
                $keys[] = (string) $item->Key;
            }

            $token = ((string) $xml->IsTruncated) === 'true' ? (string) $xml->NextContinuationToken : null;
        } while ($token !== null && $token !== '');

        return $keys;
    }

    /**
     * @param  array<string, string>  $query
     * @param  array<string, string>  $headers
     * @param  callable(PendingRequest, string): Response  $send
     */
    private function request(string $method, string $key, array $query, array $headers, string $payloadHash, callable $send): Response
    {
        $endpoint = rtrim($this->config->s3Endpoint(), '/');
        $host = (string) parse_url($endpoint, PHP_URL_HOST);
        $port = parse_url($endpoint, PHP_URL_PORT);
        $hostHeader = $port !== null ? "{$host}:{$port}" : $host;

        $path = '/'.$this->config->s3Bucket().($key !== '' ? '/'.ltrim($key, '/') : '/');

        $signed = $this->signer->sign($method, $path, $query, ['Host' => $hostHeader] + $headers, $payloadHash, new DateTimeImmutable);
        unset($signed['Host']);

        $url = $endpoint.S3Signer::encodePath($path).($query !== [] ? '?'.S3Signer::encodeQuery($query) : '');

        $http = Http::timeout($this->config->s3TimeoutSeconds())->withHeaders($signed);

        return $send($http, $url);
    }

    private function ensureOk(Response $response, string $operation, string $key): void
    {
        if (! $response->successful()) {
            throw new BackupException(sprintf('S3 %s başarısız (%s): HTTP %d', $operation, $key, $response->status()));
        }
    }
}
