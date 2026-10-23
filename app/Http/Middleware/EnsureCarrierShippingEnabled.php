<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * S6 · Kargo II acil kapatma anahtarı (`shipping.enabled`) — kapalıyken
 * kargo rotaları yokmuş gibi 404 döner.
 *
 * Rota kaydı koşula bağlanmadı: `route:cache` config'i okumadan önbelleğe
 * alır ve anahtar değişince eski rota listesi kalırdı.
 */
final class EnsureCarrierShippingEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless((bool) config('shipping.enabled'), 404);

        return $next($request);
    }
}
