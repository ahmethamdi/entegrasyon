<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Support;

use App\Domain\Invoicing\Exceptions\InvoiceProviderException;
use App\Domain\Invoicing\Models\InvoiceAccount;
use App\Domain\Invoicing\Providers\Logo\LogoApp;
use App\Domain\Invoicing\Providers\Logo\LogoInvoiceProvider;
use App\Domain\Sync\Enums\ErrorClass;

/**
 * Satıcının şirket içi ERP'leri (Logo …) — hangisi sunulur, formu ne
 * ister, bağlanırken nasıl denenir. TEK yer: denetleyici ve ekran buradan okur.
 *
 * SUNULMA (iki kapı, kargodaki `Carriers::offered` deseni):
 *   · platform kimliği isteyen ERP kimlik yoksa gizli (Logo: `LogoApp`)
 *   · `InvoiceAccount::UNVERIFIED` varsayılan gizli; pilot için
 *     `ERP_UNVERIFIED_ENABLED` — "Beta" rozeti YOK (24 Eki kararı)
 *
 * ALANLAR: `credentials` (şifreli, ekrana dönmez) ve `settings` (ekranda
 * okunur: cari kodu, birim). Şifre hiçbir zaman `settings`'e yazılmaz.
 */
final class ErpProviders
{
    /** @return list<string> */
    public static function offered(): array
    {
        return array_values(array_filter(InvoiceAccount::ERP_PROVIDERS, static function (string $provider): bool {
            if (in_array($provider, InvoiceAccount::UNVERIFIED, true) && ! in_array($provider, (array) config('services.erp.unverified_enabled', []), true)) {
                return false;
            }

            return match ($provider) {
                InvoiceAccount::PROVIDER_LOGO => LogoApp::configured(),
                default => true,
            };
        }));
    }

    /**
     * @return list<array{name: string, label: string, type: string, required: bool, store: string, rules: list<string>, help?: string}>
     */
    public static function fields(string $provider): array
    {
        return match ($provider) {
            InvoiceAccount::PROVIDER_LOGO => [
                ['name' => 'base_url', 'label' => __('REST servis adresi'), 'type' => 'url', 'required' => true, 'store' => 'credentials', 'rules' => ['required', 'string', 'max:255'], 'help' => __('Logo REST servisinin dışarıya https (443) ile açılmış adresi, ör. https://logo.firmaniz.com — iç ağ adresi kabul edilmez.')],
                ['name' => 'username', 'label' => __('Logo kullanıcı adı'), 'type' => 'text', 'required' => true, 'store' => 'credentials', 'rules' => ['required', 'string', 'max:64']],
                ['name' => 'password', 'label' => __('Logo şifresi'), 'type' => 'password', 'required' => false, 'store' => 'credentials', 'rules' => ['nullable', 'string', 'max:128']],
                ['name' => 'firm_no', 'label' => __('Firma numarası'), 'type' => 'text', 'required' => true, 'store' => 'credentials', 'rules' => ['required', 'string', 'regex:/^\d{1,3}$/']],
                ['name' => LogoInvoiceProvider::SETTING_ARP_CODE, 'label' => __('Siparişlerin açılacağı cari kodu'), 'type' => 'text', 'required' => true, 'store' => 'settings', 'rules' => ['required', 'string', 'max:64'], 'help' => __('Logo\'da tanımlı pazar yeri carinizin kodu (ör. 120.TRENDYOL). Cari kartı 34Pazar açmaz.')],
                ['name' => LogoInvoiceProvider::SETTING_UNIT_CODE, 'label' => __('Birim kodu (boşsa ADET)'), 'type' => 'text', 'required' => false, 'store' => 'settings', 'rules' => ['nullable', 'string', 'max:16']],
            ],
            default => [],
        };
    }

    /** @return array<string, list<string>> */
    public static function rules(string $provider): array
    {
        $rules = [];

        foreach (self::fields($provider) as $field) {
            $rules[$field['name']] = $field['rules'];
        }

        return $rules;
    }

    /**
     * Kaydetmeden önce ERP'de dener (token). Adres kayıt anında da süzülür.
     *
     * @param  array<string, string>  $credentials
     * @return array<string, string> adresi normalleşmiş kimlik
     *
     * @throws InvoiceProviderException
     */
    public static function probe(string $provider, array $credentials, ErpEndpoint $http): array
    {
        $credentials['base_url'] = ErpEndpoint::normalise((string) ($credentials['base_url'] ?? ''));

        match ($provider) {
            InvoiceAccount::PROVIDER_LOGO => LogoInvoiceProvider::probe($http, [
                'base_url' => $credentials['base_url'],
                'username' => (string) ($credentials['username'] ?? ''),
                'password' => (string) ($credentials['password'] ?? ''),
                'firm_no' => (string) ($credentials['firm_no'] ?? ''),
            ]),
            default => throw new InvoiceProviderException(ErrorClass::VALIDATION, "Bilinmeyen ERP: {$provider}"),
        };

        return $credentials;
    }

    /** Ekranda firmanın adı yerine (ERP firma unvanını vermez): "Logo · firma 214". */
    public static function companyLabel(string $provider, array $credentials): string
    {
        return match ($provider) {
            InvoiceAccount::PROVIDER_LOGO => 'Logo · firma '.($credentials['firm_no'] ?? ''),
            default => InvoiceAccount::nameOf($provider),
        };
    }
}
