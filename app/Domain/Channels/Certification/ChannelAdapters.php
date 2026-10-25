<?php

declare(strict_types=1);

namespace App\Domain\Channels\Certification;

/**
 * S15 · bilinen adaptörler — klasörden türetilir, ELLE LİSTE YOK.
 *
 * `app/Domain/Channels/Adapters/{Ad}/{Ad}Adapter.php` → kod = küçük harf ad
 * (`Trendyol` → `trendyol`, `WooCommerce` → `woocommerce`). Yeni kanal
 * klasörüyle eklendiği an matrise, test taramasına ve sözleşme testlerine
 * kendiliğinden girer; `ChannelCertificationContractTest` seeder'daki
 * `adapter_class` ile bu listenin birebir aynı olduğunu sınar.
 */
final class ChannelAdapters
{
    /** @return array<string, class-string> kod => adaptör sınıfı (koda göre sıralı) */
    public static function all(): array
    {
        $out = [];

        foreach (glob(app_path('Domain/Channels/Adapters/*/*Adapter.php')) ?: [] as $file) {
            $dir = basename(dirname($file));
            if (basename($file, '.php') !== "{$dir}Adapter") {
                continue;
            }

            $class = "App\\Domain\\Channels\\Adapters\\{$dir}\\{$dir}Adapter";
            if (class_exists($class)) {
                $out[mb_strtolower($dir)] = $class;
            }
        }

        ksort($out);

        return $out;
    }
}
