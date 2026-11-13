<?php

declare(strict_types=1);

namespace App\Domain\Shipping\Actions;

use App\Domain\Shipping\Exceptions\CarrierException;
use App\Domain\Shipping\Models\CarrierAccount;
use App\Domain\Shipping\Support\Carriers;
use App\Support\Tenancy\TenantContext;

/**
 * Satıcının kargo hesabını bağlar: önce firmada DENER, sonra kaydeder.
 *
 * Doğrulama zararsız bir sorgudur (olmayan anahtarı sormak) — firmada iz
 * bırakmaz. Kimliği reddedilen hesap kaydedilmez; böylece "bağlı" görünen
 * ama ilk gönderide çöken hesap olmaz.
 */
final class ConnectCarrierAccount
{
    public function __construct(private readonly Carriers $carriers) {}

    /**
     * `$extra` firmaya özgü alanlardır (HepsiJet firma kodu, gönderici adresi …);
     * kimlikle aynı şifreli dizide durur.
     *
     * @param  array<string, string>  $extra
     *
     * @throws CarrierException firma kimliği reddederse ya da ulaşılamazsa
     */
    public function run(string $provider, string $environment, string $username, string $password, array $extra = []): CarrierAccount
    {
        $credentials = ['username' => $username, 'password' => $password, ...$extra];

        $candidate = new CarrierAccount([
            'provider' => $provider,
            'environment' => $environment,
            'credentials' => $credentials,
            'status' => CarrierAccount::STATUS_CONNECTED,
        ]);

        $this->carriers->for($provider)->verify($candidate);

        return CarrierAccount::query()->updateOrCreate(
            ['tenant_id' => TenantContext::idOrFail(), 'provider' => $provider],
            [
                'environment' => $environment,
                'credentials' => $credentials,
                'status' => CarrierAccount::STATUS_CONNECTED,
                'last_error' => null,
                'verified_at' => now(),
            ],
        );
    }
}
