<?php

declare(strict_types=1);

namespace App\Domain\Channels\Adapters\Ticimax;

use App\Domain\Channels\Contracts\AdapterResult;
use App\Domain\Channels\Contracts\ChannelAdapter;
use App\Domain\Channels\Contracts\DeclaresChannelCurrency;
use App\Domain\Channels\Contracts\DeclaresRequestQuota;
use App\Domain\Channels\Contracts\HealthResult;
use App\Domain\Channels\Contracts\RateLimitProfile;
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
use App\Domain\Sync\Support\NormalizedOrderEvent;
use App\Domain\Sync\Support\OrderPage;
use App\Domain\Sync\Support\PricePushBatch;
use App\Domain\Sync\Support\RemoteInventorySnapshot;
use App\Domain\Sync\Support\RemotePriceSnapshot;
use App\Domain\Sync\Support\RemoteProduct;
use App\Domain\Sync\Support\RemoteProductPage;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonInterface;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use RuntimeException;
use Throwable;

/**
 * Ticimax kanal adapter'ı — mağazanın kendi alan adındaki WCF/SOAP servisleri.
 *
 * Araştırma: `docs/YENI-KANALLAR-API-NOTLARI.md` §3. Şema canlı WSDL'den
 * (`TicimaxSoap` başlığı); filtre varsayılanları tek açık kaynak PHP
 * istemcisinden (`hasokeyk/ticimax-php`) teyit edildi. Gerçek mağazayla
 * HENÜZ SINANMADI → kanal `is_active = false`.
 *
 * ─────────────────────────────────────────────────────────────────────
 * KİMLİK: alan adı + "WS Yetki Kodu" (`UyeKodu`)
 * ─────────────────────────────────────────────────────────────────────
 * Her çağrının İLK parametresi yetki kodudur; başlık, token, süre yoktur.
 * Hesap kimliği mağazanın alan adıdır — her Ticimax mağazası kendi alan
 * adında çalışır, adresler oradan kurulur (`https://{alan}/Servis/…svc`).
 *
 * ─────────────────────────────────────────────────────────────────────
 * ⚠️ "FİLTRE YOK" DEĞERİ ALANA GÖRE DEĞİŞİR — SESSİZ BOŞ LİSTE TUZAĞI
 * ─────────────────────────────────────────────────────────────────────
 * Gönderilmeyen int alan 0 sayılır. Durum alanlarında (`Aktif`,
 * `SiparisDurumu`…) 0 GERÇEK bir değerdir ("pasif", "ön sipariş") ve
 * filtre olarak uygulanır: liste hata vermeden yalnız pasif ürünlerle
 * dolardı. Bu alanlarda "hepsi" = -1, kimlik alanlarında (`KategoriID`,
 * `UrunKartiID`) = 0.
 *
 * ─────────────────────────────────────────────────────────────────────
 * KDV: FİYAT VARYASYONUN KENDİ BİÇİMİYLE YAZILIR
 * ─────────────────────────────────────────────────────────────────────
 * 34Pazar fiyatı KDV DAHİLDİR. Ticimax'ta varyasyon `KdvDahil=false`
 * olabilir — o zaman `SatisFiyati` NET'tir. Brüt fiyat olduğu gibi
 * yazılsaydı müşteri KDV'yi iki kez öderdi. İçe aktarma `kdv_dahil` ve
 * `kdv_orani`'yı listing'e yazar; gönderim ve okuma ona göre çevirir.
 *
 * ─────────────────────────────────────────────────────────────────────
 * SİPARİŞ: YALNIZ YOKLAMA, BÜTÜN SİPARİŞ DÜZEYİNDE İPTAL/İADE
 * ─────────────────────────────────────────────────────────────────────
 * Webhook yok. Kalem durum kodlarının anlamı belgesiz → kısmi kalem
 * iptali bu sürümde stoğa DÖNMEZ (eksik kalır, fazla satış olmaz).
 * Sipariş durumu 8 (iptal) ve 9 (iade edildi) bütün kalemleri döndürür.
 * `EntegrasyonAktarildi` bayrağına DOKUNULMAZ: satıcının ERP'si de onu
 * kullanıyor olabilir ve biz işaretlersek ERP siparişi hiç görmezdi.
 *
 * `SupportsFulfillment` 9 Eki 2026'da eklendi (`SaveKargoTakipNo` +
 * `SetSiparisKargoyaVerildi`): panelden girilen takip numarası Ticimax'a
 * gitmiyordu.
 */
final class TicimaxAdapter implements ChannelAdapter, DeclaresChannelCurrency, SupportsCatalogImport, SupportsFulfillment, SupportsInventory, SupportsOrders, SupportsPricing
{
    use DeclaresRequestQuota;

    /** Mağaza alan adı — hesap kimliği. SIR DEĞİL. */
    public const DOMAIN_KEY = 'ticimax_domain';

    /** Mağaza para birimi (`settings`). Yoksa TRY. */
    public const CURRENCY_KEY = 'ticimax_currency';

    public const DEFAULT_CURRENCY = 'TRY';

    /** Kasadaki yetki kodu. */
    public const AUTH_CODE_SECRET = 'uye_kodu';

    private const PAGE_SIZE = 100;

    private const MAX_INVENTORY_BATCH = 100;

    /** Sipariş sorgu penceresi: tarih dilimi belgesiz, 3 saat geriye genişletilir. */
    private const ORDER_DATE_SLACK_HOURS = 3;

    private const CHANNEL_TIMEZONE = 'Europe/Istanbul';

