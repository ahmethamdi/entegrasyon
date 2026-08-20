<?php

namespace App\Http\Middleware;

use App\Domain\Identity\Support\OnboardingProgress;
use Illuminate\Http\Request;
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
        $tenant = $request->attributes->get('tenant');

        return [
            ...parent::share($request),

            // Kiracı ve kullanıcı her sayfada gerekli (başlık, çıkış düğmesi).
            // Yalnızca GÖRÜNEN alanlar paylaşılır: modeli olduğu gibi
            // göndermek parola hash'i ve kimlik bilgisi sızdırabilir.
            'tenant' => $tenant === null ? null : [
                'id' => $tenant->id,
                'name' => $tenant->name,
            ],
            'auth' => [
                'user' => $request->user() === null ? null : [
                    'id' => $request->user()->id,
                    'name' => $request->user()->name,
                    'email' => $request->user()->email,
                ],
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
            'onboarding' => fn (): ?array => $request->attributes->get('tenant') === null
                ? null
                : (new OnboardingProgress)->forCurrentTenant(),
        ];
    }
}
