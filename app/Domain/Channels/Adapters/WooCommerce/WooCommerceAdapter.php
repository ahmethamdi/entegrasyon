<?php

declare(strict_types=1);

namespace App\Domain\Channels\Adapters\WooCommerce;

use App\Domain\Catalog\Models\Variant;
use App\Domain\Channels\Contracts\AdapterResult;
use App\Domain\Channels\Contracts\ChannelAdapter;
use App\Domain\Channels\Contracts\HealthResult;
use App\Domain\Channels\Contracts\RateLimitProfile;
use App\Domain\Channels\Contracts\SupportsCatalog;
use App\Domain\Channels\Contracts\SupportsCatalogImport;
use App\Domain\Channels\Contracts\SupportsFulfillment;
use App\Domain\Channels\Contracts\SupportsInventory;
use App\Domain\Channels\Contracts\SupportsOrders;
use App\Domain\Channels\Contracts\SupportsPricing;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Support\ChannelHttpClient;
use App\Domain\Channels\Support\CredentialVault;
use App\Domain\Messaging\Models\InboxMessage;
use App\Domain\Orders\Models\Fulfillment;
use App\Domain\Orders\Models\Order;
use App\Domain\Sync\Enums\ErrorClass;
use App\Domain\Sync\Models\Listing;
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
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonInterface;
use DateTimeImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Throwable;

/**
 * WooCommerce kanal adapter'ı — wc/v3 REST API.
 *
 * Mimari Karar Dokümanı v2.2 · §7, §13 · faz 1.4 ve 1.7, §1 · Karar 25–26.
 *
 * MAĞAZA KANALI: taksonomi ve onay süreci YOKTUR — kategori serbesttir.
 * Bu yüzden SupportsTaxonomy ve SupportsApprovalWorkflow UYGULANMAZ; panelde
 * o sekmeler `instanceof` ile kapanır, kanal adı kontrol edilmez.
 *
 * DEĞİŞMEZ KURAL — ADAPTER YAN ETKİSİZDİR:
 *   Veritabanına yazmaz, kuyruğa iş atmaz, durum güncellemez. Girdi alır,
 *   kanalla konuşur, AdapterResult döner. Durumu SyncResultRecorder yazar.
 *   (api_calls günlüğü ChannelHttpClient'ın işidir — teknik kayıt, durum değil.)
 *
 * DEĞİŞMEZ KURAL — STOK MUTLAK DEĞER:
 *   Delta ASLA gönderilmez. Kaybolan veya iki kez işlenen bir istek delta
 *   modelinde bakiyeyi kalıcı olarak kaydırır. Mutlak değerde tekrar
 *   zararsızdır — yeniden denemenin güvenliği buna dayanır.
 *   Kırpma burada YAPILMAZ: yükü kuran InventoryBatchBuilder
 *   OutboundQuantity::forChannel() uygulamıştır ve iki yerde kırpmak,
 *   birinin unutulduğu gün fark edilmeyen bir hata demektir.
 *
 * DEĞİŞMEZ KURAL — SINIFLANDIRMA BURADA, KARAR ÇEKİRDEKTE:
 *   classifyError() Woo'nun cevabını çekirdeğin anladığı sınıfa çevirir;
 *   ne yapılacağına RetryPolicy karar verir.
 *
 * Webhook bir yetenek değil taşıma biçimidir — SupportsWebhooks arayüzü
 * YOKTUR. İmza doğrulama ve olay kimliği çıkarma ChannelAdapter'ın parçası.
 */
final class WooCommerceAdapter implements ChannelAdapter, SupportsCatalog, SupportsCatalogImport, SupportsFulfillment, SupportsInventory, SupportsOrders, SupportsPricing
{
    public function __construct(
        private readonly ChannelConnection $connection,
        private readonly ChannelHttpClient $client,
    ) {}

    public function connection(): ChannelConnection
    {
        return $this->connection;
    }

    // ---------------------------------------------------------------- sağlık

    public function healthCheck(): HealthResult
    {
        $startedAt = hrtime(true);

        try {
            $response = $this->client->get('system_status');

            $latency = (int) round((hrtime(true) - $startedAt) / 1_000_000);

            return $response->successful()
                ? HealthResult::healthy(latencyMs: $latency)
                : HealthResult::unhealthy("HTTP {$response->status()}");
        } catch (Throwable $e) {
            return HealthResult::unhealthy($e->getMessage());
        }
    }

