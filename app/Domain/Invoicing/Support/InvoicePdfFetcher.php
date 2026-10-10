<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Support;

use App\Domain\Channels\Exceptions\BlockedDestinationException;
use App\Domain\Channels\Support\OutboundUrlGuard;
use App\Domain\Invoicing\Exceptions\InvoiceProviderException;
use App\Domain\Sync\Enums\ErrorClass;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Entegratörün süreli PDF bağlantısından faturayı BELLEĞE indirir.
 *
 * `ChannelHttpClient::download()` KULLANILMAZ: o yalnız `image/*` kabul
 * eder ve indirmeyi bir kanal bağlantısının `api_calls` günlüğüne yazar —
 * PDF entegratörden gelir, kanal isteği değildir. Kural yine de aynıdır:
 *
 * ⚠️ YALNIZ HTTPS, İÇ AĞ YOK, YÖNLENDİRME YOK (B3 · SSRF). Bağlantı
 * entegratörün yanıtından gelir; bozuk ya da ele geçirilmiş bir yanıt
 * `http://169.254.169.254/` verirse sunucu iç ağı okuyup Trendyol'a
 * "fatura" diye yüklerdi. Hedef `OutboundUrlGuard` ile çözülür ve
 * bağlantı o IP'ye sabitlenir.
 *
 * ⚠️ BOYUT OKURKEN SINIRLANIR, OKUDUKTAN SONRA DEĞİL. `body()` gövdenin
 * tamamını belleğe alır; 2 GB'lık bir yanıt sınırı kontrol edilmeden
 * worker'ı öldürürdü. Akış parça parça okunur, sınır aşılınca kesilir.
 *
 * ⚠️ TÜR BAŞLIKTAN DEĞİL İÇERİKTEN: depolama servisleri PDF'i
 * `application/octet-stream` ile sunabilir. Dosya `%PDF-` ile başlamalı —
 * HTML hata sayfası "fatura" diye pakete eklenmemeli.
 *
 * Dosya diske, veritabanına ya da günlüğe YAZILMAZ.
 */
final class InvoicePdfFetcher
{
    private const TIMEOUT_SECONDS = 30;

    private const CHUNK_BYTES = 65536;

    public function __construct(private readonly OutboundUrlGuard $guard) {}

    /**
     * @throws InvoiceProviderException Sınıfıyla: bozuk bağlantı/dosya
     *                                  kalıcı, ulaşılamayan bağlantı geçici
     */
    public function fetch(string $url, int $maxBytes): string
    {
        $parts = parse_url($url);
        $host = (string) ($parts['host'] ?? '');

        if (($parts['scheme'] ?? '') !== 'https' || $host === '') {
            throw new InvoiceProviderException(ErrorClass::VALIDATION, 'Fatura PDF bağlantısı güvenli (https) değil.');
        }

        try {
            $ip = $this->guard->resolve($host, isset($parts['port']) ? (int) $parts['port'] : null);
        } catch (BlockedDestinationException $e) {
            throw new InvoiceProviderException(ErrorClass::VALIDATION, 'Fatura PDF bağlantısı reddedildi: '.$e->getMessage());
        }

        $pinned = str_contains($ip, ':') ? '['.$ip.']' : $ip;

        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)->withOptions([
                'allow_redirects' => false,
                'stream' => true,
                'curl' => [CURLOPT_RESOLVE => [sprintf('%s:%d:%s', trim($host, '[]'), (int) ($parts['port'] ?? 443), $pinned)]],
            ])->get($url);
        } catch (ConnectionException $e) {
            throw new InvoiceProviderException(ErrorClass::NETWORK, 'Fatura PDF\'i indirilemedi: '.$e->getMessage());
        }

        // Süreli bağlantının süresi dolmuş olabilir (403/404): GEÇİCİ sayılır,
        // çünkü sonraki deneme entegratörden TAZE bağlantı ister.
        if (! $response->successful()) {
            throw new InvoiceProviderException(ErrorClass::SERVER_ERROR, "Fatura PDF'i indirilemedi: HTTP {$response->status()}.");
        }

        $declared = $response->header('Content-Length');

        if (ctype_digit($declared) && (int) $declared > $maxBytes) {
            throw new InvoiceProviderException(ErrorClass::VALIDATION, self::tooLarge($maxBytes));
        }

        $stream = $response->toPsrResponse()->getBody();
        $pdf = '';

        while (! $stream->eof()) {
            $pdf .= $stream->read(self::CHUNK_BYTES);

            if (strlen($pdf) > $maxBytes) {
                $stream->close();

                throw new InvoiceProviderException(ErrorClass::VALIDATION, self::tooLarge($maxBytes));
            }
        }

        if (! str_starts_with($pdf, '%PDF-')) {
            throw new InvoiceProviderException(ErrorClass::VALIDATION, 'Entegratörden gelen dosya PDF değil.');
        }

        return $pdf;
    }

    private static function tooLarge(int $maxBytes): string
    {
        return 'Fatura PDF\'i kanalın dosya sınırını ('.intdiv($maxBytes, 1024 * 1024).' MB) aşıyor.';
    }
}
