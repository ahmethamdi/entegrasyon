<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Middleware\SetLocale;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Dil seçimi — girişliyse kullanıcıya, değilse oturuma yazılır.
 *
 * Kullanıcıya yazılır ki seçim cihazlar arası kalsın; yalnız oturumda
 * tutulsaydı satıcı telefondan girdiğinde dil geri dönerdi.
 */
final class LocaleController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $locale = $request->validate([
            'locale' => ['required', 'string', Rule::in(SetLocale::SUPPORTED)],
        ])['locale'];

        $request->user()?->forceFill(['locale' => $locale])->save();
        $request->session()->put(SetLocale::SESSION_KEY, $locale);

        return back();
    }
}
