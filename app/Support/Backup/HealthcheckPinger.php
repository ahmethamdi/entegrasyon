<?php

declare(strict_types=1);

namespace App\Support\Backup;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * healthchecks.io ping'i (dead-man switch).
 *
 * Mantık tersinedir: biz "yaşıyorum" deriz, susarsak DIŞARIDAKİ servis
 * alarm verir. Zamanlayıcı ya da sunucu tümden ölünce bizim alarmlarımız da
 * ölür — onu ancak dışarıdan bir göz görür.
 *
 * Adres boşsa hiçbir şey yapmaz. Ping hatası işi DÜŞÜRMEZ: yedek alındıysa
 * healthchecks'e ulaşılamaması yedeği başarısız yapmaz (susarsak zaten
 * dışarıda alarm çalar).
 */
final class HealthcheckPinger
{
    /** @param  ''|'start'|'fail'  $signal */
    public function ping(string $baseUrl, string $signal = '', ?string $body = null): bool
    {
        $baseUrl = rtrim($baseUrl, '/');
        if ($baseUrl === '') {
            return false;
        }

        $url = $signal === '' ? $baseUrl : "{$baseUrl}/{$signal}";

        try {
            $request = Http::timeout(10)->retry(2, 500, throw: false);
            $response = $body === null
                ? $request->get($url)
                : $request->withBody(mb_substr($body, 0, 10_000), 'text/plain')->post($url);

            return $response->successful();
        } catch (Throwable $e) {
            Log::warning('healthcheck.ping_failed', ['signal' => $signal, 'error' => $e->getMessage()]);

            return false;
        }
    }
}
