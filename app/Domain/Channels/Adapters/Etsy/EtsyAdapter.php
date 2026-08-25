<?php

declare(strict_types=1);

namespace App\Domain\Channels\Adapters\Etsy;

use App\Domain\Channels\Contracts\ChannelAdapter;
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
 * Etsy Open API v3 adapter — BEŞİNCİ kanal.
 *
 * V3.0 · §11 · §20 · §21 · v2.2 §7.
 *
 * ─────────────────────────────────────────────────────────────────────
 * KAPSAM — SLICE 3.1 (bağlantı + kimlik + sağlık)
 * ─────────────────────────────────────────────────────────────────────
 * Yazılan: kimlik/başlık katmanı, sağlık kontrolü, hata sınıflandırma,
 * hız sınırı profili, **token yenileme** (`SupportsTokenRefresh`).
 *
 * HENÜZ YAZILMADI (sonraki slice'lar): taksonomi (3.3) · katalog (3.4) ·
 * stok (3.5) · fiyat (3.6) · sipariş yoklaması (3.7). O yetenek
 * arayüzleri BU SINIFTA UYGULANMAZ — ilan edilen ama çalışmayan yetenek
 * panelde çalışmayan sekme demektir (§05) ve "yazılmamış yetenek
 * SESSİZCE BAŞARILI DÖNMEZ" kuralı (v2.2 · §7).
 *
 * ─────────────────────────────────────────────────────────────────────
 * ⚠️ İKİ AYRI KİMLİK BAŞLIĞI VARDIR (§11.2)
 * ─────────────────────────────────────────────────────────────────────
 * `Authorization: Bearer {token}` SATICININ kimliğidir ve YENİLENİR.
 * `x-api-key: {keystring}` UYGULAMANIN kimliğidir ve YENİLENMEZ.
 *
 * İkisi karıştırılırsa yenileme çalışır ama istek yine 401 alır — ve o
 * 401 `AUTHENTICATION` KALICI sayılır, listing'ler "anahtarın yanlış"
 * damgasıyla toplu ölür. Oysa anahtar doğrudur.
 *
 * Bearer'ı `ChannelHttpClient` `access_token` sırrından kendisi kurar;
 * `x-api-key` ADAPTER'dan gelir ve istemci taşır (`if ($channel ===
 * '...')` YAZILMAZ — Hepsiburada'nın `User-Agent` kararının aynısı).
 *
 * ⚠️ ANAHTAR YOKSA İSTEK HİÇ ATILMAZ. Boş `x-api-key` ile giden istek
 * 401 alır ve sebep hiçbir yerde görünmez ("satıcı kimliği yoksa istek
 * atılmaz" kuralı, `356a662`).
 *
 * ─────────────────────────────────────────────────────────────────────
 * ⚠️ WEBHOOK YOKTUR (§11.4)
 * ─────────────────────────────────────────────────────────────────────
 * `verifyWebhookSignature()` DAİMA `false` döner — Trendyol'daki kararın
 * aynısı. `true` dönmek Etsy adına imzasız sipariş enjekte etmenin
 * kapısını açardı. Sipariş YOKLAMAYLA gelir (slice 3.7).
 */
final class EtsyAdapter implements ChannelAdapter, SupportsTokenRefresh
{
    /**
     * Uygulama anahtarının `settings` içindeki yeri.
     *
     * ⚠️ `settings` ŞİFRESİZDİR ve panele Inertia prop'u olarak gider —
     * oraya YALNIZCA keystring yazılır ve o bir SIR DEĞİL, uygulamanın
     * KİMLİĞİDİR (§19 · madde 4: "kimlik ≠ sır"). Access ve refresh
     * token'lar `channel_credentials` kasasındadır.
     */
    public const KEYSTRING_KEY = 'etsy_keystring';

    /** Mağaza kimliğinin `settings` içindeki yeri — yol üzerinde taşınır. */
    public const SHOP_ID_KEY = 'shop_id';

    /**
     * Hız sınırı — 10 istek/sn (§21).
     *
     * ⚠️ ASIL SINIR GÜNLÜK KOTADIR: 10.000 istek/gün, HESAP BAŞINA.
     * Envanter yazma ilan başına ayrı çağrı gerektirdiği için (§11.3) bu
     * gerçek bir TAVANDIR ve 5.000+ ürünlü mağazalarda AŞILIR — §21'de
     * açıkça kayıtlı bir ölçek sınırıdır.
     *
     * `ChannelRateLimiter` günlük kova TUTMAZ ve bu bilinçlidir: kova
     * saniyeliktir ve günlük kotayı temsil edecek şekilde esnetilseydi
     * tek bir yoğun tur bütün günü kilitlerdi. Günlük kota izleme P2'dir
     * (§21: "%80 aşılınca panelde uyarı").
     */
    private const REQUESTS_PER_SECOND = 10;

    /**
     * ⚠️ ENVANTER PARTİSİ İLAN BAŞINA **1**'DİR (§11.3).
     *
     * Bu bir performans sorunu DEĞİL, KANALIN ŞEKLİDİR: envanter uç
     * noktası tek ilanı adresler ve o ilanın TÜM varyantlarını tek
     * gövdede ister. `InventoryBatchBuilder` operasyonları yine
     * birleştirir; adapter `external_parent_id`'ye göre gruplar ve her
     * grup için AYRI çağrı yapar.
     */
    private const MAX_INVENTORY_BATCH = 1;

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
     * Satıcının kendi kullanıcı kaydı okunur ve gecikme ölçülür.
     *
     * `users/me` SEÇİLDİ çünkü en ucuz kimlikli çağrıdır ve HER İKİ
     * kimlik başlığının doğruluğunu birlikte kanıtlar (§11.2): `x-api-key`
     * yanlışsa 401, `Bearer` yanlışsa yine 401 gelir ve ikisi de burada
     * yakalanır.
     *
     * ⚠️ MAĞAZA KİMLİĞİ SEÇİLMEMİŞSE BAĞLANTI SAĞLIKSIZDIR.
     * `shop_id` yol üzerinde taşınır (§19) ve sipariş yoklaması ile
     * katalog okuması onsuz çalışamaz. Sağlıklı sayılsaydı bağlantı
     * `active` olur, satıcı ürün göndermeye başlar ve her çağrı
     * doldurulmamış yer tutucu istisnasıyla ölürdü — Shopify'ın
     * "konum seçilmemişse sağlıksız" kuralının (P1-5) aynısı.
     */
    public function healthCheck(): HealthResult
    {
        $startedAt = hrtime(true);

        try {
            $response = $this->client->get(
                EtsyEndpoints::url(EtsyEndpoints::ME),
                headers: $this->apiKeyHeader(),
            );

            $response->throw();

            $latency = (int) round((hrtime(true) - $startedAt) / 1_000_000);

            if (! isset($response->json()['user_id'])) {
                return HealthResult::unhealthy(
                    'Etsy yanıtı kullanıcı bilgisi taşımıyor.'
                );
            }

            if ($this->shopId() === null) {
                return HealthResult::unhealthy(
                    'Etsy mağazası (shop) seçilmedi. Mağaza kimliği sipariş '.
                    've katalog çağrılarında yol üzerinde taşınır; seçilmeden '.
                    'hiçbir çağrı yapılamaz.'
                );
            }

            return HealthResult::healthy(latencyMs: $latency);
        } catch (Throwable $e) {
            return HealthResult::unhealthy($e->getMessage());
        }
    }

    // ------------------------------------------------------------ hız sınırı

    /**
     * Sabit profil — Etsy sınırı yanıtta BİLDİRMEZ.
     *
     * Trendyol'da sınır yanıt başlığından öğrenilir ve bağlantıya
     * yazılır; Etsy'de öğrenilecek bir başlık YOKTUR. Öğrenme kodu
     * yazmak, hiç çalışmayan ve hiç sınanamayan bir yol bırakırdı
     * (Hepsiburada'daki kararın aynısı).
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

    public function maxInventoryBatchSize(): int
    {
        return self::MAX_INVENTORY_BATCH;
    }

    // -------------------------------------------------------- sınıflandırma

    /**
     * Etsy hatasını çekirdeğin anladığı sınıfa çevirir (§21).
     *
     * SINIFLANDIRMA BURADA, KARAR ÇEKİRDEKTE (`RetryPolicy`).
     *
     * ⚠️ 401 `AUTHENTICATION` DÖNER ve o KALICIDIR — ama bu Etsy'de
     * "anahtar yanlış" demek DEĞİLDİR: token 1 SAATLİKTİR ve büyük
     * olasılıkla yalnızca SÜRESİ DOLMUŞTUR. Kalıcı sayılması doğru
     * davranıştır çünkü yeniden denemek düzeltmez; düzelten şey
     * `credentials:refresh` taramasıdır (§20) ve o 15 dakikada bir koşar.
     *
     * §21'in açık kuralı: "401 → yenileme dener, sonra kalıcı."
     */
    public function classifyError(Throwable $e): ErrorClass
    {
        if ($e instanceof ConnectionException) {
            return ErrorClass::NETWORK;
        }

        if (! $e instanceof RequestException) {
            return ErrorClass::NETWORK;
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

    // ------------------------------------------------------------- webhook

    /**
     * ⚠️ ETSY WEBHOOK SUNMAZ — DAİMA `false` (§11.4).
     *
     * Trendyol'daki kararın aynısı ve gerekçesi birebir: `true` dönmek
     * Etsy adına İMZASIZ SİPARİŞ ENJEKTE etmenin kapısını açardı. Güvenli
     * taraf "evet" DEMEMEKTİR.
     *
     * Sipariş yoklamayla gelir (slice 3.7) ve olay kimliği
     * `{receipt_id}:{status}` biçiminde TÜRETİLİR.
     *
     * @param  array<string, array<int, string|null>>  $headers
     */
    public function verifyWebhookSignature(string $raw, array $headers): bool
    {
        return false;
    }

    /** @param array<string, array<int, string|null>> $headers */
    public function extractEventId(array $headers): ?string
    {
        return null;                    // yoklama kimliği normalizer türetir
    }

    /** @param array<string, array<int, string|null>> $headers */
    public function extractEventType(array $headers): string
    {
        return 'unknown';
    }

    // ------------------------------------------------------- token yenileme

    /**
     * Access token'ı refresh token ile tazeler (§11.2 · §20 · P0-5).
     *
     * ⚠️ BU METOT KASAYA YAZMAZ — `RefreshedCredentials` DÖNER ve yazmayı
     * `TokenRefresher` yapar (v2.2 · "adapter yan etkisizdir"). Yazsaydı
     * `channel_credentials`'ın tek yazma kapısı olan kasa devre dışı
     * kalır, anahtar sürümü ve maskeleme yüzeyi ikiye bölünürdü.
     *
     * ⚠️ REFRESH TOKEN TEK KULLANIMLIKTIR. Etsy her yenilemede YENİ bir
     * refresh token döner ve ESKİSİNİ İPTAL EDER; dönen değer
     * saklanmazsa bağlantı bir sonraki yenilemede ölür. Bu yüzden sır
     * kümesi TAM olarak yazılır, yalnızca access token değil.
     *
     * Paralel iki yenilemenin ilkini iptal etme tuzağını çekirdek çözer:
     * tarama `FOR UPDATE SKIP LOCKED` ile tek satırı kilitler (§20).
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
                'Etsy refresh token yok — bağlantı yeniden yetkilendirilmelidir.'
            );
        }

        $response = $this->client->post(
            EtsyEndpoints::url(EtsyEndpoints::TOKEN),
            EtsyAuth::refreshRequest($this->keystring(), $refreshToken),
        );

        $response->throw();

        /** @var array<string, mixed> $body */
        $body = $response->json();

        $access = $body['access_token'] ?? null;

        if (! is_string($access) || $access === '') {
            throw new RuntimeException('Etsy yenileme yanıtı access token taşımıyor.');
        }

        // YENİ REFRESH TOKEN DÖNERSE O YAZILIR; dönmezse eskisi korunur.
        // Körlemesine üzerine yazılsaydı yanıtta alan yoksa refresh token
        // NULL olur ve bağlantı sonraki turda ölürdü.
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
     * koşar (§20); pay 15 dakikadan KISA olsaydı token, iki tur arasında
     * hem "henüz aday değil" hem "artık ölmüş" olabilirdi ve o aralıktaki
     * her çağrı 401 alırdı.
     *
     * 1 saatlik token için 15 dakikalık pay ÜÇ DENEME hakkı verir (§20:
     * "sıklık en kısa TTL'in dörtte biridir").
     */
    public function refreshLeadSeconds(): int
    {
        return 900;
    }

    // ────────────────────────────────────────────────────────── yardımcılar

    /**
     * `x-api-key` başlığı — UYGULAMANIN kimliği.
     *
     * ⚠️ ANAHTAR YOKSA İSTEK HİÇ ATILMAZ. Boş başlıkla giden istek 401
     * alır, `AUTHENTICATION` KALICI sayılır ve listing "anahtarın yanlış"
     * diyerek ölür — oysa anahtar YOKTUR, yanlış değildir. Hepsiburada'nın
     * "satıcı kimliği yoksa istek atılmaz" kuralının aynısı.
     *
     * @return array<string, string>
     */
    private function apiKeyHeader(): array
    {
        return ['x-api-key' => $this->keystring()];
    }

    private function keystring(): string
    {
        $settings = $this->connection->settings;
        $keystring = is_array($settings) ? ($settings[self::KEYSTRING_KEY] ?? null) : null;

        if (! is_string($keystring) || $keystring === '') {
            throw new RuntimeException(
                'Etsy uygulama anahtarı (keystring) tanımsız — istek kimliksiz '.
                'gider ve kanal 401 döner; sebep hiçbir yerde görünmezdi.'
            );
        }

        return $keystring;
    }

    /** Mağaza kimliği — yol üzerinde taşınır (§19). */
    private function shopId(): ?string
    {
        $settings = $this->connection->settings;
        $shopId = is_array($settings) ? ($settings[self::SHOP_ID_KEY] ?? null) : null;

        return is_string($shopId) && $shopId !== '' ? $shopId : null;
    }

    /**
     * Kasadaki sırlar — AÇIKÇA SİSTEM BAĞLAMINDA.
     *
     * `channel_credentials` kiracıya göre kapsanır ama bu adapter kuyruk
     * işinden ve `runAsSystem()` taramasından da çağrılır; oralarda bağlam
     * YOKTUR. Kapsanmış sorgu istisna fırlatır ve istek SESSİZCE KİMLİKSİZ
     * giderdi (`97a7eb7`'de yaşanmış hata biçimi).
     *
     * Token yenileme tam olarak böyle bir yerden çağrılır: `TokenRefresher`
     * `runAsSystem()` altında koşar.
     *
     * ⚠️ HATA YUTULMAZ — `ChannelHttpClient` ve `ShopifyAdapter`'ın
     * AKSİNE. Orada boş dizi dönmek doğrudur: istek kimliksiz gider,
     * kanal 401 verir ve durum `last_error`'a yazılır. Burada ise boş
     * dizi "refresh token yok" demeye dönüşür ve bu, kasası okunamayan
     * bir bağlantıyı "yeniden yetkilendir" damgasıyla ÖLDÜRÜRDÜ — oysa
     * sorun geçici olabilir (şifre çözme hatası, bağlam sorunu).
     * İstisna yükselirse `TokenRefresher` turu işaretler ve SONRAKİ TURDA
     * yeniden dener (§20 · "başarısız yenileme bağlantıyı öldürmez").
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
     * yazılsaydı (ör. "1 saat") ve Etsy o süreyi değiştirseydi, tarama
     * token'ı ya çok geç ya hiç yenilemez; ikisi de bağlantıyı öldürür.
     * NULL, `TokenRefresher`'a "süre bilinmiyor" der.
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
