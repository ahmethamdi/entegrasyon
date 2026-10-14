<?php

declare(strict_types=1);

namespace App\Domain\Suppliers\Support;

use App\Domain\Channels\Exceptions\BlockedDestinationException;
use App\Domain\Channels\Support\OutboundUrlGuard;
use App\Domain\Suppliers\Enums\SupplierReason;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\StreamInterface;
use Throwable;

/**
 * Tedarikçi akışını İNDİRİR — kullanıcı denetimli adres = SSRF sınırı.
 *
 * KURALLAR (ADR-012 · §3):
 *   - YALNIZ https ve 443. port. http kabul EDİLMEZ: IP sabitlemesi
 *     (CURLOPT_RESOLVE) ana makine:port çiftine bağlıdır; 80. porta giden
 *     istekte sabitleme işlemez ve curl kendi DNS'ini çözer (rebinding).
 *   - Ana makine `OutboundUrlGuard` ile çözülür, HER IP genel olmalı;
 *     bağlantı denetlenen IP'ye SABİTLENİR.
 *   - Yönlendirme Guzzle'a BIRAKILMAZ: en çok 3 adım, her adımda aynı
 *     denetim (https + genel IP + sabitleme) baştan yapılır.
 *   - Gövde parça parça okunur ve geçici dosyaya yazılır; sınır aşılınca
 *     okuma KESİLİR (önce tamamını okuyup sonra ölçmek bellek saldırısıdır).
 *   - İçerik türüne göre reddedilmez (toptancılar text/plain, octet-stream
 *     gönderir); XML olup olmadığına ayrıştırıcı karar verir.
 *
 * `sample` kipinde sınır aşılınca dosya KESİLİR ve `truncated = true`
 * döner — yalnız yapı keşfi içindir, ASLA senkronizasyon için kullanılmaz.
 */
final class SupplierFeedFetcher
{
    public const MAX_REDIRECTS = 3;

    public const CONNECT_TIMEOUT_SECONDS = 10;

    public const TOTAL_TIMEOUT_SECONDS = 120;

    /** Örnek okuma panel isteğinin içindedir — kısa tutulur. */
    public const SAMPLE_TIMEOUT_SECONDS = 20;

    public const SAMPLE_BYTES = 2_000_000;

    private const CHUNK_BYTES = 65_536;

    public function __construct(private readonly OutboundUrlGuard $guard) {}

    public static function maxBytes(): int
    {
        return (int) config('entegrasyon.supplier_feed_max_bytes', 64_000_000);
    }

    /**
     * Adresi yalnız BİÇİM olarak denetler (kayıt anı): https, port, ana
     * makine kuralları. DNS çözümü istek anında yapılır.
     */
    public function assertAcceptableUrl(string $url): string
    {
        $parts = parse_url(trim($url));

        if ($parts === false || ! isset($parts['host'])) {
            throw new SupplierFeedException(SupplierReason::SUPPLIER_URL_BLOCKED, 'Adres çözümlenemedi.');
        }

        if (strtolower((string) ($parts['scheme'] ?? '')) !== 'https') {
            throw new SupplierFeedException(SupplierReason::SUPPLIER_URL_BLOCKED, 'Yalnız https adresleri kabul edilir.');
        }

        try {
            $this->guard->assertAllowedHost((string) $parts['host'], isset($parts['port']) ? (int) $parts['port'] : null);
        } catch (BlockedDestinationException $e) {
            throw new SupplierFeedException(SupplierReason::SUPPLIER_URL_BLOCKED, $e->getMessage());
        }

        return strtolower((string) $parts['host']);
    }

