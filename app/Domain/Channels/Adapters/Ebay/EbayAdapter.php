<?php

declare(strict_types=1);

namespace App\Domain\Channels\Adapters\Ebay;

use App\Domain\Channels\Contracts\ChannelAdapter;
use App\Domain\Channels\Contracts\DeclaresRequestQuota;
use App\Domain\Channels\Contracts\HealthResult;
use App\Domain\Channels\Contracts\RateLimitProfile;
use App\Domain\Channels\Contracts\RefreshedCredentials;
use App\Domain\Channels\Contracts\SupportsTokenRefresh;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Support\ChannelHttpClient;
use App\Domain\Channels\Support\CredentialVault;
use App\Domain\Sync\Enums\ErrorClass;
use App\Support\Tenancy\TenantContext;
use DateTimeImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use RuntimeException;
use Throwable;

/**
 * eBay Sell API adapter — ALTINCI kanal.
 *
 * V3.0 · §13 · §20 · §21 · v2.2 §7.
 *
 * ─────────────────────────────────────────────────────────────────────
 * KAPSAM — SLICE 4.1
 * ─────────────────────────────────────────────────────────────────────
 * Yazılan: kimlik/başlık katmanı, sağlık kontrolü, hata sınıflandırma,
 * hız sınırı profili ve **token yenileme** (`SupportsTokenRefresh`).
 *
 * HENÜZ YAZILMAYANLAR ve slice'ları: `SupportsOfferLifecycle` (4.3–4.4),
 * `SupportsTaxonomy` (4.5), `SupportsInventory` + `SupportsPricing`
 * (4.6), `SupportsOrders` (4.7), `SupportsFulfillment` (4.8).
 *
 * ⚠️ YETENEK ARAYÜZÜ YAZILMADAN İLAN EDİLMEZ (§05). Uygulanmamış bir
 * arayüz panelde ÇALIŞMAYAN bir sekme açar; `EbayAdapterTest` bunu
 * `assertNotInstanceOf` ile korur ve slice kapandıkça o satır taşınır.
 *
 * ⚠️ KANAL `is_active = false` BAŞLAR ve panelde GÖRÜNMEZ. Açılma
 * kararı 4.9'da, gerçek mağaza doğrulamasından SONRA verilir
 * (Hepsiburada ve Shopify'daki kararın aynısı).
 *
 * ─────────────────────────────────────────────────────────────────────
 * ⚠️ İKİ FARKLI KİMLİK BİÇİMİ VARDIR ve KARIŞTIRILMAZ (§13.3)
 * ─────────────────────────────────────────────────────────────────────
 * API çağrıları  → `Authorization: Bearer {access_token}` (SATICININ
 *                   kimliği; `ChannelHttpClient` kasadan kendisi kurar)
 * Token isteği   → `Authorization: Basic {client_id:client_secret}`
 *                   (UYGULAMANIN kimliği; ADAPTER verir)
 *
 * İkisi karıştırılırsa yenileme isteği ölü token'la gider, 401 alır ve
 * bağlantı "anahtarın yanlış" damgasıyla ölür — oysa anahtar doğrudur.
 * Kapı `ChannelHttpClient::hasAuthorizationHeader()` içindedir ve
 * GENELDİR.
 *
 * ─────────────────────────────────────────────────────────────────────
 * ⚠️ WEBHOOK YOKTUR — SİPARİŞ İÇİN (§13.6)
 * ─────────────────────────────────────────────────────────────────────
 * eBay Notification API SUNAR ama o, hesap kapanma ve politika ihlali
 * bildirir; SİPARİŞ İÇİN DEĞİLDİR. `verifyWebhookSignature()` daima
 * `false` döner (Trendyol ve Etsy'deki kararın aynısı): `true` dönmek
 * eBay adına İMZASIZ SİPARİŞ ENJEKTE etmenin kapısını açardı.
 *
 * `supports_webhooks = false` yazılır ve `PollChannelOrders` kapısı onu
 * okur; `true` olsaydı yoklama turu bu kanalı ATLAR ve siparişler HİÇ
 * GELMEZDİ.
 */
final class EbayAdapter implements ChannelAdapter, SupportsTokenRefresh
{
    use DeclaresRequestQuota;

    /** Sabit hız sınırı — eBay sınırı yanıt gövdesinde BİLDİRMEZ (§21). */
    private const REQUESTS_PER_SECOND = 5;

