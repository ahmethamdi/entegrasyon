<?php

declare(strict_types=1);

namespace App\Domain\Channels\Support;

use App\Domain\Channels\Adapters\Ebay\EbayAdapter;
use App\Domain\Channels\Adapters\Etsy\EtsyApp;
use App\Domain\Channels\Adapters\Hepsiburada\HepsiburadaAdapter;
use App\Domain\Channels\Adapters\Ikas\IkasAdapter;
use App\Domain\Channels\Adapters\N11\N11Adapter;
use App\Domain\Channels\Adapters\Pazarama\PazaramaAdapter;
use App\Domain\Channels\Adapters\Shopify\ShopifyAdapter;
use App\Domain\Channels\Adapters\Ticimax\TicimaxAdapter;
use App\Domain\Channels\Adapters\Trendyol\TrendyolAdapter;
use InvalidArgumentException;

/**
 * Bağlanma formunun KANAL BAŞINA alan tanımı — TEK KAYNAK.
 *
 * `PanelConnectSupport`'un yerini alır: o sınıf "bu kanal panelden
 * bağlanamıyor" diyen GEÇİCİ bir dürüstlük katmanıydı ve bu sınıf onun
 * cevabını verir — kanal HANGİ alanları ister.
 *
 * ═════════════════════════════════════════════════════════════════════
 * KİMLİK BİÇİMİ KANALIN GERÇEĞİDİR, CONTROLLER'IN DEĞİL
 * ═════════════════════════════════════════════════════════════════════
 * Woo `consumer_key`/`consumer_secret` ister, Trendyol `api_key`/
 * `api_secret`, Shopify TEK bir Admin API token'ı, Etsy ise formdan HİÇ
 * anahtar İSTEMEZ (tarayıcı Etsy'ye yönlendirilir ve token oradan gelir).
 *
 * Bu bilgi burada TEK YERDE toplanır; controller ondan doğrulama kuralı
 * üretir, Vue ondan alan çizer. `if ($code === 'shopify')` YAZILMAZ —
 * projenin "yetenekler tip sisteminden okunur, panelde kanal adı
 * kontrol edilmez" kuralının bağlama formundaki karşılığı. İkiye
 * bölünseydi biri güncellenir, öteki sessizce eski kalırdı: form alanı
 * sorar ama doğrulama reddeder — ya da tersi, form sormaz ama kasaya
 * boş kimlik yazılır ve istek SESSİZCE kimliksiz gider (`97a7eb7`).
 *
 * ─────────────────────────────────────────────────────────────────────
 * ⚠️ İKİ TÜR ALAN VARDIR ve AYRI KOLONLARA GİDER — KARIŞMAZ
 * ─────────────────────────────────────────────────────────────────────
 * **SIR** (`secretFields`) → `channel_credentials`, ŞİFRELİ kasa.
 * **KİMLİK** (`identityFields`) → `channel_connections.settings`,
 * ŞİFRESİZ jsonb ve panele Inertia prop'u olarak GİDER (§19 · madde 4:
 * KİMLİK ≠ SIR).
 *
 * Yön karıştırılırsa iki ayrı felaket olur:
 *   • Sır `settings`'e düşerse tarayıcıda görünür ve kasa şifrelemesinin
 *     tüm anlamı kaybolur.
 *   • Kimlik kasaya düşerse adapter onu `settings` içinde ARAR ve
 *     BULAMAZ: Shopify "konum seçilmedi", Etsy "mağaza seçilmedi" der ve
 *     bağlantı sonsuza kadar `pending` kalır.
 *
 * ─────────────────────────────────────────────────────────────────────
 * ⚠️ ALAN ADLARI SÖZLEŞMEDİR — BEKLENEN METİNLE SINANIR
 * ─────────────────────────────────────────────────────────────────────
 * Sır adları `ChannelHttpClient::BASIC_AUTH_KEY_PAIRS` ve adapter'ların
 * okuduğu anahtarlarla eşleşmek ZORUNDADIR; kimlik adları da
 * `ShopifyAdapter::LOCATION_KEY` / `EtsyAdapter::SHOP_ID_KEY` ile. Bu
 * yüzden sabitler burada YENİDEN YAZILMAZ, adapter'dan OKUNUR: yeniden
 * adlandırma ikisini birlikte taşır. Sır adları için böyle bir sabit
 * yoktur ve `ChannelConnectFormTest` onları beklenen metinle sınar —
 * `ChannelTypeSeeder`'ın yetenek sürüklenmesi hatasının (slice 3.8)
 * aynı biçimi.
 */
final class ChannelConnectForm
{
    /**
     * Hesap kimliği OAuth dönüşünde öğrenilen kanalın GEÇİCİ kimliği
     * (`accountFromOauth`). Callback asıl kimliği (Etsy `shop_id`) yazar;
     * bu önekle kalan bağlantı yetkilendirilmemiştir.
     */
    public const PENDING_ACCOUNT_PREFIX = 'oauth-bekliyor:';

