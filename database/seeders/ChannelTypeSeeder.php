<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Channels\Models\ChannelType;
use Illuminate\Database\Seeder;

/**
 * Kanal platform tanımları.
 *
 * Mimari Karar Dokümanı v2.2 · §4 · channel_types ve §7 · yetenek tablosu.
 *
 * capabilities alanı koddaki instanceof Supports* kontrolünün veritabanı
 * yansımasıdır; panel sekmelerini buradan okur.
 *
 * NOT: adapter_class alanları sonraki fazda yazılacak adapter sınıflarını
 * işaret eder. Bu turda adapter iş mantığı uygulanmadığı için sınıflar
 * henüz mevcut değildir; AdapterRegistry yazılırken çözülecektir.
 */
class ChannelTypeSeeder extends Seeder
{
    public function run(): void
    {
        // İlk dikey dilim kanalı — mağaza (storefront).
        ChannelType::updateOrCreate(
            ['code' => 'woocommerce'],
            [
                'name' => 'WooCommerce',
                'kind' => 'storefront',
                'adapter_class' => 'App\\Domain\\Channels\\Adapters\\WooCommerce\\WooCommerceAdapter',
                'capabilities' => [
                    'catalog' => true,
                    'inventory' => true,
                    'pricing' => true,
                    'orders' => true,
                    'taxonomy' => false,
                    'approval' => false,
                    'fulfillment' => true,
                ],
                'rate_limit_profile' => [
                    'strategy' => 'fixed_window',
                    'requests' => 120,
                    'window_seconds' => 60,
                    'max_inventory_batch' => 100,
                    'max_price_batch' => 100,
                ],
                'supports_webhooks' => true,
                'is_active' => true,
            ],
        );

        // İkinci kanal — pazaryeri (marketplace). Faz 2'de aktifleşir.
        ChannelType::updateOrCreate(
            ['code' => 'trendyol'],
            [
                'name' => 'Trendyol',
                'kind' => 'marketplace',
                'adapter_class' => 'App\\Domain\\Channels\\Adapters\\Trendyol\\TrendyolAdapter',
                'capabilities' => [
                    'catalog' => true,
                    'inventory' => true,
                    'pricing' => true,
                    'orders' => true,
                    'taxonomy' => true,
                    'approval' => true,
                    'fulfillment' => false,
                ],
                'rate_limit_profile' => [
                    'strategy' => 'fixed_window',
                    'requests' => 50,
                    'window_seconds' => 60,
                    'max_inventory_batch' => 1000,
                    'max_price_batch' => 1000,
                ],
                // Webhook yok: sipariş yoklama ile çekilir.
                'supports_webhooks' => false,
                'is_active' => false,
            ],
        );
    }
}
