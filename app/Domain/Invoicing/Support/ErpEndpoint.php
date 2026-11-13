<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Support;

use App\Domain\Channels\Exceptions\BlockedDestinationException;
use App\Domain\Channels\Support\OutboundUrlGuard;
use App\Domain\Invoicing\Exceptions\InvoiceProviderException;
use App\Domain\Sync\Enums\ErrorClass;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Satıcının KENDİ SUNUCUSUNDAKİ ERP servisine (Logo REST, Mikro API) istek.
 *
 * İki ERP de satıcının şirket içi sunucusunda çalışır; buluttan erişim için
 * satıcı servisi HTTPS + 443 ile dışarı açar (ters vekil / tünel). Adresi
 * satıcı yazdığı için kanal mağaza adresiyle AYNI SSRF riski vardır
 * (`OutboundUrlGuard` başlığı): kayıtta sözdizimi, istekte DNS çözülüp
 * IP'ye sabitlenir (CURLOPT_RESOLVE) — iç ağa (`postgres`, 169.254.169.254)
 * istek gitmez, DNS rebinding işe yaramaz. Yönlendirme İZLENMEZ.
 *
 * Gövde LOG'A YAZILMAZ: ERP şifresi ve alıcı bilgisi içindedir.
 */
final class ErpEndpoint
{
    public function __construct(private readonly OutboundUrlGuard $guard) {}

    /**
     * Kayıt anında: https, standart port, iç ağ değil. Sonunda `/` olmayan kök döner.
     *
     * @throws InvoiceProviderException VALIDATION
     */
    public static function normalise(string $url): string
    {
        $url = rtrim(trim($url), '/');
        $parts = parse_url($url);
        $host = (string) ($parts['host'] ?? '');

        if (($parts['scheme'] ?? '') !== 'https' || $host === '' || isset($parts['user']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new InvoiceProviderException(ErrorClass::VALIDATION, 'Sunucu adresi https:// ile başlayan bir alan adı olmalıdır (ör. https://erp.firmaniz.com).');
        }

        try {
            (new OutboundUrlGuard)->assertAllowedHost($host, isset($parts['port']) ? (int) $parts['port'] : null);
        } catch (BlockedDestinationException $e) {
            throw new InvoiceProviderException(ErrorClass::VALIDATION, $e->getMessage());
        }

        return $url;
    }

    /**
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>|null  $json  JSON gövde
     * @param  array<string, string>|null  $form  form gövdesi (application/x-www-form-urlencoded)
     */
    public function send(string $method, string $url, array $headers = [], ?array $json = null, ?array $form = null): Response
    {
        $parts = parse_url($url);
        $host = (string) ($parts['host'] ?? '');
        $port = (int) ($parts['port'] ?? 443);

        if (($parts['scheme'] ?? '') !== 'https' || $host === '') {
            throw new InvoiceProviderException(ErrorClass::VALIDATION, 'ERP sunucu adresi güvenli (https) değil.');
        }

        try {
            $ip = $this->guard->resolve($host, isset($parts['port']) ? $port : null);
        } catch (BlockedDestinationException $e) {
            throw new InvoiceProviderException(ErrorClass::VALIDATION, 'ERP sunucu adresi reddedildi: '.$e->getMessage());
        }

        $pinned = str_contains($ip, ':') ? '['.$ip.']' : $ip;

        try {
            $request = Http::timeout((int) config('services.erp.timeout', 30))
                ->acceptJson()
                ->withHeaders($headers)
                ->withOptions([
                    'allow_redirects' => false,
                    'curl' => [CURLOPT_RESOLVE => [sprintf('%s:%d:%s', trim($host, '[]'), $port, $pinned)]],
                ]);

            if ($form !== null) {
                $request = $request->asForm();
            }

            $response = match ($method) {
                'GET' => $request->get($url),
                default => $request->send($method, $url, $form !== null ? ['form_params' => $form] : ['json' => $json ?? []]),
            };
        } catch (ConnectionException $e) {
            $timedOut = str_contains(strtolower($e->getMessage()), 'timed out') || str_contains($e->getMessage(), 'cURL error 28');

            throw new InvoiceProviderException($timedOut ? ErrorClass::TIMEOUT : ErrorClass::NETWORK, 'ERP sunucusuna bağlanılamadı (sunucu kapalı ya da dışarıya açık değil).');
        }

        if ($response->status() === 429) {
            throw new InvoiceProviderException(ErrorClass::RATE_LIMITED, 'ERP servisi istek sınırına takıldı.');
        }

        if ($response->serverError() && self::decode($response) === null) {
            throw new InvoiceProviderException(ErrorClass::SERVER_ERROR, 'ERP servisi hata verdi (HTTP '.$response->status().').');
        }

        return $response;
    }

    /** @return array<mixed>|null gövde JSON değilse null */
    public static function decode(Response $response): ?array
    {
        $data = json_decode((string) $response->body(), true);

        return is_array($data) ? $data : null;
    }
}