    /**
     * Kanal başına alan tanımları.
     *
     * `oauth` TRUE ise form sır SORMAZ ve kaydettikten sonra satıcıyı
     * kanalın yetkilendirme ekranına YÖNLENDİRİR: akış tersine döner,
     * bağlantı satırı önce açılır ve kimlik ancak satıcı onayladıktan
     * SONRA gelir (`EtsyOAuthController`).
     *
     * @var array<string, array{
     *     secrets: array<int, array{name: string, label: string, hint?: string, masked?: bool, placeholder?: string}>,
     *     identity: array<int, array{name: string, label: string, hint?: string, placeholder?: string, rules?: list<string>, optional?: bool}>,
     *     account?: string,
     *     oauth: bool,
     *     help?: string,
     *     install_only?: bool,
     * }>
     */
    private const CHANNELS = [
        'woocommerce' => [
            'secrets' => [
                ['name' => 'consumer_key', 'label' => 'Consumer key', 'placeholder' => 'ck_...'],
                ['name' => 'consumer_secret', 'label' => 'Consumer secret', 'placeholder' => 'cs_...', 'masked' => true],
            ],
            'identity' => [],
            'oauth' => false,
            'help' => 'WooCommerce yönetiminde Ayarlar → Gelişmiş → REST API '
                .'altından Okuma/Yazma izinli bir anahtar üret.',
        ],

        'trendyol' => [
            'secrets' => [
                ['name' => 'api_key', 'label' => 'API key', 'placeholder' => ''],
                ['name' => 'api_secret', 'label' => 'API secret', 'placeholder' => '', 'masked' => true],
            ],
            'identity' => [
                [
                    'name' => TrendyolAdapter::SELLER_ID_KEY,
                    'label' => 'Satıcı ID (Cari ID)',
                    'placeholder' => '123456',
                    // ⚠️ YALNIZCA RAKAM. Değer İSTEK YOLUNA girer
                    // (`.../sellers/{id}/...`); `../` ya da `?` taşıyan
                    // bir değer isteği başka bir kaynağa yönlendirirdi.
                    'rules' => ['regex:/^[0-9]+$/'],
                    'hint' => 'Aynı sayfadaki "Satıcı ID" değeri. Bütün '
                        .'Trendyol çağrıları bu kimlik üzerinden yapılır.',
                ],
                [
                    'name' => TrendyolAdapter::INTEGRATOR_NAME_KEY,
                    'label' => 'Entegratör adı (isteğe bağlı)',
                    'placeholder' => TrendyolAdapter::DEFAULT_INTEGRATOR_NAME,
                    'optional' => true,
                    // ⚠️ Değer `User-Agent` başlığına girer; satır sonu
                    // ya da denetim karakteri başlık enjeksiyonu olurdu.
                    'rules' => ['regex:/^[A-Za-z0-9]{1,30}$/'],
                    'hint' => 'Boş bırakırsan "SelfIntegration" gönderilir. '
                        .'Trendyol\'a kayıtlı bir entegratör firmasıysan '
                        .'kayıtlı adını yaz; yanlış ad 403 ile reddedilir.',
                ],
                [
                    'name' => TrendyolAdapter::VAT_RATE_KEY,
                    'label' => 'Varsayılan KDV oranı (isteğe bağlı)',
                    'placeholder' => '20',
                    'optional' => true,
                    // Trendyol'un kabul ettiği oranlar; başka değer kalıcı
                    // `VALIDATION` ile reddedilir.
                    'rules' => ['in:0,1,10,20'],
                    'hint' => 'Boş bırakırsan %20. Trendyol 0, 1, 10 ve 20 kabul eder.',
                ],
                [
                    'name' => TrendyolAdapter::DIMENSIONAL_WEIGHT_KEY,
                    'label' => 'Varsayılan desi (isteğe bağlı)',
                    'placeholder' => '1',
                    'optional' => true,
                    'rules' => ['regex:/^[0-9]{1,4}([.,][0-9]{1,2})?$/'],
                    'hint' => 'Boş bırakırsan 1. Desi hacimden hesaplanır (en × boy × '
                        .'yükseklik / 3000), ağırlık değildir; kargo ücretini belirler.',
                ],
                [
                    'name' => TrendyolAdapter::SHIPMENT_ADDRESS_KEY,
                    'label' => 'Sevkiyat adresi kimliği (isteğe bağlı)',
                    'placeholder' => '',
                    'optional' => true,
                    'rules' => ['regex:/^[0-9]+$/'],
                    'hint' => 'Boş bırakırsan Trendyol\'daki varsayılan sevkiyat adresin kullanılır.',
                ],
                [
                    'name' => TrendyolAdapter::RETURNING_ADDRESS_KEY,
                    'label' => 'İade adresi kimliği (isteğe bağlı)',
                    'placeholder' => '',
                    'optional' => true,
                    'rules' => ['regex:/^[0-9]+$/'],
                    'hint' => 'Boş bırakırsan Trendyol\'daki varsayılan iade adresin kullanılır.',
                ],
            ],
            // ⚠️ HESAP KİMLİĞİ SATICI ID'SİDİR — MAĞAZA ADRESİ SORULMAZ.
            //
            // Trendyol'da tek bir API adresi vardır ve bütün satıcılar
            // onu paylaşır. Adres sorulup host'u hesap kimliği yapılsaydı
            // her Trendyol satıcısı aynı `external_account_id`'ye düşer
            // ve `(type, account)` tekilliği İKİNCİ satıcıyı "bu mağaza
            // başka bir hesaba bağlı" diye reddederdi. Üstelik adres
            // Woo ayrıştırıcısından geçip sonuna `/wp-json/wc/v3`
            // ekleniyordu — panelden bağlanan Trendyol hiç çalışamazdı.
            'account' => TrendyolAdapter::SELLER_ID_KEY,
            'oauth' => false,
            'help' => 'Trendyol Satıcı Paneli → Hesap Bilgilerim → Entegrasyon '
                .'Bilgileri altındaki API anahtarı, gizli anahtar ve satıcı ID.',
        ],

        'hepsiburada' => [
            // Resmî dokümana göre (6 Eki 2026): Basic auth = merchantId +
            // Servis Anahtarı, `User-Agent` = HB'ye kayıtlı entegratör
            // kullanıcı adı. Eski kullanıcı adı/parola çifti 15 Ağu 2024'te
            // kapandı. Kanal hâlâ `is_active = false`: gerçek hesapla
            // sınanmadı.
            'secrets' => [
                [
                    'name' => HepsiburadaAdapter::SERVICE_KEY_SECRET,
                    'label' => 'Servis Anahtarı',
                    'placeholder' => '',
                    'masked' => true,
                    'hint' => 'Satıcı paneli → Bilgilerim → Entegrasyon → Entegratör '
                        .'Bilgileri → Entegratörlerim → "Servis Anahtarı".',
                ],
            ],
            'identity' => [
                [
                    'name' => HepsiburadaAdapter::MERCHANT_ID_KEY,
                    'label' => 'Merchant ID',
                    'placeholder' => '00000000-0000-0000-0000-000000000000',
                    // ⚠️ Değer İSTEK YOLUNA girer (`/merchantid/{id}`); yalnız
                    // GUID biçimi kabul edilir.
                    'rules' => ['regex:/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/'],
                    'hint' => 'Satıcı panelindeki mağaza kimliği (GUID). Bütün '
                        .'Hepsiburada çağrıları bu kimlik üzerinden yapılır.',
                ],
                [
                    'name' => HepsiburadaAdapter::INTEGRATOR_KEY,
                    'label' => 'Entegratör kullanıcı adı',
                    'placeholder' => 'firma_dev',
                    // ⚠️ Değer `User-Agent` başlığına girer; satır sonu ya da
                    // denetim karakteri başlık enjeksiyonu olurdu.
                    'rules' => ['regex:/^[A-Za-z0-9_.\-]{1,64}$/'],
                    'hint' => 'Hepsiburada\'nın entegratör yetkilendirmesinde verdiği '
                        .'kullanıcı adı. Her istekte gönderilir; yanlışsa istekler reddedilir.',
                ],
                [
                    'name' => HepsiburadaAdapter::ENVIRONMENT_KEY,
                    'label' => 'Ortam (test / canli)',
                    'placeholder' => HepsiburadaAdapter::ENVIRONMENT_LIVE,
                    'optional' => true,
                    'rules' => ['in:'.HepsiburadaAdapter::ENVIRONMENT_TEST.','.HepsiburadaAdapter::ENVIRONMENT_LIVE],
                    'hint' => 'Hepsiburada yetkiyi önce test ortamında verir. Test '
                        .'bilgileriyle bağlanıyorsan "test" yaz; boş bırakırsan canlı.',
                ],
            ],
            'account' => HepsiburadaAdapter::MERCHANT_ID_KEY,
            'oauth' => false,
            'help' => 'Önce satıcı panelinden entegratör yetkilendirme talebi aç '
                .'(Yardım Merkezi → Talepler → "API ENTEGRASYON – API Entegratör '
                .'Yetkilendirme İşlemleri"). Gelen bilgilerle bağlan.',
        ],

        'ikas' => [
            // SATICININ KENDİ ÖZEL UYGULAMASI (8 Eki 2026): Partner onayı
            // gerekmez. Çift kasaya gider; erişim anahtarı bağlanırken
            // `client_credentials` ile alınır (`token_exchange`).
            'secrets' => [
                [
                    'name' => IkasAdapter::CLIENT_ID_SECRET,
                    'label' => 'Client ID',
                    'placeholder' => '',
                ],
                [
                    'name' => IkasAdapter::CLIENT_SECRET_SECRET,
                    'label' => 'Client Secret',
                    'placeholder' => '',
                    'masked' => true,
                    'hint' => 'ikas bu değeri yalnız bir kez gösterir. Kaybettiysen yeni bir özel uygulama aç.',
                ],
            ],
            'identity' => [
                [
                    'name' => IkasAdapter::STORE_NAME_KEY,
                    'label' => 'Mağaza adı',
                    'placeholder' => 'magazam',
                    // Alt alan adıdır (`{ad}.myikas.com`); sağlık kontrolü
                    // ikas'ın döndürdüğü mağaza adıyla karşılaştırır.
                    'rules' => ['regex:/^[a-z0-9][a-z0-9\-]{0,62}$/'],
                    'hint' => 'Panel adresindeki ad: magazam.myikas.com ise "magazam".',
                ],
                [
                    'name' => IkasAdapter::CURRENCY_KEY,
                    'label' => 'Mağaza para birimi (isteğe bağlı)',
                    'placeholder' => IkasAdapter::DEFAULT_CURRENCY,
                    'optional' => true,
                    'rules' => ['in:TRY,EUR,USD,GBP'],
                    'hint' => 'Boş bırakırsan TRY. Fiyatlar bu para birimiyle gönderilir.',
                ],
            ],
            'account' => IkasAdapter::STORE_NAME_KEY,
            'oauth' => false,
            'token_exchange' => true,
            'help' => 'ikas panelinde Uygulamalar → Uygulamalarım → Özel Uygulamalar → '
                .'Standart Uygulama ile bir uygulama oluştur; ürün, sipariş ve stok için '
                .'okuma/yazma izni ver. Çıkan Client ID ve Client Secret\'ı buraya yapıştır.',
        ],

        'ticimax' => [
            // Mağaza alan adı + panelden üretilen WS yetki kodu (8 Eki 2026).
            // Partner onayı yok; servisler mağazanın kendi alan adında.
            'secrets' => [
                [
                    'name' => TicimaxAdapter::AUTH_CODE_SECRET,
                    'label' => 'WS yetki kodu',
                    'placeholder' => '',
                    'masked' => true,
                    'hint' => 'Ticimax yönetim panelinde "WS Yetki Kodu Yönetimi" sayfasından oluştur. Kod süresizdir; sızarsa panelden sil.',
                ],
            ],
            'identity' => [
                [
                    'name' => TicimaxAdapter::DOMAIN_KEY,
                    'label' => 'Mağaza alan adı',
                    'placeholder' => 'www.magazam.com',
                    // Değer istek ADRESİNE girer; yalnız alan adı kabul edilir
                    // (şema, yol, port yok).
                    'rules' => ['regex:/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/i'],
                    'hint' => 'Mağazanın açıldığı alan adı, https:// olmadan.',
                ],
                [
                    'name' => TicimaxAdapter::CURRENCY_KEY,
                    'label' => 'Mağaza para birimi (isteğe bağlı)',
                    'placeholder' => TicimaxAdapter::DEFAULT_CURRENCY,
                    'optional' => true,
                    'rules' => ['in:TRY,EUR,USD,GBP'],
                    'hint' => 'Boş bırakırsan TRY. Fiyatlar bu para birimiyle gönderilir.',
                ],
            ],
            'account' => TicimaxAdapter::DOMAIN_KEY,
            'oauth' => false,
            'help' => 'Ticimax panelinde WS yetki kodu oluştur ve mağaza alan adınla birlikte gir. '
                .'Web servis erişimi paketinde kapalıysa Ticimax destekten açtırman gerekir.',
        ],

        'n11' => [
            // Mağaza bazlı appKey + appSecret, başlıkta (8 Eki 2026). Partner
            // onayı yok. ⚠️ Adlar `api_key`/`api_secret` DEĞİL: o çift
            // `ChannelHttpClient`'ta Basic auth'a çevrilirdi.
            'secrets' => [
                [
                    'name' => N11Adapter::APP_KEY_SECRET,
                    'label' => 'API anahtarı (appKey)',
                    'placeholder' => '',
                    'hint' => 'Satıcı Ofisi (so.n11.com) → Hesabım → API Hesapları → Yeni Hesap Oluştur. Anahtar ekranda görünür.',
                ],
                [
                    'name' => N11Adapter::APP_SECRET_SECRET,
                    'label' => 'API şifresi (appSecret)',
                    'placeholder' => '',
                    'masked' => true,
                    'hint' => 'N11 API şifresini ekranda göstermez, hesabının e-posta adresine gönderir.',
                ],
            ],
            'identity' => [
                [
                    'name' => N11Adapter::SELLER_NAME_KEY,
                    'label' => 'n11 mağaza adı',
                    'placeholder' => 'magazam',
                    // Yalnız hesap kimliği; istek adresine ya da başlığa GİRMEZ.
                    'rules' => ['regex:/^[\pL\pN][\pL\pN ._\-]{1,63}$/u'],
                    'hint' => 'n11\'deki mağaza adın. Bağlantıyı ayırt etmek için kullanılır; tek API adresini bütün satıcılar paylaşır.',
                ],
            ],
            'account' => N11Adapter::SELLER_NAME_KEY,
            'oauth' => false,
            'help' => 'n11 Satıcı Ofisi → Hesabım → API Hesapları\'ndan bir API hesabı oluştur. '
                .'Anahtarı ekrandan, şifreyi e-postandan alıp buraya gir. n11depom ürün ve siparişleri bu bağlantıya gelmez.',
        ],

        'pazarama' => [
            // Mağaza bazlı clientId + clientSecret (8 Eki 2026), partner onayı
            // yok. Çift kasaya gider; 1 saatlik erişim anahtarı bağlanırken
            // `client_credentials` ile alınır (`token_exchange`). Adlar ikas'la
            // aynı ve `ChannelHttpClient`'ın Basic auth çiftleri arasında YOK.
            'secrets' => [
                [
                    'name' => PazaramaAdapter::CLIENT_ID_SECRET,
                    'label' => 'Client ID',
                    'placeholder' => '',
                ],
                [
                    'name' => PazaramaAdapter::CLIENT_SECRET_SECRET,
                    'label' => 'Client Secret',
                    'placeholder' => '',
                    'masked' => true,
                    'hint' => 'Pazarama bu değeri yalnız bir kez gösterir. Yeni anahtar üretirsen eskisi hemen geçersiz olur.',
                ],
            ],
            'identity' => [
                [
                    'name' => PazaramaAdapter::SELLER_NAME_KEY,
                    'label' => 'Pazarama mağaza adı',
                    'placeholder' => 'magazam',
                    // Yalnız hesap kimliği; istek adresine ya da başlığa GİRMEZ.
                    'rules' => ['regex:/^[\pL\pN][\pL\pN ._\-]{1,63}$/u'],
                    'hint' => 'Pazarama\'daki mağaza adın. Bağlantıyı ayırt etmek için kullanılır; tek API adresini bütün satıcılar paylaşır.',
                ],
                [
                    'name' => PazaramaAdapter::SECRET_CREATED_AT_KEY,
                    'label' => 'API anahtarının üretildiği gün (isteğe bağlı)',
                    'placeholder' => 'YYYY-AA-GG',
                    'optional' => true,
                    'rules' => ['date_format:Y-m-d', 'before_or_equal:today'],
                    'hint' => 'Pazarama API şifresi üretildiği günden 365 gün sonra geçersiz olur. Günü yazarsan bitişe 14 gün kala uyarırız.',
                ],
            ],
            'account' => PazaramaAdapter::SELLER_NAME_KEY,
            'oauth' => false,
            'token_exchange' => true,
            'help' => 'Pazarama satıcı panelinde (isortagim.pazarama.com) Hesabım → Hesap Bilgileri → '
                .'Entegrasyon Bilgileri → "Yeni API Key Üret" ile Client ID ve Client Secret üret ve buraya gir.',
        ],

        'shopify' => [
            // 34PAZAR UYGULAMASI ÜZERİNDEN (OAuth) — 5 Eki 2026.
            //
            // Eskiden satıcı kendi "özel uygulamasını" açıp `shpat_`
            // anahtarı, webhook imza anahtarı ve `gid://shopify/Location/…`
            // değerini YAPIŞTIRIYORDU. 1 Ocak 2026'dan beri Shopify yeni
            // özel uygulama açtırmıyor → yeni mağaza BAĞLANAMIYORDU. Artık
            // satıcı yalnız mağaza adresini yazar, Shopify'da onaylar;
            // anahtar (`ShopifyOAuthController`), konum ve webhook'lar
            // bizim işimiz.
            //
            // ⚠️ PANELDEN BAĞLANMAZ — YALNIZ SHOPIFY'DAN KURULUR (6 Eki 2026).
            // App Store kuralı 2.3.1: "kurulum ya da yapılandırma akışında
            // myshopify adresi veya mağaza alanı elle İSTENEMEZ". Adres
            // soran bu form incelemede ret sebebiydi. Satıcı uygulamayı
            // Shopify'dan kurar (`ShopifyInstallController`); mevcut
            // bağlantının yeniden yetkilendirilmesi kayıtlı adresi kullanır.
            'secrets' => [],
            'identity' => [],
            'oauth' => true,
            'install_only' => true,
        ],

        'etsy' => [
            // ⚠️ FORMDAN HİÇBİR ŞEY İSTENMEZ (7 Eki 2026). Önceden
            // keystring ve shop ID satıcıya soruluyordu: satıcı kendi Etsy
            // geliştirici uygulamasını açmak zorundaydı. Artık uygulama
            // 34Pazar'ın (`EtsyApp`, sunucu ayarı); token'ları ve mağaza
            // kimliğini `EtsyOAuthController::callback()` yazar.
            //
            // Mağaza adresi de SORULMAZ: `www.etsy.com/shop/X` biçiminde
            // hesap kimliği alan adı olurdu ve her satıcı `www.etsy.com`
            // ile çakışırdı. Gerçek kimlik (`shop_id`) OAuth dönüşünde gelir.
            'secrets' => [],
            'identity' => [],
            'oauth' => true,
            'account_from_oauth' => true,
            'app_configured' => [EtsyApp::class, 'configured'],
            'help' => 'Kaydettikten sonra Etsy\'nin yetkilendirme ekranına '
                .'yönlendirileceksin. Anahtar girmene gerek yok — izni '
                .'Etsy üzerinden vereceksin.',
        ],

        'ebay' => [
            // ⚠️ ETSY'DEN AYRILIR: OAUTH AMA SIR DE SORULUR.
            //
            // Etsy'de keystring `settings`'te durur çünkü TEK BAŞINA bir
            // kimliktir (`x-api-key` başlığı) ve sır yoktur. eBay'de
            // `client_id` ile `client_secret` AYRILMAZ bir Basic auth
            // ÇİFTİ oluşturur (§13.3); ikisi farklı kolonlara bölünseydi
            // biri güncellenip öteki eski kalabilir ve token yenileme
            // SESSİZCE kimliksiz giderdi.
            //
            // Bu yüzden ÇİFT KASADADIR ve `oauth` yine `true`'dur:
            // access/refresh token'ları satıcı DEĞİL, OAuth turu yazar.
            'secrets' => [
                [
                    'name' => 'client_id',
                    'label' => 'Uygulama kimliği (App ID / Client ID)',
                    'placeholder' => '',
                    'hint' => 'eBay geliştirici hesabında Application Keys '
                        .'altındaki App ID. Bu bir parola değildir ama '
                        .'Cert ID ile birlikte tek bir kimlik çifti '
                        .'oluşturur ve ikisi birlikte saklanır.',
                ],
                [
                    'name' => 'client_secret',
                    'label' => 'Uygulama sırrı (Cert ID / Client Secret)',
                    'placeholder' => '',
                    'masked' => true,
                    'hint' => 'Aynı sayfadaki Cert ID. Token yenileme bu '
                        .'çiftle yapılır; yanlışsa bağlantı iki saat sonra '
                        .'sessizce ölür.',
                ],
            ],
            'identity' => [
                [
                    'name' => EbayAdapter::RU_NAME_KEY,
                    'label' => 'Yönlendirme adı (RuName)',
                    'placeholder' => 'Ad_Soyad-AppName-PRD-abc123-def456',
                    // ⚠️ eBay'DE `redirect_uri` HAM ADRES DEĞİL BİR
                    // TAKMA ADDIR (§13.3). Gerçek callback adresi eBay
                    // panelinde bu adın altında saklanır; ham adres
                    // gönderilseydi `invalid_request` alınırdı.
                    //
                    // Sabit yazılamaz: RuName satıcının KENDİ eBay
                    // uygulamasına aittir ve kodda sabitlenseydi yalnızca
                    // TEK bir geliştirici hesabı çalışırdı.
                    'hint' => 'eBay geliştirici hesabında User Tokens → '
                        .'Get a Token from eBay via Your Application '
                        .'altındaki RuName değeri. Bu bir adres değil, '
                        .'eBay\'in adres yerine kullandığı takma addır.',
                ],
                [
                    'name' => EbayAdapter::MARKETPLACE_ID_KEY,
                    'label' => 'Pazar yeri (marketplace)',
                    'placeholder' => 'EBAY_DE',
                    // ⚠️ KATEGORİ AĞACI MARKETPLACE BAŞINADIR (§13.5).
                    // Yanlış pazar yeri seçilirse ABD ağacıyla
                    // eşleştirilen bir kategori Almanya'ya gönderilir ve
                    // `VALIDATION` alınır — o hata KALICIDIR.
                    'hint' => 'İlanların yayınlanacağı eBay pazarı '
                        .'(EBAY_DE, EBAY_US, EBAY_GB…). Kategori ağacı '
                        .'pazara göre DEĞİŞİR; yanlış pazar seçilirse '
                        .'ürünler kalıcı doğrulama hatası alır. Birden çok '
                        .'pazarda satıyorsan her biri için ayrı bağlantı '
                        .'açman gerekir.',
                ],
                [
                    'name' => EbayAdapter::MERCHANT_LOCATION_KEY,
                    'label' => 'Stok konumu (merchant location key)',
                    'placeholder' => 'WAREHOUSE-1',
                    'hint' => 'eBay Seller Hub → Account → Business '
                        .'policies altında tanımladığın konumun anahtarı. '
                        .'Offer yaratmada ZORUNLUDUR.',
                ],
                [
                    'name' => EbayAdapter::FULFILLMENT_POLICY_KEY,
                    'label' => 'Kargo politikası kimliği',
                    'placeholder' => '',
                    // ⚠️ ÜÇLÜ EKSİKSE OFFER `VALIDATION` ALIR ve o hata
                    // KALICIDIR — listing "düzeltilemez" damgasıyla ölür.
                    // Bu yüzden sağlık kontrolü üçünü de ŞART KOŞAR ve
                    // formda sorulmasalardı panelden bağlanan hiçbir
                    // bağlantı `active` OLAMAZDI (Shopify'ın
                    // `location_gid` hatasının aynısı).
                    'hint' => 'Seller Hub → Account → Business policies '
                        .'altındaki kargo politikasının kimliği. Üç '
                        .'politika da offer yaratmada zorunludur; eksikse '
                        .'gönderilen her ürün kalıcı hatayla ölür.',
                ],
                [
                    'name' => EbayAdapter::PAYMENT_POLICY_KEY,
                    'label' => 'Ödeme politikası kimliği',
                    'placeholder' => '',
                    'hint' => 'Aynı sayfadaki ödeme politikasının kimliği.',
                ],
                [
                    'name' => EbayAdapter::RETURN_POLICY_KEY,
                    'label' => 'İade politikası kimliği',
                    'placeholder' => '',
                    'hint' => 'Aynı sayfadaki iade politikasının kimliği.',
                ],
            ],
            'oauth' => true,
            'help' => 'Uygulama kimliğini ve sırrını girdikten sonra '
                .'eBay\'in yetkilendirme ekranına yönlendirileceksin. '
                .'Mağaza erişimini orada onaylayacaksın.',
        ],
    ];

