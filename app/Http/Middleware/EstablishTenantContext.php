<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Identity\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Oturumdan kiracı bağlamını kurar.
 *
 * Mimari Karar Dokümanı v2.2 · §13 · faz 1.1, §11 · Güvenlik.
 *
 * DEĞİŞMEZ KURAL — BAĞLAM ÜYELİKTEN DOĞRULANIR:
 *   Oturumdaki kiracı kimliğine ASLA olduğu gibi güvenilmez. Kullanıcının
 *   o kiracıya üye olduğu her istekte veritabanından doğrulanır. Aksi halde
 *   oturum çerezini kurcalayan biri başka kiracının verisine erişirdi;
 *   `BelongsToTenant` global scope'u bağlamı sorgulamaz, ona güvenir.
 *
 * DEĞİŞMEZ KURAL — İSTEK SONUNDA BAĞLAM TEMİZLENİR:
 *   `TenantContext` statiktir. Octane veya uzun ömürlü süreçlerde bağlam
 *   sonraki isteğe sızar ve o istek başka kiracının verisini görür.
 *   Kuyruk tarafında aynı sorunu `QueueServiceProvider` kancaları çözüyor;
 *   HTTP tarafındaki karşılığı burasıdır ve `finally` ile zorlanır.
 *
 * Kiracısı olmayan kullanıcı panele giremez: bağlam kurulamadan
 * tenant-scoped her sorgu istisna fırlatırdı ve kullanıcı anlamsız bir
 * hata ekranıyla karşılaşırdı.
 */
final class EstablishTenantContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return $next($request);
        }

        $tenant = $this->resolveTenant($request, $user->id);

        if ($tenant === null) {
            // Kiracısı yok veya oturumdaki kimlik doğrulanamadı.
            // Oturum kapatılmaz — kullanıcı geçerli, kiracısı yok.
            $request->session()->forget('tenant_id');

            return redirect()->guest(route('login'));
        }

        $request->session()->put('tenant_id', $tenant->id);

        TenantContext::set($tenant->id);

        // Görünümlerin ve Inertia'nın okuyabilmesi için istekte taşınır.
        $request->attributes->set('tenant', $tenant);

        try {
            return $next($request);
        } finally {
            // İSTİSNA ATSA BİLE temizlenir — sızıntı sessizdir ve
            // hiçbir günlükte iz bırakmaz.
            TenantContext::clear();
        }
    }

    /**
     * Oturumdaki kiracıyı ÜYELİKTEN doğrular; yoksa ilk üyeliği seçer.
     *
     * Sorgu `tenant_users` üzerinden kurulur: "bu kullanıcı bu kiracının
     * üyesi mi" sorusu tek kaynaktan cevaplanır ve oturumdaki değer
     * yalnızca bir ipucu olarak kullanılır.
     */
    private function resolveTenant(Request $request, string $userId): ?Tenant
    {
        $sessionTenantId = $request->session()->get('tenant_id');

        return TenantContext::runAsSystem(function () use ($sessionTenantId, $userId): ?Tenant {
            $query = Tenant::query()
                ->whereHas('users', fn ($q) => $q->where('users.id', $userId))
                ->where('status', 'active');

            if (is_string($sessionTenantId) && $sessionTenantId !== '') {
                $claimed = (clone $query)->whereKey($sessionTenantId)->first();

                if ($claimed !== null) {
                    return $claimed;
                }
                // Doğrulanamadı: sessizce ilk üyeliğe düşülür. Kurcalanmış
                // bir kimlik kabul EDİLMEZ ama kullanıcı da kilitlenmez.
            }

            return $query->orderBy('created_at')->first();
        });
    }
}
