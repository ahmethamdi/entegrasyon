<?php

declare(strict_types=1);

namespace App\Domain\Shipping\Support;

use App\Domain\Shipping\Contracts\Carrier;
use App\Domain\Shipping\Models\CarrierAccount;
use App\Domain\Shipping\Providers\Aras\ArasCarrier;
use App\Domain\Shipping\Providers\Hepsijet\HepsijetCarrier;
use App\Domain\Shipping\Providers\Mng\MngApp;
use App\Domain\Shipping\Providers\Mng\MngCarrier;
use App\Domain\Shipping\Providers\Yurtici\YurticiCarrier;
use InvalidArgumentException;

/** Firma kodu → sağlayıcı (kanal `AdapterRegistry`'sinin karşılığı). */
final class Carriers
{
    /** @var array<string, class-string<Carrier>> */
    private const MAP = [
        CarrierAccount::PROVIDER_YURTICI => YurticiCarrier::class,
        CarrierAccount::PROVIDER_ARAS => ArasCarrier::class,
        CarrierAccount::PROVIDER_HEPSIJET => HepsijetCarrier::class,
        CarrierAccount::PROVIDER_MNG => MngCarrier::class,
    ];

    public function for(string $provider): Carrier
    {
        $class = self::MAP[$provider] ?? throw new InvalidArgumentException("Bilinmeyen kargo firması: {$provider}");

        return app($class);
    }

    /**
     * Satıcıya YENİ bağlantı için sunulan firmalar.
     *
     * Kanallardaki `ChannelType::isWithheld` deseni:
     *   · `CarrierAccount::UNVERIFIED` firma varsayılan GİZLİ; pilot için
     *     `CARRIERS_UNVERIFIED_ENABLED` (virgüllü kod listesi) açar
     *   · `CARRIERS_WITHHELD` bir firmayı elle saklar (doğrulanmış olsa da)
     *   · platform anahtarı isteyen firma (MNG API Zone) anahtar yoksa gizli
     *     (`AmazonApp`/`OttoApp`/`EtsyApp::configured` deseni)
     * Mevcut hesaplar ve açılmış gönderiler etkilenmez; yalnız ayar ekranı
     * ve yeni bağlantı.
     *
     * @return list<string>
     */
    public static function offered(): array
    {
        return array_values(array_filter(
            CarrierAccount::PROVIDERS,
            static fn (string $provider): bool => ! self::isWithheld($provider),
        ));
    }

    public static function isWithheld(string $provider): bool
    {
        if (in_array($provider, (array) config('shipping.withheld', []), true)) {
            return true;
        }

        if ($provider === CarrierAccount::PROVIDER_MNG && ! MngApp::configured()) {
            return true;
        }

        return CarrierAccount::isUnverified($provider)
            && ! in_array($provider, (array) config('shipping.unverified_enabled', []), true);
    }

    /**
     * Durum sorgusu olan firmalar — yoklama turu yalnız bunların gönderisine bakar.
     *
     * @return list<string>
     */
    public function trackingProviders(): array
    {
        return array_values(array_filter(
            array_keys(self::MAP),
            fn (string $provider): bool => $this->for($provider)->supportsTracking(),
        ));
    }

    /**
     * Firmaya giden anahtar: "34P" + 12 büyük harf/rakam. Yurtiçi cargoKey
     * ve Aras IntegrationCode bu biçimi kabul etti (test ortamı, 23 Eki).
     * Rastgele üretilir ve tabloda `(provider, cargo_key)` tekildir.
     */
    public static function newCargoKey(): string
    {
        return '34P'.self::randomTail(12);
    }

    /**
     * Hesaba göre anahtar. HepsiJet belgesi: `customerDeliveryNo` (barkod)
     * firmanın kısaltma koduyla BAŞLAR ve 9–16 karakterdir → kod + rastgele
     * kuyruk, toplam 16. Diğer firmalar `newCargoKey()`.
     */
    public static function newCargoKeyFor(CarrierAccount $account): string
    {
        if ($account->provider === CarrierAccount::PROVIDER_HEPSIJET) {
            $prefix = strtoupper((string) $account->credential('company_code'));

            return $prefix.self::randomTail(max(16 - strlen($prefix), 3));
        }

        return self::newCargoKey();
    }

    private static function randomTail(int $length): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $tail = '';

        for ($i = 0; $i < $length; $i++) {
            $tail .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $tail;
    }
}
