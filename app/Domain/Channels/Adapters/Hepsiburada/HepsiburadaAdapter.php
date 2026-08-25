<?php

declare(strict_types=1);

namespace App\Domain\Channels\Adapters\Hepsiburada;

use App\Domain\Channels\Contracts\AdapterResult;
use App\Domain\Channels\Contracts\ChannelAdapter;
use App\Domain\Channels\Contracts\HealthResult;
use App\Domain\Channels\Contracts\RateLimitProfile;
use App\Domain\Channels\Contracts\SupportsInventory;
use App\Domain\Channels\Contracts\SupportsOrders;
use App\Domain\Channels\Contracts\SupportsPricing;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Support\ChannelHttpClient;
use App\Domain\Channels\Support\CredentialVault;
use App\Domain\Messaging\Models\InboxMessage;
use App\Domain\Orders\Models\Order;
use App\Domain\Sync\Enums\ErrorClass;
use App\Domain\Sync\Models\Listing;
use App\Domain\Sync\Support\InventoryPushBatch;
use App\Domain\Sync\Support\NormalizedOrderEvent;
use App\Domain\Sync\Support\OrderPage;
use App\Domain\Sync\Support\PricePushBatch;
use App\Domain\Sync\Support\RemoteInventorySnapshot;
use App\Domain\Sync\Support\RemotePriceSnapshot;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use RuntimeException;
use Throwable;

/**
 * Hepsiburada kanal adapter'ı — MPOP / listing-external REST API.
 *
 * Mimari Karar Dokümanı v2.2 · §7 (Adapter Architecture).
 *
 * ─────────────────────────────────────────────────────────────────────
 * ⚠️ DOKÜMAN BU KANALI KAPSAM DIŞI BIRAKIYOR — KULLANICI KARARIYLA AÇILDI
 * ─────────────────────────────────────────────────────────────────────
 * §16: "468 saatte dört kanal yüzeysel çalışır; iki kanal kusursuz
 * çalışır... Hepsiburada v2'de Faz 5'te 'belki' idi; v2.1'de kapsam
 * dışına alındı." Kapsam dışı tablosu da "Ay 7" diyor.
 *
 * Faz 4 bittiği (90/90 sa) ve 468 saatlik planın sonuna gelindiği için
 * bu madde kullanıcının açık kararıyla ele alındı. Doküman ihlali
 * DEĞİL — dokümanın kendi zaman çizelgesinin dışına çıkış.
 *
 * ─────────────────────────────────────────────────────────────────────
 * ⚠️ UÇ NOKTALAR RESMÎ DOKÜMANDAN DOĞRULANMADI
 * ─────────────────────────────────────────────────────────────────────
 * `developers.hepsiburada.com` bot isteklerini 403 ile reddediyor.
 * Yollar `HepsiburadaEndpoints` içinde TEK YERDE toplandı ve orada
 * açıkça işaretlendi. **Kanal `is_active = false` ile seed edilir** ve
 * panelde görünmez; doğrulama sırası o dosyada yazılı.
 *
 * ─────────────────────────────────────────────────────────────────────
 * ÜÇ KRİTİK FARK — TRENDYOL'A BENZİYOR AMA AYNI DEĞİL
 * ─────────────────────────────────────────────────────────────────────
 *
 * 1. **`User-Agent` KİMLİK DOĞRULAMANIN PARÇASIDIR.** Hepsiburada
 *    `{merchantId} - {AppName}` biçiminde bir `User-Agent` bekler ve
 *    eksikse **kimlik bilgisi DOĞRU olsa bile 401 döner**. Bu, projede
 *    daha önce yaşanmış (`97a7eb7`) "istek sessizce kimliksiz gitti"
 *    hatasının bir başka biçimidir: anahtar doğru, listing
 *    "anahtarın yanlış" diyerek ölür.
 *
 * 2. **STOK VE FİYAT AYNI YÜKTE GİDER — TRENDYOL'UN TERSİ.** Trendyol'da
 *    "stok yükü fiyat alanı TAŞIMAZ" katı bir kuraldı çünkü orada biri
 *    diğerini SESSİZCE ezerdi. Hepsiburada'nın uç noktası ikisini
 *    birlikte bekliyor ve eksik alanı **sıfır** sayabiliyor — kanal
 *    "stok 0 veya fiyat 0 = satışa kapat" diye yorumluyor. Yani burada
 *    ayırmak, Trendyol'da birleştirmek kadar tehlikelidir.
 *
 *    Bu yüzden `pushInventory` ve `pushPrices` **mevcut değeri okuyup
 *    yükü tamamlamak zorundadır**; §7'nin "mutlak değer gönderilir"
 *    kuralı burada iki alana birden uygulanır.
 *
 * 3. **WEBHOOK VAR** (`X-HB-Signature` HMAC) — Trendyol'un aksine.
 *    Woo ile aynı gelen hat kuralları geçerli: imza HAM GÖVDE üzerinden
 *    ve JSON ayrıştırmadan ÖNCE doğrulanır.
 *
 * ─────────────────────────────────────────────────────────────────────
 * KAPSAM — BU TUR SADECE İSTEMCİ KATMANI
 * ─────────────────────────────────────────────────────────────────────
 * Yazılan: kimlik/başlık katmanı, sağlık kontrolü, hata sınıflandırma,
 * hız sınırı profili, webhook imza doğrulaması.
 *
 * YAZILMAYAN ve AÇIKÇA İSTİSNA FIRLATAN: stok/fiyat itme, sipariş
 * yoklama, katalog aktarımı, taksonomi. §7'nin açık yasağı gereği
 * **SESSİZCE BAŞARILI DÖNMEZLER**: `AdapterResult::success()` dönseydi
 * operasyon tamamlandı sanılır, `synced_version` ilerler ve satır
 * kanalda hiçbir şey değişmemişken "senkron" görünürdü.
 *
 * `SupportsCatalog` ve `SupportsTaxonomy` bu turda UYGULANMADI: yetenek
 * `instanceof` ile okunur ve ilan edilen ama çalışmayan bir yetenek,
 * panelde çalışmayan bir sekme demektir.
 */