    /**
     * `settings` anahtarları (§17 · DB Delta 5).
     *
     * ⚠️ HEPSİ YAPILANDIRMADIR, SIR DEĞİL — `settings` şifrelenmemiş
     * jsonb'dir ve panele Inertia prop'u olarak GİDER (§19 · madde 4:
     * KİMLİK ≠ SIR). Sır olan `client_secret` ve token'lar kasadadır.
     *
     * ⚠️ SABİTLER `public` — `ChannelConnectForm` onları OKUR ve yeniden
     * YAZMAZ. Yeniden adlandırma ikisini BİRLİKTE taşır; iki yerde
     * yazılsalardı form bir adı sorar, adapter başka bir adı arar ve
     * bağlantı sonsuza kadar `pending` kalırdı (`ShopifyAdapter::
     * LOCATION_KEY` kararının aynısı).
     */
    public const MERCHANT_LOCATION_KEY = 'merchant_location_key';

    public const MARKETPLACE_ID_KEY = 'marketplace_id';

    public const SANDBOX_KEY = 'use_sandbox';

    /**
     * Offer için ZORUNLU politika üçlüsü (§17).
     *
     * ⚠️ EKSİKSE OFFER `VALIDATION` ALIR ve o hata KALICIDIR — listing
     * "düzeltilemez" damgasıyla ölür. Bu yüzden sağlık kontrolü üçünün
     * varlığını ŞART KOŞAR ve bağlantı onlarsız `active` OLMAZ.
     */
    public const FULFILLMENT_POLICY_KEY = 'fulfillment_policy_id';

    public const PAYMENT_POLICY_KEY = 'payment_policy_id';

    public const RETURN_POLICY_KEY = 'return_policy_id';

    public const POLICY_KEYS = [
        self::FULFILLMENT_POLICY_KEY,
        self::PAYMENT_POLICY_KEY,
        self::RETURN_POLICY_KEY,
    ];

    public function __construct(
        private readonly ChannelConnection $connection,
        private readonly ChannelHttpClient $client,
    ) {}

    public function connection(): ChannelConnection
    {
        return $this->connection;
    }

    // ---------------------------------------------------------------- sağlık

    /**
     * Satıcının ayrıcalıkları okunur ve gecikme ölçülür.
     *
     * `sell/account/v1/privilege` SEÇİLDİ çünkü en ucuz kimlikli
     * çağrıdır ve hem token'ın geçerliliğini hem `sell.account`
     * scope'unun varlığını BİRLİKTE kanıtlar.
     *
     * ⚠️ YAPILANDIRMA EKSİKSE BAĞLANTI SAĞLIKSIZDIR — ve bu, kanal
     * çalışıyor olsa BİLE geçerlidir. Shopify'ın "konum seçilmemişse
     * sağlıksız" (P1-5) ve Etsy'nin "mağaza seçilmemişse sağlıksız"
     * kurallarının eBay karşılığı, ama BURADA DAHA AĞIR: eksik politika
     * `VALIDATION` üretir ve o KALICIDIR.
     *
     * Sağlıklı sayılsaydı bağlantı `active` olur, satıcı ürün göndermeye
     * başlar ve HER ürün kalıcı hatayla ölürdü — "aktif ama çalışmayan
     * bağlantı en pahalı hata biçimidir" kuralının tam vakası.
     *
     * ⚠️ EKSİK ALAN ADIYLA SÖYLENİR, SAYIYLA DEĞİL. "Üç alan eksik"
     * demek satıcıya ne yapacağını söylemez (eşleştirme ekranındaki
     * "eksik zorunlu öznitelik ADIYLA gösterilir" kuralının aynısı).
     */
    public function healthCheck(): HealthResult
    {
        $missing = $this->missingSettings();

        if ($missing !== []) {
            return HealthResult::unhealthy(
                'eBay bağlantısı eksik yapılandırma taşıyor: '.implode(', ', $missing).'. '
                .'Bu alanlar offer yaratmada ZORUNLUDUR; eksikken gönderilen her '
                .'ürün kalıcı doğrulama hatasıyla ölürdü.'
            );
        }

        $startedAt = hrtime(true);

        try {
            $response = $this->client->get(
                EbayEndpoints::url(EbayEndpoints::PRIVILEGE, sandbox: $this->useSandbox()),
            );

            $response->throw();

            $latency = (int) round((hrtime(true) - $startedAt) / 1_000_000);

            // ⚠️ 200 TEK BAŞINA YETMEZ — gövde beklenen alanı taşımalıdır.
            // Vekil sunucu veya bakım sayfası 200 döndürebilir; alan
            // kontrolü olmasaydı bağlantı "sağlıklı" sayılır ve ilk
            // gerçek çağrıda ölürdü.
            if (! isset($response->json()['sellingLimit'])) {
                return HealthResult::unhealthy(
                    'eBay yanıtı satıcı ayrıcalığı bilgisi taşımıyor.'
                );
            }

            return HealthResult::healthy(latencyMs: $latency);
        } catch (Throwable $e) {
            return HealthResult::unhealthy($e->getMessage());
        }
    }

