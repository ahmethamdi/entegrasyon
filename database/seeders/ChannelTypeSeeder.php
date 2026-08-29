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
                    // ⚠️ ANAHTAR HİÇ YOKTU ve `?? false` ile SESSİZCE
                    // kapalı sayılıyordu — oysa `WooCommerceAdapter`
                    // `SupportsCatalogImport`'u `99008b8`'den beri
                    // UYGULUYOR. Yani "kanaldan ürün çekme" çalışıyordu
                    // ama satıcı onu panelde HİÇ GÖREMİYORDU.
                    // `ChannelTypeSeederTest` artık bunu `instanceof`
                    // ile karşılaştırarak koruyor.
                    'catalog_import' => true,
                    'inventory' => true,
                    'pricing' => true,
                    'orders' => true,
                    'taxonomy' => false,
                    'approval' => false,
                    'fulfillment' => true,
                ],
                'rate_limit_profile' => [
                    // ⚠️ `requests_per_second` ADI SÖZLEŞMEDİR —
                    // `RateLimitProfile::fromArray()` TAM OLARAK bu adı
                    // okur. `requests` yazıldığında `??` varsayılanı
                    // devreye girer ve profil SESSİZCE 5/sn'ye düşer;
                    // senkron çalışmaya devam eder, yalnızca kat kat
                    // yavaş akar ve hiçbir alarm çalmaz. Beş kanalda
                    // birden yaşandı (Etsy slice 3.1 · gerçek çalıştırma).
                    //
                    // WOO §21'DE "istek/dk"DIR: 120/dk = 2/sn.
                    // `window_seconds` bilgi amaçlıdır — kova saniyelik
                    // yenilenir ve o alanı OKUMAZ, bu yüzden dönüşüm
                    // BURADA yapılır.
                    'strategy' => 'fixed_window',
                    'requests_per_second' => 2,
                    'burst_capacity' => 10,
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
                    // ⚠️ ANAHTAR ADI `requests_per_second` (yukarıdaki
                    // gerekçe). Trendyol §21'de "istek/sn"dir ama TABAN
                    // profil TUTUCU seçilir: gerçek sınır SATICI
                    // SEVİYESİNE göre değişir ve YANIT BAŞLIĞINDAN
                    // öğrenilip bağlantıya yazılır (`learned_rate_limit`).
                    // Yüksek bir taban, düşük seviyeli satıcıyı ilk
                    // turdan 429'a sokardı — öğrenme ancak bir yanıt
                    // geldikten SONRA devreye girer.
                    'strategy' => 'fixed_window',
                    'requests_per_second' => 1,
                    'burst_capacity' => 10,
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
                    // ⚠️ ANAHTAR ADI `requests_per_second` — `requests`
                    // yazıldığında profil SESSİZCE 5/sn'ye düşer.
                    'requests_per_second' => 10,
                    'burst_capacity' => 10,
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
        // ⚠️ `is_active = true` — §05'in ADIM 12'si (slice 1.9).
        //
        // §04'ün capability matrisi TAMAMLANDI: catalog · catalog_import ·
        // inventory · pricing · orders · fulfillment. `taxonomy` ve
        // `approval` HİÇ AÇILMAYACAK (§04 dipnotları) — yani "yetenekler
        // slice slice açılır" kuralının bekleyeni kalmadı.
        //
        // ⚠️ AÇILIŞ KULLANICI KARARIYLA YAPILDI ve §05'in adım 1 / adım 12
        // ayrımı bu noktada TAM KARŞILANMADI: adım 12 GERÇEK bir mağazada
        // sağlık kontrolü ve tek kiracıda uçtan uca sürüm ister; o sürüm
        // yapılmadı, çünkü gerçek Shopify mağazası + custom app Admin API
        // anahtarı gerekiyor ve ikisi de kullanıcıdadır. Kanal panelde
        // GÖRÜNÜR hâle geldi; ilk gerçek bağlantıda sağlık kontrolü
        // geçmezse bağlantı `pending` kalır ve `last_error` panelde
        // gösterilir (`CheckChannelHealth`), yani satıcı sessiz bir hataya
        // değil görünür bir hataya düşer.
        //
        // ⚠️ BU SATIRI DEĞİŞTİRMEK MEVCUT KURULUMLARI ETKİLEMEZ:
        // `upsert()` `is_active`'i YALNIZCA YENİ satırda uygular ve mevcut
        // satırda operatörün kararını korur (P1-3). Zaten tohumlanmış bir
        // veritabanında kanalı açmak için satır ELLE güncellenmelidir.
        $this->upsert(
            ['code' => 'shopify'],
            [
                'name' => 'Shopify',
                'kind' => 'storefront',
                'adapter_class' => 'App\\Domain\\Channels\\Adapters\\Shopify\\ShopifyAdapter',
                'capabilities' => [
                    // §04'ün matrisi TAMAM — altı yetenek de yazıldı.
                    'catalog' => true,          // slice 1.3 ✓
                    'catalog_import' => true,   // slice 1.4 ✓
                    'inventory' => true,        // slice 1.5 ✓
                    'pricing' => true,          // slice 1.6 ✓
                    'orders' => true,           // slice 1.7 ✓
                    // Shopify'da kategori zorunlu DEĞİL (`product_type`
                    // serbest metin) — taksonomi arayüzü HİÇ uygulanmaz.
                    'taxonomy' => false,
                    // Onay süreci YOKTUR: ürün yayınlanır yayınlanmaz canlı.
                    'approval' => false,
                    'fulfillment' => true,      // slice 1.8 ✓
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
                    // ⚠️ ANAHTAR ADI `requests_per_second` — `requests`
                    // yazıldığında profil SESSİZCE 5/sn'ye düşerdi ve
                    // maliyet kovasının 50 puan/sn yenilenmesi HİÇ
                    // uygulanmazdı.
                    'strategy' => 'token_bucket',
                    'requests_per_second' => 50,
                    'window_seconds' => 1,
                    'burst_capacity' => 1000,
                    // `inventorySetOnHandQuantities` tek mutation'da çok
                    // kalem kabul eder (§06.5).
                    'max_inventory_batch' => 250,
                    'max_price_batch' => 250,
                ],
                // Woo ile aynı: webhook VAR (`X-Shopify-Hmac-Sha256`).
                'supports_webhooks' => true,
                'is_active' => true,
            ],
        );

        // BEŞİNCİ KANAL — el yapımı/vintage pazarı. V3.0 · Faz 3 · §11.
        //
        // ⚠️ `is_active = false` — §05'in 12 adımlı listesinde ADIM 1.
        // Slice 3.1 yalnızca bağlantı/kimlik/sağlık katmanını yazdı;
        // katalog, stok, fiyat ve sipariş HENÜZ YOK. Kanal açılsaydı
        // satıcı bağlanır, ürün göndermeye çalışır ve hiçbir yetenek
        // bulunmadığı için hepsi sessizce hiçbir şey yapmazdı.
        //
        // ⚠️ ETSY'NİN VERİ MODELİ ÜÇ SEVİYELİDİR ve ADLAR TERSTİR (§11.1):
        // Etsy'nin "Listing"i bizim ÜRÜNÜMÜZ, Etsy'nin "Product"ı bizim
        // VARYANTIMIZDIR. Dönüşüm MAPPER'da yapılır, çekirdek model
        // DEĞİŞMEZ — Etsy'nin variation modelini Core'a zorlamak, altı
        // kanalın beşinde anlamsız bir seviye açardı.
        $this->upsert(
            ['code' => 'etsy'],
            [
                'name' => 'Etsy',
                'kind' => 'marketplace',
                'adapter_class' => 'App\\Domain\\Channels\\Adapters\\Etsy\\EtsyAdapter',
                'capabilities' => [
                    // SLICE SLICE AÇILIR — §04'ün matrisi V3 HEDEFİDİR,
                    // bugünkü durum DEĞİL. İlan edilen ama çalışmayan
                    // yetenek panelde çalışmayan sekme demektir (§05).
                    'catalog' => true,          // slice 3.4 ✓
                    // ⚠️ İÇE AKTARMA AYRI BİR YETENEKTİR ve HENÜZ YOK:
                    // `SupportsCatalogImport` "kanalda ne var ki bende
                    // YOK" sorusunu sorar; `SupportsCatalog`'un okuma
                    // metotları YEREL kayıttan başlar. İkisi karıştırılsa
                    // panel çalışmayan bir sekme gösterirdi.
                    'catalog_import' => false,
                    'inventory' => true,        // slice 3.5 ✓
                    'pricing' => true,          // slice 3.6 ✓
                    'orders' => true,           // slice 3.7 ✓
                    'taxonomy' => true,         // slice 3.3 ✓
                    // ⚠️ ONAY SÜRECİ YOKTUR (§11.5) — Etsy'de ilan
                    // yayınlanır yayınlanmaz canlıdır. Açılsaydı panelde
                    // HİÇ DOLMAYACAK bir sekme belirirdi.
                    'approval' => false,
                    // ⚠️ `SupportsFulfillment` UYGULANMADI. §11.4 ondan
                    // söz ediyor ama §27'nin slice tablosunda kendi
                    // satırı YOK; ilan edilip yazılmasaydı panelde
                    // ÇALIŞMAYAN bir sekme açardı (§05). Bilinçli bir
                    // açık madde ve `EtsyAdapterTest` bunu
                    // `assertNotInstanceOf` ile korur.
                    //
                    // AYRI KONU — İADE: Etsy iade için uç nokta VERMİYOR
                    // (§11.4 · dürüst sınır). Satıcı iadeyi panelden
                    // işler, yoklama bunu `updated` görür ve stok
                    // hareketi ÜRETMEZ; `returned` sayılsaydı satılmış
                    // stok geri eklenir ve bakiye bozulurdu.
                    'fulfillment' => false,
                ],
                'rate_limit_profile' => [
                    // 10 istek/sn (§21). ASIL SINIR GÜNLÜK KOTADIR:
                    // 10.000 istek/gün, HESAP BAŞINA. Envanter yazma ilan
                    // başına ayrı çağrı gerektirdiği için (§11.3) bu
                    // gerçek bir TAVANDIR ve 5.000+ ürünlü mağazalarda
                    // AŞILIR — §21'de açıkça kayıtlı bir ölçek sınırı.
                    // ⚠️ ANAHTAR ADI `requests_per_second` (sözleşme).
                    'strategy' => 'token_bucket',
                    'requests_per_second' => 10,
                    'window_seconds' => 1,
                    'burst_capacity' => 10,
                    // ⚠️ İLAN BAŞINA **1** (§11.3): envanter uç noktası
                    // tek ilanı adresler ve o ilanın TÜM varyantlarını
                    // tek gövdede ister. Performans sorunu değil,
                    // KANALIN ŞEKLİ.
                    'max_inventory_batch' => 1,
                    'max_price_batch' => 1,
                ],
                // ⚠️ WEBHOOK YOKTUR (§11.4) — sipariş YOKLAMAYLA gelir
                // (Trendyol kalıbı). Bayrak `true` olsaydı yoklama turu
                // `supports_webhooks` kapısında bu kanalı ATLAR ve
                // siparişler HİÇ GELMEZDİ.
                'supports_webhooks' => false,
                'is_active' => false,
            ],
        );

        // ─────────────────────────────────────────────────────── eBay
        //
        // ALTINCI KANAL (§13 · Faz 4). Slice 4.1 ✓ (OAuth + token
        // yenileme), slice 4.2 SÜRÜYOR (bağlantı + politika seçimi).
        //
        // ⚠️ eBay'İN YAYIN MODELİ ÜÇ ADIMLIDIR (§13.1) ve bu, V3'ün tek
        // çekirdek arayüz eklemesinin sebebidir: inventory item → offer
        // → published listing. Ara başarısızlık kurtarılamaz olduğu için
        // `SupportsCatalog` YETMEZ ve `SupportsOfferLifecycle` gerekir
        // (slice 4.3) — o yüzden `catalog` bugün KAPALIDIR.
        $this->upsert(
            ['code' => 'ebay'],
            [
                'name' => 'eBay',
                'kind' => 'marketplace',
                'adapter_class' => 'App\\Domain\\Channels\\Adapters\\Ebay\\EbayAdapter',
                'capabilities' => [
                    // ⚠️ HEPSİ KAPALI ve bu DOĞRUDUR — slice 4.1'de
                    // yazılan TEK şey kimlik/sağlık/token katmanıdır.
                    //
                    // Bayrak `true` + arayüz YOK = panelde ÇALIŞMAYAN
                    // sekme (§05). Ters yön daha sinsidir ve projede ÜÇ
                    // KEZ yaşandı (Etsy `pricing`/`orders`, WooCommerce
                    // `catalog_import`): arayüz VAR ama bayrak `false`
                    // ise satıcı çalışan özelliği HİÇ GÖREMEZ.
                    // `ChannelTypeSeederTest` ikisini `instanceof` ile
                    // karşılaştırır — YENİ SLICE YETENEK AÇTIĞINDA BU
                    // SATIRLAR DA GÜNCELLENİR.
                    // ⚠️ `catalog` KALICI OLARAK `false` ve bu bir
                    // eksiklik DEĞİLDİR. `SupportsCatalog` yayını TEK
                    // ÇAĞRI varsayar; eBay'de yayın ÜÇ ADIMDIR ve o
                    // arayüz HİÇ UYGULANMAYACAK (§03 · Delta 1).
                    // `true` yazılsaydı `ChannelTypeSeederTest`
                    // KIRILIRDI — bayrak⇄arayüz eşleşmesi o testin
                    // koruduğu tek şeydir.
                    'catalog' => false,
                    // ⚠️ ÜRÜN GÖNDERME YETENEĞİ BU ANAHTARDAN GÖRÜNÜR
                    // (slice 4.4 ✓). Açılmasaydı zincir çalışır ama
                    // `ProductChannelController` eBay'i ELER ve satıcı
                    // çalışan özelliği panelde HİÇ GÖREMEZDİ — Etsy
                    // `pricing`/`orders` ve Woo `catalog_import`
                    // hatasının aynısı.
                    'offer_lifecycle' => true,
                    'catalog_import' => false,   // kapsam DIŞI
                    'inventory' => false,        // slice 4.6
                    'pricing' => false,          // slice 4.6
                    'orders' => false,           // slice 4.7
                    // ⚠️ ASPECT'LER TRENDYOL'UN ZORUNLU ÖZNİTELİKLERİNİN
                    // KARŞILIĞIDIR ve `PrerequisiteGate` DEĞİŞMEDEN
                    // çalışır (§13.5). Ağaç MARKETPLACE başınadır.
                    'taxonomy' => true,          // slice 4.5 ✓
                    // ⚠️ ONAY SÜRECİ YOKTUR — eBay'de ilan yayınlanır
                    // yayınlanmaz canlıdır (Etsy ile aynı). Açılsaydı
                    // panelde HİÇ DOLMAYACAK bir sekme belirirdi.
                    'approval' => false,
                    'fulfillment' => false,      // slice 4.8
                ],
                'rate_limit_profile' => [
                    // ⚠️ eBay'İN ASIL SINIRI GÜNLÜKTÜR (~5.000/gün/uç
                    // nokta, §21) ve `ChannelRateLimiter` günlük kova
                    // TUTMAZ — kova saniyeliktir ve esnetilseydi tek bir
                    // yoğun tur bütün günü kilitlerdi (Etsy kararının
                    // aynısı). Saniyelik profil yalnızca ani yığılmayı
                    // yumuşatır; günlük tavanı `dailyRequestQuota()`
                    // ÖLÇER (§25).
                    // ⚠️ ANAHTAR ADI `requests_per_second` (sözleşme —
                    // `requests` yazılsaydı profil sessizce 5/sn'ye
                    // düşerdi, `35b0209`).
                    'strategy' => 'token_bucket',
                    'requests_per_second' => 5,
                    'window_seconds' => 1,
                    'burst_capacity' => 5,
                    // ⚠️ 25 — eBay'in KATI sınırı (§13.4). Stok ve fiyat
                    // AYNI çağrıda gider (Hepsiburada gibi, Trendyol'un
                    // tersi) ve tek uç nokta ikisini de taşır, bu yüzden
                    // iki sayı da AYNIDIR.
                    'max_inventory_batch' => 25,
                    'max_price_batch' => 25,
                ],
                // ⚠️ SİPARİŞ WEBHOOK'U YOKTUR (§13.6). eBay Notification
                // API SUNAR ama o hesap kapanma ve politika ihlali
                // bildirir — sipariş için DEĞİLDİR. `true` olsaydı
                // yoklama turu bu kanalı `supports_webhooks` kapısında
                // ATLAR ve siparişler HİÇ GELMEZDİ.
                'supports_webhooks' => false,
                // ⚠️ KANAL KAPALI DOĞAR (§05 · adım 1). Açılma kararı
                // slice 4.9'da, GERÇEK MAĞAZA doğrulamasından SONRA.
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
