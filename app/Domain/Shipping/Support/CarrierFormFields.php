<?php

declare(strict_types=1);

namespace App\Domain\Shipping\Support;

use App\Domain\Shipping\Models\CarrierAccount;

/**
 * Kargo ayarları formunun firma başına alanları — doğrulama kuralı ve
 * ekranda gösterilip gösterilmeyeceği TEK yerde.
 *
 * `username`/`password` her firmada vardır (etiketleri farklı olabilir);
 * diğerleri `credentials` dizisine aynı adla yazılır (şifreli). `public`
 * alanlar bağlandıktan sonra ekranda okunur (firma kodu, gönderici ili);
 * şifre ve API anahtarı ASLA geri gönderilmez.
 */
final class CarrierFormFields
{
    /**
     * @return list<array{name: string, label: string, type: string, required: bool, public: bool, rules: list<string>, help?: string}>
     */
    public static function for(string $provider): array
    {
        $base = [
            ['name' => 'username', 'label' => __('Web servis kullanıcı adı'), 'type' => 'text', 'required' => true, 'public' => false, 'rules' => ['required', 'string', 'max:100']],
            ['name' => 'password', 'label' => __('Web servis şifresi'), 'type' => 'password', 'required' => true, 'public' => false, 'rules' => ['required', 'string', 'max:200']],
        ];

        return match ($provider) {
            CarrierAccount::PROVIDER_HEPSIJET => [
                ['name' => 'username', 'label' => __('Entegrasyon kullanıcı adı'), 'type' => 'text', 'required' => true, 'public' => false, 'rules' => ['required', 'string', 'max:100']],
                ['name' => 'password', 'label' => __('Entegrasyon şifresi'), 'type' => 'password', 'required' => true, 'public' => false, 'rules' => ['required', 'string', 'max:200']],
                ['name' => 'api_key', 'label' => __('API anahtarı (HepsiJet verdiyse)'), 'type' => 'password', 'required' => false, 'public' => false, 'rules' => ['nullable', 'string', 'max:200']],
                ['name' => 'company_code', 'label' => __('Firma kısaltma kodu (barkod öneki)'), 'type' => 'text', 'required' => true, 'public' => true, 'rules' => ['required', 'string', 'regex:/^[A-Za-z0-9]{1,6}$/'], 'help' => __('HepsiJet\'in size verdiği kısaltma; gönderi barkodu bununla başlar.')],
                ['name' => 'sender_city', 'label' => __('Gönderici ili'), 'type' => 'text', 'required' => true, 'public' => true, 'rules' => ['required', 'string', 'max:64']],
                ['name' => 'sender_district', 'label' => __('Gönderici ilçesi'), 'type' => 'text', 'required' => true, 'public' => true, 'rules' => ['required', 'string', 'max:64']],
                ['name' => 'sender_address', 'label' => __('Gönderici adresi'), 'type' => 'text', 'required' => true, 'public' => false, 'rules' => ['required', 'string', 'min:10', 'max:500']],
                ['name' => 'sender_address_id', 'label' => __('Gönderici adres kodu (varsa)'), 'type' => 'text', 'required' => false, 'public' => false, 'rules' => ['nullable', 'string', 'max:64']],
                ['name' => 'xdock_code', 'label' => __('Depo kodu (varsa)'), 'type' => 'text', 'required' => false, 'public' => true, 'rules' => ['nullable', 'string', 'max:64']],
            ],
            default => $base,
        };
    }

    /** @return array<string, list<string>> */
    public static function rules(string $provider): array
    {
        $rules = [];

        foreach (self::for($provider) as $field) {
            $rules[$field['name']] = $field['rules'];
        }

        return $rules;
    }
}
