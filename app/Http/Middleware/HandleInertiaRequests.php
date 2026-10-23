<?php

namespace App\Http\Middleware;

use App\Domain\Identity\Enums\Permission;
use App\Domain\Identity\Enums\TenantRole;
use App\Domain\Identity\Support\OnboardingProgress;
use App\Domain\Identity\Support\SuperAdmin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),

            // Kiracı ve kullanıcı her sayfada gerekli (başlık, çıkış düğmesi).
            // Yalnızca GÖRÜNEN alanlar paylaşılır: modeli olduğu gibi
            // göndermek parola hash'i ve kimlik bilgisi sızdırabilir.
            //
            // `Closure` ZORUNLUDUR — `onboarding` ile AYNI gerekçe (aşağıda
            // uzun uzun yazılı). `share()` `web` grubunda, `tenant` ise ROTA
            // seviyesinde çalışır; bu metot bağlam kurulmadan ÖNCE çağrılır
            // ve kapanış dışında okunan `$tenant` HER ZAMAN null olurdu.
            //
            // Bu hata GERÇEK ÇALIŞTIRMADA bulundu: kiracı adı YALNIZCA
            // özet ekranında görünüyordu, diğer on iki ekranda başlık BOŞTU.
            // Özet ekranı bunu maskeliyordu çünkü `DashboardController`
            // KENDİ `tenant` prop'unu gönderiyor ve paylaşılanı EZİYOR.
            'tenant' => function () use ($request): ?array {
                $tenant = $request->attributes->get('tenant');

                return $tenant === null ? null : [
                    'id' => $tenant->id,
                    'name' => $tenant->name,
                ];
            },
            // S7 · rolün yetkileri — menü ve düğmeler yalnız GÖRÜNÜM için
            // okur; yetkinin kendisi `AuthorizeTenantRoute`'tadır. Kapanış
            // gerekçesi `tenant` ile aynı (rol de rota seviyesinde kurulur).
            'permissions' => function () use ($request): array {
                $role = $request->attributes->get('tenant_role');

                return $role instanceof TenantRole
                    ? array_map(static fn (Permission $p): string => $p->value, $role->permissions())
                    : [];
            },
            'tenantRole' => function () use ($request): ?array {
                $role = $request->attributes->get('tenant_role');

                return $role instanceof TenantRole ? ['value' => $role->value, 'label' => $role->label()] : null;
            },
            // Birden çok firmanın üyesi olan kullanıcıya firma seçici.
            'memberships' => function () use ($request): array {
                if ($request->user() === null || $request->attributes->get('tenant') === null) {
                    return [];
                }

                return DB::table('tenant_users')
                    ->join('tenants', 'tenants.id', '=', 'tenant_users.tenant_id')
                    ->where('tenant_users.user_id', $request->user()->id)
                    ->where('tenants.status', 'active')
                    ->orderBy('tenants.name')
                    ->get(['tenants.id', 'tenants.name'])
                    ->map(fn (object $t): array => ['id' => $t->id, 'name' => $t->name])
                    ->all();
            },
            'auth' => [
                'user' => $request->user() === null ? null : [
                    'id' => $request->user()->id,
                    'name' => $request->user()->name,
                    'email' => $request->user()->email,
                    // Menüdeki "Yönetim" bağlantısı için; yetkinin kendisi
                    // rotadaki `can:superAdmin`'dedir, bu yalnız görünüm.
                    'isSuperAdmin' => SuperAdmin::is($request->user()),
                ],
            ],

            // Panel dili ve sözlük. Anahtar Türkçe metnin KENDİSİDİR
            // (Laravel JSON çevirisi): Türkçede sözlük gönderilmez,
            // `t()` anahtarı aynen basar. Sözlük `lang/en.json` — sunucu
            // tarafı `__()` ile AYNI dosya, metin bir kez çevrilir.
            'locale' => fn (): string => app()->getLocale(),
            'translations' => fn (): array => self::dictionary(app()->getLocale()),
            // Dil anahtarı yalnız İngilizce panel açıkken görünür.
            'localeSwitch' => (bool) config('app.english_panel'),

            // Açılıp kapanabilen modüller (acil kapatma anahtarları).
            'features' => [
                'carrierShipping' => (bool) config('shipping.enabled'),
            ],

            // Tek seferlik bildirimler. `warning` sessiz başarısızlığın
            // panjurudur: kanal cevap vermediğinde kullanıcı bunu görmeli,
            // yoksa "kaydedildi" sanıp ürün göndermeye başlar.
            'flash' => [
                'success' => $request->session()->get('success'),
                'warning' => $request->session()->get('warning'),
            ],

            // Onboarding ilerlemesi (§13 · Faz 4). HER panel ekranında
            // paylaşılır, yalnızca özette değil: kullanıcı kurulumun
            // ortasında herhangi bir ekrana gidebilir ve şerit orada da
            // yolu göstermelidir.
            //
            // Kiracı bağlamı YOKKEN hesaplanmaz — giriş ve kayıt ekranları
            // kiracısızdır ve tenant-scoped sorgu orada İSTİSNA fırlatırdı
            // (izolasyon kuralı: bağlam yokken sessizce veri dönmez).
            //
            // `Closure` ile sarılır ve KİRACI KONTROLÜ DE İÇERİDE yapılır.
            // `share()` `web` grubunda, `tenant` ise ROTA seviyesinde
            // çalışır — yani bu metot bağlam kurulmadan ÖNCE çağrılır ve
            // dışarıda okunan `$tenant` her zaman null olurdu. Kapanış
            // yanıt üretilirken çalıştığı için bağlamı kurulmuş görür.
            //
            // Yan fayda: Inertia kısmi yenilemede (partial reload)
            // çağrılmayan prop'u atlar ve üç `exists()` sorgusu boşuna
            // koşmaz.
            //
            // S7 · kurulum adımlarını (kanal bağla, ürün gönder) yalnız
            // yapabilecek olan görür: depo/salt okunur üyeye tıklayınca 403
            // veren bir şerit gösterilmez.
            'onboarding' => fn (): ?array => $request->attributes->get('tenant') === null
                || ! ($request->attributes->get('tenant_role')?->allows(Permission::CHANNELS_MANAGE) ?? false)
                ? null
                : (new OnboardingProgress)->forCurrentTenant(),
        ];
    }

    /**
     * Dilin sözlüğü — Türkçe için boş (anahtar = metin).
     *
     * İstek başına bir kez okunur; dosya küçük ve opcache'lenmiş JSON
     * okuması önbellek katmanı gerektirmez.
     *
     * @return array<string, string>
     */
    public static function dictionary(string $locale): array
    {
        if ($locale === 'tr') {
            return [];
        }

        $path = lang_path("{$locale}.json");

        if (! is_file($path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [];
    }
}
