<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Shipping\Actions\ConnectCarrierAccount;
use App\Domain\Shipping\Exceptions\CarrierException;
use App\Domain\Shipping\Models\CarrierAccount;
use App\Domain\Sync\Enums\ErrorClass;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * S6 · Kargo ayarları — satıcının Yurtiçi / Aras web servis hesabı.
 *
 * İki firma da yalnız firmaların herkese açık test ortamında uçtan uca
 * denendi; gerçek müşteri hesabıyla gönderi açılmadı (23 Eki kararıyla
 * ekranda Beta rozeti YOK).
 *
 * Şifre ekrana GERİ GÖNDERİLMEZ: yalnız kullanıcı adının son 3 hanesi.
 */
final class ShippingSettingsController extends Controller
{
    public function index(): InertiaResponse
    {
        $accounts = CarrierAccount::query()->get()->keyBy('provider');

        return Inertia::render('Settings/Shipping', [
            'carriers' => array_map(fn (string $provider): array => [
                'code' => $provider,
                'name' => CarrierAccount::displayName($provider),
                // Durum sorgusu: Yurtiçi kargoya verileni kendisi bildirir;
                // Aras'ta satıcı "kargoya verdim" der.
                'autoTracking' => $provider === CarrierAccount::PROVIDER_YURTICI,
                'account' => ($a = $accounts[$provider] ?? null) === null ? null : [
                    'status' => $a->status,
                    'environment' => $a->environment,
                    'username' => '•••'.mb_substr($a->username(), -3),
                    'lastError' => $a->last_error,
                    'verifiedAt' => $a->verified_at?->toIso8601String(),
                ],
            ], CarrierAccount::PROVIDERS),
        ]);
    }

    public function store(Request $request, ConnectCarrierAccount $connect): RedirectResponse
    {
        $data = $request->validate([
            'provider' => ['required', 'string', Rule::in(CarrierAccount::PROVIDERS)],
            'environment' => ['required', 'string', Rule::in(CarrierAccount::ENVIRONMENTS)],
            'username' => ['required', 'string', 'max:100'],
            'password' => ['required', 'string', 'max:200'],
        ]);

        try {
            $connect->run($data['provider'], $data['environment'], trim($data['username']), $data['password']);
        } catch (CarrierException $e) {
            $message = $e->class === ErrorClass::AUTHENTICATION
                ? __('Kargo firması kullanıcı adı/şifreyi reddetti. Web servis bilgilerinizi kargo şubenizden ya da müşteri temsilcinizden alın.')
                : __('Kargo firmasına şu an ulaşılamadı; biraz sonra yeniden deneyin.');

            return back()->withErrors(['carrier_'.$data['provider'] => $message]);
        }

        return back()->with('success', __(':name hesabı bağlandı.', ['name' => CarrierAccount::displayName($data['provider'])]));
    }

    public function destroy(string $provider): RedirectResponse
    {
        abort_unless(in_array($provider, CarrierAccount::PROVIDERS, true), 404);

        CarrierAccount::query()->where('provider', $provider)->delete();

        return back()->with('success', __('Kargo hesabı kaldırıldı.'));
    }
}