    // ------------------------------------------------------------ hız sınırı

    /**
     * Sabit profil — eBay saniyelik sınırı BİLDİRMEZ (§21).
     *
     * ⚠️ eBay'İN SINIRI GÜNLÜKTÜR (~5.000/gün/uç nokta), saniyelik
     * DEĞİL. `ChannelRateLimiter` günlük kova TUTMAZ — kova saniyeliktir
     * ve esnetilseydi tek bir yoğun tur bütün günü kilitlerdi (Etsy'deki
     * kararın aynısı). Saniyelik profil yalnızca ani yığılmayı
     * yumuşatır; günlük tavan `dailyRequestQuota()` ile ÖLÇÜLÜR (§25).
     *
     * Trendyol'da sınır yanıt başlığından öğrenilir; eBay'de öğrenilecek
     * bir başlık YOKTUR ve öğrenme kodu yazmak hiç çalışmayan, hiç
     * sınanamayan bir yol bırakırdı (Hepsiburada kararının aynısı).
     */
    public function rateLimitProfile(): RateLimitProfile
    {
        $profile = $this->connection->channelType?->rate_limit_profile;

        return is_array($profile) && $profile !== []
            ? RateLimitProfile::fromArray($profile)
            : new RateLimitProfile(
                requestsPerSecond: self::REQUESTS_PER_SECOND,
                burstCapacity: self::REQUESTS_PER_SECOND,
            );
    }

    // -------------------------------------------------------- sınıflandırma

    /**
     * eBay hatasını çekirdeğin anladığı sınıfa çevirir (§21).
     *
     * SINIFLANDIRMA BURADA, KARAR ÇEKİRDEKTE (`RetryPolicy`).
     *
     * ⚠️ `25xxx` İŞ KURALI HATALARI KALICIDIR (§21) — HTTP durumu ne
     * olursa olsun. eBay bu aileyi 400 ile de 500 ile de döndürebilir ve
     * yalnızca duruma bakılsaydı `25002` (duplicate offer) bir 500
     * gövdesinde GEÇİCİ sayılır, iş sonsuza kadar yeniden denenir ve her
     * denemede aynı duplicate hatası alınırdı.
     *
     * `25002` özellikle kritiktir: "bu SKU için offer ZATEN VAR"
     * demektir ve tekrar denemek DÜZELTMEZ — düzelten şey
     * `channel_metadata`'daki `offer_id`'yi okuyup kaldığı yerden devam
     * etmektir (§13.2 · Delta 1'in varlık sebebi).
     *
     * ⚠️ 401 `AUTHENTICATION` DÖNER ve KALICIDIR — ama bu "anahtar
     * yanlış" demek DEĞİLDİR: token 2 SAATLİKTİR ve büyük olasılıkla
     * yalnızca SÜRESİ DOLMUŞTUR. Kalıcı sayılması doğrudur çünkü yeniden
     * denemek düzeltmez; düzelten şey `credentials:refresh` taramasıdır
     * (§20) ve o 15 dakikada bir koşar.
     */
    public function classifyError(Throwable $e): ErrorClass
    {
        if ($e instanceof ConnectionException) {
            return ErrorClass::NETWORK;
        }

        if (! $e instanceof RequestException) {
            return ErrorClass::NETWORK;
        }

        // İŞ KURALI HATASI DURUMDAN ÖNCE OKUNUR — sıra ÖNEMLİDİR.
        if ($this->hasBusinessRuleError($e)) {
            return ErrorClass::VALIDATION;
        }

        $status = $e->response->status();

        return match (true) {
            $status === 429 => ErrorClass::RATE_LIMITED,
            $status === 401, $status === 403 => ErrorClass::AUTHENTICATION,
            $status === 404 => ErrorClass::NOT_FOUND,
            $status === 409 => ErrorClass::CONFLICT,
            $status === 408 => ErrorClass::TIMEOUT,
            $status >= 500 => ErrorClass::SERVER_ERROR,
            $status >= 400 => ErrorClass::VALIDATION,
            default => ErrorClass::SERVER_ERROR,
        };
    }

