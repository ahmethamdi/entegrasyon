<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * SMS bildirimleri acil kapatma anahtarı (`sms.enabled`) — kapalıyken
 * SMS ayar rotaları yokmuş gibi 404 döner.
 */
final class EnsureSmsEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless((bool) config('sms.enabled'), 404);

        return $next($request);
    }
}