    /** Sipariş durumları (SiparisServis.pdf s.2–4). */
    private const STATUS_SHIPPED = 6;

    private const STATUS_DELIVERED = 7;

    private const STATUS_CANCELLED = 8;

    private const STATUS_RETURNED = 9;

    private const STATUS_DELETED = 10;

    public function __construct(
        private readonly ChannelConnection $connection,
        private readonly ChannelHttpClient $client,
    ) {}

    public function connection(): ChannelConnection
    {
        return $this->connection;
    }

    public function channelCurrency(): ?string
    {
        $value = $this->connection->settings[self::CURRENCY_KEY] ?? null;

        return is_string($value) && trim($value) !== '' ? strtoupper(trim($value)) : self::DEFAULT_CURRENCY;
    }

    // ---------------------------------------------------------------- sağlık

    /** `SelectUrunCount` — en hafif kimlikli çağrı; yanlış kodda Fault döner. */
    public function healthCheck(): HealthResult
    {
        $startedAt = hrtime(true);

        try {
            $this->call('UrunServis', 'SelectUrunCount', ['f' => self::productFilter()]);
        } catch (Throwable $e) {
            return HealthResult::unhealthy($e->getMessage());
        }

        return HealthResult::healthy((int) round((hrtime(true) - $startedAt) / 1_000_000));
    }

    /**
     * Belgede sınır yok; servis mağazanın kendi sunucusunda koşuyor.
     * Tek eşzamanlı istek, saniyede 2 — mağazayı yavaşlatmamak için.
     */
    public function rateLimitProfile(): RateLimitProfile
    {
        $profile = $this->connection->channelType?->rate_limit_profile;

        return is_array($profile) && $profile !== []
            ? RateLimitProfile::fromArray($profile)
            : new RateLimitProfile(requestsPerSecond: 2, burstCapacity: 4, maxConcurrent: 1);
    }

    public function classifyError(Throwable $e): ErrorClass
    {
        if ($e instanceof TicimaxSoapFault) {
            return match (true) {
                $e->isAuthentication() => ErrorClass::AUTHENTICATION,
                $e->faultCode === 'IsError' => ErrorClass::VALIDATION,
                // WCF iç hatası çoğu zaman geçicidir (mağaza sunucusu).
                default => ErrorClass::SERVER_ERROR,
            };
        }

        if ($e instanceof ConnectionException || ! $e instanceof RequestException) {
            return ErrorClass::NETWORK;
        }

        $status = $e->response->status();

        return match (true) {
            $status === 429 => ErrorClass::RATE_LIMITED,
            $status === 401, $status === 403 => ErrorClass::AUTHENTICATION,
            $status === 404 => ErrorClass::NOT_FOUND,
            $status >= 500 => ErrorClass::SERVER_ERROR,
            $status >= 400 => ErrorClass::VALIDATION,
            default => ErrorClass::SERVER_ERROR,
        };
    }

    /** Webhook yok — imzasız gövde ASLA kabul edilmez. */
    public function verifyWebhookSignature(string $raw, array $headers): bool
    {
        return false;
    }

    public function extractEventId(array $headers): ?string
    {
        return null;
    }

    public function extractEventType(array $headers): string
    {
        return 'unknown';
    }

    public function tokenEndpointFragment(): ?string
    {
        return null;
    }

    // ------------------------------------------------------------ içe aktarma

    /**
     * `SelectUrun` — ürün kartları, varyasyonlar içinde. Her varyasyon
     * ayrı ürün; kimlik varyasyon `ID`, üst kimlik kart `ID`.
     *
     * İmleç ofsettir; sıralama `ID` artan (değişmeyen alan — sayfa kaymaz).
     * SKU yoksa boş bırakılır: kimlik sayısal olduğu için çekirdeğin
     * `autoSku()`'su üretir.
     */
    public function fetchProductPage(?string $cursor = null): RemoteProductPage
    {
        $offset = $cursor === null ? 0 : max(0, (int) $cursor);

        $cards = TicimaxSoap::items($this->call('UrunServis', 'SelectUrun', [
            'f' => self::productFilter(),
            's' => self::paging($offset),
        ]), 'UrunKarti');

        $products = [];

        foreach ($cards as $card) {
            foreach (TicimaxSoap::items($card['Varyasyonlar'] ?? null, 'Varyasyon') as $variant) {
                if (isset($variant['ID']) && (int) $variant['ID'] > 0) {
                    $products[] = $this->toRemoteProduct($card, $variant);
                }
            }
        }

        $hasMore = count($cards) >= self::PAGE_SIZE;

        return new RemoteProductPage(
            products: $products,
            nextCursor: $hasMore ? (string) ($offset + self::PAGE_SIZE) : null,
            hasMore: $hasMore,
        );
    }

    public function maxImportPages(): int
    {
        return 100;
    }

