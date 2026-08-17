<?php

namespace App\Http\Middleware;

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
        ];
    }
}
