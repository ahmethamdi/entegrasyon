<?php

declare(strict_types=1);

namespace App\Domain\Channels\Support;

use App\Domain\Channels\Adapters\Ebay\EbayAdapter;
use App\Domain\Channels\Adapters\Etsy\EtsyAdapter;
use App\Domain\Channels\Adapters\Shopify\ShopifyAdapter;
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
     * Kanal başına alan tanımları.
     *
     * `oauth` TRUE ise form sır SORMAZ ve kaydettikten sonra satıcıyı
     * kanalın yetkilendirme ekranına YÖNLENDİRİR: akış tersine döner,
     * bağlantı satırı önce açılır ve kimlik ancak satıcı onayladıktan
     * SONRA gelir (`EtsyOAuthController`).
     *
     * @var array<string, array{
     *     secrets: array<int, array{name: string, label: string, hint?: string, masked?: bool, placeholder?: string}>,
     *     identity: array<int, array{name: string, label: string, hint?: string, placeholder?: string}>,
     *     oauth: bool,
     *     help?: string,
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
            'identity' => [],
            'oauth' => false,
            'help' => 'Trendyol Satıcı Paneli → Hesap Bilgilerim → Entegrasyon '
                .'Bilgileri altındaki API anahtarı ve gizli anahtar.',
        ],

        'hepsiburada' => [
            // ⚠️ BU KANAL `is_active = false` İLE KAPALIDIR ve sebebi
            // form DEĞİL: uç noktaları doğrulanmadı. Tanımı yine de
            // burada durur — kimlik biçimi (basic auth çifti) bu formla
            // UYUMLUDUR ve uç noktalar doğrulandığında kanal açılırken
            // bu satırın da eklenmesi gerektiği unutulurdu.
            'secrets' => [
                ['name' => 'api_key', 'label' => 'Kullanıcı adı', 'placeholder' => ''],
                ['name' => 'api_secret', 'label' => 'Parola', 'placeholder' => '', 'masked' => true],
            ],
            'identity' => [],
            'oauth' => false,
        ],

        'shopify' => [
            // ⚠️ TEK TOKEN — Woo'nun çifti DEĞİL. Shopify custom app
            // kurulumunda satıcı bir Admin API erişim anahtarı üretir
            // (`shpat_...`) ve o tek başına kimliktir. İkinci bir alan
            // sorulsaydı satıcı Shopify panelinde OLMAYAN bir değeri
            // arardı.
            'secrets' => [
                [
                    'name' => 'access_token',
                    'label' => 'Admin API erişim anahtarı',
                    'placeholder' => 'shpat_...',
                    'masked' => true,
                    'hint' => 'Shopify yöneticisinde Ayarlar → Uygulamalar → '
                        .'Uygulama geliştir → API kimlik bilgileri altında üretilir.',
                ],
                [
                    'name' => 'webhook_secret',
                    'label' => 'Webhook imza anahtarı',
                    'masked' => true,
                    'hint' => 'Sipariş webhook\'larının sahiciliği bununla '
                        .'doğrulanır. Girilmezse gelen siparişler REDDEDİLİR.',
                ],
            ],
            'identity' => [
                [
                    'name' => ShopifyAdapter::LOCATION_KEY,
                    'label' => 'Stok konumu (location)',
                    'placeholder' => 'gid://shopify/Location/1234567890',
                    // ⚠️ VARSAYILANI SESSİZCE SEÇMİYORUZ (P1-5 · §06.4):
                    // iki depolu bir satıcının stoğu YANLIŞ DEPOYA
                    // yazılırdı, geri alınamaz ve satıcı bunu ancak
                    // siparişler yanlış depodan çıkınca fark ederdi.
                    'hint' => 'Stok bu konuma yazılır. Shopify yöneticisinde '
                        .'Ayarlar → Konumlar altındaki konumu açtığında adres '
                        .'çubuğundaki sayı konumun kimliğidir; başına '
                        .'gid://shopify/Location/ eklenir. Çok depolu '
                        .'mağazada yanlış konum stoğu yanlış depoya yazar.',
                ],
            ],
            'oauth' => false,
        ],

        'etsy' => [
            // ⚠️ FORMDAN HİÇ SIR İSTENMEZ. Etsy OAuth 2 + PKCE kullanır
            // ve token'ları `EtsyOAuthController::callback()` kasaya
            // yazar. Burada bir "access token" alanı olsaydı satıcı Etsy
            // panelinde OLMAYAN bir değeri arar, rastgele bir şey girer
            // ve o ölü sır OAuth turuna kadar kasada dururdu.
            'secrets' => [],
            'identity' => [
                [
                    'name' => EtsyAdapter::KEYSTRING_KEY,
                    'label' => 'Uygulama anahtarı (keystring)',
                    'placeholder' => '',
                    // Keystring UYGULAMANIN kimliğidir (`x-api-key`) ve
                    // yenilenmez; SIR DEĞİLDİR (§11.2 · iki ayrı kimlik
                    // başlığı).
                    'hint' => 'Etsy geliştirici hesabındaki uygulamanın '
                        .'keystring değeri. Bu bir parola değildir; '
                        .'uygulamanın kimliğidir.',
                ],
                [
                    'name' => EtsyAdapter::SHOP_ID_KEY,
                    'label' => 'Mağaza kimliği (shop ID)',
                    'placeholder' => '12345678',
                    // ⚠️ `shop_id` YOL ÜZERİNDE taşınır (§19) ve sipariş
                    // yoklaması ile katalog okuması onsuz ÇALIŞAMAZ.
                    // Sağlık kontrolü onu bulamazsa bağlantıyı SAĞLIKSIZ
                    // sayar — yani OAuth turu kusursuz tamamlansa bile
                    // bağlantı `pending` kalırdı.
                    'hint' => 'Etsy mağaza yöneticisinde Ayarlar → Bilgiler '
                        .'ve görünüm altında görünen sayısal kimlik. Sipariş '
                        .'ve katalog çağrıları bu kimlik üzerinden yapılır.',
                ],
            ],
            'oauth' => true,
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

    /** Satıcıya gösterilecek yardım metni. */
    public static function help(string $channelTypeCode): ?string
    {
        return self::definition($channelTypeCode)['help'] ?? null;
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
            $rules[$field['name']] = ['required', 'string', 'max:255'];
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
                'connectable' => false,
            ];
        }

        return [
            'secretFields' => self::secretFields($channelTypeCode),
            'identityFields' => self::identityFields($channelTypeCode),
            'oauth' => self::usesOauth($channelTypeCode),
            'help' => self::help($channelTypeCode),
            'connectable' => true,
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