    /**
     * @param  array<string, mixed>  $card
     * @param  array<string, mixed>  $variant
     */
    private function toRemoteProduct(array $card, array $variant): RemoteProduct
    {
        $variantId = (string) (int) $variant['ID'];
        $cardId = (string) (int) ($card['ID'] ?? 0);
        $sku = trim((string) ($variant['StokKodu'] ?? ''));
        $vatIncluded = self::bool($variant['KdvDahil'] ?? true);
        $vatRate = (float) ($variant['KdvOrani'] ?? 0);

        $options = array_filter(array_map(
            static fn (array $o): string => trim((string) ($o['Deger'] ?? '')),
            TicimaxSoap::items($variant['Ozellikler'] ?? null, 'VaryasyonOzellik'),
        ));
        $title = trim((string) ($card['UrunAdi'] ?? ''));
        $title = $options === [] ? $title : trim($title.' — '.implode(' / ', $options));

        $images = array_values(array_filter(
            array_map('strval', (array) ($card['Resimler']['string'] ?? [])),
            static fn (string $url): bool => str_starts_with($url, 'https://'),
        ));

        $currency = strtoupper(trim((string) ($variant['ParaBirimiKodu'] ?? $variant['ParaBirimi'] ?? '')));

        return new RemoteProduct(
            externalId: $variantId,
            sku: $sku !== '' ? $sku : null,
            title: $title !== '' ? $title : ($sku !== '' ? $sku : $variantId),
            price: self::money(self::gross(self::effectivePrice($variant), $vatIncluded, $vatRate)),
            quantity: (int) (float) ($variant['StokAdedi'] ?? 0),
            description: is_string($card['Aciklama'] ?? null) && trim($card['Aciklama']) !== '' ? $card['Aciklama'] : null,
            brand: is_string($card['Marka'] ?? null) && trim($card['Marka']) !== '' ? trim($card['Marka']) : null,
            barcode: is_string($variant['Barkod'] ?? null) && trim($variant['Barkod']) !== '' ? trim($variant['Barkod']) : null,
            status: self::bool($variant['Aktif'] ?? false) && self::bool($card['Aktif'] ?? false) ? 'active' : 'inactive',
            images: $images,
            raw: ['card_id' => $cardId, 'variant' => $variant],
            listingIdentity: [
                'external_id' => $variantId,
                'external_parent_id' => $cardId,
                'channel_metadata' => [
                    'kdv_dahil' => $vatIncluded,
                    'kdv_orani' => $vatRate,
                    'para_birimi_id' => (int) ($variant['ParaBirimiID'] ?? 0),
                    'para_birimi' => $currency,
                ],
            ],
            currency: $currency !== '' ? $currency : $this->channelCurrency(),
        );
    }

    // ---------------------------------------------------------------- stok

    /**
     * MUTLAK stok — `StokAdediGuncelle`, toplu. Her öğede yalnız `ID` +
     * `StokAdedi` (UrunServis.pdf §11.5).
     *
     * ⚠️ Dönüş bir sayı (`int`) ve anlamı BELGESİZ (güncellenen kayıt
     * sayısı olabilir). Satır bazlı hata dönmez; uygulandığını mutabakat
     * (`fetchInventory`) doğrular. Sonuç `data.updated`'da görünür kalır.
     */
    public function pushInventory(InventoryPushBatch $batch): AdapterResult
    {
        if ($batch->isEmpty()) {
            return AdapterResult::success(['pushed' => 0]);
        }

        $items = array_map(static fn (array $item): array => [
            'ID' => (int) $item['external_id'],
            'StokAdedi' => (float) $item['quantity'],
        ], $batch->toArray());

        $updated = $this->call('UrunServis', 'StokAdediGuncelle', [
            'urunler' => ['@list' => 'Varyasyon', 'items' => $items],
        ]);

        return AdapterResult::success(['pushed' => $batch->count(), 'updated' => is_numeric($updated) ? (int) $updated : null]);
    }

    public function maxInventoryBatchSize(): int
    {
        return self::MAX_INVENTORY_BATCH;
    }

    /** @param list<Listing> $listings */
    public function fetchInventory(array $listings): RemoteInventorySnapshot
    {
        $quantities = [];

        foreach ($this->remoteVariants($listings) as $id => $variant) {
            $quantities[$id] = (int) (float) ($variant['StokAdedi'] ?? 0);
        }

        return new RemoteInventorySnapshot($quantities, new DateTimeImmutable);
    }

    // --------------------------------------------------------------- fiyat

    /**
     * MUTLAK fiyat — `VaryasyonGuncelle`, varyasyon başına tek çağrı.
     *
     * Yalnız `SatisFiyatiGuncelle` ve `IndirimliFiyatiGuncelle` bayrakları
     * açıktır; bayrağı kapalı alan Ticimax'ta YOK SAYILIR (stok, para
     * birimi, KDV değişmez). Karşılaştırma fiyatı satıştan yüksekse
     * `SatisFiyati` = karşılaştırma, `IndirimliFiyati` = satış; değilse
     * indirim 0 ile kaldırılır.
     *
     * Toplu `UpdateUrunFiyat` WSDL'de var ama belgesiz — kullanılmadı.
     */
    public function pushPrices(PricePushBatch $batch): AdapterResult
    {
        if ($batch->isEmpty()) {
            return AdapterResult::success(['pushed' => 0]);
        }

        $listings = TenantContext::runAsSystem(fn () => Listing::query()
            ->where('channel_connection_id', $this->connection->id)
            ->whereIn('id', array_column($batch->items, 'listing_id'))
            ->get()
            ->keyBy('id'));

        $item = $batch->items[0];
        $meta = $listings->get($item['listing_id'])?->channel_metadata ?? [];

        // Para birimi koruması (Etsy kuralı): varyasyon başka para
        // biriminde ise rakam olduğu gibi yazılamaz.
        $variantCurrency = strtoupper((string) ($meta['para_birimi'] ?? ''));

        if ($variantCurrency !== '' && $variantCurrency !== $this->channelCurrency()) {
            return AdapterResult::failure(
                ErrorClass::VALIDATION,
                "Ticimax'taki varyasyon {$variantCurrency} fiyatlı; 34Pazar fiyatı {$this->channelCurrency()}. Kanal fiyatı gir ya da para birimini eşitle.",
            );
        }

        if (! isset($meta['para_birimi_id']) || (int) $meta['para_birimi_id'] <= 0) {
            return AdapterResult::failure(
                ErrorClass::VALIDATION,
                'Ticimax para birimi bilinmiyor; ürünü kanaldan yeniden içe aktar.',
            );
        }

        $vatIncluded = (bool) ($meta['kdv_dahil'] ?? true);
        $vatRate = (float) ($meta['kdv_orani'] ?? 0);

        $price = (float) $item['price'];
        $compare = isset($item['compare_at_price']) && is_numeric($item['compare_at_price']) ? (float) $item['compare_at_price'] : null;
        $discounted = $compare !== null && $compare > $price;

        $this->call('UrunServis', 'VaryasyonGuncelle', [
            'urun' => [
                'ID' => (int) $item['external_id'],
                'IndirimliFiyati' => $discounted ? self::net($price, $vatIncluded, $vatRate) : 0.0,
                'ParaBirimiID' => (int) $meta['para_birimi_id'],
                'SatisFiyati' => self::net($discounted ? $compare : $price, $vatIncluded, $vatRate),
            ],
            'ayar' => self::priceOnlySettings(),
        ]);

        return AdapterResult::success(['pushed' => 1]);
    }

