<?php

declare(strict_types=1);

namespace App\Domain\Channels\Adapters\Trendyol;

use App\Domain\Catalog\Models\Variant;
use App\Domain\Channels\Adapters\Trendyol\Taxonomy\TaxonomyClient;
use App\Domain\Channels\Contracts\AdapterResult;
use App\Domain\Channels\Contracts\ChannelAdapter;
use App\Domain\Channels\Contracts\HealthResult;
use App\Domain\Channels\Contracts\RateLimitProfile;
use App\Domain\Channels\Contracts\SupportsApprovalWorkflow;
use App\Domain\Channels\Contracts\SupportsCatalog;
use App\Domain\Channels\Contracts\SupportsInventory;
use App\Domain\Channels\Contracts\SupportsOrders;
use App\Domain\Channels\Contracts\SupportsPricing;
use App\Domain\Channels\Contracts\SupportsTaxonomy;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Support\ChannelHttpClient;
use App\Domain\Messaging\Models\InboxMessage;
use App\Domain\Orders\Models\Order;
use App\Domain\Sync\Enums\ErrorClass;
use App\Domain\Sync\Models\Listing;
use App\Domain\Sync\Support\ApprovalStatusBatch;
use App\Domain\Sync\Support\CategoryTreeSnapshot;
use App\Domain\Sync\Support\InventoryPushBatch;
use App\Domain\Sync\Support\ListingPayload;
use App\Domain\Sync\Support\NormalizedOrderEvent;
use App\Domain\Sync\Support\OrderPage;
use App\Domain\Sync\Support\PricePushBatch;
use App\Domain\Sync\Support\RemoteInventorySnapshot;
use App\Domain\Sync\Support\RemoteListing;
use App\Domain\Sync\Support\RemotePriceSnapshot;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use RuntimeException;
use Throwable;

/**
 * Trendyol kanal adapter'ı — sapigw REST API.
 *
 * Mimari Karar Dokümanı v2.2 · §14 · Trendyol, §7 · Adapter Architecture,
 * §13 · Faz 2 ilk maddesi ("Trendyol istemcisi, kimlik doğrulama, dinamik
 * rate limit profili").
 *
 * PAZARYERİ KANALI: Woo'dan farklı olarak taksonomi ve onay süreci VARDIR.
 * §14'ün tasarım hedefi bu karmaşıklığın stok çekirdeğine hiç dokunmaması:
 * kategori ağacı, zorunlu öznitelikler ve onay durumu yetenek arayüzleriyle
 * taşınır; `InventoryBatchBuilder` yalnızca `lifecycle_status = 'live'`
 * kontrolü yapar ve listing'in nasıl oluştuğunu BİLMEZ.
 *
 * DEĞİŞMEZ KURAL — WEBHOOK YOK, YOKLAMA VAR:
 *   Trendyol webhook göndermez (`supports_webhooks = false`). İmza
 *   doğrulaması bu yüzden HER ZAMAN false döner — doğrulanacak imza hiç
 *   gelmez ve `true` dönmek, Trendyol adına sahte sipariş enjekte etmenin
 *   kapısını açardı. Sipariş yoklamayla çekilir; olay kimliği sipariş
 *   numarasından türer (§4).
 *
 * DEĞİŞMEZ KURAL — SATICI KİMLİĞİ HESABIN KİMLİĞİDİR:
 *   Woo'da hesap kimliği mağaza alan adıdır; Trendyol'da tek bir API adresi
 *   vardır ve tüm satıcılar onu paylaşır. Alan adı kimlik sayılsaydı her
 *   Trendyol satıcısı aynı `external_account_id` ile çakışır ve
 *   `(tenant, type, account)` tekilliği ikinci satıcıyı reddederdi.
 *
 * DEĞİŞMEZ KURAL — DİNAMİK RATE LIMIT (§14):
 *   Sınır satıcı seviyesine göre değişir ve yanıt başlığından öğrenilir.
 *   Profili ADAPTER bildirir, uygulamayı ÇEKİRDEK yapar — kova mantığı
 *   ortaktır ve `ChannelRateLimiter` bu kanal için değişmez.
 *
 * DEĞİŞMEZ KURAL — ADAPTER YAN ETKİSİZDİR:
 *   Veritabanına senkron DURUMU yazmaz, kuyruğa iş atmaz. Öğrenilen hız
 *   sınırının bağlantıya yazılması bu kuralın istisnası değildir: o bir
 *   senkron durumu değil, bağlantının kendi YAPILANDIRMASIDIR ve hiçbir
 *   operasyonun sonucunu değiştirmez.
 *
 * KAPSAM (Faz 2 · ilk madde): istemci, kimlik doğrulama, sağlık kontrolü,
 * hata sınıflandırma ve dinamik hız sınırı. Katalog aktarımı, taksonomi
 * çekme, sipariş yoklaması ve stok/fiyat itme SONRAKİ maddelerdir; burada
 * uygulanmış gibi görünmeleri, panelde çalışmayan sekmeler açardı.
 * Yetenek arayüzleri §14'teki sözleşmeyi ilan eder, gövdeler açıkça
 * "henüz yazılmadı" der ve SESSİZCE BAŞARILI DÖNMEZ.
 */
