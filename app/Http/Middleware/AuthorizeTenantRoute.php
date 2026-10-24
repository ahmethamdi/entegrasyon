<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Identity\Enums\TenantRole;
use App\Domain\Identity\Support\RoutePermissions;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Panelin TEK yetki kapısı — S7 · çok kullanıcı (23 Ekim 2026).
 *
 * `tenant` ara katmanından SONRA çalışır: rolü o kurar (üyelikten okur).
 * Rota adı `RoutePermissions` tablosunda aranır; tabloda olmayan rota ve
 * rolün vermediği yetki 403'tür.
 *
 * ROL YOKSA 403 — bağlam kurulmadan buraya gelinmişse (ara katman sırası
 * bozulmuşsa) kapı AÇILMAZ. "Rol bilinmiyor, geçsin" demek tam olarak bu
 * kapının önlemek için yazıldığı hata olurdu.
 */
final class AuthorizeTenantRoute
{
    public function handle(Request $request, Closure $next): Response
    {
        $role = $request->attributes->get('tenant_role');
        $permission = RoutePermissions::for($request->route()?->getName());

        if (! $role instanceof TenantRole || $permission === null || ! $role->allows($permission)) {
            abort(403, __('Bu işlem için yetkin yok. Hesap sahibinden rolünü değiştirmesini iste.'));
        }

        // S12 · demo kiracısında rolden BAĞIMSIZ olarak yalnız okuma geçer:
        // herkes aynı hesapla girer; tek açık bir yazma rotası demoyu
        // herkes için bozardı (App\Domain\Identity\Support\DemoAccount).
        if (! $request->isMethodSafe() && ($request->attributes->get('tenant')?->is_demo ?? false)) {
            abort(403, __('Demo hesabında değişiklik yapılamaz. Kendi verinle denemek için ücretsiz hesap aç.'));
        }

        return $next($request);
    }
}