    /** `VaryasyonGuncelle` tekil — parti 1. */
    public function maxPriceBatchSize(): int
    {
        return 1;
    }

    /** @param list<Listing> $listings */
    public function fetchPrices(array $listings): RemotePriceSnapshot
    {
        $prices = [];

        foreach ($this->remoteVariants($listings) as $id => $variant) {
            $prices[$id] = self::money(self::gross(
                self::effectivePrice($variant),
                self::bool($variant['KdvDahil'] ?? true),
                (float) ($variant['KdvOrani'] ?? 0),
            ));
        }

        return new RemotePriceSnapshot($prices, new DateTimeImmutable);
    }

    /**
     * Uzak varyasyonlar kart kimliğiyle (`UrunKartiIDList`). Kimliksiz
     * listing sorulmaz, hiç yoksa çağrı yok (süzgeçsiz sorgu bütün
     * kataloğu getirirdi).
     *
     * @param  list<Listing>  $listings
     * @return array<string, array<string, mixed>>
     */
    private function remoteVariants(array $listings): array
    {
        $cardIds = array_values(array_unique(array_filter(array_map(
            static fn (Listing $l): int => (int) ($l->external_parent_id ?? 0),
            $listings,
        ))));

        $variants = [];

        foreach (array_chunk($cardIds, self::PAGE_SIZE) as $chunk) {
            $cards = TicimaxSoap::items($this->call('UrunServis', 'SelectUrun', [
                'f' => [...self::productFilter(), 'UrunKartiIDList' => ['@ints' => $chunk]],
                's' => self::paging(0),
            ]), 'UrunKarti');

            foreach ($cards as $card) {
                foreach (TicimaxSoap::items($card['Varyasyonlar'] ?? null, 'Varyasyon') as $variant) {
                    if (isset($variant['ID'])) {
                        $variants[(string) (int) $variant['ID']] = $variant;
                    }
                }
            }
        }

        return $variants;
    }

    // ------------------------------------------------------------- siparişler

    /**
     * İki liste sırayla: DÜZENLEME tarihine göre (`edit:{ofset}`), sonra
     * DURUM tarihine göre (`status:{ofset}`). İptal yalnız durum tarihini
     * değiştiriyorsa ilk listede görünmezdi — stok geri eklenmezdi.
     * Tekrar gelen kayıt olay kimliğiyle elenir.
     */
    public function fetchOrders(CarbonInterface $since, ?string $cursor = null): OrderPage
    {
        [$list, $offset] = $cursor !== null && preg_match('/^(edit|status):(\d+)$/', $cursor, $m) === 1
            ? [$m[1], (int) $m[2]]
            : ['edit', 0];

        $from = $since->copy()->subHours(self::ORDER_DATE_SLACK_HOURS)
            ->setTimezone(self::CHANNEL_TIMEZONE)->format('Y-m-d\TH:i:s');

        $orders = TicimaxSoap::items($this->call('SiparisServis', 'SelectSiparis', [
            'f' => [
                ...self::orderFilter(),
                ($list === 'edit' ? 'DuzenlemeTarihiBas' : 'DurumTarihiBas') => $from,
            ],
            's' => self::paging($offset),
        ]), 'WebSiparis');

        $records = [];

        foreach ($orders as $order) {
            array_push($records, ...$this->recordsFor($order));
        }

        $full = count($orders) >= self::PAGE_SIZE;

        if ($list === 'edit') {
            return new OrderPage(orders: $records, nextCursor: $full ? 'edit:'.($offset + self::PAGE_SIZE) : 'status:0', hasMore: true);
        }

        return new OrderPage(
            orders: $records,
            nextCursor: $full ? 'status:'.($offset + self::PAGE_SIZE) : null,
            hasMore: $full,
        );
    }

