<?php

declare(strict_types=1);

namespace App\Domain\Shipping\Support;

use App\Domain\Shipping\Contracts\Carrier;
use App\Domain\Shipping\Models\CarrierAccount;
use App\Domain\Shipping\Providers\Aras\ArasCarrier;
use App\Domain\Shipping\Providers\Yurtici\YurticiCarrier;
use InvalidArgumentException;

/** Firma kodu → sağlayıcı (kanal `AdapterRegistry`'sinin karşılığı). */
final class Carriers
{
    /** @var array<string, class-string<Carrier>> */
    private const MAP = [
        CarrierAccount::PROVIDER_YURTICI => YurticiCarrier::class,
        CarrierAccount::PROVIDER_ARAS => ArasCarrier::class,
    ];

    public function for(string $provider): Carrier
    {
        $class = self::MAP[$provider] ?? throw new InvalidArgumentException("Bilinmeyen kargo firması: {$provider}");

        return app($class);
    }

    /**
     * Firmaya giden anahtar: "34P" + 12 büyük harf/rakam. Yurtiçi cargoKey
     * ve Aras IntegrationCode bu biçimi kabul etti (test ortamı, 23 Eki).
     * Rastgele üretilir ve tabloda `(provider, cargo_key)` tekildir.
     */
    public static function newCargoKey(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $key = '34P';

        for ($i = 0; $i < 12; $i++) {
            $key .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $key;
    }
}