final class TrendyolAdapter implements ChannelAdapter, SupportsApprovalWorkflow, SupportsCatalog, SupportsInventory, SupportsOrders, SupportsPricing, SupportsTaxonomy
{
    /** Trendyol sınırı dakika penceresinde bildirir. */
    private const RATE_LIMIT_WINDOW_SECONDS = 60;

    /** Öğrenilen sınırın `settings` içindeki yeri. */
    private const LEARNED_RATE_LIMIT_KEY = 'learned_rate_limit';

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
     * Satıcı adresine gider ve gecikmeyi ölçer.
     *
     * Sağlık kontrolü geçmeden bağlantı `active` OLMAZ: aktif ama çalışmayan
     * bağlantı en pahalı hata biçimidir — kullanıcı ürün göndermeye başlar
     * ve hepsi AUTHENTICATION ile kalıcı hataya düşer.
     */
    public function healthCheck(): HealthResult
    {
        $startedAt = hrtime(true);

        try {
            $response = $this->client->get($this->supplierPath('addresses'));

            $latency = (int) round((hrtime(true) - $startedAt) / 1_000_000);

            // Sınır her yanıtta öğrenilebilir; sağlık kontrolü de bir yanıttır.
            $this->learnRateLimit($response);

            return $response->successful()
                ? HealthResult::healthy(latencyMs: $latency)
                : HealthResult::unhealthy("HTTP {$response->status()}");
        } catch (Throwable $e) {
            return HealthResult::unhealthy($e->getMessage());
        }
    }

    // ------------------------------------------------------------ hız sınırı

    /**
     * Hız sınırı profili — önce ÖĞRENİLEN, sonra kanal türündeki.
     *
     * Trendyol'da sınır satıcı seviyesine göre değişir: sabit bir profil
     * yüksek seviyeli satıcıyı gereksiz yavaşlatır, düşük seviyeliyi ise
     * sürekli 429'a sokar.
     */
    public function rateLimitProfile(): RateLimitProfile
    {
        $learned = $this->connection->settings[self::LEARNED_RATE_LIMIT_KEY] ?? null;

        if (is_array($learned) && ($learned['requests_per_second'] ?? 0) > 0) {
            return RateLimitProfile::fromArray($learned);
        }

        $profile = $this->connection->channelType?->rate_limit_profile;

        return is_array($profile) && $profile !== []
            ? RateLimitProfile::fromArray($profile)
            : RateLimitProfile::conservative();
    }