    // ---------------------------------------------------------------- stok

    /**
     * MUTLAK stok değeri gönderir — wc/v3 batch uç noktası.
     *
     * manage_stock ZORUNLU: Woo stok yönetimi kapalıyken stock_quantity
     * alanını sessizce yok sayar ve senkron başarılı görünürken hiçbir şey
     * değişmez — teşhisi en zor hata sınıfı.
     */
    public function pushInventory(InventoryPushBatch $batch): AdapterResult
    {
        if ($batch->isEmpty()) {
            // Boş yük için çağrı yapılmaz; kota boşa harcanmaz.
            return AdapterResult::success(['pushed' => 0]);
        }

        $updates = array_map(
            static fn (array $item): array => [
                'id' => (int) $item['external_id'],
                'manage_stock' => true,
                // MUTLAK değer. Kırpma yukarıda yapıldı; burada tekrar yok.
                'stock_quantity' => $item['quantity'],
                'stock_status' => $item['quantity'] > 0 ? 'instock' : 'outofstock',
            ],
            $batch->toArray(),
        );

        $response = $this->client->post('products/batch', ['update' => $updates]);

        // Başarısızlık İSTİSNA olarak yükselir: sınıflandırma ve yeniden
        // deneme kararı iş tarafındaki tek try/catch'te toplanır (§12).
        $response->throw();

        return AdapterResult::success([
            'pushed' => $batch->count(),
            'updated' => count((array) $response->json('update', [])),
        ]);
    }

    /**
     * Uzak stok durumunu okur — mutabakatın karşılaştırma girdisi.
     *
     * @param  list<Listing>  $listings
     */
    public function fetchInventory(array $listings): RemoteInventorySnapshot
    {
        $ids = array_values(array_filter(array_map(
            static fn (Listing $l): ?string => $l->external_id,
            $listings,
        )));

        if ($ids === []) {
            return new RemoteInventorySnapshot([]);
        }

        $response = $this->client->get('products', [
            'include' => implode(',', $ids),
            'per_page' => 100,
        ]);

        $response->throw();

        $quantities = [];

        foreach ((array) $response->json() as $product) {
            if (! is_array($product) || ! isset($product['id'])) {
                continue;
            }

            $quantities[(string) $product['id']] = (int) ($product['stock_quantity'] ?? 0);
        }

        // Okuma anı taşınır: gecikmeli okuma sürüklenme sanılmamalı (§10).
        return new RemoteInventorySnapshot($quantities, new DateTimeImmutable);
    }

    public function maxInventoryBatchSize(): int
    {
        return 100;   // wc/v3 batch uç noktası
    }

    // ---------------------------------------------------------------- fiyat

    public function pushPrices(PricePushBatch $batch): AdapterResult
    {
        if ($batch->isEmpty()) {
            return AdapterResult::success(['pushed' => 0]);
        }

        $updates = array_map(
            static fn (array $item): array => [
                'id' => (int) $item['external_id'],
                // Fiyat da MUTLAK: yüzde indirim veya delta gönderilmez.
                'regular_price' => (string) $item['price'],
            ],
            $batch->items,
        );

        $response = $this->client->post('products/batch', ['update' => $updates]);

        $response->throw();

        return AdapterResult::success(['pushed' => $batch->count()]);
    }

    /** @param list<Listing> $listings */
    public function fetchPrices(array $listings): RemotePriceSnapshot
    {
        $ids = array_values(array_filter(array_map(
            static fn (Listing $l): ?string => $l->external_id,
            $listings,
        )));

        if ($ids === []) {
            return new RemotePriceSnapshot([]);
        }

        $response = $this->client->get('products', [
            'include' => implode(',', $ids),
            'per_page' => 100,
        ]);

        $response->throw();

        $prices = [];

        foreach ((array) $response->json() as $product) {
            if (! is_array($product) || ! isset($product['id'])) {
                continue;
            }

            $prices[(string) $product['id']] = (string) ($product['regular_price'] ?? '0');
        }

        return new RemotePriceSnapshot($prices, new DateTimeImmutable);
    }

    public function maxPriceBatchSize(): int
    {
        return 100;
    }

    // ---------------------------------------------------------------- katalog