    /**
     * Siparişin kayıtları.
     *
     * - Silinmiş (10) hiçbir şey üretmez.
     * - İptal (8) / iade (9): yalnız sipariş daha önce "created" olarak
     *   ALINDIYSA bütün kalemleriyle iptal/iade kaydı. Hiç alınmamışsa
     *   ikisi de üretilmez — iki tur arasında verilip iptal edilen sipariş
     *   net sıfırdır; ama "created" atlanıp iade üretilseydi hiç düşülmemiş
     *   stok GERİ EKLENİRDİ.
     * - Diğer her durum "created".
     *
     * @param  array<string, mixed>  $order
     * @return list<array<string, mixed>>
     */
    private function recordsFor(array $order): array
    {
        $id = (int) ($order['ID'] ?? 0);
        $status = (int) ($order['Durum'] ?? -1);

        if ($id <= 0 || $status === self::STATUS_DELETED) {
            return [];
        }

        if ($status === self::STATUS_CANCELLED || $status === self::STATUS_RETURNED) {
            $kind = $status === self::STATUS_CANCELLED ? 'cancelled' : 'returned';

            if (! $this->alreadyIngested("{$id}:created") || $this->alreadyIngested("{$id}:{$kind}")) {
                return [];
            }

            // Liste iptal edilmiş kalemleri TAŞIMAZ; detaydan hepsi okunur.
            $lines = TicimaxSoap::items($this->call('SiparisServis', 'SelectSiparisUrun', [
                'siparisId' => $id,
                'iptalEdilmisUrunler' => true,
            ]), 'WebSiparisUrun');

            return [['_kind' => $kind, ...self::withoutPersonalData($order), 'Urunler' => ['WebSiparisUrun' => $lines]]];
        }

        return [['_kind' => 'created', ...self::withoutPersonalData($order)]];
    }

    /** @param array<string, mixed> $order */
    public function pollingEventIdFor(array $order): ?string
    {
        $id = (int) ($order['ID'] ?? 0);
        $kind = $order['_kind'] ?? null;

        return $id > 0 && in_array($kind, ['created', 'cancelled', 'returned'], true) ? "{$id}:{$kind}" : null;
    }

    public function parseOrderEvent(InboxMessage $message): ?NormalizedOrderEvent
    {
        /** @var array<string, mixed> $payload */
        $payload = is_array($message->payload) ? $message->payload : [];
        $id = (int) ($payload['ID'] ?? 0);
        $kind = $payload['_kind'] ?? null;

        if ($id <= 0 || ! in_array($kind, ['created', 'cancelled', 'returned'], true)) {
            return null;
        }

        $lines = [];

        foreach (TicimaxSoap::items($payload['Urunler'] ?? null, 'WebSiparisUrun') as $line) {
            $quantity = (int) (float) ($line['Adet'] ?? 0);
            $unit = (float) ($line['Tutar'] ?? 0);

            $lines[] = array_filter([
                'external_line_id' => isset($line['ID']) ? (string) (int) $line['ID'] : null,
                'sku' => trim((string) ($line['StokKodu'] ?? '')),
                'external_variant_id' => isset($line['UrunID']) ? (string) (int) $line['UrunID'] : null,
                'title' => (string) ($line['UrunAdi'] ?? ''),
                'quantity' => $quantity,
                // ⚠️ `Tutar` birim fiyat sayıldı — DOĞRULANMADI.
                'unit_price' => self::money($unit),
                'line_total' => self::money($unit * $quantity),
            ], static fn (mixed $v): bool => $v !== null);
        }

        $placedAt = self::channelDate($payload['SiparisTarihi'] ?? null);
        $ref = $message->external_event_id ?? $this->pollingEventIdFor($payload);

        if ($kind !== 'created') {
            return new NormalizedOrderEvent(
                type: $kind,
                externalOrderId: (string) $id,
                externalRef: $ref,
                payload: ['status' => (string) ($payload['SiparisDurumu'] ?? ''), 'lines' => $lines],
                occurredAt: self::channelDate($payload['DurumGuncellemeTarihi'] ?? null) ?? self::channelDate($payload['DuzenlemeTarihi'] ?? null),
            );
        }

        $total = self::money($payload['SiparisToplamTutari'] ?? $payload['ToplamTutar'] ?? 0);

        return new NormalizedOrderEvent(
            type: 'created',
            externalOrderId: (string) $id,
            externalRef: $ref,
            payload: [
                'type' => 'created',
                'external_number' => (string) ($payload['SiparisNo'] ?? $id),
                'status' => (string) ($payload['SiparisDurumu'] ?? 'Durum '.($payload['Durum'] ?? '')),
                'currency' => strtoupper((string) ($payload['ParaBirimi'] ?? '')) ?: $this->channelCurrency(),
                'subtotal' => $total,
                'shipping_total' => self::money($payload['KargoTutari'] ?? 0),
                'grand_total' => $total,
                'lines' => $lines,
                'customer_ref' => [],
            ],
            occurredAt: $placedAt,
            placedAt: $placedAt,
        );
    }

    /** Onay adımı yok — `EntegrasyonAktarildi` bilinçli olarak işaretlenmez (sınıf notu). */
    public function acknowledgeOrder(Order $order): AdapterResult
    {
        return AdapterResult::success(['acknowledged' => true]);
    }

    // -------------------------------------------------------------- kargo