    /**
     * Gövdede `25xxx` ailesinden bir hata var mı?
     *
     * eBay hataları `errors[].errorId` altında SAYI olarak gelir.
     * Aralık 25000–25999'dur ve "iş kuralı" ailesidir.
     */
    private function hasBusinessRuleError(RequestException $e): bool
    {
        $errors = $e->response->json('errors');

        if (! is_array($errors)) {
            return false;
        }

        foreach ($errors as $error) {
            $id = is_array($error) ? ($error['errorId'] ?? null) : null;

            if (! is_int($id) && ! (is_string($id) && ctype_digit($id))) {
                continue;
            }

            if ((int) $id >= 25000 && (int) $id <= 25999) {
                return true;
            }
        }

        return false;
    }

    // ------------------------------------------------------------- webhook

    /**
     * ⚠️ SİPARİŞ WEBHOOK'U YOKTUR — DAİMA `false` (§13.6).
     *
     * eBay Notification API sunar ama o hesap kapanma ve politika
     * ihlali bildirir; sipariş için DEĞİLDİR. `true` dönmek eBay adına
     * İMZASIZ SİPARİŞ ENJEKTE etmenin kapısını açardı (Trendyol ve
     * Etsy'deki kararın aynısı).
     *
     * @param  array<string, array<int, string|null>>  $headers
     */
    public function verifyWebhookSignature(string $raw, array $headers): bool
    {
        return false;
    }

    /**
     * ⚠️ BAŞLIKTAN KİMLİK OKUNMAZ — eBay sipariş webhook'u GÖNDERMEZ.
     *
     * Yoklamanın kimliği `{orderId}:{status}` biçiminde ve GÖVDEDEN
     * türetilir (slice 4.7); ikisi KARIŞTIRILMAZ.
     *
     * @param  array<string, array<int, string|null>>  $headers
     */
    public function extractEventId(array $headers): ?string
    {
        return null;
    }

    /** @param array<string, array<int, string|null>> $headers */
    public function extractEventType(array $headers): string
    {
        return 'unknown';
    }

    // ------------------------------------------------------- token yenileme

    /**
     * Access token'ı refresh token ile tazeler (§13.3 · §20 · P0-5).
     *
     * ⚠️ BU METOT KASAYA YAZMAZ — `RefreshedCredentials` DÖNER ve yazmayı
     * `TokenRefresher` yapar (v2.2 · "adapter yan etkisizdir").
     *
     * ⚠️ İSTEK `Basic` KİMLİKLE VE FORM-ENCODED GİDER — İKİSİ DE ZORUNLU.
     * Kasada `access_token` bulunduğu için istemci normalde `Bearer`
     * yazardı; `Authorization` başlığını ADAPTER verdiği için kasa onu
     * EZMEZ (`ChannelHttpClient` · genel kapı). JSON gönderilseydi eBay
     * alanları hiç okumaz, `invalid_request` döner ve sebebi gövdede
     * görünmezdi.
     *
     * ⚠️ REFRESH TOKEN 18 AY SABİT ÖMÜRLÜDÜR ve YENİLEME ONU TAZELEMEZ —
     * Etsy'nin TERSİ. Etsy her yenilemede YENİ refresh token döndürür ve
     * ömür sıfırlanır; eBay'de o alan yanıtta genellikle HİÇ GELMEZ.
     * Körlemesine üzerine yazılsaydı refresh token NULL olur ve bağlantı
     * BİR SONRAKİ turda ölürdü. Bu yüzden yanıttaki değer yoksa ESKİSİ
     * KORUNUR (Etsy'de de aynı koruma var, ama orada gerekçe "yeni değer
     * gelmemiş olabilir", burada "ZATEN gelmez").
     *
     * ⚠️ BAŞARISIZLIKTA İSTİSNA FIRLATILIR, sessiz dönüş YOKTUR:
     * "yenilenemedi" ile "yenilendi" arasındaki fark bağlantının yaşamıdır.
     */
    public function refreshCredentials(): RefreshedCredentials
    {
        $secrets = $this->secrets();
        $refreshToken = $secrets['refresh_token'] ?? null;

        if (! is_string($refreshToken) || $refreshToken === '') {
            throw new RuntimeException(
                'eBay refresh token yok — bağlantı yeniden yetkilendirilmelidir.'
            );
        }

        $response = $this->client->post(
            endpoint: EbayEndpoints::url(EbayEndpoints::TOKEN, sandbox: $this->useSandbox()),
            body: EbayAuth::refreshRequest($refreshToken),
            headers: ['Authorization' => EbayAuth::basicAuthHeader(
                $this->clientId(),
                $this->clientSecret(),
            )],
            asForm: true,
        );

        $response->throw();

        /** @var array<string, mixed> $body */
        $body = $response->json();

        $access = $body['access_token'] ?? null;

        if (! is_string($access) || $access === '') {
            throw new RuntimeException('eBay yenileme yanıtı access token taşımıyor.');
        }

        $newRefresh = $body['refresh_token'] ?? null;

        return new RefreshedCredentials(
            secrets: [
                ...$secrets,
                'access_token' => $access,
                'refresh_token' => is_string($newRefresh) && $newRefresh !== ''
                    ? $newRefresh
                    : $refreshToken,
            ],
            expiresAt: $this->expiryFrom($body),
        );
    }

