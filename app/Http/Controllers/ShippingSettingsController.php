<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Shipping\Actions\ConnectCarrierAccount;
use App\Domain\Shipping\Exceptions\CarrierException;
use App\Domain\Shipping\Models\CarrierAccount;
use App\Domain\Shipping\Support\CarrierFormFields;
use App\Domain\Shipping\Support\Carriers;
use App\Domain\Sync\Enums\ErrorClass;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * S6 · Kargo ayarları — satıcının Yurtiçi / Aras / HepsiJet / MNG web servis hesabı.
 *
 * Yurtiçi ve Aras firmaların herkese açık test ortamında uçtan uca
 * denendi; gerçek müşteri hesabıyla gönderi açılmadı (23 Eki kararıyla
 * ekranda Beta rozeti YOK). `CarrierAccount::UNVERIFIED` firmaları (HepsiJet, MNG)
 * hiçbir sunucuda denenmedi → varsayılan GİZLİ (`Carriers::offered`);
 * pilot için açıldığında kartta "gerçek hesapta henüz denenmedi" notu.
 *
 * Firma başına form alanları `CarrierFormFields`'tadır; ek alanlar aynı
 * şifreli `credentials` dizisine yazılır.
 *
 * Şifre ekrana GERİ GÖNDERİLMEZ: yalnız kullanıcı adının son 3 hanesi.
 */
final class ShippingSettingsController extends Controller
{
    public function index(Carriers $registry): InertiaResponse
    {
        $accounts = CarrierAccount::query()->get()->keyBy('provider');
        // Saklanan firmanın MEVCUT hesabı yine görünür (kaldırılabilsin).
        $providers = array_values(array_filter(
            CarrierAccount::PROVIDERS,
            fn (string $p): bool => in_array($p, Carriers::offered(), true) || isset($accounts[$p]),
        ));

        return Inertia::render('Settings/Shipping', [
            'carriers' => array_map(function (string $provider) use ($accounts, $registry): array {
                $fields = CarrierFormFields::for($provider);
                $a = $accounts[$provider] ?? null;

                return [
                    'code' => $provider,
                    'name' => CarrierAccount::displayName($provider),
                    'unverified' => CarrierAccount::isUnverified($provider),
                    // Durum sorgusu olan firma kargoya verileni kendisi bildirir;
                    // olmayanda (Aras) satıcı "kargoya verdim" der.
                    'autoTracking' => $registry->for($provider)->supportsTracking(),
                    'fields' => array_map(fn (array $f): array => [
                        'name' => $f['name'],
                        'label' => $f['label'],
                        'type' => $f['type'],
                        'required' => $f['required'],
                        'help' => $f['help'] ?? null,
                    ], $fields),
                    'account' => $a === null ? null : [
                        'status' => $a->status,
                        'environment' => $a->environment,
                        'username' => '•••'.mb_substr($a->username(), -3),
                        'details' => array_values(array_filter(array_map(
                            fn (array $f): ?array => $f['public'] && $a->credential($f['name']) !== null
                                ? ['label' => $f['label'], 'value' => $a->credential($f['name'])]
                                : null,
                            $fields,
                        ))),
                        'lastError' => $a->last_error,
                        'verifiedAt' => $a->verified_at?->toIso8601String(),
                    ],
                ];
            }, $providers),
        ]);
    }

    public function store(Request $request, ConnectCarrierAccount $connect): RedirectResponse
    {
        $base = $request->validate([
            'provider' => ['required', 'string', Rule::in(Carriers::offered())],
            'environment' => ['required', 'string', Rule::in(CarrierAccount::ENVIRONMENTS)],
        ]);
        $provider = $base['provider'];
        $data = $request->validate(CarrierFormFields::rules($provider));

        $extra = [];

        foreach (CarrierFormFields::for($provider) as $field) {
            $value = $data[$field['name']] ?? null;

            if (in_array($field['name'], ['username', 'password'], true) || ! is_string($value) || trim($value) === '') {
                continue;
            }

            $extra[$field['name']] = $field['name'] === 'company_code' ? strtoupper(trim($value)) : trim($value);
        }

        try {
            $connect->run($provider, $base['environment'], trim((string) $data['username']), (string) $data['password'], $extra);
        } catch (CarrierException $e) {
            $message = $e->class === ErrorClass::AUTHENTICATION
                ? __('Kargo firması kullanıcı adı/şifreyi reddetti. Web servis bilgilerinizi kargo şubenizden ya da müşteri temsilcinizden alın.')
                : __('Kargo firmasına şu an ulaşılamadı; biraz sonra yeniden deneyin.');

            return back()->withErrors(['carrier_'.$provider => $message]);
        }

        return back()->with('success', __(':name hesabı bağlandı.', ['name' => CarrierAccount::displayName($provider)]));
    }

    public function destroy(string $provider): RedirectResponse
    {
        abort_unless(in_array($provider, CarrierAccount::PROVIDERS, true), 404);

        CarrierAccount::query()->where('provider', $provider)->delete();

        return back()->with('success', __('Kargo hesabı kaldırıldı.'));
    }
}