    public function createListing(ListingPayload $payload): AdapterResult
    {
        $response = $this->client->post('products', WooProductMapper::toWooProduct($payload));

        $response->throw();

        return AdapterResult::success([
            'external_id' => (string) $response->json('id'),
            'external_url' => $response->json('permalink'),
        ]);
    }

    public function updateListing(ListingPayload $payload): AdapterResult
    {
        $externalId = $payload->listing->external_id;

        if ($externalId === null) {
            return AdapterResult::failure(
                ErrorClass::VALIDATION,
                'Güncellenecek listing kanalda yok (external_id boş).',
            );
        }

        $response = $this->client->put(
            "products/{$externalId}",
            WooProductMapper::toWooProduct($payload),
        );

        $response->throw();

        return AdapterResult::success(['external_id' => $externalId]);
    }

    public function delist(Listing $listing): AdapterResult
    {
        if ($listing->external_id === null) {
            return AdapterResult::success(['already_absent' => true]);
        }

        // SİLMEZ, taslağa çeker: silme geri alınamaz ve kanaldaki yorumları,
        // sıralamayı, SEO geçmişini de götürür.
        $response = $this->client->put("products/{$listing->external_id}", [
            'status' => 'draft',
        ]);

        $response->throw();

        return AdapterResult::success(['status' => 'draft']);
    }

    /**
     * Kanalda zaten var olan ürünü SKU ile bulur.
     *
     * Bu adım olmadan mevcut ürünler yeniden yaratılır ve kanalda kopya
     * listeler oluşur.
     */
    public function findExistingListing(Variant $variant): ?RemoteListing
    {
        $response = $this->client->get('products', ['sku' => $variant->sku]);

        $response->throw();

        $products = (array) $response->json();
        $product = $products[0] ?? null;

        return is_array($product) ? WooProductMapper::toRemoteListing($product) : null;
    }

    public function fetchListing(Listing $listing): ?RemoteListing
    {
        if ($listing->external_id === null) {
            return null;
        }

        $response = $this->client->get("products/{$listing->external_id}");

        if ($response->status() === 404) {
            return null;    // mutabakat REMOTE_MISSING olarak işaretler
        }

        $response->throw();

        $product = $response->json();

        return is_array($product) ? WooProductMapper::toRemoteListing($product) : null;
    }

    // ------------------------------------------------- katalog içe aktarma

    /**
     * Kanaldaki ürünleri sayfa sayfa okur — §7 · SupportsCatalogImport.
     *
     * İMLEÇ SAYFA NUMARASIDIR ve `fetchOrders()` ile aynı biçimdedir;
     * `X-WP-TotalPages` başlığı toplam sayfayı verir.
     *
     * `status` FİLTRESİ YOKTUR — taslak ve özel ürünler de gelir. Satıcının
     * kanalda taslak tuttuğu ürün onun kataloğunun parçasıdır; süzülseydi
     * içe aktarma sessizce eksik çalışır ve satıcı neyin gelmediğini
     * anlayamazdı. Ne yapılacağına içe aktarma action'ı karar verir.
     *
     * VARYASYONLAR ÇEKİLMEZ: `type=variable` ürünün varyasyonları ayrı uç
     * noktadadır (`products/{id}/variations`). Bu tur ürün seviyesinde
     * çalışır ve kanonik modelde tek varyantlı ürün yaratır — varyasyon
     * desteği ayrı bir maddedir. Sessizce yarım varyant yaratmak, satıcının
     * bedenlerinden yalnızca birinin stoğunu senkronlamak demek olurdu.
     */
    public function fetchProductPage(?string $cursor = null): RemoteProductPage
    {
        $page = $cursor === null ? 1 : (int) $cursor;

        $response = $this->client->get('products', [
            'per_page' => 100,
            'page' => $page,
            'orderby' => 'id',
            'order' => 'asc',
        ]);

        $response->throw();

        $products = array_values(array_filter((array) $response->json(), 'is_array'));

        $totalPages = (int) ($response->header('X-WP-TotalPages') ?: 1);

        return new RemoteProductPage(
            products: array_map(
                static fn (array $product): RemoteProduct => WooProductMapper::toRemoteProduct($product),
                $products,
            ),
            nextCursor: $page < $totalPages ? (string) ($page + 1) : null,
            hasMore: $page < $totalPages,
        );
    }

