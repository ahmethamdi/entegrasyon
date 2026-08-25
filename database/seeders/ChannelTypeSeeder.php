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
 *
 * ⚠️ `is_active` VAR OLAN SATIRDA ASLA EZİLMEZ (V3.0 · §16 · DB Delta 4).
 * Gerekçe `upsert()` yardımcısının başlığında; ChannelTypeSeederTest korur.
 */
class ChannelTypeSeeder extends Seeder
{
    public function run(): void
    {
        // İlk dikey dilim kanalı — mağaza (storefront).
        $this->upsert(
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
        $this->upsert(
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
        $this->upsert(
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

        // DÖRDÜNCÜ KANAL — mağaza (storefront). V3.0 · Faz 1 · §06.
        //
        // ⚠️ v2.2'DEN BİLİNÇLİ SAPMA: doküman §2/§11 Shopify'ı ayrı bir
        // Node/Remix servisi olarak öngörüyor (App Store yolu, Ay 8+).
        // V3.0 onaylanmış proje kararıyla LARAVEL ADAPTER yazıyor:
        // satıcı kendi custom app Admin API anahtarıyla bağlanır, projeye
        // ikinci teknoloji yığını SOKULMAZ. §11'in servis token'ı
        // değişmezi İPTAL EDİLMEDİ, ERTELENDİ.
        //
        // ⚠️ `is_active = false` — §05'in 12 adımlı listesinde ADIM 1.
        // Kanal ancak GERÇEK bir mağazada sağlık kontrolü geçtikten ve
        // tek kiracıda uçtan uca sürüldükten sonra açılır (adım 12,
        // §26 · kademeli açılış). Uç noktaları doğrulanabilir olsa da
        // (Hepsiburada'nın aksine) bu sıra ATLANMAZ.
        //
        // YETENEKLER SLICE SLICE AÇILIR: bu turda yalnızca istemci
        // katmanı yazıldı. İlan edilen ama çalışmayan yetenek panelde
        // çalışmayan sekme demektir (§05).
        $this->upsert(
            ['code' => 'shopify'],
            [
                'name' => 'Shopify',
                'kind' => 'storefront',
                'adapter_class' => 'App\\Domain\\Channels\\Adapters\\Shopify\\ShopifyAdapter',
                'capabilities' => [
                    // Slice slice AÇILIR. §04'ün capability matrisi V3
                    // HEDEFİDİR, bugünkü durum değil — ilan edilen ama
                    // çalışmayan yetenek panelde çalışmayan sekme demektir.
                    'catalog' => true,          // slice 1.3 ✓
                    'catalog_import' => true,   // slice 1.4 ✓
                    'inventory' => true,        // slice 1.5 ✓
                    'pricing' => false,         // slice 1.6
                    'orders' => false,          // slice 1.7
                    // Shopify'da kategori zorunlu DEĞİL (`product_type`
                    // serbest metin) — taksonomi arayüzü HİÇ uygulanmaz.
                    'taxonomy' => false,
                    // Onay süreci YOKTUR: ürün yayınlanır yayınlanmaz canlı.
                    'approval' => false,
                    'fulfillment' => false,
                ],
                'rate_limit_profile' => [
                    // MALİYET TABANLI — istek sayısı değil SORGU MALİYETİ
                    // (§06.8). 1.000 puanlık kova, saniyede 50 puan
                    // yenilenir. `ChannelRateLimiter` DEĞİŞMEZ: bir jeton
                    // bir puan olarak yorumlanır.
                    //
                    // GERÇEK DEĞER YANIT GÖVDESİNDEN ÖĞRENİLİR
                    // (`extensions.cost.throttleStatus`) — Plus'ta kova
                    // 2.000 puandır ve sabit profil Plus'ı yavaşlatır,
                    // standardı 429'a sokardı.
                    'strategy' => 'token_bucket',
                    'requests' => 50,
                    'window_seconds' => 1,
                    'burst_capacity' => 1000,
                    // `inventorySetOnHandQuantities` tek mutation'da çok
                    // kalem kabul eder (§06.5).
                    'max_inventory_batch' => 250,
                    'max_price_batch' => 250,
                ],
                // Woo ile aynı: webhook VAR (`X-Shopify-Hmac-Sha256`).
                'supports_webhooks' => true,
                'is_active' => false,
            ],
        );
    }

    /**
     * Tanımı yazar ama `is_active`'i VAR OLAN satırda EZMEZ.
     *
     * V3.0 · §16 · DB Delta 4 · P1-3 · T-V3-23.
     *
     * `updateOrCreate` kullanılamaz: güncelleme kümesine `is_active` de
     * girer ve seeder her koşuşta kanalın operasyonel durumunu tohum
     * değerine geri sarar. `356a662`'de tam olarak bu yaşandı —
     * `db:seed --class=ChannelTypeSeeder` **Trendyol'u kapattı** ve kanal
     * elle SQL ile geri açıldı. Altı kanalda bu tuzak altı kez ısırır.
     *
     * AÇIK/KAPALI KARARI SEEDER'IN DEĞİL OPERATÖRÜN KARARIDIR: §05'in 12
     * adımlı listesi kanalı kapalı doğurur (adım 1) ve gerçek hesapla
     * sağlık kontrolü GEÇTİKTEN sonra açar (adım 12). Adım 12'yi geri alan
     * bir seeder o listeyi anlamsız kılar. Koruma İKİ YÖNLÜDÜR: sorun
     * çıktığı için acilen kapatılan bir kanal da (§26 · geri alma) sessizce
     * geri açılmamalıdır.
     *
     * DİĞER TÜM ALANLAR GÜNCELLENMEYE DEVAM EDER. "Satır varsa hiç dokunma"
     * demek seeder'ı tanımların tek kaynağı olmaktan çıkarırdı: bir hız
     * sınırı düzeltmesi veya yeni yetenek bayrağı üretime ASLA ulaşmaz,
     * kod ile veritabanı sessizce ayrışırdı.
     *
     * @param  array<string, mixed>  $key
     * @param  array<string, mixed>  $attributes
     */
    private function upsert(array $key, array $attributes): ChannelType
    {
        $type = ChannelType::query()->firstOrNew($key);

        // Yalnızca YENİ satırda tohum değerini uygula; mevcut satırda
        // operatörün kararı korunur.
        if ($type->exists) {
            unset($attributes['is_active']);
        }

        $type->fill([...$key, ...$attributes])->save();

        return $type;
    }
}