final class HepsiburadaAdapter implements ChannelAdapter, SupportsInventory, SupportsOrders, SupportsPricing
{
    /** Satıcı kimliğinin `settings` içindeki yeri. */
    public const MERCHANT_ID_KEY = 'merchant_id';

    /** `User-Agent` başlığındaki uygulama adı. */
    private const APP_NAME = 'Entegrasyon';

    /** Webhook imza başlığı. DOĞRULANMADI. */
    private const SIGNATURE_HEADER = 'x-hb-signature';

    /**
     * Toplu stok güncellemesinde tek istekteki üst sınır.
     *
     * İkincil kaynak 4000 diyor; **bilinçli olarak 1000'de tutuluyor**.
     * Gerekçe: sınır doğrulanmadı ve aşımın bedeli ağır — kanal isteği
     * kısmen işlerse hangi satırın gittiği bilinmez. Doğrulandığında
     * artırılabilir; küçük parti yalnızca daha çok istek demektir,
     * yanlış sonuç değil.
     */
    private const MAX_INVENTORY_BATCH = 1000;

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
     * Satıcının listeleri okunur ve gecikme ölçülür.
     *
     * Sağlık kontrolü geçmeden bağlantı `active` OLMAZ (§13 · faz 1.4):
     * aktif ama çalışmayan bağlantı en pahalı hata biçimidir.
     *
     * ÖZELLİKLE BU KANALDA DEĞERLİ: eksik `User-Agent` 401 üretir ve
     * sağlık kontrolü bunu bağlantı kurulurken yakalar — ilk gerçek
     * senkronda değil.
     */
    public function healthCheck(): HealthResult
    {
        $startedAt = hrtime(true);

        try {
            $response = $this->client->get(
                endpoint: $this->listingPath(HepsiburadaEndpoints::LISTING_LIST),
                query: ['offset' => 0, 'limit' => 1],
                headers: $this->defaultHeaders(),
            );

            $latency = (int) round((hrtime(true) - $startedAt) / 1_000_000);

            return $response->successful()
                ? HealthResult::healthy(latencyMs: $latency)
                : HealthResult::unhealthy("HTTP {$response->status()}");
        } catch (Throwable $e) {
            return HealthResult::unhealthy($e->getMessage());
        }
    }

    // ------------------------------------------------------------ hız sınırı

    /**
     * Sabit profil — Trendyol'un AKSİNE dinamik öğrenme YOK.
     *
     * Hepsiburada sınırı yanıt başlığında bildirmiyor (ikincil kaynak:
     * listing ~30 istek/sn, sipariş ~10 istek/sn). Öğrenilecek bir
     * başlık yokken "öğrenme" kodu yazmak, hiç çalışmayan ve hiç
     * sınanamayan bir yol bırakırdı.
     *
     * **EN DÜŞÜK SINIR SEÇİLİR** (sipariş uç noktası): kova bağlantı
     * başınadır ve tek bir kova iki farklı sınırı ayrı ayrı temsil
     * edemez. Yüksek sınırı seçmek, sipariş çağrılarını sürekli 429'a
     * sokardı; düşük sınırın bedeli yalnızca listing çağrılarının
     * olabileceğinden yavaş gitmesidir.
     */
    public function rateLimitProfile(): RateLimitProfile
    {
        $profile = $this->connection->channelType?->rate_limit_profile;

        return is_array($profile) && $profile !== []
            ? RateLimitProfile::fromArray($profile)
            : new RateLimitProfile(requestsPerSecond: 10, burstCapacity: 20);
    }