    /**
     * Panelden girilen takip numarasını Ticimax siparişine yazar
     * (`SaveKargoTakipNo`) ve sipariş hâlâ kargoda değilse "Kargoya verildi"
     * yapar (`SetSiparisKargoyaVerildi`).
     *
     * ⚠️ ÖNCE OKUNUR, YALNIZ EKSİK ADIM ATILIR. Takip numarası
     * `SiparisKargoTakipNoKontrol` ile, durum `SelectSiparis` ile okunur.
     * Numara aynıysa yazılmaz, sipariş zaten kargodaysa (6/7) durum
     * değiştirilmez: iki çağrının alıcıya e-posta/SMS gönderip göndermediği
     * belgesiz (⚠️ DOĞRULANMADI) ve yanıtı kaybolan iş yeniden denendiğinde
     * alıcı ikinci bildirimi almamalı. Durum yazmadan ÖNCE yeniden okunur:
     * PDF başlığı `SaveKargoTakipNo`'nun "kargo işlemlerini" de yaptığını
     * söylüyor; durumu kendisi değiştirdiyse ikinci geçiş yapılmaz.
     *
     * ⚠️ PARAMETRE SIRASI WSDL SIRASIDIR, ALFABETİK DEĞİL. Bunlar DataContract
     * değil mesaj parçasıdır (`TicimaxSoap::envelope`); WCF sırası bozuk
     * parçayı HATA VERMEDEN yok sayar — numara sessizce boş kalırdı.
     *
     * ⚠️ FİRMA KODU BOŞ GİDER. PDF `KargoKodu` için "Boş gönderilebilir"
     * diyor ama değerin neye karşılık geldiğini söylemiyor (⚠️ DOĞRULANMADI);
     * tahmini bir kod yanlış firmayı yazardı. Firma, mağazanın listesinde
     * (`SelectKargoFirmalari`) adıyla bulunursa `SetSiparisKargoFirmaId` ile
     * kimliğinden yazılır; bulunmazsa firma alanına dokunulmaz.
     *
     * ⚠️ YETKİ REDDİ KİMLİK HATASI SAYILMAZ. Yetki kodu sipariş servisine
     * kapalıysa Fault metni "yetki" içerir ve `classifyError()` onu
     * `AUTHENTICATION` sayar; devre kesici o sınıfta SÜRESİZ açılır ve tek
     * kargo bildirimi bağlantının stok akışını durdururdu. Burada `VALIDATION`.
     */
    public function pushFulfillment(Fulfillment $fulfillment): AdapterResult
    {
        $orderId = (int) $fulfillment->order?->external_id;

        if ($orderId <= 0) {
            return AdapterResult::failure(
                ErrorClass::VALIDATION,
                'Kargo bildirimi için siparişin kanal kimliği yok.',
            );
        }

        $tracking = trim((string) $fulfillment->tracking_number);

        if ($tracking === '') {
            return AdapterResult::failure(ErrorClass::VALIDATION, 'Ticimax için takip numarası boş olamaz.');
        }

        try {
            $status = $this->orderStatus($orderId);

            // Sipariş okunamadıysa YAZILMAZ: yazma çağrısı başka bir siparişe
            // ya da boşa gitseydi satır yine "gönderildi" görünürdü.
            if ($status === -1) {
                return AdapterResult::failure(ErrorClass::NOT_FOUND, 'Sipariş Ticimax\'ta bulunamadı.');
            }

            if (in_array($status, [self::STATUS_CANCELLED, self::STATUS_RETURNED, self::STATUS_DELETED], true)) {
                return AdapterResult::failure(ErrorClass::VALIDATION, 'Sipariş Ticimax\'ta iptal, iade ya da silinmiş durumda; kargo bildirilmedi.');
            }

            $alreadyWritten = self::trackingKey($this->currentTrackingNumber($orderId)) === self::trackingKey($tracking);
            $alreadyShipped = in_array($status, [self::STATUS_SHIPPED, self::STATUS_DELIVERED], true);

            if ($alreadyWritten && $alreadyShipped) {
                return AdapterResult::success(['already_shipped' => true]);
            }

            $saveResult = null;

            if (! $alreadyWritten) {
                $this->assignCarrier($orderId, (string) $fulfillment->carrier);

                $saveResult = $this->call('SiparisServis', 'SaveKargoTakipNo', [
                    'siparisId' => $orderId,
                    'kargoKodu' => '',
                    'kargoTakipNo' => $tracking,
                    'kargoTakipLink' => '',
                    // Boşsa Ticimax kendisi üretir (PDF).
                    'BarkodBilgisi' => '',
                    'KargoTakipLinkGoster' => false,
                ]);

                $alreadyShipped = in_array($this->orderStatus($orderId), [self::STATUS_SHIPPED, self::STATUS_DELIVERED], true);
            }

            if (! $alreadyShipped) {
                $this->call('SiparisServis', 'SetSiparisKargoyaVerildi', ['siparisId' => $orderId]);
            }
        } catch (TicimaxSoapFault $e) {
            if (! $e->isAuthentication()) {
                throw $e;
            }

            return $this->forbiddenFulfillment();
        } catch (RequestException $e) {
            if ($e->response->status() !== 403) {
                throw $e;
            }

            return $this->forbiddenFulfillment();
        }

        // `SaveKargoTakipNo` `string` döndürür ama anlamı belgesiz
        // (⚠️ DOĞRULANMADI) — gerçek mağazada bakılsın diye sonuçta durur.
        return AdapterResult::success(array_filter([
            'tracking_written' => ! $alreadyWritten,
            'save_result' => is_string($saveResult) && $saveResult !== '' ? mb_substr($saveResult, 0, 200) : null,
        ], static fn (mixed $v): bool => $v !== null));
    }

