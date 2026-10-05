<?php

declare(strict_types=1);

namespace Tests\Feature\Channels;

use App\Domain\Channels\Adapters\Ebay\EbayAdapter;
use App\Domain\Channels\Adapters\Etsy\EtsyAdapter;
use App\Domain\Channels\Adapters\Shopify\ShopifyAdapter;
use App\Domain\Channels\Adapters\Trendyol\TrendyolAdapter;
use App\Domain\Channels\Adapters\WooCommerce\WooCommerceAdapter;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Models\ChannelType;
use App\Domain\Channels\Registry\AdapterRegistry;
use App\Domain\Channels\Support\ChannelConnectForm;
use App\Domain\Channels\Support\CredentialVault;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Bağlanma formunun KANAL BAŞINA dallandırılması — A1 (Shopify) + A2 (Etsy).
 *
 * ⚠️ BU MADDE DOKÜMANIN §13 LİSTESİNDE YOKTUR — kullanıcı onaylı bir panel
 * maddesidir (25 Ağustos: "kanallardan bağlansın bütün platformlar").
 * `PanelConnectSupport`'un yerini alır: o sınıf "bu kanal bağlanamıyor"
 * diyen GEÇİCİ bir dürüstlük katmanıydı ve bu iş bittiğinde kaldırılır.
 *
 * ─────────────────────────────────────────────────────────────────────
 * KİMLİK BİÇİMİ KANALIN GERÇEĞİDİR — CONTROLLER'IN DEĞİL
 * ─────────────────────────────────────────────────────────────────────
 * Woo `consumer_key`/`consumer_secret` ister, Shopify TEK bir Admin API
 * token'ı, Etsy ise hiç anahtar İSTEMEZ (tarayıcı Etsy'ye yönlendirilir).
 * Bu bilgi `ChannelConnectForm` içinde TEK KAYNAKTIR; controller ondan
 * doğrulama kuralı üretir, Vue ondan alan çizer.
 *
 * `if ($code === 'shopify')` YAZILMAZ — projenin "yetenekler tip
 * sisteminden okunur, panelde kanal adı kontrol edilmez" kuralının
 * bağlama formundaki karşılığı. İki yerde yaşasaydı yeni kanal
 * eklendiğinde biri güncellenir, öteki sessizce eski kalırdı: form
 * alanı sorar ama doğrulama onu reddeder ya da tersi.
 */
final class ChannelConnectFormTest extends TestCase
{
    use RefreshDatabase;

    // ══════════════════════════════════════════ sözleşme · alan tanımları

    /**
     * ⚠️ SHOPIFY FORMDAN HİÇBİR ANAHTAR İSTEMEZ — 34Pazar uygulaması (OAuth).
     *
     * 1 Ocak 2026'dan beri Shopify yeni "özel uygulama" açtırmıyor; formun
     * istediği `shpat_` anahtarını YENİ bir mağaza alamıyordu. Form yeniden
     * anahtar sorarsa satıcı Shopify'da olmayan bir değeri arar.
     */
    #[Test]
    public function the_shopify_form_asks_only_for_the_store_address(): void
    {
        $this->assertSame([], ChannelConnectForm::secretFields('shopify'));
        $this->assertSame([], ChannelConnectForm::identityFields('shopify'));
        $this->assertTrue(ChannelConnectForm::usesOauth('shopify'));
        $this->assertTrue(ChannelConnectForm::asksStoreUrl('shopify'));
    }

    /**
     * ⚠️ ETSY FORMDAN HİÇ SIR İSTEMEZ — token'ları OAuth callback'i yazar.
     *
     * İsteseydi satıcı Etsy panelinde OLMAYAN bir "access token" arar ve
     * bulamayınca rastgele bir değer girerdi; o değer kasaya yazılır,
     * ardından OAuth turu üzerine yazar ve arada kalan her çağrı 401
     * alırdı. `keystring` ve `shop_id` ise KİMLİKTİR ve `settings`'te
     * yaşar (§19 · madde 4).
     */
    #[Test]
    public function etsy_asks_for_identity_only_and_never_for_a_secret(): void
    {
        $this->assertSame([], ChannelConnectForm::secretFields('etsy'));

        $this->assertSame(
            [EtsyAdapter::KEYSTRING_KEY, EtsyAdapter::SHOP_ID_KEY],
            array_column(ChannelConnectForm::identityFields('etsy'), 'name'),
        );
    }

    // ────────────────────────────────────────── eBay (slice 4.2 · §13 · §17)