    /**
     * Yanıt başlığından sınırı öğrenir ve bağlantıya yazar.
     *
     * SÜREÇLE ÖLMEZ: her worker kendi başına yeniden öğrenseydi ilk istekler
     * daima varsayılan profille giderdi ve yüksek seviyeli satıcı kotasının
     * çoğunu hiç kullanamazdı.
     *
     * ANLAMSIZ BAŞLIK YOK SAYILIR: bozuk bir değer profili sıfırlayıp
     * kanalı tamamen durdurabilirdi. Bilinmeyen karşısında mevcut profil
     * korunur.
     */
    private function learnRateLimit(Response $response): void
    {
        $header = $response->header('X-RateLimit-Limit');

        if ($header === '' || ! ctype_digit(trim($header))) {
            return;
        }

        $perMinute = (int) trim($header);

        if ($perMinute <= 0) {
            return;
        }

        $perSecond = max(1, intdiv($perMinute, self::RATE_LIMIT_WINDOW_SECONDS));

        $profile = new RateLimitProfile(
            requestsPerSecond: $perSecond,
            // Kova, saniyelik hızın iki katına kadar ani yüke izin verir;
            // pencere başında biriken jetonlar bu şekilde kullanılabilir.
            burstCapacity: $perSecond * 2,
        );

        $current = $this->connection->settings[self::LEARNED_RATE_LIMIT_KEY] ?? null;
        $next = $profile->toArray();

        // Değişmediyse yazma: her sağlık kontrolü bir UPDATE atmamalı.
        if ($current === $next) {
            return;
        }

        $this->connection->forceFill([
            'settings' => [...$this->connection->settings ?? [], self::LEARNED_RATE_LIMIT_KEY => $next],
        ])->save();
    }

    // -------------------------------------------------------- sınıflandırma