    /**
     * Tur başına en fazla 50 sayfa — 100'lük sayfayla 5.000 ürün.
     *
     * Sınır KOTA değil EMNİYETTİR: bozuk bir kanal `X-WP-TotalPages`'i
     * sürekli büyük döndürürse tur sonsuza kadar sürerdi. Kalan ürünler
     * kullanıcının ikinci turunda gelir; içe aktarma var olan SKU'yu
     * günceller, yani tekrar zararsızdır.
     */
    public function maxImportPages(): int
    {
        return 50;
    }

    // ---------------------------------------------------------------- sipariş

    public function fetchOrders(CarbonInterface $since, ?string $cursor = null): OrderPage
    {
        $page = $cursor === null ? 1 : (int) $cursor;

        $response = $this->client->get('orders', [
            'after' => $since->toIso8601String(),
            'per_page' => 50,
            'page' => $page,
            'orderby' => 'date',
            'order' => 'asc',
        ]);

        $response->throw();

        /** @var list<array<string, mixed>> $orders */
        $orders = array_values(array_filter((array) $response->json(), 'is_array'));

        $totalPages = (int) ($response->header('X-WP-TotalPages') ?: 1);

        return new OrderPage(
            orders: $orders,
            nextCursor: $page < $totalPages ? (string) ($page + 1) : null,
            hasMore: $page < $totalPages,
        );
    }

    /**
     * Ham gövdeyi kanonik olaya çevirir — TİP dahil.
     *
     * Tip kritiktir: created / updated / cancelled / returned ayrı yollara
     * gider. Tek yola sokulsaydı iptal ve iade siparişin yeniden yaratılması
     * gibi işlenir ve stok iki kez düşerdi (Karar 24).
     */
    public function parseOrderEvent(InboxMessage $message): ?NormalizedOrderEvent
    {
        return WooOrderNormalizer::normalize($message);
    }

    public function acknowledgeOrder(Order $order): AdapterResult
    {
        // Woo'da ayrı bir onay adımı yoktur; sipariş webhook ile gelir ve
        // kabul edilmiş sayılır. Sözleşme gereği başarı döner.
        return AdapterResult::success(['acknowledged' => true]);
    }

    // ---------------------------------------------------------------- kargo

    public function pushFulfillment(Fulfillment $fulfillment): AdapterResult
    {
        $externalOrderId = $fulfillment->order?->external_id;

        if ($externalOrderId === null) {
            return AdapterResult::failure(
                ErrorClass::VALIDATION,
                'Kargo bildirimi için siparişin kanal kimliği yok.',
            );
        }

        $response = $this->client->put("orders/{$externalOrderId}", [
            'status' => 'completed',
            'meta_data' => array_values(array_filter([
                $fulfillment->tracking_number === null ? null : [
                    'key' => '_tracking_number',
                    'value' => $fulfillment->tracking_number,
                ],
                $fulfillment->carrier === null ? null : [
                    'key' => '_tracking_provider',
                    'value' => $fulfillment->carrier,
                ],
            ])),
        ]);

        $response->throw();

        return AdapterResult::success(['status' => 'completed']);
    }

    /** @return array<string, string> */
    public function fetchCarriers(): array
    {
        // Woo çekirdeğinde kargo firması listesi yoktur; eklentiye bağlıdır.
        return [];
    }

    // ---------------------------------------------------------------- hata