    public static function isDefined(string $channelTypeCode): bool
    {
        return isset(self::CHANNELS[$channelTypeCode]);
    }

    /**
     * Kasaya yazılacak alanlar.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function secretFields(string $channelTypeCode): array
    {
        return self::definition($channelTypeCode)['secrets'];
    }

    /**
     * `settings` kolonuna yazılacak alanlar — SIR DEĞİL, KİMLİK.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function identityFields(string $channelTypeCode): array
    {
        return self::definition($channelTypeCode)['identity'];
    }

    /** Kaydettikten sonra kanalın yetkilendirme ekranına gidilir mi? */
    public static function usesOauth(string $channelTypeCode): bool
    {
        return self::definition($channelTypeCode)['oauth'];
    }

    /**
     * Formdaki çift bir ERİŞİM ANAHTARIYLA değiştirilir mi (ikas
     * `client_credentials`)? Öyleyse `ConnectChannel` sağlık kontrolünden
     * ÖNCE ilk anahtarı alır; alınmasaydı kontrol kimliksiz giderdi.
     */
    public static function exchangesToken(string $channelTypeCode): bool
    {
        return self::isDefined($channelTypeCode)
            && (self::definition($channelTypeCode)['token_exchange'] ?? false) === true;
    }

    /** Satıcıya gösterilecek yardım metni. */
    public static function help(string $channelTypeCode): ?string
    {
        return self::definition($channelTypeCode)['help'] ?? null;
    }

