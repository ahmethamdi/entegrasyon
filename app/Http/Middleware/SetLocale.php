<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Panel dili: kullanıcının seçimi → oturum (giriş öncesi) → tarayıcı.
 *
 * Tarayıcı yalnız İngilizceyi Türkçeden ÖNCE istiyorsa İngilizce seçilir;
 * belirsizse Türkçe (ürün TR pazarına satılıyor, `config/app.php`).
 * Desteklenmeyen değer hiçbir yoldan kabul edilmez — sessizce `de` gibi
 * bir dile geçilseydi her metin anahtarın kendisi (Türkçe) basılırdı ama
 * tarih/sayı biçimi başka dile kayardı.
 */
final class SetLocale
{
    public const SUPPORTED = ['tr', 'en'];

    public const SESSION_KEY = 'locale';

    public function handle(Request $request, Closure $next): Response
    {
        App::setLocale(self::resolve($request));

        return $next($request);
    }

    public static function resolve(Request $request): string
    {
        if (! config('app.english_panel')) {
            return 'tr';
        }

        $chosen = $request->user()?->locale ?? $request->session()->get(self::SESSION_KEY);

        if (is_string($chosen) && in_array($chosen, self::SUPPORTED, true)) {
            return $chosen;
        }

        return $request->getPreferredLanguage(self::SUPPORTED) === 'en' ? 'en' : 'tr';
    }
}