    /**
     * ⚠️ BU TESTİN KORUDUĞU KURAL A MADDESİNİN DERSİDİR: *"sağlık
     * kontrolünün istediği HER kimlik alanı formda SORULMALIDIR."*
     *
     * Shopify'da tam olarak bu kaçırıldı: `location_gid` AYLARCA hiçbir
     * kod yolundan yazılmıyordu, yalnızca testlerde elle tohumlanıyordu.
     * Kanal 52/52 saat "bitmiş" sayılırken **panelden bağlanan hiçbir
     * bağlantı `active` OLAMAZDI.**
     *
     * eBay'de aynı tuzak BEŞ KAT daha geniş: `EbayAdapter::healthCheck()`
     * beş alan birden ŞART KOŞUYOR ve biri eksikse bağlantı `pending`
     * kalır. Liste burada ELLE yazılmaz — adapter'ın sabitlerinden
     * OKUNUR, yani yeniden adlandırma ikisini BİRLİKTE taşır.
     */
    #[Test]
    public function the_ebay_form_asks_for_every_setting_the_health_check_demands(): void
    {
        $asked = array_column(ChannelConnectForm::identityFields('ebay'), 'name');

        foreach ([
            EbayAdapter::MARKETPLACE_ID_KEY,
            EbayAdapter::MERCHANT_LOCATION_KEY,
            ...EbayAdapter::POLICY_KEYS,
        ] as $required) {
            $this->assertContains(
                $required,
                $asked,
                "Sağlık kontrolü `{$required}` istiyor ama form onu SORMUYOR — "
                .'panelden bağlanan hiçbir eBay bağlantısı `active` olamazdı '
                .'(Shopify `location_gid` hatasının aynısı).',
            );
        }
    }

    /**
     * ⚠️ ETSY'DEN AYRILIR: eBay OAUTH AMA SIR DA SORAR.
     *
     * Etsy'de keystring `settings`'te durur çünkü TEK BAŞINA bir
     * kimliktir ve sır yoktur. eBay'de `client_id` ile `client_secret`
     * AYRILMAZ bir Basic auth ÇİFTİ oluşturur (§13.3); farklı kolonlara
     * bölünseydi biri güncellenip öteki eski kalabilir ve token yenileme
     * SESSİZCE kimliksiz giderdi.
     */
    #[Test]
    public function ebay_asks_for_the_client_credential_pair_as_secrets(): void
    {
        $this->assertSame(
            ['client_id', 'client_secret'],
            array_column(ChannelConnectForm::secretFields('ebay'), 'name'),
        );
    }

    /**
     * ⚠️ TOKEN'LAR FORMDAN İSTENMEZ — OAuth turu yazar.
     *
     * `access_token` alanı olsaydı satıcı eBay panelinde OLMAYAN bir
     * değeri arar, rastgele bir şey girer ve o ölü sır OAuth turuna
     * kadar kasada dururdu.
     */
    #[Test]
    public function ebay_never_asks_for_a_token(): void
    {
        $names = array_column(ChannelConnectForm::secretFields('ebay'), 'name');

        $this->assertNotContains('access_token', $names);
        $this->assertNotContains('refresh_token', $names);
        $this->assertTrue(ChannelConnectForm::usesOauth('ebay'));
    }

    /**
     * ⚠️ İSTEMCİ SIRRI `settings`'E DÜŞMEZ.
     *
     * `settings` ŞİFRESİZ jsonb'dir ve panele Inertia prop'u olarak
     * GİDER (§19 · madde 4). `client_secret` oraya düşseydi tarayıcıda
     * görünür ve kasa şifrelemesinin tüm anlamı kaybolurdu.
     */
    #[Test]
    public function the_ebay_client_secret_is_never_an_identity_field(): void
    {
        $identity = array_column(ChannelConnectForm::identityFields('ebay'), 'name');

        $this->assertNotContains('client_secret', $identity);
        $this->assertNotContains('client_id', $identity);
    }

    /**
     * ⚠️ HER ZORUNLU ALAN DOĞRULAMA KURALI ÜRETİR.
     *
     * Kural üretilmeseydi form alanı sorar ama boş gönderim kabul
     * edilir; kasaya/`settings`'e boş değer yazılır ve bağlantı sonsuza
     * kadar `pending` kalırdı.
     */
    #[Test]
    public function every_ebay_field_produces_a_validation_rule(): void
    {
        $rules = ChannelConnectForm::validationRules('ebay');

        foreach ([
            'client_id',
            'client_secret',
            EbayAdapter::MARKETPLACE_ID_KEY,
            EbayAdapter::MERCHANT_LOCATION_KEY,
            ...EbayAdapter::POLICY_KEYS,
        ] as $field) {
            $this->assertArrayHasKey($field, $rules, "`{$field}` doğrulanmıyor.");
            $this->assertContains('required', $rules[$field]);
        }
    }

