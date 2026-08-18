<?php

declare(strict_types=1);

namespace App\Domain\Channels\Adapters\Trendyol;

use App\Domain\Catalog\Models\Variant;
use App\Domain\Channels\Adapters\Trendyol\Catalog\ListingMapper;
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
use DateTimeImmutable;
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
        'UnPacked' => 'updated',
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

        $response = $this->client->post(
            $this->supplierPath('v2/products/price-and-inventory'),
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

        foreach ($rows as $row) {
            $barcode = (string) ($row['barcode'] ?? '');

            if ($barcode === '') {
                continue;
            }

            $quantities[$barcode] = (int) ($row['quantity'] ?? 0);
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

        $response = $this->client->post(
            $this->supplierPath('v2/products/price-and-inventory'),
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

        foreach ($rows as $row) {
            $barcode = (string) ($row['barcode'] ?? '');

            if ($barcode === '') {
                continue;
            }

            $prices[$barcode] = (string) ($row['salePrice'] ?? '0');
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
     * @return list<array<string, mixed>>|null Sorulacak kimlik yoksa null
     */
    private function fetchRemoteRows(array $listings): ?array
    {
        $barcodes = [];

        foreach ($listings as $listing) {
            if ($listing->external_id !== null) {
                $barcodes[] = $listing->external_id;
            }
        }

        // Filtresiz istek kanalın TÜM kataloğunu getirirdi.
        if ($barcodes === []) {
            return null;
        }

        $barcodes = array_values(array_unique($barcodes));

        $response = $this->client->get($this->supplierPath('products'), [
            'barcode' => implode(',', $barcodes),
            'size' => count($barcodes),
        ]);

        // Sessizce boş snapshot'a düşme — yükselt.
        $response->throw();

        $rows = $response->json('content') ?? [];

        return array_values(array_filter($rows, 'is_array'));
    }

    // ------------------------------------------------------------- katalog

    /**
     * Ürünü kanala aktarır.
     *
     * TRENDYOL ÜRÜN YARATMAYI ASENKRON YAPAR: yanıt `batchRequestId`
     * döner, ürün kimliği DEĞİL. Kimlik BARKODDUR ve onu biz belirleriz —
     * bu yüzden `external_id` yükten okunur, yanıttan değil. Batch
     * kimliğini `external_id` sanmak, sonraki güncellemede var olmayan bir
     * ürünü aramak demekti.
     *
     * BAŞARISIZLIKTA İSTİSNA FIRLATILIR, `failure()` DÖNMEZ (§7):
     *   sınıflandırma ve yeniden deneme kararı `PushListing`'deki tek
     *   try/catch'te toplanır.
     */
    public function createListing(ListingPayload $payload): AdapterResult
    {
        $item = (new ListingMapper)->toChannelItem($payload);

        $response = $this->client->post(
            $this->supplierPath('v2/products'),
            ['items' => [$item]],
        );

        $response->throw();

        return AdapterResult::success([
            // Kimlik BARKODDUR — yanıttaki batch kimliği değil.
            'external_id' => $item['barcode'],
            'batch_request_id' => $response->json('batchRequestId'),
        ]);
    }

    /**
     * Var olan ürünü günceller.
     *
     * Trendyol'da güncelleme AYRI bir uç noktadır (`v2/products` PUT
     * değil, `v2/products/price-and-inventory` de değil): içerik
     * güncellemesi `v2/products` üzerinden aynı barkodla yapılır ve kanal
     * onu güncelleme sayar.
     */
    public function updateListing(ListingPayload $payload): AdapterResult
    {
        $item = (new ListingMapper)->toChannelItem($payload);

        $response = $this->client->post(
            $this->supplierPath('v2/products'),
            ['items' => [$item]],
        );

        $response->throw();

        return AdapterResult::success([
            'external_id' => $payload->listing->external_id ?? $item['barcode'],
            'batch_request_id' => $response->json('batchRequestId'),
        ]);
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

        $response = $this->client->get(
            $this->supplierPath('products'),
            ['barcode' => $barcode],
        );

        $response->throw();

        foreach ($response->json('content') ?? [] as $row) {
            // Kanal benzer barkodları da döndürebilir; TAM eşleşme aranır.
            if ((string) ($row['barcode'] ?? '') !== (string) $barcode) {
                continue;
            }

            return new RemoteListing(
                externalId: (string) $row['barcode'],
                title: $row['title'] ?? null,
                url: $row['productUrl'] ?? null,
                raw: $row,
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

        $response = $this->client->get($this->supplierPath('orders'), [
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

        $orderNumber = $payload['orderNumber'] ?? $payload['id'] ?? null;

        if ($orderNumber === null || (string) $orderNumber === '') {
            // Kimliksiz gövdeden sipariş yaratılamaz; satır hata durumuna
            // düşer ve elle incelenir — sessizce yutulmaz.
            return null;
        }

        $orderNumber = (string) $orderNumber;
        $status = (string) ($payload['status'] ?? '');
        $type = self::STATUS_TO_TYPE[$status] ?? 'updated';

        return new NormalizedOrderEvent(
            type: $type,
            externalOrderId: $orderNumber,
            // Çıpa DURUMU taşır — aynı siparişin iki olayı çakışamaz.
            externalRef: $message->external_event_id ?? "{$orderNumber}:{$status}",
            payload: $this->toCanonicalOrderPayload($payload, $type, $orderNumber),
            occurredAt: $this->parseOrderDate($payload),
        );
    }

    /**
     * Trendyol gövdesini `OrderPayloadMapper`'ın beklediği biçime çevirir.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function toCanonicalOrderPayload(array $payload, string $type, string $orderNumber): array
    {
        return [
            'type' => $type,
            'external_number' => $orderNumber,
            'status' => (string) ($payload['status'] ?? 'pending'),
            'financial_status' => ($payload['status'] ?? null) === 'Returned' ? 'refunded' : null,
            'currency' => (string) ($payload['currencyCode'] ?? 'TRY'),
            'subtotal' => (string) ($payload['totalPrice'] ?? '0'),
            'shipping_total' => (string) ($payload['totalShippingPrice'] ?? '0'),
            'tax_total' => '0',
            'grand_total' => (string) ($payload['grossAmount'] ?? $payload['totalPrice'] ?? '0'),
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

            $lines[] = [
                'external_line_id' => (string) ($item['id'] ?? ''),
                'sku' => (string) ($item['barcode'] ?? $item['merchantSku'] ?? ''),
                'title' => (string) ($item['productName'] ?? $item['barcode'] ?? ''),
                'quantity' => $quantity,
                'unit_price' => (string) ($item['price'] ?? $item['amount'] ?? '0'),
                'line_total' => (string) ($item['amount'] ?? '0'),
            ];
        }

        return $lines;
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
        $barcodes = [];

        foreach ($listings as $listing) {
            if ($listing->external_id !== null) {
                $barcodes[] = $listing->external_id;
            }
        }

        // Sorulacak kimlik yoksa çağrı yapılmaz: filtresiz istek kanalın
        // tüm kataloğunu getirirdi.
        if ($barcodes === []) {
            return new ApprovalStatusBatch([]);
        }

        $response = $this->client->get(
            $this->supplierPath('products'),
            ['barcode' => implode(',', array_unique($barcodes)), 'size' => count($barcodes)],
        );

        // Sessizce boş ağaca/listeye düşme — yükselt.
        $response->throw();

        $statuses = [];

        foreach ($response->json('content') ?? [] as $row) {
            $barcode = (string) ($row['barcode'] ?? '');

            if ($barcode === '') {
                continue;
            }

            $statuses[$barcode] = $this->classifyApproval($row);
        }

        return new ApprovalStatusBatch($statuses, new DateTimeImmutable);
    }

    /**
     * Kanal satırını kanonik onay durumuna çevirir.
     *
     * ONAYLANMIŞ AMA SATIŞA KAPALI ÜRÜN "approved" SAYILMAZ:
     *   Trendyol'da `approved: true` + `onSale: false` mümkündür (satıcı
     *   kapatmış olabilir). O satır kanalda GÖRÜNMEZ; "onaylandı" demek
     *   satıcıya ürünün yayında olduğunu düşündürür ve neden satmadığını
     *   araştırmasına engel olurdu.
     *
     * @param  array<string, mixed>  $row
     * @return array{status: string, reason: string|null}
     */
    private function classifyApproval(array $row): array
    {
        $approved = (bool) ($row['approved'] ?? false);
        $onSale = (bool) ($row['onSale'] ?? false);

        if (! $approved) {
            return [
                'status' => 'rejected',
                'reason' => $this->rejectionReason($row),
            ];
        }

        return [
            'status' => $onSale ? 'approved' : 'inactive',
            'reason' => null,
        ];
    }

    /**
     * Red sebebi — kanal onu iç içe bir listede taşır.
     *
     * SEBEP GÖSTERİLMEK ZORUNDADIR: "reddedildi" tek başına satıcıya ne
     * düzelteceğini söylemez. Birden çok sebep varsa hepsi birleştirilir;
     * ilkini almak satıcıyı düzeltip yeniden reddedilmeye gönderirdi.
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
            $reason = is_array($detail) ? ($detail['reason'] ?? null) : $detail;

            if (is_string($reason) && $reason !== '') {
                $reasons[] = $reason;
            }
        }

        return $reasons === [] ? null : implode(' · ', $reasons);
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