    /**
     * Süre dolmadan 15 dakika önce yenile.
     *
     * ⚠️ PAY TARAMA SIKLIĞINDAN KÜÇÜK OLAMAZ. Tarama 15 dakikada bir
     * koşar (§20); pay daha KISA olsaydı token iki tur arasında hem
     * "henüz aday değil" hem "artık ölmüş" olabilirdi ve o aralıktaki
     * her çağrı 401 alırdı.
     *
     * ⚠️ ETSY İLE AYNI SAYI, FARKLI BOLLUK. Etsy'nin token'ı 1 saatlik
     * ve 15 dakika ÜÇ deneme hakkı verir; eBay'inki 2 saatlik, yani aynı
     * pay YEDİ deneme demektir. Payı büyütmek CAZİP ama YANLIŞ olurdu:
     * erken yenileme, refresh token'ı gereğinden sık kullanmak demektir
     * ve o token TEK KULLANIMLIK davranan kanallarda risklidir.
     */
    public function refreshLeadSeconds(): int
    {
        return 900;
    }

    /**
     * Token yenileme uç noktası — `token_refresh_failures` bunu süzer (§25).
     *
     * ⚠️ SÜZGEÇ OLMADAN HER 4xx "TOKEN HATASI" SAYILIR. `api_calls`
     * kanala giden HER çağrıyı taşır; süzülmezse başarısız bir stok
     * itmesi "token yenilenemedi" sayılır, satıcıya yeniden
     * yetkilendirme yaptırılır ve gerçek sorun HİÇ görünmez.
     */
    public function tokenEndpointFragment(): ?string
    {
        return EbayEndpoints::TOKEN;
    }

    /**
     * Günlük istek tavanı — uç nokta BAŞINA ~5.000 (§21).
     *
     * ⚠️ SAYI UÇ NOKTA BAŞINADIR, HESAP BAŞINA DEĞİL — Etsy'nin
     * TERSİ. Etsy'de 10.000 tüm hesap için ortaktır ve envanter yazma
     * onu gerçekten TÜKETİR; eBay'de her uç noktanın kendi kovası
     * vardır ve toplu uç nokta 25 offer'ı TEK çağrıda taşır.
     *
     * Yine de TEK bir sayı bildirilir: §25'in metriği bağlantı başına
     * ölçer ve uç nokta kırılımı taşımaz. En dar kova bildirilirse
     * uyarı ERKEN yanar — bu, geç yanmasından iyidir.
     */
    public function dailyRequestQuota(): ?int
    {
        return 5_000;
    }

    // ────────────────────────────────────────────────────────── yardımcılar

    /**
     * Eksik `settings` alanları — ADIYLA (§17).
     *
     * @return list<string>
     */
    private function missingSettings(): array
    {
        $settings = $this->connection->settings;
        $settings = is_array($settings) ? $settings : [];

        $required = [
            self::MERCHANT_LOCATION_KEY,
            self::MARKETPLACE_ID_KEY,
            ...self::POLICY_KEYS,
        ];

        $missing = [];

        foreach ($required as $key) {
            $value = $settings[$key] ?? null;

            if (! is_string($value) || $value === '') {
                $missing[] = $key;
            }
        }

        return $missing;
    }