    /**
     * Hesap kimliğini taşıyan kimlik alanının adı; mağaza adresinden
     * türüyorsa null.
     *
     * Tek API adresini paylaşan pazaryerlerinde (Trendyol) hesap kimliği
     * adres OLAMAZ — bkz. tanımdaki `account` notu.
     */
    public static function accountField(string $channelTypeCode): ?string
    {
        return self::definition($channelTypeCode)['account'] ?? null;
    }

    /** Form mağaza adresi soruyor mu? Hesap kimliği bir alandan geliyorsa hayır. */
    /** Kanal panelden değil, yalnız kendi platformundan kurularak bağlanır. */
    public static function installOnly(string $channelTypeCode): bool
    {
        return self::isDefined($channelTypeCode)
            && (self::definition($channelTypeCode)['install_only'] ?? false) === true;
    }

    /** Kurulum düğmesinin adresi; panelden bağlanan kanalda null. */
    public static function installUrl(string $channelTypeCode): ?string
    {
        if (! self::installOnly($channelTypeCode)) {
            return null;
        }

        $url = config("services.{$channelTypeCode}.install_url");

        return is_string($url) && $url !== '' ? $url : null;
    }

    public static function asksStoreUrl(string $channelTypeCode): bool
    {
        return self::accountField($channelTypeCode) === null && ! self::accountFromOauth($channelTypeCode);
    }