    /**
     * Woo hatasını çekirdeğin anladığı sınıfa çevirir.
     *
     * Gövdeyi yalnızca adapter anlar; ne yapılacağına çekirdek karar verir.
     * Bu ayrım olmadan her adapter kendi yeniden deneme politikasını taşırdı.
     */
    public function classifyError(Throwable $e): ErrorClass
    {
        // Ağ hatası: yanıt hiç gelmedi. Sonuç BELİRSİZ — istek işlenmiş de
        // olabilir; bu yüzden idempotency kritiktir.
        //
        // TIMEOUT ve NETWORK ayrımı mesaj metnine göre YAPILMAZ: cURL metni
        // sürüme ve dile göre değişir, "28" gibi kod parçaları IP ve port
        // numaralarında da geçer. İkisi de geçici ve aynı gecikme
        // politikasına tabi olduğu için (§12) ayrım pratik bir fark
        // üretmezdi; yanlış eşleşme riski taşımaya değmez.
        if ($e instanceof ConnectionException) {
            return ErrorClass::NETWORK;
        }

        $status = $e instanceof RequestException
            ? $e->response->status()
            : $this->statusFromPrevious($e);

        if ($status === null) {
            return ErrorClass::NETWORK;
        }

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

    public function rateLimitProfile(): RateLimitProfile
    {
        $profile = $this->connection->channelType?->rate_limit_profile;

        return is_array($profile) && $profile !== []
            ? RateLimitProfile::fromArray($profile)
            : RateLimitProfile::conservative();
    }

    // ---------------------------------------------------------------- webhook

    /**
     * İmzayı HAM gövde üzerinden doğrular.
     *
     * JSON AYRIŞTIRILMADAN ÖNCE çağrılır: ayrıştırıp yeniden serileştirmek
     * baytları değiştirir ve imza tutmaz. Doğrulanmamış webhook = sahte
     * sipariş enjeksiyonu.
     *
     * hash_equals ZORUNLU: normal karşılaştırma ilk farklı baytta döner ve
     * zamanlama üzerinden imza baytı baytı tahmin edilebilir.
     *
     * @param  array<string, array<int, string|null>>  $headers
     */
    public function verifyWebhookSignature(string $raw, array $headers): bool
    {
        $provided = $this->header($headers, 'x-wc-webhook-signature');

        if ($provided === null || $provided === '') {
            return false;   // muafiyet yok
        }

        $secret = $this->webhookSecret();

        if ($secret === null || $secret === '') {
            return false;   // sır yoksa doğrulanamaz; kabul de edilmez
        }

        $expected = base64_encode(hash_hmac('sha256', $raw, $secret, true));

        return hash_equals($expected, $provided);
    }

    /**
     * Olay kimliği — inbox tekilleştirmesinin BİRİNCİL çıpası.
     *
     * Yoksa null döner ve inbox payload_hash + dedupe_window yoluna düşer;
     * o yol saat sınırında bölünür, bu yüzden başlık tercih edilir.
     *
     * @param  array<string, array<int, string|null>>  $headers
     */
    public function extractEventId(array $headers): ?string
    {
        return $this->header($headers, 'x-wc-webhook-delivery-id');
    }

    /** @param array<string, array<int, string|null>> $headers */
    public function extractEventType(array $headers): string
    {
        return $this->header($headers, 'x-wc-webhook-topic') ?? 'unknown';
    }

    // ---------------------------------------------------------------- iç

    /**
     * Webhook imzalama sırrı — AÇIKÇA sistem bağlamında okunur.
     *
     * Webhook doğrulaması kiracı bağlamı kurulMADAN önce çalışır: gelen
     * istekte oturum yoktur ve kiracı ancak bağlantı bulunduktan sonra
     * bilinir (WebhookController da bağlantıyı runAsSystem ile arar).
     * Bağlam beklenirse okuma başarısız olur ve MEŞRU her webhook sessizce
     * reddedilir — kanal 2xx alamadığı için yeniden gönderir ve sipariş
     * sonsuza kadar sıkışır.
     *
     * Erişim burada bilinçli ve dar kapsamlıdır: yalnızca bu bağlantının
     * kimlik bilgisi, yalnızca imza doğrulaması için.
     */
    private function webhookSecret(): ?string
    {
        try {
            $secrets = TenantContext::runAsSystem(
                fn (): array => app(CredentialVault::class)->read($this->connection)
            );
        } catch (Throwable) {
            // Kimlik bilgisi hiç yoksa doğrulanamaz; kabul de EDİLMEZ.
            return null;
        }

        $secret = $secrets['webhook_secret'] ?? null;

        return is_string($secret) ? $secret : null;
    }

    /** @param array<string, array<int, string|null>> $headers */
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

    /** Sarmalanmış istisnalarda durum kodunu arar. */
    private function statusFromPrevious(Throwable $e): ?int
    {
        $previous = $e->getPrevious();

        while ($previous !== null) {
            if ($previous instanceof RequestException) {
                return $previous->response->status();
            }

            $previous = $previous->getPrevious();
        }

        $code = $e->getCode();

        return is_int($code) && $code >= 100 && $code < 600 ? $code : null;
    }
}