    /**
     * ⚠️ WOO/TRENDYOL DEĞİŞMEDİ — bu madde bir GENİŞLETMEDİR, yeniden
     * yazım değil.
     *
     * Basic-auth çifti `ChannelHttpClient::BASIC_AUTH_KEY_PAIRS` ile
     * eşleşmek ZORUNDADIR; kayarsa istek kimliksiz gider.
     */
    #[Test]
    public function the_existing_channels_keep_their_key_pair(): void
    {
        $this->assertSame(
            ['consumer_key', 'consumer_secret'],
            array_column(ChannelConnectForm::secretFields('woocommerce'), 'name'),
        );

        $this->assertSame(
            ['api_key', 'api_secret'],
            array_column(ChannelConnectForm::secretFields('trendyol'), 'name'),
        );
    }

    /**
     * ⚠️ TANIMSIZ KANAL SESSİZCE BOŞ FORM ÜRETMEZ.
     *
     * Boş dönseydi yeni bir kanal açıldığında form hiçbir anahtar sormaz,
     * `store()` boş `secrets` ile kasaya yazar ve bağlantı kimliksiz
     * kalırdı — sağlık kontrolü 401 alır ve sebep "anahtarın yanlış"
     * gibi görünürdü. Oysa anahtar hiç sorulmamıştır.
     */
    #[Test]
    public function an_unknown_channel_is_refused_rather_than_silently_empty(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ChannelConnectForm::secretFields('bilinmeyen-kanal');
    }

    /**
     * ⚠️ TANIMI OLAN HER AÇIK KANAL BAĞLANABİLİR OLMALIDIR — ve tersi.
     *
     * `ChannelTypeSeeder`'ın yetenek testinin kardeşi: tohumlanan `is_active`
     * ile form tanımı AYRIŞIRSA satıcı panelde gördüğü kanala bağlanamaz
     * (Etsy + Shopify'ın 25 Ağustos'taki hâli) ya da form olmayan bir
     * kanalı sorar.
     */
    #[Test]
    public function every_active_channel_has_a_connect_form_definition(): void
    {
        $this->seedChannelTypes();

        $active = $this->asSystem(
            fn (): array => ChannelType::query()->where('is_active', true)->pluck('code')->all(),
        );

        $this->assertNotEmpty($active, 'Hiç açık kanal yok — test hiçbir şey sınamıyor.');

        foreach ($active as $code) {
            $this->assertTrue(
                ChannelConnectForm::isDefined($code),
                "`{$code}` kanalı panelde AÇIK ama bağlanma formu onun kimlik "
                .'biçimini bilmiyor — satıcı görür ama bağlanamaz.',
            );
        }
    }

    // ══════════════════════════════════════════════ A1 · Shopify bağlama

    /**
     * ⚠️ SHOPIFY PANELDEN BAĞLANMAZ — App Store kuralı 2.3.1.
     *
     * "Kurulum ya da yapılandırma akışında myshopify adresi elle
     * istenemez." Ekran formu göstermiyor; doğrudan POST da reddedilir,
     * bağlantı açılmaz ve Shopify'a istek gitmez. Mağaza yalnız
     * Shopify'dan kurulumla bağlanır (`ShopifyInstallFlowTest`).
     */
    #[Test]
    public function shopify_cannot_be_connected_by_typing_a_store_address(): void
    {
        [$user] = $this->tenantWithChannels();

        config()->set('services.shopify.client_id', 'istemci-123');
        config()->set('services.shopify.client_secret', 'sir-456');
        Http::fake();

        $this->actingAs($user)
            ->post('/channels', [
                'channel_type_code' => 'shopify',
                'label' => 'Shopify Mağazam',
                'store_url' => 'magaza.myshopify.com',
            ])
            ->assertSessionHasErrors('channel_type_code');

        $this->assertNull($this->connectionFor('shopify'));
        $this->assertNull(session('shopify.oauth.state'));
        Http::assertNothingSent();
    }

    /** Ekran Shopify için alan değil, App Store kurulum bağlantısı alır. */
    #[Test]
    public function the_shopify_form_offers_the_install_link_instead_of_fields(): void
    {
        config()->set('services.shopify.install_url', 'https://apps.shopify.com/34pazar');

        $shopify = ChannelConnectForm::present('shopify');

        $this->assertSame('https://apps.shopify.com/34pazar', $shopify['installUrl']);
        $this->assertSame([], $shopify['secretFields']);
        $this->assertSame([], $shopify['identityFields']);
        $this->assertNull(ChannelConnectForm::present('woocommerce')['installUrl']);
    }