    // -------------------------------------------------------- sınıflandırma

    /**
     * Hepsiburada hatasını çekirdeğin anladığı sınıfa çevirir.
     *
     * SINIFLANDIRMA BURADA, KARAR ÇEKİRDEKTE (`RetryPolicy`).
     * `VALIDATION` ve `AUTHENTICATION` KALICIDIR.
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
     * HMAC — HAM GÖVDE üzerinden, JSON AYRIŞTIRMADAN ÖNCE.
     *
     * Ayrıştırıp yeniden serileştirmek baytları değiştirir (anahtar
     * sırası, boşluk, sayı biçimi) ve imza tutmaz.
     *
     * SABİT ZAMANLI KARŞILAŞTIRMA (`hash_equals`): `===` ilk farklı
     * baytta döner ve karşılaştırma süresi doğru ön ek uzunluğunu
     * SIZDIRIR. Zamanlama saldırısı işlevsel testte görünmez; kuralı
     * koruyan şey test değil bu yorumdur.
     *
     * KİRACI BAĞLAMI BEKLENMEZ: webhook anonim gelir ve kiracı ancak
     * bağlantı bulunduktan sonra bilinir. Kimlik bilgisi bu yüzden
     * `runAsSystem()` ile okunur — bağlam beklenirse MEŞRU HER WEBHOOK
     * sessizce reddedilir ve kanal sonsuza kadar yeniden gönderir
     * (§13 · faz 1.4'te yaşanmış hata).
     *
     * @param  array<string, array<int, string|null>>  $headers
     */
    public function verifyWebhookSignature(string $raw, array $headers): bool
    {
        $provided = $this->header($headers, self::SIGNATURE_HEADER);

        if ($provided === null || $provided === '') {
            return false;
        }

        $secret = $this->webhookSecret();

        if ($secret === null || $secret === '') {
            // Sır tanımlı değilse doğrulama YAPILAMAZ ve "geçti" denemez.
            // Güvenli taraf REDDETMEKTİR: kabul etmek, imzasız sipariş
            // enjeksiyonuna kapı açardı.
            return false;
        }

        $expected = base64_encode(hash_hmac('sha256', $raw, $secret, true));

        return hash_equals($expected, $provided);
    }

    /**
     * Olay kimliği — tekilleştirmenin BİRİNCİL çıpası (§4).
     *
     * @param  array<string, array<int, string|null>>  $headers
     */
    public function extractEventId(array $headers): ?string
    {
        return $this->header($headers, 'x-hb-event-id');
    }

    /** @param array<string, array<int, string|null>> $headers */
    public function extractEventType(array $headers): string
    {
        return $this->header($headers, 'x-hb-event-type') ?? 'unknown';
    }

    // ------------------------------------------------------------- yetenekler

    /**
     * @param  list<Listing>  $listings
     */
    public function fetchInventory(array $listings): RemoteInventorySnapshot
    {
        throw new RuntimeException(
            'Hepsiburada stok okuma henüz yazılmadı — uç noktalar doğrulanmadı '.
            '(HepsiburadaEndpoints).'
        );
    }

    public function pushInventory(InventoryPushBatch $batch): AdapterResult
    {
        // SESSİZCE BAŞARILI DÖNMEZ (§7): `AdapterResult::success()`
        // dönseydi operasyon tamamlandı sanılır, `synced_version` ilerler
        // ve satır kanalda hiçbir şey değişmemişken "senkron" görünürdü.
        throw new RuntimeException(
            'Hepsiburada stok itme henüz yazılmadı. DİKKAT: stok ve fiyat AYNI '.
            'yükte gider (Trendyol\'un tersi) — yalnızca stok göndermek fiyatı '.
            'sıfırlayıp satışı KAPATABİLİR.'
        );
    }

    public function maxInventoryBatchSize(): int
    {
        return self::MAX_INVENTORY_BATCH;
    }

    /**
     * @param  list<Listing>  $listings
     */
    public function fetchPrices(array $listings): RemotePriceSnapshot
    {
        throw new RuntimeException(
            'Hepsiburada fiyat okuma henüz yazılmadı — uç noktalar doğrulanmadı.'
        );
    }

    public function pushPrices(PricePushBatch $batch): AdapterResult
    {
        throw new RuntimeException(
            'Hepsiburada fiyat itme henüz yazılmadı. DİKKAT: stok ve fiyat AYNI '.
            'yükte gider — yalnızca fiyat göndermek stoğu sıfırlayıp satışı '.
            'KAPATABİLİR.'
        );
    }

    public function maxPriceBatchSize(): int
    {
        return self::MAX_INVENTORY_BATCH;
    }