    /**
     * Hesap kimliği OAuth dönüşünde mi öğreniliyor (Etsy `shop_id`)? Öyleyse
     * bağlantı geçici kimlikle açılır, callback asıl kimliği yazar.
     */
    public static function accountFromOauth(string $channelTypeCode): bool
    {
        return self::isDefined($channelTypeCode)
            && (self::definition($channelTypeCode)['account_from_oauth'] ?? false) === true;
    }

    /**
     * Kanalın 34Pazar uygulaması sunucuda tanımlı mı? Tanımsızsa bağlantı
     * açılmaz: satıcı yetkilendirme ekranına gider, Etsy "bilinmeyen
     * uygulama" der ve geride yarım bir bağlantı kalırdı.
     */
    public static function appConfigured(string $channelTypeCode): bool
    {
        $check = self::isDefined($channelTypeCode) ? (self::definition($channelTypeCode)['app_configured'] ?? null) : null;

        return $check === null || (bool) $check();
    }

    /**
     * Laravel doğrulama kuralları — alan tanımından TÜRETİLİR.
     *
     * Elle yazılsaydı tanım ile kural ayrışır ve form sorduğu bir alanı
     * doğrulamadan geçirir (ya da doğrulamanın istediği bir alanı hiç
     * sormaz) — ikisi de sessiz.
     *
     * @return array<string, array<int, string>>
     */
    public static function validationRules(string $channelTypeCode): array
    {
        $rules = [];

        foreach (self::secretFields($channelTypeCode) as $field) {
            $rules[$field['name']] = ['required', 'string', 'max:255'];
        }

        foreach (self::identityFields($channelTypeCode) as $field) {
            $rules[$field['name']] = [
                // İsteğe bağlı alan boş gelirse `pick()` onu ATLAR ve
                // adapter varsayılanı kullanır.
                ($field['optional'] ?? false) ? 'nullable' : 'required',
                'string', 'max:255', ...$field['rules'] ?? [],
            ];
        }

        return $rules;
    }