    public function fetch(string $url, bool $sample = false): FetchedFeed
    {
        $limit = $sample ? self::SAMPLE_BYTES : self::maxBytes();
        $current = trim($url);

        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $host = $this->assertAcceptableUrl($current);
            $parts = parse_url($current);

            try {
                $ip = $this->guard->resolve($host, isset($parts['port']) ? (int) $parts['port'] : null);
            } catch (BlockedDestinationException $e) {
                throw new SupplierFeedException(SupplierReason::SUPPLIER_URL_BLOCKED, $e->getMessage());
            }

            try {
                $response = Http::withOptions([
                    'allow_redirects' => false,
                    'stream' => true,
                    'connect_timeout' => self::CONNECT_TIMEOUT_SECONDS,
                    'timeout' => $sample ? self::SAMPLE_TIMEOUT_SECONDS : self::TOTAL_TIMEOUT_SECONDS,
                    'curl' => [
                        CURLOPT_RESOLVE => [sprintf('%s:443:%s', $host, str_contains($ip, ':') ? '['.$ip.']' : $ip)],
                        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                    ],
                ])->withHeaders([
                    'Accept' => 'application/xml, text/xml;q=0.9, */*;q=0.5',
                    'User-Agent' => '34Pazar-SupplierFeed/1.0',
                ])->get($current);
            } catch (ConnectionException $e) {
                throw new SupplierFeedException(SupplierReason::SUPPLIER_FETCH_FAILED, $e->getMessage());
            }

            $status = $response->status();

            if (in_array($status, [301, 302, 303, 307, 308], true)) {
                $location = (string) $response->header('Location');

                if ($location === '') {
                    throw new SupplierFeedException(SupplierReason::SUPPLIER_FETCH_FAILED, "HTTP {$status} yönlendirme adresi yok.");
                }

                $current = $this->absolute($current, $location);

                continue;
            }

            if ($status < 200 || $status >= 300) {
                throw new SupplierFeedException(SupplierReason::SUPPLIER_FETCH_FAILED, "HTTP {$status}");
            }

            $declared = (int) $response->header('Content-Length');

            if (! $sample && $declared > $limit) {
                throw new SupplierFeedException(SupplierReason::SUPPLIER_FEED_TOO_LARGE, "Content-Length {$declared} > {$limit}");
            }

            return $this->drain($response->toPsrResponse()->getBody(), $limit, $sample, $status, (string) $response->header('Content-Type'));
        }

        throw new SupplierFeedException(SupplierReason::SUPPLIER_FETCH_FAILED, 'Çok fazla yönlendirme.');
    }

    private function drain(StreamInterface $body, int $limit, bool $sample, int $status, string $contentType): FetchedFeed
    {
        $path = tempnam(sys_get_temp_dir(), 'supplier-feed-');
        $handle = fopen($path, 'wb');
        $bytes = 0;
        $truncated = false;

        try {
            while (! $body->eof()) {
                $chunk = $body->read(self::CHUNK_BYTES);

                if ($chunk === '') {
                    break;
                }

                if ($bytes + strlen($chunk) > $limit) {
                    if (! $sample) {
                        throw new SupplierFeedException(SupplierReason::SUPPLIER_FEED_TOO_LARGE, "> {$limit} bayt");
                    }

                    fwrite($handle, substr($chunk, 0, $limit - $bytes));
                    $bytes = $limit;
                    $truncated = true;

                    break;
                }

                fwrite($handle, $chunk);
                $bytes += strlen($chunk);
            }
        } catch (SupplierFeedException $e) {
            fclose($handle);
            @unlink($path);

            throw $e;
        } catch (Throwable $e) {
            fclose($handle);
            @unlink($path);

            throw new SupplierFeedException(SupplierReason::SUPPLIER_FETCH_FAILED, $e->getMessage());
        } finally {
            $body->close();
        }

        fclose($handle);

        return new FetchedFeed($path, $bytes, $truncated, $status, $contentType);
    }

    private function absolute(string $base, string $location): string
    {
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $location) === 1) {
            return $location;
        }

        $parts = parse_url($base);
        $origin = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '').(isset($parts['port']) ? ':'.$parts['port'] : '');

        if (str_starts_with($location, '//')) {
            return ($parts['scheme'] ?? 'https').':'.$location;
        }

        if (str_starts_with($location, '/')) {
            return $origin.$location;
        }

        $dir = rtrim(dirname($parts['path'] ?? '/'), '/');

        return $origin.$dir.'/'.$location;
    }
}