    public function fetchOrders(CarbonInterface $since, ?string $cursor = null): OrderPage
    {
        throw new RuntimeException(
            'Hepsiburada sipariş yoklaması henüz yazılmadı — uç noktalar '.
            'doğrulanmadı.'
        );
    }

    /**
     * ⚠️ YAZILMAMIŞ YETENEK SESSİZCE `null` DÖNMEZ.
     *
     * `null` dönseydi tekilleştirme saatlik hash yoluna düşer ve yoklama
     * "çalışıyor" görünürken aynı siparişin İPTALİ o pencerede
     * kaybolabilirdi (v2.2 · §7).
     *
     * @param  array<string, mixed>  $order
     */
    public function pollingEventIdFor(array $order): ?string
    {
        throw new RuntimeException(
            'Hepsiburada sipariş yoklaması henüz yazılmadı — uç noktalar '.
            'doğrulanmadı.'
        );
    }

    public function parseOrderEvent(InboxMessage $message): ?NormalizedOrderEvent
    {
        throw new RuntimeException(
            'Hepsiburada sipariş normalleştirmesi henüz yazılmadı.'
        );
    }

    public function acknowledgeOrder(Order $order): AdapterResult
    {
        throw new RuntimeException(
            'Hepsiburada sipariş onaylama KAPSAM DIŞI — kargo akışının parçası.'
        );
    }

    // ------------------------------------------------------------------- iç

    /**
     * Her isteğe eklenen başlıklar.
     *
     * `User-Agent` KİMLİK DOĞRULAMANIN PARÇASIDIR ve eksikse kanal 401
     * döner (sınıf başlığındaki gerekçe). Biçim: `{merchantId} - {AppName}`.
     *
     * @return array<string, string>
     */
    private function defaultHeaders(): array
    {
        return ['User-Agent' => $this->merchantId().' - '.self::APP_NAME];
    }

    /**
     * Satıcı kimliği — HESABIN kimliğidir, mağaza adresi DEĞİL.
     *
     * Trendyol'un `supplierId` kuralıyla aynı: Hepsiburada'da tek API
     * adresi vardır ve tüm satıcılar onu paylaşır. Alan adı kimlik
     * sayılsaydı her satıcı aynı `external_account_id` ile çakışır ve
     * `(tenant, type, account)` tekilliği ikincisini reddederdi.
     *
     * `external_account_id` BİRİNCİL kaynaktır; `settings` yalnızca
     * geri düşüştür. İkisi de yoksa istisna: sessizce boş bir kimlikle
     * istek atmak, `User-Agent`'ı bozar ve 401'e yol açar — üstelik
     * sebebi görünmez.
     */
    private function merchantId(): string
    {
        $id = $this->connection->external_account_id
            ?: ($this->connection->settings[self::MERCHANT_ID_KEY] ?? null);

        if (! is_string($id) || $id === '') {
            throw new RuntimeException(
                'Hepsiburada satıcı kimliği (merchantId) tanımsız — '.
                'User-Agent kurulamaz ve kanal 401 döner.'
            );
        }

        return $id;
    }

    /** Webhook sırrı kasadan okunur; bağlam beklenmez (sınıf başlığı). */
    private function webhookSecret(): ?string
    {
        $secrets = TenantContext::runAsSystem(
            fn (): array => $this->readSecrets(),
        );

        $secret = $secrets['webhook_secret'] ?? null;

        return is_string($secret) ? $secret : null;
    }

    /** @return array<string, mixed> */
    private function readSecrets(): array
    {
        try {
            return app(CredentialVault::class)
                ->read($this->connection);
        } catch (Throwable) {
            return [];
        }
    }

    /** Listing hostundaki tam adres. */
    private function listingPath(string $template): string
    {
        return HepsiburadaEndpoints::HOST_LISTING.HepsiburadaEndpoints::path(
            $template,
            ['merchantId' => $this->merchantId()],
        );
    }

    /**
     * Başlık okuma — ad BÜYÜK/KÜÇÜK HARFTEN bağımsızdır.
     *
     * HTTP başlık adları büyük/küçük harf duyarsızdır ve vekil sunucular
     * onları yeniden yazar. Tam eşleşme aransaydı `X-HB-Signature`
     * gönderen bir kanal `x-hb-signature` aranırken bulunamaz ve MEŞRU
     * webhook reddedilirdi.
     *
     * @param  array<string, array<int, string|null>>  $headers
     */
    private function header(array $headers, string $name): ?string
    {
        foreach ($headers as $key => $values) {
            if (mb_strtolower((string) $key) !== $name) {
                continue;
            }

            $value = is_array($values) ? ($values[0] ?? null) : $values;

            return is_string($value) ? $value : null;
        }

        return null;
    }
}