    /**
     * Panele gönderilen tanım — alan ADLARI ve etiketleri; değer YOK.
     *
     * @return array<string, mixed>
     */
    public static function present(string $channelTypeCode): array
    {
        if (! self::isDefined($channelTypeCode)) {
            // ⚠️ SESSİZCE BOŞ FORM ÜRETİLMEZ ama EKRAN DA ÇÖKMEZ.
            // Tanımsız kanal panelde "bağlanamıyor" diye görünür; bu,
            // `PanelConnectSupport`'un dürüst uyarısının yerini alan
            // KALICI hâldir ve yeni bir kanal `is_active = true`
            // yapılıp tanımı unutulursa satıcı sebebi görür.
            return [
                'secretFields' => [],
                'identityFields' => [],
                'oauth' => false,
                'help' => null,
                'asksStoreUrl' => true,
                'connectable' => false,
            ];
        }

        // Doğrulama kuralları sunucunun işidir; ekrana gitmez.
        //
        // Etiket, ipucu ve örnek değer BURADA, çalışma anında çevrilir:
        // `CHANNELS` bir sınıf sabitidir ve içinde `__()` çağrılamaz.
        // Anahtar Türkçe metnin kendisidir (`lang/en.json`).
        $forScreen = static fn (array $fields): array => array_map(
            static function (array $field): array {
                $field = array_diff_key($field, ['rules' => true]);

                foreach (['label', 'hint', 'placeholder'] as $key) {
                    if (isset($field[$key]) && $field[$key] !== '') {
                        $field[$key] = __($field[$key]);
                    }
                }

                return $field;
            },
            $fields,
        );

        $help = self::help($channelTypeCode);

        return [
            'secretFields' => $forScreen(self::secretFields($channelTypeCode)),
            'identityFields' => $forScreen(self::identityFields($channelTypeCode)),
            'oauth' => self::usesOauth($channelTypeCode),
            'help' => $help !== null ? __($help) : null,
            'asksStoreUrl' => self::asksStoreUrl($channelTypeCode),
            // Uygulaması sunucuda tanımsız kanal formu göstermez; ekran
            // sebebini ayrıca yazar (`appMissing`).
            'connectable' => self::appConfigured($channelTypeCode),
            'appMissing' => ! self::appConfigured($channelTypeCode),
            'installUrl' => self::installUrl($channelTypeCode),
        ];
    }

    /**
     * @return array{
     *     secrets: array<int, array<string, mixed>>,
     *     identity: array<int, array<string, mixed>>,
     *     oauth: bool,
     *     help?: string,
     * }
     */
    private static function definition(string $channelTypeCode): array
    {
        if (! isset(self::CHANNELS[$channelTypeCode])) {
            // ⚠️ BOŞ DİZİ DÖNMEZ — İSTİSNA FIRLATIR.
            //
            // Boş dönseydi doğrulama kuralı da boş olur, `store()` hiçbir
            // anahtar sormadan kasaya BOŞ bir kimlik yazar ve bağlantı
            // kimliksiz kalırdı: kanal 401 döner, `AUTHENTICATION`
            // KALICI sayılır ve satır "anahtarın yanlış" diyerek ölür —
            // oysa anahtar hiç SORULMAMIŞTIR.
            throw new InvalidArgumentException(
                "`{$channelTypeCode}` kanalı için bağlanma formu tanımı yok. "
                .'Kanal açılırken `ChannelConnectForm::CHANNELS` içine '
                .'kimlik biçimi eklenmelidir.'
            );
        }

        return self::CHANNELS[$channelTypeCode];
    }
}
