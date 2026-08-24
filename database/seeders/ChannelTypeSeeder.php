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

        // ÜÇÜNCÜ KANAL — pazaryeri.
        //
        // ⚠️ DOKÜMAN BU KANALI KAPSAM DIŞI BIRAKIYOR (§16: "Ay 7").
        // Faz 4 bittiği için kullanıcının açık kararıyla açıldı.
        //
        // ⚠️ `is_active = false` VE BU BİLİNÇLİDİR: uç nokta yolları
        // resmî dokümandan DOĞRULANMADI (`developers.hepsiburada.com`
        // bot isteklerini 403 ile reddediyor). Aktif edilirse panelde
        // açılır listede görünür ve satıcı doğrulanmamış adreslere istek
        // atan bir bağlantı kurar — kanal 200 dönerse senkron BAŞARILI
        // görünür ve hiçbir şey gitmemiş olur.
        //
        // Aktifleştirme sırası `HepsiburadaEndpoints` sınıf başlığında.
        ChannelType::updateOrCreate(
            ['code' => 'hepsiburada'],
            [
                'name' => 'Hepsiburada',
                'kind' => 'marketplace',
                'adapter_class' => 'App\\Domain\\Channels\\Adapters\\Hepsiburada\\HepsiburadaAdapter',
                'capabilities' => [
                    // Katalog ve taksonomi HENÜZ yazılmadı; ilan edilen
                    // ama çalışmayan yetenek panelde çalışmayan sekme
                    // demektir.
                    'catalog' => false,
                    'inventory' => true,
                    'pricing' => true,
                    'orders' => true,
                    'taxonomy' => false,
                    'approval' => false,
                    'fulfillment' => false,
                ],
                'rate_limit_profile' => [
                    'strategy' => 'fixed_window',
                    // EN DÜŞÜK SINIR seçilir: kova BAĞLANTI başınadır ve
                    // tek kova iki farklı uç nokta sınırını (listing ~30/sn,
                    // sipariş ~10/sn) ayrı ayrı temsil edemez. Yüksek
                    // sınır sipariş çağrılarını sürekli 429'a sokardı.
                    'requests' => 10,
                    'window_seconds' => 1,
                    // İkincil kaynak 4000 diyor; doğrulanmadığı için
                    // 1000'de tutuluyor. Küçük parti yalnızca daha çok
                    // istek demektir, yanlış sonuç değil.
                    'max_inventory_batch' => 1000,
                    'max_price_batch' => 1000,
                ],
                // Trendyol'un AKSİNE webhook VAR (`X-HB-Signature` HMAC).
                'supports_webhooks' => true,
                'is_active' => false,
            ],
        );
    }
}
