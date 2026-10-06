<?php

declare(strict_types=1);

namespace App\Domain\Channels\Adapters\Trendyol;

use App\Domain\Catalog\Models\Variant;
use App\Domain\Channels\Adapters\Trendyol\Catalog\ListingContext;
use App\Domain\Channels\Adapters\Trendyol\Catalog\ListingMapper;
use App\Domain\Channels\Adapters\Trendyol\Taxonomy\TaxonomyClient;
use App\Domain\Channels\Contracts\AdapterResult;
use App\Domain\Channels\Contracts\ChannelAdapter;
use App\Domain\Channels\Contracts\DeclaresImageLimit;
use App\Domain\Channels\Contracts\DeclaresRequestQuota;
use App\Domain\Channels\Contracts\HealthResult;
use App\Domain\Channels\Contracts\RateLimitProfile;
use App\Domain\Channels\Contracts\SupportsApprovalWorkflow;
use App\Domain\Channels\Contracts\SupportsCatalog;
use App\Domain\Channels\Contracts\SupportsCatalogImport;
use App\Domain\Channels\Contracts\SupportsInventory;
use App\Domain\Channels\Contracts\SupportsOrders;
use App\Domain\Channels\Contracts\SupportsPricing;
use App\Domain\Channels\Contracts\SupportsTaxonomy;
use App\Domain\Channels\Exceptions\ListingNotPublishable;
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
use App\Domain\Sync\Support\RemoteProduct;
use App\Domain\Sync\Support\RemoteProductPage;
use Carbon\CarbonInterface;
use DateTimeImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