    /**
     * Siparişte kayıtlı takip numarası (`SiparisKargoTakipNoKontrol`).
     *
     * ⚠️ YANIT `WebServisResponse`'tur: numarası olmayan siparişte
     * `IsError` dönüp dönmediği belgesiz (⚠️ DOĞRULANMADI). Dönerse bu
     * "numara yok" okunur — kargo bildirimi o yüzden durmamalı; numarayı
     * yeniden yazmak üzerine yazmadır, ikinci paket açmaz. Yetki reddi
     * yükselir.
     */
    private function currentTrackingNumber(int $orderId): string
    {
        try {
            $current = $this->call('SiparisServis', 'SiparisKargoTakipNoKontrol', ['siparisId' => $orderId]);
        } catch (TicimaxSoapFault $e) {
            if ($e->faultCode !== 'IsError' || $e->isAuthentication()) {
                throw $e;
            }

            return '';
        }

        return is_array($current) ? (string) ($current['KargoTakipNo'] ?? '') : '';
    }

    /**
     * Mağazanın kargo firmaları — `ID => Tanim`.
     *
     * @return array<string, string>
     */
    public function fetchCarriers(): array
    {
        $carriers = [];

        foreach (TicimaxSoap::items($this->call('CustomServis', 'SelectKargoFirmalari', []), 'KargoFirma') as $firm) {
            if ((int) ($firm['ID'] ?? 0) > 0 && isset($firm['Tanim'])) {
                $carriers[(string) (int) $firm['ID']] = (string) $firm['Tanim'];
            }
        }

        return $carriers;
    }

    private function forbiddenFulfillment(): AdapterResult
    {
        return AdapterResult::failure(
            ErrorClass::VALIDATION,
            'Ticimax yetki kodu kargo bildirmeye izin vermiyor. Ticimax panelinde WS yetki kodunun sipariş servisi iznini kontrol edin veya takip numarasını Ticimax panelinden girin.',
        );
    }

    /**
     * Siparişin durum kodu (`Durum`); sipariş yoksa -1.
     *
     * ⚠️ DÖNEN SİPARİŞİN KİMLİĞİ KARŞILAŞTIRILIR. Süzgeç sessizce yok
     * sayılsaydı (WCF sırası bozuk alanı hata vermeden atlar) ilk sipariş
     * döner ve BAŞKA siparişin durumu okunurdu.
     */
    private function orderStatus(int $orderId): int
    {
        $orders = TicimaxSoap::items($this->call('SiparisServis', 'SelectSiparis', [
            'f' => [...self::orderFilter(), 'SiparisID' => $orderId, 'UrunGetir' => false],
            's' => ['BaslangicIndex' => 0, 'KayitSayisi' => 1, 'SiralamaDegeri' => 'ID', 'SiralamaYonu' => 'ASC'],
        ]), 'WebSiparis');

        if (! isset($orders[0]['Durum']) || (int) ($orders[0]['ID'] ?? 0) !== $orderId) {
            return -1;
        }

        return (int) $orders[0]['Durum'];
    }

    /**
     * Firma mağazanın listesinde adıyla varsa siparişe kimliğiyle yazılır.
     *
     * Liste okunamazsa (yetki dışı Fault) firma atlanır: firma yardımcı
     * bilgidir, takip numarasının gitmesini durdurmamalı. Yetki reddi
     * yükselir — aynı kod sipariş yazmayı da reddederdi.
     */
    private function assignCarrier(int $orderId, string $carrier): void
    {
        $wanted = self::carrierKey($carrier);

        if ($wanted === '') {
            return;
        }

        try {
            $carriers = $this->fetchCarriers();
        } catch (TicimaxSoapFault $e) {
            if ($e->isAuthentication()) {
                throw $e;
            }

            return;
        }

        foreach ($carriers as $id => $name) {
            if (self::carrierKey($name) === $wanted) {
                $this->call('SiparisServis', 'SetSiparisKargoFirmaId', ['siparisId' => $orderId, 'kargoFirmaId' => (int) $id]);

                return;
            }
        }
    }

    private static function trackingKey(string $tracking): string
    {
        return mb_strtoupper((string) preg_replace('/\s+/u', '', $tracking));
    }

    /** "Yurtiçi Kargo", "yurtici", "YURTİÇİ KARGO A.Ş." aynı anahtara düşer. */
    private static function carrierKey(string $carrier): string
    {
        $key = mb_strtolower(strtr($carrier, ['İ' => 'i', 'I' => 'ı']));
        $key = strtr($key, ['ı' => 'i', 'ş' => 's', 'ğ' => 'g', 'ü' => 'u', 'ö' => 'o', 'ç' => 'c']);
        $key = (string) preg_replace('/[^a-z0-9]/', '', $key);

        return (string) preg_replace('/(kargo|cargo)(as|ltdsti)?$/', '', $key);
    }

    // ------------------------------------------------------------------- iç

    /**
     * SOAP çağrısı. Yetki kodu yoksa istek ATILMAZ. Fault, HTTP 500 ile
     * gelse bile önce okunur; `WebServisResponse.IsError` de hatadır.
     *
     * @param  array<string, mixed>  $params  `UyeKodu` HARİÇ, WSDL sırasıyla
     */
    private function call(string $service, string $operation, array $params): mixed
    {
        $code = TenantContext::runAsSystem(fn (): array => $this->readSecrets())[self::AUTH_CODE_SECRET] ?? null;

        if (! is_string($code) || $code === '') {
            throw new RuntimeException('Ticimax yetki kodu (UyeKodu) kasada yok.');
        }

        $response = $this->client->postRaw(
            $this->serviceUrl($service),
            TicimaxSoap::envelope($operation, ['UyeKodu' => $code, ...$params]),
            'text/xml; charset=utf-8',
            ['SOAPAction' => '"'.TicimaxSoap::action($service, $operation).'"', 'Accept' => 'text/xml'],
        );

        $result = TicimaxSoap::result($response->body(), $operation);

        $response->throw();

        if (is_array($result) && self::bool($result['IsError'] ?? false)) {
            throw new TicimaxSoapFault('Ticimax: '.(string) ($result['ErrorMessage'] ?? 'işlem reddedildi'), 'IsError');
        }

        return $result;
    }