    /**
     * Trendyol hatasını çekirdeğin anladığı sınıfa çevirir.
     *
     * SINIFLANDIRMA BURADA, KARAR ÇEKİRDEKTE: ne yapılacağına `RetryPolicy`
     * karar verir. `VALIDATION` ve `AUTHENTICATION` kalıcıdır.
     */
    public function classifyError(Throwable $e): ErrorClass
    {
        // Yanıt hiç gelmedi; sonuç BELİRSİZ. TIMEOUT/NETWORK ayrımı mesaj
        // metnine göre yapılmaz (Woo'daki gerekçenin aynısı: cURL metni
        // sürüme ve dile göre değişir ve ikisi aynı politikaya tabidir).
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
            // Kalan 4xx iş kuralı ihlalidir: kullanıcı müdahalesi gerekir ve
            // yeniden denemek bütçe israfıdır.
            $status >= 400 => ErrorClass::VALIDATION,
            default => ErrorClass::SERVER_ERROR,
        };
    }

    // ------------------------------------------------------------- webhook

    /**
     * TRENDYOL WEBHOOK GÖNDERMEZ — HER ZAMAN FALSE.
     *
     * `true` dönseydi Trendyol adına imzasız sipariş enjekte etmenin kapısı
     * açılırdı. Doğrulanacak imza hiç gelmediği için doğru cevap "hayır"dır.
     *
     * @param  array<string, array<int, string|null>>  $headers
     */
    public function verifyWebhookSignature(string $raw, array $headers): bool
    {
        return false;
    }

    /**
     * Olay kimliği BAŞLIKTAN gelmez.
     *
     * Yoklamada kimlik sipariş numarası + durumdan türer (§4 · tekilleştirme
     * tablosu) ve onu yoklama işi üretir; burada uydurulacak bir değer yok.
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
        return 'order.polled';
    }

    // ------------------------------------------------------------- stok

    public function maxInventoryBatchSize(): int
    {
        // Stok-fiyat güncelleme uç noktası (§14 · adapter taslağı).
        return 1000;
    }

    public function pushInventory(InventoryPushBatch $batch): AdapterResult
    {
        throw $this->notImplemented('stok itme');
    }

    /** @param list<Listing> $listings */
    public function fetchInventory(array $listings): RemoteInventorySnapshot
    {
        throw $this->notImplemented('uzak stok okuma');
    }

    // ------------------------------------------------------------- fiyat

    public function maxPriceBatchSize(): int
    {
        return 1000;
    }

    public function pushPrices(PricePushBatch $batch): AdapterResult
    {
        throw $this->notImplemented('fiyat itme');
    }

    /** @param list<Listing> $listings */
    public function fetchPrices(array $listings): RemotePriceSnapshot
    {
        throw $this->notImplemented('uzak fiyat okuma');
    }

    // ------------------------------------------------------------- katalog

    public function createListing(ListingPayload $payload): AdapterResult
    {
        throw $this->notImplemented('ürün aktarımı');
    }

    public function updateListing(ListingPayload $payload): AdapterResult
    {
        throw $this->notImplemented('ürün güncelleme');
    }

    public function delist(Listing $listing): AdapterResult
    {
        throw $this->notImplemented('listeden çıkarma');
    }

    public function findExistingListing(Variant $variant): ?RemoteListing
    {
        throw $this->notImplemented('ürün eşleştirme');
    }

    public function fetchListing(Listing $listing): ?RemoteListing
    {
        throw $this->notImplemented('uzak listing okuma');
    }

    // ------------------------------------------------------------- sipariş

    public function fetchOrders(CarbonInterface $since, ?string $cursor = null): OrderPage
    {
        throw $this->notImplemented('sipariş yoklaması');
    }

    public function parseOrderEvent(InboxMessage $message): ?NormalizedOrderEvent
    {
        throw $this->notImplemented('sipariş normalizasyonu');
    }

    public function acknowledgeOrder(Order $order): AdapterResult
    {
        throw $this->notImplemented('sipariş onaylama');
    }

    // ------------------------------------------------------------ taksonomi

    public function fetchCategoryTree(): CategoryTreeSnapshot
    {
        return $this->taxonomy()->fetchTree();
    }

    /** @return array<string, mixed> */
    public function fetchCategoryAttributes(string $categoryId): array
    {
        return $this->taxonomy()->fetchAttributes($categoryId);
    }

    /**
     * Taksonomi sürümü — AĞACI ÇEKİP İÇERİĞİNDEN türetir.
     *
     * Kanal bir sürüm numarası vermediği için "sürüm değişti mi" sorusu
     * ancak ağaç okunarak cevaplanabilir. Çağıran zaten ağacı çekecekse
     * `fetchCategoryTree()` kullanmalı ve sürümü snapshot'tan okumalıdır;
     * bu metot iki kez çekmeye yol açar.
     */
    public function taxonomyVersion(): string
    {
        return $this->fetchCategoryTree()->version;
    }

    // ---------------------------------------------------------------- onay

    /** @param list<Listing> $listings */
    public function fetchApprovalStatus(array $listings): ApprovalStatusBatch
    {
        throw $this->notImplemented('onay durumu takibi');
    }

    // ------------------------------------------------------------------ iç

    /**
     * Satıcıya özgü yol.
     *
     * Satıcı kimliği YOL ÜZERİNDEDİR: doğru anahtarla yanlış kimlik başka
     * bir satıcının kaynağını ister ve 403 alır. Kimlik `settings` içinde
     * durur — sır değildir ve panelde görünür.
     */
    /**
     * Taksonomi istemcisi.
     *
     * Ağaç uç noktası satıcıya özgü DEĞİLDİR (kategori ağacı tüm satıcılar
     * için aynıdır), bu yüzden `supplierPath()` kullanılmaz.
     */
    private function taxonomy(): TaxonomyClient
    {
        return new TaxonomyClient($this->client);
    }

    private function supplierPath(string $endpoint): string
    {
        $supplierId = (string) ($this->connection->settings['supplier_id'] ?? '');

        if ($supplierId === '') {
            throw new RuntimeException(
                "Trendyol bağlantısında satıcı kimliği yok: {$this->connection->id}"
            );
        }

        return "suppliers/{$supplierId}/".ltrim($endpoint, '/');
    }

    /**
     * Henüz yazılmamış yetenek — SESSİZCE BAŞARILI DÖNMEZ.
     *
     * `AdapterResult::success()` dönseydi senkron operasyonu tamamlandı
     * sanılır, `synced_version` ilerler ve kanalda hiçbir şey değişmemişken
     * satır "senkron" görünürdü — mutabakat bunu sürüklenme olarak bulana
     * kadar sessiz kalırdı.
     */
    private function notImplemented(string $what): RuntimeException
    {
        return new RuntimeException(
            "Trendyol {$what} henüz yazılmadı (§13 · Faz 2)."
        );
    }
}
