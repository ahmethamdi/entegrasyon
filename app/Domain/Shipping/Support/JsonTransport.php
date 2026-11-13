<?php

declare(strict_types=1);

namespace App\Domain\Shipping\Support;

use App\Domain\Shipping\Exceptions\CarrierException;
use App\Domain\Sync\Enums\ErrorClass;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * REST/JSON konuşan kargo firmaları (HepsiJet, MNG) için küçük taşıyıcı —
 * `SoapTransport`'un JSON karşılığı.
 *
 * Yalnız TAŞIMA hatalarını sınıflar (bağlantı, zaman aşımı, 429, 5xx'in
 * okunamayan gövdesi); 4xx ve iş hatalarını firmanın sağlayıcısı yorumlar,
 * çünkü aynı HTTP kodu iki firmada farklı anlam taşır.
 *
 * İstek gövdesi LOG'A YAZILMAZ: alıcının adı, adresi, telefonu ve
 * satıcının firma şifresi içindedir.
 */
final class JsonTransport
{
    /**
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>|null  $json
     */
    public function send(string $method, string $url, array $headers = [], ?array $json = null): Response
    {
        try {
            $request = Http::timeout((int) config('shipping.timeout', 30))
                ->acceptJson()
                ->withHeaders($headers);

            $response = match ($method) {
                'GET' => $request->get($url),
                'PUT' => $request->asJson()->put($url, $json ?? []),
                default => $request->asJson()->post($url, $json ?? []),
            };
        } catch (ConnectionException $e) {
            $timedOut = str_contains(strtolower($e->getMessage()), 'timed out') || str_contains($e->getMessage(), 'cURL error 28');

            throw new CarrierException($timedOut ? ErrorClass::TIMEOUT : ErrorClass::NETWORK, 'Kargo firmasına bağlanılamadı.');
        }

        if ($response->status() === 429) {
            throw new CarrierException(ErrorClass::RATE_LIMITED, 'Kargo firması istek sınırına takıldı.');
        }

        return $response;
    }

    /**
     * Gövde JSON nesnesi/dizisi değilse (HTML hata sayfası, boş gövde) null.
     *
     * @return array<mixed>|null
     */
    public static function decode(Response $response): ?array
    {
        $data = json_decode((string) $response->body(), true);

        return is_array($data) ? $data : null;
    }
}