    private function serviceUrl(string $service): string
    {
        $domain = $this->connection->external_account_id ?: ($this->connection->settings[self::DOMAIN_KEY] ?? null);

        if (! is_string($domain) || preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/i', $domain) !== 1) {
            throw new RuntimeException('Ticimax mağaza alan adı tanımsız ya da geçersiz.');
        }

        // ⚠️ HTTPS — WSDL'deki adres `http://`; PDF Transport güvenliği şart koşar.
        return 'https://'.strtolower($domain).'/Servis/'.$service.'.svc';
    }

    /**
     * Ürün süzgeci: durum alanları -1 (hepsi), kimlik alanları 0 (sınıf notu).
     *
     * @return array<string, mixed>
     */
    private static function productFilter(): array
    {
        return [
            'Aktif' => -1, 'Firsat' => -1, 'Indirimli' => -1, 'Vitrin' => -1,
            'KategoriID' => 0, 'MarkaID' => 0, 'UrunKartiID' => 0,
        ];
    }

    /** @return array<string, mixed> */
    private static function orderFilter(): array
    {
        return [
            'EntegrasyonAktarildi' => -1, 'SiparisDurumu' => -1, 'OdemeTipi' => -1,
            'OdemeDurumu' => -1, 'SiparisID' => -1, 'TedarikciID' => -1,
            // Liste kalemleri taşısın (iptal edilmişler hariç).
            'UrunGetir' => true, 'IptalEdilmisUrunler' => false,
        ];
    }

    /** @return array<string, mixed> */
    private static function paging(int $offset): array
    {
        return ['BaslangicIndex' => $offset, 'KayitSayisi' => self::PAGE_SIZE, 'SiralamaDegeri' => 'ID', 'SiralamaYonu' => 'ASC'];
    }

    /**
     * Yalnız satış ve indirimli fiyat bayrakları açık; geri kalan her
     * bayrak AÇIKÇA false (gönderilmeyen de false sayılır, ama niyet görünsün).
     *
     * @return array<string, bool>
     */
    private static function priceOnlySettings(): array
    {
        return ['IndirimliFiyatiGuncelle' => true, 'SatisFiyatiGuncelle' => true, 'StokAdediGuncelle' => false, 'ParaBirimiGuncelle' => false, 'KdvOraniGuncelle' => false, 'KdvDahilGuncelle' => false];
    }

    /**
     * Kişisel veri inbox'a girmez: ad, e-posta, adres, IP, not.
     *
     * @param  array<string, mixed>  $order
     * @return array<string, mixed>
     */
    private static function withoutPersonalData(array $order): array
    {
        $keep = ['ID', 'Durum', 'SiparisDurumu', 'SiparisNo', 'SiparisKodu', 'SiparisTarihi', 'DuzenlemeTarihi',
            'DurumGuncellemeTarihi', 'ParaBirimi', 'SiparisToplamTutari', 'ToplamTutar', 'KargoTutari', 'Urunler'];

        return array_intersect_key($order, array_flip($keep));
    }

    /** Bu olay daha önce inbox'a yazıldı mı? */
    private function alreadyIngested(string $eventId): bool
    {
        return TenantContext::runAsSystem(fn (): bool => InboxMessage::query()
            ->where('channel_connection_id', $this->connection->id)
            ->where('external_event_id', $eventId)
            ->exists());
    }

    /** İndirim satıştan düşük ve sıfırdan büyükse indirim, değilse satış. @param array<string, mixed> $variant */
    private static function effectivePrice(array $variant): float
    {
        $sell = (float) ($variant['SatisFiyati'] ?? 0);
        $discount = (float) ($variant['IndirimliFiyati'] ?? 0);

        return $discount > 0 && $discount < $sell ? $discount : $sell;
    }

    private static function gross(float $amount, bool $vatIncluded, float $rate): float
    {
        return $vatIncluded ? $amount : $amount * (1 + $rate / 100);
    }

    private static function net(float $gross, bool $vatIncluded, float $rate): float
    {
        return round($vatIncluded ? $gross : $gross / (1 + $rate / 100), 4);
    }

    private static function money(mixed $value): string
    {
        return number_format(round((float) $value, 2), 2, '.', '');
    }

    private static function bool(mixed $value): bool
    {
        return $value === true || $value === 'true' || $value === '1' || $value === 1;
    }

    /** Ofsetsiz WCF tarihi Türkiye saati sayılır (DOĞRULANMADI). */
    private static function channelDate(mixed $raw): ?DateTimeImmutable
    {
        if (! is_string($raw) || trim($raw) === '' || str_starts_with($raw, '0001-01-01')) {
            return null;
        }

        try {
            $hasZone = preg_match('/(Z|[+-]\d{2}:?\d{2})$/', trim($raw)) === 1;

            return new DateTimeImmutable(trim($raw), $hasZone ? null : new DateTimeZone(self::CHANNEL_TIMEZONE));
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array<string, mixed> */
    private function readSecrets(): array
    {
        try {
            return app(CredentialVault::class)->read($this->connection);
        } catch (Throwable) {
            return [];
        }
    }
}