/**
 * Trendyol kanal adapter'ı — apigw entegrasyon API'si.
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
final class TrendyolAdapter implements ChannelAdapter, DeclaresImageLimit, SupportsApprovalWorkflow, SupportsCatalog, SupportsCatalogImport, SupportsInventory, SupportsOrders, SupportsPricing, SupportsTaxonomy
{
    use DeclaresRequestQuota;

    /**
     * Satıcı kimliğinin `settings` içindeki adı — bağlanma formu da
     * bunu okur (`ChannelConnectForm`). Bağlantının hesap kimliği de odur.
     */
    public const SELLER_ID_KEY = 'supplier_id';

    /**
     * `User-Agent`'taki entegratör adının `settings` içindeki yeri.
     * Boşsa {@see DEFAULT_INTEGRATOR_NAME}.
     */
    public const INTEGRATOR_NAME_KEY = 'integrator_name';

    /**
     * Kendi entegrasyonunu yazan satıcının adı (Trendyol dokümanı).
     * Kayıtlı entegratör olunca firma adı formdan girilir.
     */
    public const DEFAULT_INTEGRATOR_NAME = 'SelfIntegration';

    /** KDV oranı ayarı (%); Trendyol 0, 1, 10, 20 kabul eder. */
    public const VAT_RATE_KEY = 'vat_rate';

    public const DEFAULT_VAT_RATE = 20;

    /** Desi ayarı — hacimsel ağırlık, gerçek ağırlık DEĞİL. */
    public const DIMENSIONAL_WEIGHT_KEY = 'dimensional_weight';

    public const DEFAULT_DIMENSIONAL_WEIGHT = 1.0;

    /**
     * Sevkiyat / iade adresi kimlikleri. Boşsa GÖNDERİLMEZ ve Trendyol
     * satıcının varsayılan adresini kullanır (gerçek hesapta doğrulanacak).
     */
    public const SHIPMENT_ADDRESS_KEY = 'shipment_address_id';

    public const RETURNING_ADDRESS_KEY = 'returning_address_id';

    /** Marka kimliği önbelleği — markalar nadiren değişir. */
    private const BRAND_CACHE_SECONDS = 86_400;

    /** Üretim entegrasyon adresi (apigw). Eski `sapigw` kapatıldı. */
    public const BASE_URL = 'https://apigw.trendyol.com/integration';

    /** Trendyol sınırı dakika penceresinde bildirir. */
    private const RATE_LIMIT_WINDOW_SECONDS = 60;

    /** Öğrenilen sınırın `settings` içindeki yeri. */
    private const LEARNED_RATE_LIMIT_KEY = 'learned_rate_limit';

    /** V2 ürün filtresi: istek başına en fazla 50 barkod. */
    private const BARCODES_PER_REQUEST = 50;

    /** `products/approved` sayfa üst sınırı. */
    private const APPROVED_PAGE_SIZE = 100;

    /** `products/unapproved` sayfa üst sınırı. */
    private const UNAPPROVED_PAGE_SIZE = 1000;

    /** Yoklama sayfa boyutu — kanalın üst sınırı 200. */
    private const ORDER_PAGE_SIZE = 200;

    /**
     * Trendyol paket durumu → kanonik olay tipi.
     *
     * LİSTEDE OLMAYAN DURUM `updated` SAYILIR (bkz. `parseOrderEvent`):
     * kanal durum listesini genişletebilir ve bilinmeyeni `created` ya da
     * `cancelled` saymak bakiyeyi bozardı.
     *
     * `Delivered` ve `Shipped` stok hareketi ÜRETMEZ: stok sipariş
     * oluştuğunda zaten düşülmüştür; kargo aşamaları yalnızca anlık
     * görüntüyü tazeler.
     */
    private const STATUS_TO_TYPE = [
        'Created' => 'created',
        'Awaiting' => 'created',
        'Picking' => 'updated',
        'Invoiced' => 'updated',
        'Shipped' => 'updated',
        'Delivered' => 'updated',
        'AtCollectionPoint' => 'updated',
        'Cancelled' => 'cancelled',
        'UnDelivered' => 'updated',
        'Returned' => 'returned',
        // ⚠️ BÖLÜNEN PAKET İPTAL SAYILIR. Paket bölünürse eskisi
        // `UnPacked` olur ve kalemler YENİ `shipmentPackageId`'li paketlerde
        // `Created` olarak yeniden gelir. Eski paket stoğu geri vermeseydi
        // yeni paketler aynı satışı İKİNCİ kez düşerdi. Gerçek hesapta
        // doğrulanmalı (A11 · gerçek hesap pilotu).
        'UnPacked' => 'cancelled',
    ];

    public function __construct(
        private readonly ChannelConnection $connection,
        private readonly ChannelHttpClient $client,
    ) {}

    /** Trendyol: barkod başına en fazla 8 görsel. */
    public function maxImages(): int
    {
        return ListingMapper::MAX_IMAGES;
    }

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
            $response = $this->get($this->sellerUrl('', 'addresses'));

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

        // Eksik VERİ (marka yok, görsel yok, kategori eşleşmemiş): satıcı
        // düzeltene kadar yeniden denemek boşunadır ve sebebi geciktirir.
        if ($e instanceof ListingNotPublishable) {
            return ErrorClass::VALIDATION;
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

    /**
     * Stoğu MUTLAK değer olarak iter.
     *
     * Mimari Karar Dokümanı v2.2 · §1 · Karar 25, §7 · SupportsInventory,
     * §13 · Faz 2 ("Stok ve fiyat itme").
     *
     * DEĞİŞMEZ KURAL — MUTLAK DEĞER, DELTA ASLA:
     *   Kaybolan veya iki kez işlenen bir delta isteği kanaldaki bakiyeyi
     *   KALICI olarak kaydırır ve fark geri kazanılamaz. Mutlak değerde
     *   tekrar zararsızdır — yeniden denemenin güvenli olmasının ve
     *   mutabakatın çalışabilmesinin dayanağı budur.
     *
     * DEĞİŞMEZ KURAL — KİMLİK BARKODDUR, SAYIYA ÇEVRİLMEZ:
     *   Woo'da kimlik sayısal ürün kimliğidir ve o adapter `(int)` dönüşümü
     *   yapar. Aynı satır buraya kopyalansaydı harf içeren her barkod
     *   (`TSH-201`) `0`'a düşer ve istek yanlış ürüne giderdi ya da hiçbir
     *   şeyi güncellemezdi — kanal 200 döndüğü için senkron BAŞARILI
     *   görünürdü ve hata ancak mutabakat turunda ortaya çıkardı.
     *
     * DEĞİŞMEZ KURAL — STOK YÜKÜ FİYAT ALANI TAŞIMAZ:
     *   Uç nokta stok ve fiyatla paylaşılır ve KISMİ güncellemeyi
     *   destekler. Stok yükünde fiyat da gönderilseydi, panelden yapılmış
     *   ama henüz kanala gitmemiş bir fiyat değişikliği eski değerle
     *   EZİLİRDİ: stok her satışta gider, fiyat nadiren değişir — ezme
     *   sessiz ve sürekli olurdu.
     *
     * ASENKRON KABUL "UYGULANDI" DEMEK DEĞİLDİR: kanal `batchRequestId`
     * döner ve işi kuyruğuna alır. Kimlik sonuçta taşınır; gerçekten
     * uygulanıp uygulanmadığını mutabakat turu doğrular.
     */
    public function pushInventory(InventoryPushBatch $batch): AdapterResult
    {
        if ($batch->isEmpty()) {
            // Boş yükte çağrı yapılmaz: kota boşa gitmez ve kanal boş
            // `items` dizisini VALIDATION ile reddederdi — o hata KALICIDIR.
            return AdapterResult::success(['pushed' => 0]);
        }

        $items = array_map(
            static fn (array $item): array => [
                // Barkod OLDUĞU GİBİ; `(int)` dönüşümü YOK.
                'barcode' => (string) $item['external_id'],
                // MUTLAK değer. Kırpma OutboundQuantity'de yapıldı.
                'quantity' => $item['quantity'],
            ],
            $batch->toArray(),
        );

        $response = $this->post(
            $this->sellerUrl('inventory', 'products/price-and-inventory'),
            ['items' => $items],
        );

        // Başarısızlık İSTİSNA olarak yükselir (§7): sınıflandırma ve
        // yeniden deneme kararı PushInventory'deki tek try/catch'te toplanır.
        $response->throw();

        return AdapterResult::success([
            'pushed' => $batch->count(),
            'batch_request_id' => $response->json('batchRequestId'),
        ]);
    }

    /**
     * Uzak stok durumunu TOPLU okur — mutabakatın karşılaştırma girdisi.
     *
     * KİMLİĞİ OLMAYAN LISTING SORULMAZ: `external_id` NULL ise ürün kanala
     * hiç gitmemiştir. Hiç kimlik kalmazsa çağrı da YAPILMAZ — filtresiz
     * istek kanalın TÜM kataloğunu geri getirirdi.
     *
     * BAŞARISIZ YANIT YÜKSELTİLİR: `json()` bir 500 gövdesinde de dizi
     * döndürür ve boş snapshot mutabakatta "kanalda ürün yok" diye okunup
     * `REMOTE_MISSING` üretirdi — oysa olan yalnızca geçici bir hatadır.
     *
     * @param  list<Listing>  $listings
     */
    public function fetchInventory(array $listings): RemoteInventorySnapshot
    {
        $rows = $this->fetchRemoteRows($listings);

        if ($rows === null) {
            return new RemoteInventorySnapshot([]);
        }

        $quantities = [];

        foreach ($rows as $barcode => $row) {
            $quantities[$barcode] = $row['quantity'];
        }

        // Okuma anı taşınır: gecikmeli okuma sürüklenme sanılmamalı (§10).
        return new RemoteInventorySnapshot($quantities, new DateTimeImmutable);
    }

    // ------------------------------------------------------------- fiyat

    public function maxPriceBatchSize(): int
    {
        return 1000;
    }

    /**
     * Fiyatı MUTLAK değer olarak iter — stokla AYNI uç nokta.
     *
     * Yüzde indirim veya delta gönderilmez; gerekçe stoktakiyle aynıdır.
     *
     * FİYAT YÜKÜ STOK ALANI TAŞIMAZ: simetrik kural. `quantity` de
     * gönderilseydi, yükü kuran taraf güncel bakiyeyi bilmediği için
     * kanaldaki stoğu bayat bir değerle ezerdi — üstelik satılmış ürünü
     * yeniden satışa açarak.
     *
     * `listPrice` ZORUNLUDUR: alan atlanırsa kanal `VALIDATION` döner ve o
     * hata KALICIDIR. Satıcı üstü çizili fiyat girmemişse satış fiyatı
     * kullanılır — kampanyasız ürün "düzeltilemez" damgasıyla ölmemeli.
     */
    public function pushPrices(PricePushBatch $batch): AdapterResult
    {
        if ($batch->isEmpty()) {
            return AdapterResult::success(['pushed' => 0]);
        }

        $items = array_map(
            static function (array $item): array {
                $salePrice = (float) $item['price'];

                return [
                    'barcode' => (string) $item['external_id'],
                    'salePrice' => $salePrice,
                    // Üstü çizili fiyat yoksa satış fiyatı: alan zorunlu.
                    'listPrice' => isset($item['compare_at_price'])
                        ? (float) $item['compare_at_price']
                        : $salePrice,
                ];
            },
            $batch->items,
        );

        $response = $this->post(
            $this->sellerUrl('inventory', 'products/price-and-inventory'),
            ['items' => $items],
        );

        $response->throw();

        return AdapterResult::success([
            'pushed' => $batch->count(),
            'batch_request_id' => $response->json('batchRequestId'),
        ]);
    }

    /**
     * Uzak fiyatı TOPLU okur.
     *
     * Fiyat STRING taşınır: float para birimi için güvenilir değildir ve
     * yuvarlama hataları kuruş kayması üretir.
     *
     * @param  list<Listing>  $listings
     */
    public function fetchPrices(array $listings): RemotePriceSnapshot
    {
        $rows = $this->fetchRemoteRows($listings);

        if ($rows === null) {
            return new RemotePriceSnapshot([]);
        }

        $prices = [];

        foreach ($rows as $barcode => $row) {
            $prices[$barcode] = $row['sale_price'];
        }

        return new RemotePriceSnapshot($prices, new DateTimeImmutable);
    }

    /**
     * Uzak ürün satırlarını barkodla toplu okur — stok ve fiyat ORTAK.
     *
     * İki okuma yolu aynı uç noktayı ve aynı filtreyi kullanır; ayrı
     * yazılsalardı "kimliksiz listing sorulmaz" ve "başarısız yanıt
     * yükseltilir" kurallarının biri değişince diğeri sessizce geride
     * kalırdı.
     *
     * @param  list<Listing>  $listings
     * @return array<string, array<string, mixed>>|null Sorulacak kimlik yoksa null
     */
    private function fetchRemoteRows(array $listings): ?array
    {
        $barcodes = $this->barcodesOf($listings);

        // Filtresiz istek kanalın TÜM kataloğunu getirirdi.
        if ($barcodes === []) {
            return null;
        }

        return $this->approvedVariants($barcodes);
    }

    /**
     * Onaylı ürünlerin varyantları, barkoda göre — Product V2.
     *
     * Mimari değişiklik (A11 ④): V1 `product/sellers/{id}/products` düz
     * satır dönüyordu; V2 `products/approved` İÇERİK → `variants[]`
     * döner, stok `stock.quantity`, fiyat `price.salePrice` altındadır.
     * Eski adlarla okunsaydı her ürün stok 0 / fiyat 0 görünür ve
     * mutabakat bütün kataloğu "sürüklenmiş" sanıp yeniden iterdi.
     *
     * ⚠️ İSTEK BAŞINA EN FAZLA 50 BARKOD, sayfa en fazla 100 (doküman).
     * V1 kodu bütün barkodları virgülle tek `barcode` parametresine
     * koyuyordu — kanal onu TEK barkod sayar ve hiçbirini bulmazdı.
     *
     * İçerik, sorulmayan kardeş varyantları da döndürür; yalnızca
     * sorulan barkodlar alınır.
     *
     * @param  list<string>  $barcodes
     * @return array<string, array{barcode: string, content_id: string|null, title: string|null, url: string|null, quantity: int, sale_price: string, sellable: bool, lock_reason: string|null, raw: array<string, mixed>}>
     */
    private function approvedVariants(array $barcodes): array
    {
        $found = [];

        foreach (array_chunk($barcodes, self::BARCODES_PER_REQUEST) as $chunk) {
            $wanted = array_flip($chunk);

            foreach ($this->pages('products/approved', ['barcodes' => implode(',', $chunk)], self::APPROVED_PAGE_SIZE) as $product) {
                foreach ((array) ($product['variants'] ?? []) as $variant) {
                    $barcode = is_array($variant) ? (string) ($variant['barcode'] ?? '') : '';

                    if ($barcode === '' || ! isset($wanted[$barcode])) {
                        continue;
                    }

                    $found[$barcode] = [
                        'barcode' => $barcode,
                        'content_id' => isset($product['contentId']) ? (string) $product['contentId'] : null,
                        'title' => isset($product['title']) ? (string) $product['title'] : null,
                        'url' => isset($variant['productUrl']) ? (string) $variant['productUrl'] : null,
                        // `stock` nesnesi miktarsız gelebilir (hiç stok
                        // girilmemiş varyant) — o 0'dır.
                        'quantity' => (int) ($variant['stock']['quantity'] ?? 0),
                        'sale_price' => (string) ($variant['price']['salePrice'] ?? '0'),
                        'sellable' => (bool) ($variant['onSale'] ?? false)
                            && ! ($variant['archived'] ?? false)
                            && ! ($variant['locked'] ?? false)
                            && ! ($variant['blacklisted'] ?? false),
                        'lock_reason' => isset($variant['lockReason']) ? (string) $variant['lockReason'] : null,
                        'raw' => [...$variant, 'contentId' => $product['contentId'] ?? null],
                    ];
                }
            }
        }

        return $found;
    }

    /**
     * Onaysız ürünler, barkoda göre — Product V2 `products/unapproved`.
     *
     * Onaysız gövdede `variants[]` YOKTUR; barkod satırın kendisindedir.
     * `status` verilirse (`rejected`, `pendingApproval`) yalnızca o
     * durumdakiler döner.
     *
     * @param  list<string>  $barcodes
     * @return array<string, array<string, mixed>>
     */
    private function unapprovedProducts(array $barcodes, ?string $status = null): array
    {
        $found = [];

        foreach (array_chunk($barcodes, self::BARCODES_PER_REQUEST) as $chunk) {
            $wanted = array_flip($chunk);
            $query = array_filter(['barcodes' => implode(',', $chunk), 'status' => $status]);

            foreach ($this->pages('products/unapproved', $query, self::UNAPPROVED_PAGE_SIZE) as $row) {
                $barcode = (string) ($row['barcode'] ?? '');

                if ($barcode !== '' && isset($wanted[$barcode])) {
                    $found[$barcode] = $row;
                }
            }
        }

        return $found;
    }

    /**
     * Ürün filtresinin bütün sayfaları.
     *
     * BAŞARISIZ YANIT YÜKSELTİLİR: `json()` bir 500 gövdesinde de dizi
     * döndürür ve boş sonuç "kanalda ürün yok" diye okunurdu.
     *
     * @param  array<string, mixed>  $query
     * @return \Generator<int, array<string, mixed>>
     */
    private function pages(string $endpoint, array $query, int $size): \Generator
    {
        $page = 0;

        do {
            $response = $this->get($this->sellerUrl('product', $endpoint), [...$query, 'page' => $page, 'size' => $size]);

            $response->throw();

            foreach ((array) ($response->json('content') ?? []) as $row) {
                if (is_array($row)) {
                    yield $row;
                }
            }

            $page++;
        } while ($page < (int) ($response->json('totalPages') ?? 1));
    }

    /**
     * @param  list<Listing>  $listings
     * @return list<string>
     */
    private function barcodesOf(array $listings): array
    {
        $barcodes = [];

        foreach ($listings as $listing) {
            if ($listing->external_id !== null && $listing->external_id !== '') {
                $barcodes[] = (string) $listing->external_id;
            }
        }

        return array_values(array_unique($barcodes));
    }

    // ------------------------------------------------- katalog içe aktarma

    /**
     * Satıcının Trendyol'daki onaylı ürünlerini sayfa sayfa okur — §7 ·
     * SupportsCatalogImport. 6 Eki 2026'ya kadar YOKTU: Trendyol satıcısı
     * mevcut kataloğunu 34Pazar'a hiç çekemiyordu (panel "ürün çekmeyi
     * destekleyen kanal yok" diyordu).
     *
     * Trendyol'da bir İÇERİK (`contentId`) altında birden çok VARYANT
     * (beden/renk) durur; kanonik model 1 ürün = 1 varyanttır. Her varyant
     * ayrı ürün olur, içerik kimliği `external_parent_id`'ye yazılır ve
     * panel bunları ekranda gruplar (Shopify ile aynı).
     *
     * KİMLİK BARKODDUR: gönderim (`batchResult`) ve okuma
     * (`approvedVariants`) ilanı barkodla tanır. İçe aktarılan ürün bu
     * barkodla CANLI ilana bağlanmazsa ilk gönderim Trendyol'da KOPYA
     * ürün yaratırdı.
     *
     * SKU: satıcının `stockCode`'u, yoksa barkod. Gerçek hesapta (2.918
     * ürün) `stockCode` boş geldi — barkod Trendyol'da zaten benzersizdir.
     *
     * ARŞİVLİ VARYANT ALINMAZ: satıcı onu Trendyol'da kaldırmıştır; stok
     * gönderilse bile satılamaz.
     *
     * İmleç sayfa numarasıdır (Trendyol `page`, 0'dan başlar).
     */
    public function fetchProductPage(?string $cursor = null): RemoteProductPage
    {
        $page = $cursor === null ? 0 : (int) $cursor;

        $response = $this->get($this->sellerUrl('product', 'products/approved'), [
            'page' => $page,
            'size' => self::APPROVED_PAGE_SIZE,
        ]);

        $response->throw();

        $products = [];

        foreach ((array) ($response->json('content') ?? []) as $content) {
            if (! is_array($content)) {
                continue;
            }

            foreach ((array) ($content['variants'] ?? []) as $variant) {
                if (is_array($variant) && ! self::flag($variant['archived'] ?? false)) {
                    $products[] = self::toRemoteProduct($content, $variant);
                }
            }
        }

        $totalPages = (int) ($response->json('totalPages') ?? 1);

        return new RemoteProductPage(
            products: $products,
            nextCursor: $page + 1 < $totalPages ? (string) ($page + 1) : null,
            hasMore: $page + 1 < $totalPages,
        );
    }

    /**
     * Tur başına en fazla 50 sayfa — 100'lük sayfayla 5.000 içerik.
     *
     * Emniyet sınırıdır (bkz. Woo). Trendyol `page` ile 10.000 kaydın
     * ötesine geçmez; o büyüklükte `nextPageToken` gerekir — ayrı madde.
     */
    public function maxImportPages(): int
    {
        return 50;
    }

    /**
     * @param  array<string, mixed>  $content
     * @param  array<string, mixed>  $variant
     */
    private static function toRemoteProduct(array $content, array $variant): RemoteProduct
    {
        $barcode = trim((string) ($variant['barcode'] ?? ''));
        $stockCode = trim((string) ($variant['stockCode'] ?? ''));
        $contentId = isset($content['contentId']) ? (string) $content['contentId'] : null;
        $brand = is_array($content['brand'] ?? null) ? trim((string) ($content['brand']['name'] ?? '')) : '';
        $description = trim((string) ($content['description'] ?? ''));
        $url = isset($variant['productUrl']) ? (string) $variant['productUrl'] : null;

        return new RemoteProduct(
            externalId: $barcode,
            sku: $stockCode !== '' ? $stockCode : ($barcode !== '' ? $barcode : null),
            title: isset($content['title']) ? (string) $content['title'] : null,
            // Fiyat STRING kalır — float dönüşümü kuruş kayması üretir.
            price: isset($variant['price']['salePrice']) ? (string) $variant['price']['salePrice'] : null,
            // Stok nesnesi miktarsız gelebilir (hiç stok girilmemiş) — o 0'dır.
            quantity: (int) ($variant['stock']['quantity'] ?? 0),
            description: $description !== '' ? $description : null,
            brand: $brand !== '' ? $brand : null,
            barcode: $barcode !== '' ? $barcode : null,
            status: self::flag($variant['onSale'] ?? false) ? 'on_sale' : 'not_on_sale',
            images: array_values(array_filter(array_map(
                static fn (mixed $image): string => is_array($image) ? (string) ($image['url'] ?? '') : '',
                (array) ($content['images'] ?? []),
            ), static fn (string $url): bool => str_starts_with($url, 'https://'))),
            raw: [...$variant, 'contentId' => $contentId],
            listingIdentity: $barcode === '' ? [] : array_filter([
                'external_id' => $barcode,
                'external_parent_id' => $contentId,
                'external_url' => $url,
            ], static fn (?string $value): bool => $value !== null && $value !== ''),
            currency: 'TRY',
        );
    }

    /** Trendyol bayrakları JSON'da bool, bazı uçlarda "true"/"false" metni gelir. */
    private static function flag(mixed $value): bool
    {
        return $value === true || (is_string($value) && strtolower($value) === 'true');
    }

    // ------------------------------------------------------------- katalog

    /**
     * Ürünü kanala aktarır — Product V2 `v2/products`.
     *
     * TRENDYOL ÜRÜN YARATMAYI ASENKRON YAPAR: yanıt `batchRequestId`
     * döner, ürün kimliği DEĞİL. Kimlik BARKODDUR ve onu biz belirleriz —
     * bu yüzden `external_id` yükten okunur, yanıttan değil.
     *
     * ⚠️ TOPLU İŞ KİMLİĞİ SAKLANIR (`channel_metadata.batch_request_ids`).
     * Kanal kalemi toplu işin İÇİNDE reddederse HTTP yanıtı yine 200'dür;
     * kimlik saklanmasaydı red hiçbir yerde görünmez ve ürün sonsuza dek
     * "onay bekliyor" kalırdı. Onay takibi bu kimlikle sonucu okur.
     *
     * BAŞARISIZLIKTA İSTİSNA FIRLATILIR, `failure()` DÖNMEZ (§7).
     */
    public function createListing(ListingPayload $payload): AdapterResult
    {
        $mapper = new ListingMapper;
        $item = $mapper->toChannelItem($payload, $this->listingContext($payload, $mapper));

        $response = $this->post($this->sellerUrl('product', 'v2/products'), ['items' => [$item]]);

        $response->throw();

        return $this->batchResult($item['barcode'], [$response->json('batchRequestId')]);
    }

    /**
     * Var olan ürünü günceller — ürünün DURUMUNA göre farklı uç nokta.
     *
     * Onaylı ürün: içerik `content-bulk-update` (contentId ile) + varyant
     * alanları `variant-bulk-update` (barkod ile). Onaysız (bekleyen ya da
     * reddedilmiş) ürün: `unapproved-bulk-update`, yaratmayla aynı gövde.
     *
     * V1 kodu güncellemeyi YARATMA uç noktasına yolluyordu; V2'de aynı
     * barkodla ikinci yaratma kalıcı hata verir.
     */
    public function updateListing(ListingPayload $payload): AdapterResult
    {
        $mapper = new ListingMapper;
        $context = $this->listingContext($payload, $mapper);
        $variantItem = $mapper->toVariantUpdate($payload, $context);
        $barcode = (string) ($payload->listing->external_id ?? $variantItem['barcode']);

        $approved = $this->approvedVariants([$barcode])[$barcode] ?? null;

        if ($approved !== null && $approved['content_id'] !== null) {
            $content = $this->post(
                $this->sellerUrl('product', 'products/content-bulk-update'),
                ['items' => [$mapper->toContentUpdate($payload, (int) $approved['content_id'])]],
            );
            $content->throw();

            $variant = $this->post(
                $this->sellerUrl('product', 'products/variant-bulk-update'),
                ['items' => [[...$variantItem, 'barcode' => $barcode]]],
            );
            $variant->throw();

            return $this->batchResult(
                $barcode,
                [$content->json('batchRequestId'), $variant->json('batchRequestId')],
                ['content_id' => $approved['content_id']],
            );
        }

        $item = [...$mapper->toChannelItem($payload, $context), 'barcode' => $barcode];

        $response = $this->post($this->sellerUrl('product', 'products/unapproved-bulk-update'), ['items' => [$item]]);

        $response->throw();

        return $this->batchResult($barcode, [$response->json('batchRequestId')]);
    }

    /**
     * @param  list<mixed>  $batchIds
     * @param  array<string, mixed>  $metadata
     */
    private function batchResult(string $barcode, array $batchIds, array $metadata = []): AdapterResult
    {
        $batchIds = array_values(array_filter(
            array_map(static fn (mixed $id): string => is_scalar($id) ? (string) $id : '', $batchIds),
            static fn (string $id): bool => $id !== '',
        ));

        return AdapterResult::success([
            // Kimlik BARKODDUR — yanıttaki batch kimliği değil.
            'external_id' => $barcode,
            'batch_request_id' => $batchIds[0] ?? null,
            // SON gönderimin toplu işleri: eski kimlikler EZİLİR, çünkü
            // onay takibi yalnızca güncel gönderimin sonucunu sormalı.
            'channel_metadata' => [...$metadata, 'batch_request_ids' => $batchIds],
        ]);
    }

    /**
     * Ürün yükünün katalog dışı kısmı: kanaldaki marka kimliği ve bağlantı
     * ayarları (KDV, desi, adresler).
     */
    private function listingContext(ListingPayload $payload, ListingMapper $mapper): ListingContext
    {
        $settings = $this->connection->settings ?? [];

        $vatRate = $settings[self::VAT_RATE_KEY] ?? null;
        // Türkçe ondalık virgülü: "1,5" sessizce varsayılana düşmemeli.
        $weight = str_replace(',', '.', (string) ($settings[self::DIMENSIONAL_WEIGHT_KEY] ?? ''));
        $shipment = $settings[self::SHIPMENT_ADDRESS_KEY] ?? null;
        $returning = $settings[self::RETURNING_ADDRESS_KEY] ?? null;

        return new ListingContext(
            brandId: $this->brandId($mapper->brandName($payload)),
            vatRate: is_numeric($vatRate) ? (int) $vatRate : self::DEFAULT_VAT_RATE,
            dimensionalWeight: is_numeric($weight) && (float) $weight > 0
                ? (float) $weight
                : self::DEFAULT_DIMENSIONAL_WEIGHT,
            shipmentAddressId: is_numeric($shipment) ? (int) $shipment : null,
            returningAddressId: is_numeric($returning) ? (int) $returning : null,
        );
    }

    /**
     * Marka ADINDAN Trendyol marka kimliği (`product/brands/by-name`).
     *
     * ⚠️ TAM EŞLEŞME, harf duyarlı (kanal da öyle arar). "Nike" için
     * "Nike Kids" seçilseydi ürün YANLIŞ markayla açılır ve marka
     * onaydan sonra değiştirilemez. Bulunamazsa ürün durur ve satıcı
     * kanaldaki adayları görür.
     *
     * Yalnızca BULUNAN kimlik önbelleğe alınır: "yok" önbelleklenseydi
     * satıcı markayı Trendyol'a başvurup açtırdıktan sonra bir gün boyunca
     * hâlâ "yok" görürdü.
     */
    private function brandId(string $name): int
    {
        $cacheKey = 'trendyol:brand:'.sha1($name);
        $cached = Cache::get($cacheKey);

        if (is_int($cached)) {
            return $cached;
        }

        $response = $this->get(self::baseUrl().'/product/brands/by-name', ['name' => $name]);

        $response->throw();

        $candidates = [];

        foreach ((array) $response->json() as $brand) {
            if (! is_array($brand) || ! isset($brand['id'], $brand['name'])) {
                continue;
            }

            if ((string) $brand['name'] === $name) {
                Cache::put($cacheKey, (int) $brand['id'], self::BRAND_CACHE_SECONDS);

                return (int) $brand['id'];
            }

            $candidates[] = (string) $brand['name'];
        }

        throw new ListingNotPublishable($candidates === []
            ? "\"{$name}\" markası Trendyol'da yok; markayı Trendyol satıcı panelinden açtırmanız gerekir."
            : "\"{$name}\" markası Trendyol'da birebir bulunamadı. Benzerleri: ".implode(', ', array_slice($candidates, 0, 5)).'. Üründeki marka adını bunlardan biriyle aynı yazın.');
    }

    public function delist(Listing $listing): AdapterResult
    {
        throw $this->notImplemented('listeden çıkarma');
    }

    /**
     * Kanalda aynı barkodlu ürün var mı — KOPYA LİSTELEME KORUMASI.
     *
     * Satıcı ürünü daha önce Trendyol panelinden açmış olabilir.
     * Sormadan yaratmak kopya listeleme üretir ve geri alınamaz: yorumlar,
     * sıralama ve SEO geçmişi ilk üründe kalır.
     */
    public function findExistingListing(Variant $variant): ?RemoteListing
    {
        $barcode = $variant->barcode ?? $variant->sku;

        if ($barcode === null || trim((string) $barcode) === '') {
            return null;
        }

        $barcode = (string) $barcode;

        $approved = $this->approvedVariants([$barcode])[$barcode] ?? null;

        if ($approved !== null) {
            return new RemoteListing(
                externalId: $barcode,
                title: $approved['title'],
                url: $approved['url'],
                raw: $approved['raw'],
            );
        }

        // ⚠️ ONAY BEKLEYEN ÜRÜN DE "VAR" SAYILIR. Yalnızca onaylılara
        // bakılsaydı satıcının panelden açtığı ama henüz onaylanmamış
        // ürün görünmez, aynı barkod ikinci kez gönderilir ve kanal onu
        // kalıcı `VALIDATION` ile reddederdi.
        $pending = $this->unapprovedProducts([$barcode])[$barcode] ?? null;

        if ($pending !== null) {
            return new RemoteListing(
                externalId: $barcode,
                title: isset($pending['title']) ? (string) $pending['title'] : null,
                url: null,
                raw: $pending,
            );
        }

        return null;
    }

    public function fetchListing(Listing $listing): ?RemoteListing
    {
        throw $this->notImplemented('uzak listing okuma');
    }

    // ------------------------------------------------------------- sipariş

    /**
     * Siparişleri YOKLAMA ile çeker — webhook yoktur.
     *
     * Mimari Karar Dokümanı v2.2 · §13 · Faz 2 ("Sipariş yoklaması"),
     * §7 · SupportsOrders, §14.
     *
     * TARİH MİLİSANİYE EPOCH'TUR: Trendyol saniye kabul etmez. Saniye
     * gönderilseydi pencere 1970'e düşer ve kanal TÜM sipariş geçmişini
     * döndürürdü — ilk turda binlerce sipariş, tükenen kota ve saatlerce
     * süren bir tur.
     *
     * HAM GÖVDE DÖNER: ayrıştırma `parseOrderEvent` ile SONRA yapılır.
     * Sıra bilinçlidir — ayrıştırma hatası siparişin kaybolmasına değil,
     * inbox satırının hata durumuna düşmesine yol açar.
     *
     * BAŞARISIZ YANIT YÜKSELTİLİR: `json()` bir 500 gövdesinde de dizi
     * döndürür ve boş sayfa "yeni sipariş yok" diye okunurdu; imleç
     * ilerler ve o penceredeki siparişler bir daha HİÇ sorulmazdı.
     */
    public function fetchOrders(CarbonInterface $since, ?string $cursor = null): OrderPage
    {
        $page = $cursor === null ? 0 : max(0, (int) $cursor);

        $response = $this->get($this->sellerUrl('order', 'v2/orders'), [
            // MİLİSANİYE — saniye değil.
            'startDate' => $since->getTimestampMs(),
            'page' => $page,
            'size' => self::ORDER_PAGE_SIZE,
            // Eskiden yeniye: tur yarıda kalırsa imleç en eski işlenmemiş
            // siparişin gerisinde kalır ve hiçbir şey atlanmaz.
            'orderByField' => 'PackageLastModifiedDate',
            'orderByDirection' => 'ASC',
        ]);

        // Sessizce boş sayfaya düşme — yükselt.
        $response->throw();

        $orders = array_values(array_filter(
            (array) ($response->json('content') ?? []),
            'is_array',
        ));

        $totalPages = (int) ($response->json('totalPages') ?? 1);
        $hasMore = $page + 1 < $totalPages;

        return new OrderPage(
            orders: $orders,
            nextCursor: $hasMore ? (string) ($page + 1) : null,
            hasMore: $hasMore,
        );
    }

    /**
     * Yoklanan siparişin olay kimliği — `{orderNumber}:{status}`.
     *
     * ⚠️ KİMLİK DURUMU TAŞIR. Yalnızca sipariş numarasına bağlansaydı aynı
     * siparişin sonraki İPTALİ birincil tekillik indeksine takılır ve
     * `insertOrIgnore` tarafından SESSİZCE YUTULURDU — stok geri
     * eklenmez, bakiye kalıcı eksik kalırdı (§1 · Karar 24).
     *
     * `parseOrderEvent()` ile AYNI biçimi üretir ve bu bir tesadüf
     * değildir: normalizer `external_event_id`'yi çıpası olarak kullanır,
     * ayrışsalardı inbox satırı ile `order_events` satırı farklı
     * kimliklere bağlanırdı.
     *
     * @param  array<string, mixed>  $order
     */
    public function pollingEventIdFor(array $order): ?string
    {
        $package = self::packageId($order);

        if ($package === null) {
            return null;
        }

        $status = self::packageStatus($order);

        return $status === '' ? $package : "{$package}:{$status}";
    }

    /**
     * Ham Trendyol siparişini kanonik olaya çevirir — TİP dahil.
     *
     * DEĞİŞMEZ KURAL — TİP AYRIMI (§1 · Karar 24):
     *   created / updated / cancelled / returned AYRI yollara gider. Tek
     *   yola sokulsaydı iptal ve iade siparişin yeniden yaratılması gibi
     *   işlenir ve stok İKİ KEZ düşerdi.
     *
     * DEĞİŞMEZ KURAL — BİLİNMEYEN DURUM `updated`:
     *   Trendyol durum listesini genişletebilir. Bilinmeyen bir durumu
     *   `created` saymak var olan siparişi yeniden yaratmayı denerdi;
     *   `cancelled` saymak satılmış stoğu geri eklerdi. İkisi de bakiyeyi
     *   bozar. `updated` stok hareketi ÜRETMEZ ve güvenli olanıdır.
     *
     * ÇIPA DURUMU TAŞIR: `externalRef` stok hareketi idempotency
     * anahtarının çıpasıdır ve yalnızca sipariş numarasına bağlansaydı
     * aynı siparişin iptali ile iadesi `order_events` üzerinde çakışır,
     * ikincisi sessizce yutulurdu.
     */
    public function parseOrderEvent(InboxMessage $message): ?NormalizedOrderEvent
    {
        /** @var array<string, mixed> $payload */
        $payload = is_array($message->payload) ? $message->payload : [];

        $package = self::packageId($payload);

        if ($package === null) {
            // Kimliksiz gövdeden sipariş yaratılamaz; satır hata durumuna
            // düşer ve elle incelenir — sessizce yutulmaz.
            return null;
        }

        $orderNumber = (string) ($payload['orderNumber'] ?? $package);
        $status = self::packageStatus($payload);
        $type = self::STATUS_TO_TYPE[$status] ?? 'updated';

        return new NormalizedOrderEvent(
            type: $type,
            // ⚠️ SİPARİŞ BİRİMİ PAKETTİR, sipariş numarası DEĞİL. Bir
            // sipariş birden çok pakete bölünebilir; numaraya bağlansaydı
            // ikinci paketin `created`'ı "bu sipariş zaten alınmış" diye
            // atlanır ve o paketin stoğu HİÇ düşmezdi. Numara
            // `external_number` olarak görünür kalır.
            externalOrderId: $package,
            // Çıpa DURUMU taşır — aynı paketin iki olayı çakışamaz.
            externalRef: $message->external_event_id ?? "{$package}:{$status}",
            payload: $this->toCanonicalOrderPayload($payload, $type, $orderNumber, $status),
            occurredAt: $this->parseOrderDate($payload),
        );
    }

    /**
     * Trendyol gövdesini `OrderPayloadMapper`'ın beklediği biçime çevirir.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function toCanonicalOrderPayload(array $payload, string $type, string $orderNumber, string $status): array
    {
        // v2 alanları `package*` önekini taşır; eski adlar geri düşüş.
        $total = $payload['packageTotalPrice'] ?? $payload['totalPrice'] ?? '0';

        return [
            'type' => $type,
            'external_number' => $orderNumber,
            'status' => $status !== '' ? $status : 'pending',
            'financial_status' => $status === 'Returned' ? 'refunded' : null,
            'currency' => (string) ($payload['currencyCode'] ?? 'TRY'),
            'subtotal' => (string) $total,
            'shipping_total' => (string) ($payload['totalShippingPrice'] ?? '0'),
            'tax_total' => '0',
            'grand_total' => (string) ($payload['packageGrossAmount'] ?? $payload['grossAmount'] ?? $total),
            'lines' => $this->orderLines($payload),
            // Kişisel veri taşınmaz; yalnızca referans.
            'customer_ref' => array_filter([
                'external_customer_id' => isset($payload['customerId'])
                    ? (string) $payload['customerId']
                    : null,
            ]),
        ];
    }

    /**
     * Sipariş kalemleri.
     *
     * SKU BARKODDUR: Trendyol ürünü barkodla tanır ve listing'in
     * `external_id`'si de odur (`ListingMapper`). Eşleşmezse
     * `order_lines.variant_id` NULL kalır, satır PENDING olur ve sipariş
     * KAYBEDİLMEZ (Karar 24).
     *
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    private function orderLines(array $payload): array
    {
        $lines = [];

        foreach ((array) ($payload['lines'] ?? []) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $quantity = (int) ($item['quantity'] ?? 0);

            // v2: `lineId`, `lineUnitPrice`, `lineGrossAmount`, `stockCode`.
            $lines[] = [
                'external_line_id' => (string) ($item['lineId'] ?? $item['id'] ?? ''),
                'sku' => (string) ($item['barcode'] ?? $item['stockCode'] ?? $item['merchantSku'] ?? ''),
                'title' => (string) ($item['productName'] ?? $item['barcode'] ?? ''),
                'quantity' => $quantity,
                'unit_price' => (string) ($item['lineUnitPrice'] ?? $item['price'] ?? $item['amount'] ?? '0'),
                'line_total' => (string) ($item['lineGrossAmount'] ?? $item['amount'] ?? '0'),
            ];
        }

        return $lines;
    }

    /**
     * Paket kimliği — v2'de `shipmentPackageId`, eski gövdede `id`.
     *
     * @param  array<string, mixed>  $payload
     */
    private static function packageId(array $payload): ?string
    {
        $id = $payload['shipmentPackageId'] ?? $payload['id'] ?? null;

        return $id === null || (string) $id === '' ? null : (string) $id;
    }

    /**
     * Paket durumu — v2'de `shipmentPackageStatus`, yoksa `status`.
     *
     * @param  array<string, mixed>  $payload
     */
    private static function packageStatus(array $payload): string
    {
        return (string) ($payload['shipmentPackageStatus'] ?? $payload['status'] ?? '');
    }

    /** @param array<string, mixed> $payload */
    private function parseOrderDate(array $payload): ?DateTimeImmutable
    {
        $raw = $payload['orderDate'] ?? $payload['lastModifiedDate'] ?? null;

        if (! is_numeric($raw)) {
            return null;
        }

        // Kanal milisaniye epoch gönderir.
        return (new DateTimeImmutable)->setTimestamp(intdiv((int) $raw, 1000));
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

    /**
     * Onay durumlarını TOPLU okur.
     *
     * Mimari Karar Dokümanı v2.2 · §7 · SupportsApprovalWorkflow, §14.
     *
     * TOPLU OKUNUR: listing başına ayrı istek, 500 ürünlü katalogda 500
     * istek demektir ve kotayı anlamsızca tüketir.
     *
     * KİMLİĞİ OLMAYAN LISTING SORULMAZ: `external_id` NULL ise ürün kanala
     * hiç gitmemiştir. Hiç kimlik kalmazsa çağrı da YAPILMAZ — boş bir
     * filtreyle istek atmak kanalın TÜM kataloğunu geri getirirdi.
     *
     * YANITTA OLMAYAN LISTING İÇİN DURUM UYDURULMAZ: Trendyol yeni
     * gönderilen ürünü listeye hemen koymaz ve yokluğu red saymak satıcıyı
     * var olmayan bir hatayı düzeltmeye gönderirdi. Anahtar yoksa
     * `statusFor()` null döner ve çekirdek satıra dokunmaz.
     *
     * BAŞARISIZ YANIT YÜKSELTİLİR: `json()` bir 500 gövdesinde de dizi
     * döndürür ve boş sonuç "hiçbiri onaylanmadı" diye yorumlanırdı —
     * taksonomide bire bir aynı hata yaşandı.
     *
     * @param  list<Listing>  $listings
     */
    public function fetchApprovalStatus(array $listings): ApprovalStatusBatch
    {
        $barcodes = $this->barcodesOf($listings);

        // Sorulacak kimlik yoksa çağrı yapılmaz: filtresiz istek kanalın
        // tüm kataloğunu getirirdi.
        if ($barcodes === []) {
            return new ApprovalStatusBatch([]);
        }

        $statuses = [];

        foreach ($this->approvedVariants($barcodes) as $barcode => $row) {
            $statuses[$barcode] = [
                'status' => $row['sellable'] ? 'approved' : 'inactive',
                'reason' => $row['sellable'] ? null : $row['lock_reason'],
            ];
        }

        $remaining = array_values(array_diff($barcodes, array_keys($statuses)));

        // ⚠️ YALNIZCA `rejected` SORULUR. V1 kodu `approved: false` olan
        // HER satırı red sayıyordu — onay BEKLEYEN ürün de "reddedildi"
        // görünür ve satıcı var olmayan bir hatayı düzeltmeye giderdi.
        // Bekleyen ürün için durum uydurulmaz (sınıf notu).
        if ($remaining !== []) {
            foreach ($this->unapprovedProducts($remaining, 'rejected') as $barcode => $row) {
                $statuses[$barcode] = [
                    'status' => 'rejected',
                    'reason' => $this->rejectionReason($row),
                ];
            }
        }

        // ⚠️ TOPLU İŞTE REDDEDİLEN KALEM ÜRÜN OLARAK HİÇ OLUŞMAZ ve iki
        // filtrede de görünmez. Sonucu okunmasaydı satıcı "onay bekliyor"
        // yazısına sonsuza dek bakardı (A11 ④b).
        foreach ($listings as $listing) {
            $barcode = (string) $listing->external_id;

            if ($barcode === '' || isset($statuses[$barcode])) {
                continue;
            }

            $failure = $this->batchFailure((array) ($listing->channel_metadata['batch_request_ids'] ?? []));

            if ($failure !== null) {
                $statuses[$barcode] = ['status' => 'rejected', 'reason' => $failure];
            }
        }

        return new ApprovalStatusBatch($statuses, new DateTimeImmutable);
    }

    /**
     * Toplu işlerin başarısız kalem sebepleri; hepsi başarılı ya da henüz
     * bitmemişse null.
     *
     * Kanal sonucu sınırlı süre saklar (ürün işlerinde ~4 saat): süresi
     * dolmuş iş 404 döner ve "bilinmiyor" sayılır — red UYDURULMAZ.
     *
     * @param  list<mixed>  $batchIds
     */
    private function batchFailure(array $batchIds): ?string
    {
        $reasons = [];

        foreach ($batchIds as $batchId) {
            if (! is_scalar($batchId) || (string) $batchId === '') {
                continue;
            }

            $response = $this->get($this->sellerUrl('product', 'products/batch-requests/'.rawurlencode((string) $batchId)));

            if ($response->status() === 404) {
                continue;
            }

            $response->throw();

            if ($response->json('status') !== 'COMPLETED') {
                continue;
            }

            foreach ((array) ($response->json('items') ?? []) as $item) {
                if (! is_array($item) || ($item['status'] ?? null) !== 'FAILED') {
                    continue;
                }

                foreach ((array) ($item['failureReasons'] ?? []) as $reason) {
                    if (is_string($reason) && $reason !== '') {
                        $reasons[] = $reason;
                    }
                }

                // Sebepsiz başarısızlık da başarısızlıktır.
                if ($reasons === []) {
                    $reasons[] = 'Trendyol toplu işi kalemi reddetti (sebep belirtilmedi).';
                }
            }
        }

        return $reasons === [] ? null : implode(' · ', array_unique($reasons));
    }

    /**
     * Red sebebi — kanal onu iç içe bir listede taşır.
     *
     * SEBEP GÖSTERİLMEK ZORUNDADIR: "reddedildi" tek başına satıcıya ne
     * düzelteceğini söylemez. Birden çok sebep varsa hepsi birleştirilir;
     * ilkini almak satıcıyı düzeltip yeniden reddedilmeye gönderirdi.
     * V2: `{rejectReason, rejectReasonDetail}` — ikisi de gösterilir;
     * ayrıntı ne yapılacağını söyler.
     *
     * @param  array<string, mixed>  $row
     */
    private function rejectionReason(array $row): ?string
    {
        $details = $row['rejectReasonDetails'] ?? [];

        if (! is_array($details) || $details === []) {
            return null;
        }

        $reasons = [];

        foreach ($details as $detail) {
            $reason = is_array($detail)
                ? implode(': ', array_filter([
                    $detail['rejectReason'] ?? $detail['reason'] ?? null,
                    $detail['rejectReasonDetail'] ?? null,
                ], static fn (mixed $v): bool => is_string($v) && $v !== ''))
                : $detail;

            if (is_string($reason) && $reason !== '') {
                $reasons[] = $reason;
            }
        }

        return $reasons === [] ? null : implode(' · ', $reasons);
    }

    // ------------------------------------------------------------------ iç

    /**
     * Taksonomi istemcisi.
     *
     * Ağaç uç noktası satıcıya özgü DEĞİLDİR (kategori ağacı tüm satıcılar
     * için aynıdır), bu yüzden `supplierPath()` kullanılmaz.
     */
    private function taxonomy(): TaxonomyClient
    {
        return new TaxonomyClient($this->client, self::baseUrl(), $this->defaultHeaders());
    }

    /**
     * Her isteğe eklenen başlıklar.
     *
     * ⚠️ `User-Agent` ZORUNLUDUR: Trendyol başlıksız ya da biçimsiz
     * isteği 403 ile reddeder — anahtar DOĞRU olsa bile. 403 bizde
     * `AUTHENTICATION` sayılır ve KALICIDIR: satıcı "anahtarın yanlış"
     * uyarısıyla anahtarını defalarca yeniden girer, hiçbiri işe yaramaz
     * (`97a7eb7` hata biçimi). Biçim: `{satıcı ID} - {entegratör adı}`.
     *
     * İstekler YALNIZCA `get()`/`post()` üzerinden gider; doğrudan
     * `$this->client` çağrısı başlığı atlardı.
     *
     * @return array<string, string>
     */
    private function defaultHeaders(): array
    {
        $name = trim((string) ($this->connection->settings[self::INTEGRATOR_NAME_KEY] ?? ''));

        return ['User-Agent' => $this->sellerId().' - '.($name !== '' ? $name : self::DEFAULT_INTEGRATOR_NAME)];
    }

    /** @param array<string, mixed> $query */
    private function get(string $endpoint, array $query = []): Response
    {
        return $this->client->get($endpoint, $query, headers: $this->defaultHeaders());
    }

    /** @param array<string, mixed> $body */
    private function post(string $endpoint, array $body): Response
    {
        return $this->client->post($endpoint, $body, headers: $this->defaultHeaders());
    }

    /** Satıcı kimliği; yoksa istisna — kimliksiz istek 403 alırdı. */
    private function sellerId(): string
    {
        $sellerId = (string) ($this->connection->settings[self::SELLER_ID_KEY] ?? '');

        if ($sellerId === '') {
            throw new RuntimeException(
                "Trendyol bağlantısında satıcı kimliği yok: {$this->connection->id}"
            );
        }

        return $sellerId;
    }

    /**
     * Satıcıya özgü TAM adres: `{taban}/{servis}/sellers/{id}/{uç}`.
     *
     * Satıcı kimliği YOL ÜZERİNDEDİR: doğru anahtarla yanlış kimlik başka
     * bir satıcının kaynağını ister ve 403 alır.
     *
     * ⚠️ ADRES TAM VERİLİR, `settings.base_url` OKUNMAZ. Eski yollar
     * (`sapigw/suppliers/{id}/...`) Trendyol'da kapatıldı ve panelden
     * kurulan bağlantının `base_url`'ü Woo ayrıştırıcısından
     * geliyordu — taban adresi satıcı değil kanal belirler.
     */
    private function sellerUrl(string $service, string $endpoint): string
    {
        $service = $service === '' ? '' : trim($service, '/').'/';

        return self::baseUrl()."/{$service}sellers/{$this->sellerId()}/".ltrim($endpoint, '/');
    }

    /**
     * Entegrasyon taban adresi. Yalnızca `services.trendyol.base_url` ile
     * (stage ortamı: `https://stageapigw.trendyol.com/integration`)
     * değişir; satıcı formundan GELMEZ — gelseydi anahtarlar satıcının
     * yazdığı herhangi bir adrese Basic auth ile gönderilirdi.
     */
    public static function baseUrl(): string
    {
        return rtrim((string) (config('services.trendyol.base_url') ?: self::BASE_URL), '/');
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