    /**
     * ⚠️ BAŞKA KANALIN ALANLARI KABUL EDİLMEZ.
     *
     * Woo'ya Shopify'ın eski `access_token`'ı gönderilirse istek REDDEDİLİR.
     * Sessizce yok sayılsaydı `consumer_key` eksik kalır, kasaya boş bir
     * kimlik yazılır ve her çağrı 401 alırdı — anahtar yanlış değil, HİÇ
     * gönderilmemiş olurdu.
     */
    #[Test]
    public function the_wrong_channels_fields_are_refused(): void
    {
        [$user] = $this->tenantWithChannels();

        Http::fake();

        $this->actingAs($user)
            ->post('/channels', [
                'channel_type_code' => 'woocommerce',
                'label' => 'Woo',
                'store_url' => 'https://magaza.example.com',
                'access_token' => 'shpat_yanlis',
            ])
            ->assertSessionHasErrors('consumer_key');

        Http::assertNothingSent();
        $this->assertNull($this->connectionFor('woocommerce'));
    }

    // ═════════════════════════════════════════════════ A2 · Etsy bağlama

    /**
     * Etsy bağlantısı KİMLİKLE kurulur ve satıcı Etsy'ye YÖNLENDİRİLİR.
     *
     * ⚠️ AKIŞ TERSTİR: `ConnectChannel` kimliğin ÖNCEDEN elde olduğunu
     * varsayar; OAuth'ta bağlantı satırı önce açılır, token ancak satıcı
     * Etsy'de onayladıktan SONRA gelir. Bu yüzden burada beklenen sonuç
     * `active` DEĞİL, yetkilendirme ekranına yönlendirmedir.
     */
    #[Test]
    public function etsy_creates_a_pending_connection_and_redirects_to_oauth(): void
    {
        [$user] = $this->tenantWithChannels();

        Http::fake();

        $response = $this->actingAs($user)->post('/channels', [
            'channel_type_code' => 'etsy',
            'label' => 'Etsy Mağazam',
            'store_url' => 'magazam.etsy.com',
            EtsyAdapter::KEYSTRING_KEY => 'keystring-abc',
            EtsyAdapter::SHOP_ID_KEY => '12345678',
        ]);

        $connection = $this->connectionFor('etsy');

        $this->assertNotNull($connection, 'Etsy bağlantı satırı hiç açılmadı.');
        $this->assertSame('pending', $connection->status);

        // ⚠️ İDDİA "BİR YERE YÖNLENDİRİLDİ" DEĞİL, HEDEFİN ETSY OLMASIDIR.
        //
        // İlk yazımda burada `channels.etsy.authorize` rotası bekleniyordu
        // ve test YEŞİLDİ — ama o rota POST'tur ve tarayıcı yönlendirmeyi
        // GET olarak izler: istek hiçbir rotaya uymaz, Laravel geri
        // bounce eder ve satıcı Etsy'yi HİÇ GÖRMEZ. `assertRedirect`
        // yalnızca ADRESİ karşılaştırır, yönlendirmeyi İZLEMEZ.
        // GERÇEK TARAYICI ÇALIŞTIRMASINDA bulundu.
        $target = (string) $response->headers->get('Location');

        $this->assertStringStartsWith(
            'https://www.etsy.com/oauth/connect?',
            $target,
            'Satıcı Etsy\'nin yetkilendirme ekranına gitmedi.',
        );
        $this->assertStringContainsString('code_challenge_method=S256', $target);

        // El sıkışma sırları oturuma YAZILDI — callback onları okuyacak.
        // Yazılmasaydı `state` doğrulaması (P0-10) dönüşte HER ZAMAN
        // başarısız olur ve bağlanma akışı hiç tamamlanamazdı.
        $response->assertSessionHas('etsy.oauth.state');
        $response->assertSessionHas('etsy.oauth.code_verifier');
        $response->assertSessionHas('etsy.oauth.connection', $connection->id);
    }