    /**
     * Sandbox mı? — `settings.use_sandbox` (§13.3).
     *
     * Varsayılan ÜRETİMDİR: bayrak yoksa sandbox'a gitmek, satıcının
     * gerçek mağazası yerine boş bir test hesabına yazmak olurdu ve
     * "senkron başarılı" görünürken hiçbir şey değişmezdi.
     */
    private function useSandbox(): bool
    {
        $settings = $this->connection->settings;

        return is_array($settings) && ($settings[self::SANDBOX_KEY] ?? false) === true;
    }

    /**
     * Uygulamanın eBay App ID'si — SIR DEĞİL ama kasada durur.
     *
     * ⚠️ `client_id` KASADADIR, `settings`'te DEĞİL — ve bu, §19'un
     * "KİMLİK ≠ SIR" kuralından bilinçli bir sapma DEĞİLDİR: değer
     * kimlik olsa da `client_secret` ile AYRILMAZ bir çift oluşturur ve
     * ikisi tek yerde durmazsa biri güncellenip öteki eski kalabilir.
     * Basic auth çifti tek kaynaktan okunur.
     */
    private function clientId(): string
    {
        return $this->requiredSecret('client_id', 'eBay uygulama kimliği (App ID)');
    }

    private function clientSecret(): string
    {
        return $this->requiredSecret('client_secret', 'eBay uygulama sırrı (Cert ID)');
    }

    /**
     * ⚠️ ANAHTAR YOKSA İSTEK HİÇ ATILMAZ.
     *
     * Boş kimlikle giden istek 401 alır, `AUTHENTICATION` KALICI sayılır
     * ve bağlantı "anahtarın yanlış" diyerek ölür — oysa anahtar YOKTUR,
     * yanlış değildir (`97a7eb7` hata biçimi; Hepsiburada ve Etsy'de de
     * aynı kural yazılı).
     */
    private function requiredSecret(string $key, string $label): string
    {
        $value = $this->secrets()[$key] ?? null;

        if (! is_string($value) || $value === '') {
            throw new RuntimeException(
                "{$label} tanımsız — token isteği kimliksiz gider ve kanal 401 "
                .'döner; sebep hiçbir yerde görünmezdi.'
            );
        }

        return $value;
    }

    /**
     * Kasadaki sırlar — AÇIKÇA SİSTEM BAĞLAMINDA.
     *
     * `channel_credentials` kiracıya göre kapsanır ama bu adapter kuyruk
     * işinden ve `runAsSystem()` taramasından da çağrılır; oralarda bağlam
     * YOKTUR. Kapsanmış sorgu istisna fırlatır ve istek SESSİZCE KİMLİKSİZ
     * giderdi (`97a7eb7`).
     *
     * Token yenileme tam olarak böyle bir yerden çağrılır: `TokenRefresher`
     * `runAsSystem()` altında koşar.
     *
     * ⚠️ HATA YUTULMAZ (Etsy'deki kararın aynısı): boş dizi dönmek
     * "refresh token yok" demeye dönüşür ve kasası okunamayan bir
     * bağlantıyı "yeniden yetkilendir" damgasıyla ÖLDÜRÜRDÜ — oysa sorun
     * geçici olabilir. İstisna yükselirse `TokenRefresher` turu işaretler
     * ve SONRAKİ TURDA yeniden dener (§20).
     *
     * @return array<string, mixed>
     */
    private function secrets(): array
    {
        return TenantContext::runAsSystem(
            fn (): array => app(CredentialVault::class)->read($this->connection)
        );
    }

    /**
     * Yanıttaki `expires_in` (saniye) → mutlak an.
     *
     * ⚠️ ALAN YOKSA NULL DÖNER, UYDURULMAZ. Varsayılan bir süre
     * yazılsaydı ("2 saat") ve eBay onu değiştirseydi tarama token'ı ya
     * çok geç ya hiç yenilemez; ikisi de bağlantıyı öldürür. NULL,
     * `TokenRefresher`'a "süre bilinmiyor" der.
     *
     * @param  array<string, mixed>  $body
     */
    private function expiryFrom(array $body): ?DateTimeImmutable
    {
        $seconds = $body['expires_in'] ?? null;

        if (! is_int($seconds) && ! (is_string($seconds) && ctype_digit($seconds))) {
            return null;
        }

        return new DateTimeImmutable('@'.(time() + (int) $seconds));
    }
}