    /**
     * ⚠️ PANELDEN GELEN İSTEK `X-Inertia-Location` İLE GÖNDERİLİR.
     *
     * Form bu akışı bir Inertia XHR'ı olarak başlatır ve XHR bir 302'yi
     * ŞEFFAF olarak izler: tarayıcı Etsy'nin HTML'ini alır, Inertia onu
     * bir sayfa yanıtı sanmaz ve ekranda HAM JSON kalır — satıcı Etsy'yi
     * HİÇ GÖRMEZ ve bağlantısı `pending` asılı kalır. Inertia'nın
     * sözleşmesi 409 + `X-Inertia-Location`'dır.
     *
     * GERÇEK TARAYICI ÇALIŞTIRMASINDA bulundu; `assertRedirect` bir
     * XHR'ın yönlendirmeyi nasıl izlediğini MODELLEMEZ ve önceki hâlde
     * bu testler yeşilken ekran bozuktu.
     */
    #[Test]
    public function an_inertia_request_receives_an_external_location_header(): void
    {
        [$user] = $this->tenantWithChannels();

        Http::fake();

        $response = $this->actingAs($user)
            ->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => ''])
            ->post('/channels', [
                'channel_type_code' => 'etsy',
                'label' => 'Etsy',
                'store_url' => 'magazam.etsy.com',
                EtsyAdapter::KEYSTRING_KEY => 'keystring-abc',
                EtsyAdapter::SHOP_ID_KEY => '12345678',
            ]);

        $response->assertStatus(409);

        $this->assertStringStartsWith(
            'https://www.etsy.com/oauth/connect?',
            (string) $response->headers->get('X-Inertia-Location'),
            'Inertia isteği dış adrese yönlendirilemedi — satıcı Etsy\'yi görmez.',
        );
    }

    /**
     * ⚠️ KEYSTRING VE SHOP_ID `settings`'E YAZILIR — adapter oradan okur.
     *
     * `shop_id` bugüne kadar HİÇBİR kod yolundan yazılmıyordu (yalnızca
     * testlerde elle tohumlanıyordu) ve Etsy'nin sağlık kontrolü onsuz
     * SAĞLIKSIZ döner: "mağaza seçilmedi". Yani OAuth turu kusursuz
     * tamamlansa bile bağlantı `pending` kalırdı.
     */
    #[Test]
    public function the_etsy_identity_reaches_the_settings_column(): void
    {
        [$user] = $this->tenantWithChannels();

        Http::fake();

        $this->actingAs($user)->post('/channels', [
            'channel_type_code' => 'etsy',
            'label' => 'Etsy',
            'store_url' => 'magazam.etsy.com',
            EtsyAdapter::KEYSTRING_KEY => 'keystring-abc',
            EtsyAdapter::SHOP_ID_KEY => '12345678',
        ]);

        $settings = $this->connectionFor('etsy')->settings;

        $this->assertSame('keystring-abc', $settings[EtsyAdapter::KEYSTRING_KEY] ?? null);
        $this->assertSame('12345678', $settings[EtsyAdapter::SHOP_ID_KEY] ?? null);
    }

    /**
     * ⚠️ ETSY BAĞLAMA SIRASINDA KANALA HİÇ İSTEK ATILMAZ ve KASA BOŞ KALIR.
     *
     * Sağlık kontrolü çalıştırılsaydı kimliksiz bir çağrı 401 alır,
     * `last_error` "anahtarın yanlış" der ve satıcı henüz Etsy'ye
     * gitmemişken bağlantısını bozuk sanırdı. Kasaya bir şey yazılsaydı
     * o değer OAuth turundan ÖNCE geçerli olmayan ölü bir sır olurdu.
     */
    #[Test]
    public function connecting_etsy_calls_nothing_and_stores_no_secret(): void
    {
        [$user] = $this->tenantWithChannels();

        Http::fake();

        $this->actingAs($user)->post('/channels', [
            'channel_type_code' => 'etsy',
            'label' => 'Etsy',
            'store_url' => 'magazam.etsy.com',
            EtsyAdapter::KEYSTRING_KEY => 'keystring-abc',
            EtsyAdapter::SHOP_ID_KEY => '12345678',
        ]);

        Http::assertNothingSent();

        $this->assertNull(
            $this->storedSecrets($this->connectionFor('etsy')),
            'Etsy bağlanırken kasaya sır yazıldı — token OAuth turunda gelir.',
        );

        // ⚠️ SAĞLIK KONTROLÜ HİÇ KOŞMADI ve bunun AYIRT EDİCİ İŞARETİ
        // `health_status`'tır — "istek atılmadı" iddiası TEK BAŞINA
        // yetmez: `Http::fake()` altında kontrol koşsa da ağa bir şey
        // gitmez, yalnızca sahte yanıt okunur ve satır SAĞLIKSIZ
        // damgalanırdı. O damga satıcıya "kaydedildi ama kanal cevap
        // vermedi" dedirtir ve satıcı henüz hiçbir anahtar VERMEMİŞKEN
        // anahtarlarını kontrol etmeye çalışırdı.
        $connection = $this->connectionFor('etsy');

        $this->assertSame('unknown', $connection->health_status);
        $this->assertNull($connection->last_error);
    }

    /**
     * ⚠️ EKSİK `shop_id` ALAN HATASIDIR ve bağlantı AÇILMAZ.
     *
     * Açılsaydı satıcı OAuth turunu tamamlar, token kasaya yazılır ve
     * sağlık kontrolü yine "mağaza seçilmedi" derdi — tüm el sıkışma
     * boşa gitmiş olurdu.
     */
    #[Test]
    public function etsy_without_a_shop_id_is_a_field_error(): void
    {
        [$user] = $this->tenantWithChannels();

        $this->actingAs($user)
            ->post('/channels', [
                'channel_type_code' => 'etsy',
                'label' => 'Etsy',
                'store_url' => 'magazam.etsy.com',
                EtsyAdapter::KEYSTRING_KEY => 'keystring-abc',
            ])
            ->assertSessionHasErrors(EtsyAdapter::SHOP_ID_KEY);

        $this->assertNull($this->connectionFor('etsy'));
    }

    /**
     * ⚠️ YENİDEN BAĞLAMA YENİ SATIR AÇMAZ — anahtar yenileme akışı.
     *
     * Etsy'de bu ayrıca REFRESH TOKEN'IN süresi dolduğunda tek çıkış
     * yoludur: satıcı aynı mağazayı yeniden bağlar ve OAuth turunu
     * tekrarlar. Yeni satır açılsaydı `(tenant, type, account)` kısıtı
     * ihlal edilir ve listing'ler eski bağlantıda asılı kalırdı.
     */
    #[Test]
    public function reconnecting_etsy_reuses_the_same_row(): void
    {
        [$user] = $this->tenantWithChannels();

        Http::fake();

        $payload = [
            'channel_type_code' => 'etsy',
            'label' => 'Etsy',
            'store_url' => 'magazam.etsy.com',
            EtsyAdapter::KEYSTRING_KEY => 'keystring-abc',
            EtsyAdapter::SHOP_ID_KEY => '12345678',
        ];

        $this->actingAs($user)->post('/channels', $payload);
        $first = $this->connectionFor('etsy');

        $this->actingAs($user)->post('/channels', [
            ...$payload,
            EtsyAdapter::KEYSTRING_KEY => 'keystring-YENI',
        ]);

        $this->assertSame(
            1,
            $this->asSystem(fn (): int => ChannelConnection::query()
                ->where('channel_type_code', 'etsy')->count()),
        );

        $again = $this->connectionFor('etsy');

        $this->assertSame($first->id, $again->id);
        $this->assertSame('keystring-YENI', $again->settings[EtsyAdapter::KEYSTRING_KEY]);
    }

    /**
     * ⚠️ YENİDEN BAĞLAMA MEVCUT AYARLARI EZMEZ.
     *
     * `ConnectChannel` `settings`'i BİRLEŞTİRİR (`PushListing::
     * adoptRemoteIdentity` kuralının aynısı). Ezseydi yeniden bağlanan
     * satıcı kanalın öğrendiği ayarları (Shopify'da seçili depo
     * `location_gid`) kaybeder ve stok bir daha yazılamazdı.
     */
    #[Test]
    public function reconnecting_preserves_settings_the_form_did_not_send(): void
    {
        [$user, $tenant] = $this->tenantWithChannels();

        Http::fake();
        $form = [
            'channel_type_code' => 'woocommerce',
            'label' => 'Woo',
            'store_url' => 'https://magaza.example.com',
            'consumer_key' => 'ck_test',
            'consumer_secret' => 'cs_test',
        ];

        $this->actingAs($user)->post('/channels', $form);

        // Kanal sonradan bir ayar öğrendi — form onu göndermez.
        $this->asTenant($tenant, function (): void {
            $connection = ChannelConnection::query()
                ->where('channel_type_code', 'woocommerce')->firstOrFail();

            $connection->settings = [...$connection->settings, 'ogrenilmis' => 'deger'];
            $connection->save();
        });

        $this->actingAs($user)->post('/channels', $form);

        $this->assertSame('deger', $this->connectionFor('woocommerce')->settings['ogrenilmis'] ?? null);
    }

    // ══════════════════════════════════════════════ A11 · Trendyol bağlama

    /**
     * ⚠️ TRENDYOL PANELDEN BAĞLANABİLİR ve HESAP KİMLİĞİ SATICI ID'SİDİR.
     *
     * Önceki hâl: form satıcı ID'si sormuyordu (adapter onsuz her çağrıda
     * istisna fırlatır) ve "mağaza adresi" Woo ayrıştırıcısından geçip
     * `base_url`'ün sonuna `/wp-json/wc/v3` ekliyordu. Panelden bağlanan
     * bir Trendyol hesabı HİÇBİR koşulda çalışamazdı ve testler bunu
     * göremedi çünkü hepsi ayarları elle kuruyordu.
     */
    #[Test]
    public function trendyol_connects_with_the_seller_id_as_account_identity(): void
    {
        [$user] = $this->tenantWithChannels();

        Http::fake(['*' => Http::response([], 200)]);

        $this->actingAs($user)->post('/channels', [
            'channel_type_code' => 'trendyol',
            'label' => 'Trendyol',
            'api_key' => 'anahtar',
            'api_secret' => 'gizli',
            TrendyolAdapter::SELLER_ID_KEY => '123456',
        ])->assertSessionHasNoErrors()->assertRedirect('/channels');

        $connection = $this->connectionFor('trendyol');

        $this->assertNotNull($connection);
        $this->assertSame('123456', $connection->external_account_id);
        $this->assertSame('123456', $connection->settings[TrendyolAdapter::SELLER_ID_KEY] ?? null);

        // Woo ayrıştırıcısının izi YOK.
        $this->assertArrayNotHasKey('base_url', $connection->settings);

        $secrets = $this->storedSecrets($connection);
        $this->assertSame('anahtar', $secrets['api_key'] ?? null);
        $this->assertSame('gizli', $secrets['api_secret'] ?? null);
    }

    /**
     * ⚠️ İKİ TRENDYOL SATICISI YAN YANA BAĞLANABİLİR.
     *
     * Hesap kimliği adresin host'u olsaydı ikisi de `apigw.trendyol.com`
     * olur ve ikinci satıcı "bu mağaza başka bir hesaba bağlı" diye
     * reddedilirdi — üstelik başka bir kiracıdaysa.
     */
    #[Test]
    public function two_trendyol_sellers_in_different_tenants_do_not_collide(): void
    {
        [$first] = $this->tenantWithChannels();
        [$second] = $this->tenantWithChannels();

        Http::fake(['*' => Http::response([], 200)]);

        foreach ([[$first, '111'], [$second, '222']] as [$user, $sellerId]) {
            $this->actingAs($user)->post('/channels', [
                'channel_type_code' => 'trendyol',
                'label' => 'Trendyol',
                'api_key' => 'k',
                'api_secret' => 's',
                TrendyolAdapter::SELLER_ID_KEY => $sellerId,
            ])->assertSessionHasNoErrors();
        }

        $accounts = $this->asSystem(fn (): array => ChannelConnection::query()
            ->where('channel_type_code', 'trendyol')
            ->orderBy('external_account_id')
            ->pluck('external_account_id')
            ->all());

        $this->assertSame(['111', '222'], $accounts);
    }

    /**
     * Aynı satıcı ID'si başka kiracıya bağlıysa hata SATICI ID ALANINDA
     * görünür — ekranda olmayan `store_url` alanında kaybolmaz.
     */
    #[Test]
    public function a_seller_id_owned_by_another_tenant_is_a_field_error(): void
    {
        [$first] = $this->tenantWithChannels();
        [$second] = $this->tenantWithChannels();

        Http::fake(['*' => Http::response([], 200)]);

        $payload = [
            'channel_type_code' => 'trendyol',
            'label' => 'Trendyol',
            'api_key' => 'k',
            'api_secret' => 's',
            TrendyolAdapter::SELLER_ID_KEY => '555',
        ];

        $this->actingAs($first)->post('/channels', $payload)->assertSessionHasNoErrors();

        $this->actingAs($second)->post('/channels', $payload)
            ->assertSessionHasErrors(TrendyolAdapter::SELLER_ID_KEY);
    }

    /**
     * ⚠️ SATICI ID'Sİ YALNIZCA RAKAMDIR — değer İSTEK YOLUNA girer.
     */
    #[Test]
    public function a_non_numeric_seller_id_is_refused(): void
    {
        [$user] = $this->tenantWithChannels();

        Http::fake();

        $this->actingAs($user)->post('/channels', [
            'channel_type_code' => 'trendyol',
            'label' => 'Trendyol',
            'api_key' => 'k',
            'api_secret' => 's',
            TrendyolAdapter::SELLER_ID_KEY => '123/../../orders',
        ])->assertSessionHasErrors(TrendyolAdapter::SELLER_ID_KEY);

        $this->assertNull($this->connectionFor('trendyol'));
        Http::assertNothingSent();
    }

    /**
     * Entegratör adı İSTEĞE BAĞLIDIR; girilirse `settings`'e yazılır,
     * başlık enjeksiyonu taşıyabilecek değer reddedilir.
     */
    #[Test]
    public function the_integrator_name_is_optional_and_validated(): void
    {
        [$user] = $this->tenantWithChannels();

        Http::fake(['*' => Http::response([], 200)]);

        $payload = [
            'channel_type_code' => 'trendyol',
            'label' => 'Trendyol',
            'api_key' => 'k',
            'api_secret' => 's',
            TrendyolAdapter::SELLER_ID_KEY => '321',
        ];

        $this->actingAs($user)->post('/channels', [
            ...$payload,
            TrendyolAdapter::INTEGRATOR_NAME_KEY => "Firma\r\nX-Header: 1",
        ])->assertSessionHasErrors(TrendyolAdapter::INTEGRATOR_NAME_KEY);

        // Boş bırakılabilir — adapter varsayılanı kullanır.
        $this->actingAs($user)->post('/channels', [
            ...$payload,
            TrendyolAdapter::INTEGRATOR_NAME_KEY => '',
        ])->assertSessionHasNoErrors();

        $this->assertArrayNotHasKey(
            TrendyolAdapter::INTEGRATOR_NAME_KEY,
            $this->connectionFor('trendyol')->settings,
        );

        $this->actingAs($user)->post('/channels', [
            ...$payload,
            TrendyolAdapter::INTEGRATOR_NAME_KEY => 'Firma34',
        ])->assertSessionHasNoErrors();

        $this->assertSame(
            'Firma34',
            $this->connectionFor('trendyol')->settings[TrendyolAdapter::INTEGRATOR_NAME_KEY] ?? null,
        );
    }

    /** Ekran Trendyol'da adres sormaz, öteki kanallarda sorar. */
    #[Test]
    public function only_address_based_channels_ask_for_a_store_url(): void
    {
        $this->assertFalse(ChannelConnectForm::present('trendyol')['asksStoreUrl']);
        $this->assertTrue(ChannelConnectForm::present('shopify')['asksStoreUrl']);
        $this->assertTrue(ChannelConnectForm::present('woocommerce')['asksStoreUrl']);

        // Doğrulama kuralları tarayıcıya gitmez.
        foreach (ChannelConnectForm::present('trendyol')['identityFields'] as $field) {
            $this->assertArrayNotHasKey('rules', $field);
        }
    }

    // ═══════════════════════════════════════════════════ form ekranı

    /**
     * ⚠️ EKRAN ALAN TANIMLARINI TAŞIR — Vue'da `if (code === '...')` YOK.
     *
     * Taşımasaydı Vue kanal adını kontrol eden bir blok yazmak zorunda
     * kalırdı ve o blok sunucudaki doğrulamadan AYRI yaşardı: biri
     * değiştiğinde form alanı sorar ama doğrulama reddeder (ya da tersi).
     */
    #[Test]
    public function the_create_screen_carries_the_field_definitions(): void
    {
        [$user] = $this->tenantWithChannels();

        $this->actingAs($user)
            ->get('/channels/create')
            ->assertInertia(fn ($page) => $page
                ->component('Channels/Create')
                ->where('channelTypes', function (mixed $types): bool {
                    $byCode = collect($types)->keyBy('code');

                    $shopify = $byCode->get('shopify');
                    $etsy = $byCode->get('etsy');

                    return $shopify !== null
                        && $etsy !== null
                        // İkisi de sır SORMAZ, OAuth'a yönlendirir.
                        && $shopify['secretFields'] === []
                        && $shopify['oauth'] === true
                        && $etsy['secretFields'] === []
                        && $etsy['oauth'] === true;
                }),
            );
    }

    // ──────────────────────────────────────────────────────── yardımcılar

    private function connectionFor(string $code): ?ChannelConnection
    {
        return $this->asSystem(fn (): ?ChannelConnection => ChannelConnection::query()
            ->where('channel_type_code', $code)
            ->first());
    }

    /** @return array<string, mixed>|null */
    private function storedSecrets(?ChannelConnection $connection): ?array
    {
        if ($connection === null) {
            return null;
        }

        return TenantContext::runAsSystem(function () use ($connection): ?array {
            $fresh = ChannelConnection::query()->find($connection->id);

            if ($fresh?->activeCredential()->first() === null) {
                return null;
            }

            return app(CredentialVault::class)->read($fresh);
        });
    }

    /** @return array{0: User, 1: Tenant} */
    private function tenantWithChannels(): array
    {
        $this->seedChannelTypes();

        $user = User::factory()->create();
        $tenant = (new CreateTenant)->run(name: 'Form '.uniqid(), owner: $user);

        return [$user, $tenant];
    }

    private function seedChannelTypes(): void
    {
        $this->asSystem(function (): void {
            ChannelType::query()->updateOrCreate(['code' => 'shopify'], [
                'name' => 'Shopify',
                'kind' => 'storefront',
                'adapter_class' => ShopifyAdapter::class,
                'supports_webhooks' => true,
                'is_active' => true,
            ]);

            ChannelType::query()->updateOrCreate(['code' => 'woocommerce'], [
                'name' => 'WooCommerce',
                'kind' => 'storefront',
                'adapter_class' => WooCommerceAdapter::class,
                'supports_webhooks' => true,
                'is_active' => true,
            ]);

            ChannelType::query()->updateOrCreate(['code' => 'etsy'], [
                'name' => 'Etsy',
                'kind' => 'marketplace',
                'adapter_class' => EtsyAdapter::class,
                'supports_webhooks' => false,
                'is_active' => true,
            ]);

            ChannelType::query()->updateOrCreate(['code' => 'trendyol'], [
                'name' => 'Trendyol',
                'kind' => 'marketplace',
                'adapter_class' => TrendyolAdapter::class,
                'supports_webhooks' => false,
                'is_active' => true,
            ]);
        });

        // Registry önbelleklemez ama açıkça tazeleyelim: yetenek okuması
        // `adapter_class`'a bağlıdır.
        app(AdapterRegistry::class);
    }
}
