<?php

declare(strict_types=1);

namespace App\Domain\Channels\Support;

use App\Domain\Catalog\Models\Variant;
use App\Domain\Channels\Contracts\SupportsCatalogImport;
use App\Domain\Channels\Contracts\SupportsInventory;
use App\Domain\Channels\Contracts\SupportsOrders;
use App\Domain\Channels\Enums\OutboundWrite;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Registry\AdapterRegistry;
use App\Domain\Inventory\Support\SellableStock;
use App\Domain\Messaging\Models\InboxMessage;
use App\Domain\Orders\Routing\OrderPayloadMapper;
use App\Domain\Sync\Models\Listing;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Pilot satıcı doğrulaması — KANALA YALNIZ OKUMA (Sprint 2).
 *
 * Gerçek bir satıcı hesabında "bağlantı, sipariş, ürün, stok bizde doğru
 * görünüyor mu" sorusunu kanala tek bir yazma isteği atmadan cevaplar:
 * yalnız `healthCheck`, `fetchOrders`, `fetchProductPage` ve
 * `fetchInventory` çağrılır. Siparişler inbox'a YAZILMAZ, kuyruğa iş
 * atılmaz, stok/listing değişmez — üretim hattını değil, onun ilk iki
 * adımını (kanal gövdesi → kanonik olay → SKU eşleşmesi) kuru çalıştırır.
 *
 * KİŞİSEL VERİ RAPORA GİRMEZ: alıcı adı/adresi tutulmaz; sipariş kimliği,
 * durum, tutar, SKU ve sayılar yeter (KVKK — rapor dosyası diske yazılır).
 *
 * Bölümler birbirinden bağımsızdır: sipariş okuma patlarsa stok bölümü
 * yine çalışır ve patlama raporda kendi bölümünde görünür.
 */
final class PilotCheck
{
    private const MAX_ORDER_PAGES = 5;

    private const SAMPLE = 20;

    public function __construct(
        private readonly AdapterRegistry $adapters = new AdapterRegistry,
    ) {}

    /** @return array<string, mixed> */
    public function run(ChannelConnection $connection, int $days = 14, int $listingLimit = 50): array
    {
        return TenantContext::runFor($connection->tenant_id, function () use ($connection, $days, $listingLimit): array {
            $adapter = $this->adapters->for($connection);

            $report = [
                'checked_at' => now()->toIso8601String(),
                'connection' => [
                    'id' => $connection->id,
                    'channel' => $connection->channel_type_code,
                    'label' => $connection->label,
                    'status' => $connection->status,
                    // Pilotta yazma izinleri KAPALI başlar (ADR-010); hangisinin
                    // açık olduğu raporda görünsün.
                    'writes_enabled' => collect(OutboundWrite::cases())
                        ->mapWithKeys(fn (OutboundWrite $w): array => [$w->value => $connection->getAttribute($w->column()) === true])
                        ->all(),
                ],
                'health' => $this->section(function () use ($adapter): array {
                    $h = $adapter->healthCheck();

                    return ['ok' => $h->healthy, 'message' => $h->message, 'latency_ms' => $h->latencyMs];
                }),
                'orders' => $adapter instanceof SupportsOrders
                    ? $this->section(fn (): array => $this->orders($connection, $adapter, $days))
                    : ['supported' => false],
                'catalog' => $adapter instanceof SupportsCatalogImport
                    ? $this->section(fn (): array => $this->catalog($adapter))
                    : ['supported' => false],
                'inventory' => $adapter instanceof SupportsInventory
                    ? $this->section(fn (): array => $this->inventory($connection, $adapter, $listingLimit))
                    : ['supported' => false],
            ];

            $report['verdict'] = $this->verdict($report);

            return $report;
        });
    }

    /**
     * @param  callable(): array<string, mixed>  $work
     * @return array<string, mixed>
     */
    private function section(callable $work): array
    {
        try {
            return ['supported' => true, 'ok' => true, ...$work()];
        } catch (Throwable $e) {
            return ['supported' => true, 'ok' => false, 'error' => $e::class.': '.$e->getMessage()];
        }
    }

    /** @return array<string, mixed> */
    private function orders(ChannelConnection $connection, SupportsOrders $adapter, int $days): array
    {
        $since = Carbon::now()->subDays($days);
        $cursor = null;
        $pages = 0;
        $orders = [];

        do {
            $page = $adapter->fetchOrders($since, $cursor);
            array_push($orders, ...$page->orders);
            $cursor = $page->nextCursor;
            $pages++;
        } while ($page->hasMore && $cursor !== null && $pages < self::MAX_ORDER_PAGES);

        $parsed = 0;
        $missingEventId = 0;
        $lines = 0;
        $matched = 0;
        $unmatched = [];
        $failures = [];
        $samples = [];

        foreach ($orders as $raw) {
            $eventId = $adapter->pollingEventIdFor($raw);

            if ($eventId === null) {
                // Kimliksiz sipariş tekilleştirilemez: üretimde aynı sipariş
                // iki kez düşebilir — pilotta görünmesi gereken ilk şey.
                $missingEventId++;
            }

            // Kaydedilmeyen, bellekteki ileti: üretimdeki yoklama ile aynı alanlar.
            $message = new InboxMessage;
            $message->forceFill([
                'channel_connection_id' => $connection->id,
                'source' => 'polling',
                'external_event_id' => $eventId,
                'event_type' => 'order.polled',
                'payload' => $raw,
            ]);

            try {
                $event = $adapter->parseOrderEvent($message);
            } catch (Throwable $e) {
                $failures[] = $e->getMessage();

                continue;
            }

            if ($event === null) {
                $failures[] = 'ayrıştırıcı boş döndü';

                continue;
            }

            $parsed++;
            $incoming = OrderPayloadMapper::toIncomingOrder($event, $connection->id);

            foreach ($incoming->lines as $line) {
                $lines++;

                if ($line->variantId !== null) {
                    $matched++;
                } else {
                    $unmatched[$line->sku !== '' ? $line->sku : '(SKU yok)'] = true;
                }
            }

            if (count($samples) < 5) {
                $samples[] = [
                    'external_id' => $incoming->externalId,
                    'number' => $incoming->externalNumber,
                    'type' => $event->type,
                    'status' => $incoming->status,
                    'placed_at' => $incoming->placedAt?->format(DATE_ATOM),
                    'grand_total' => $incoming->grandTotal.' '.$incoming->currency,
                    'lines' => count($incoming->lines),
                ];
            }
        }

        return [
            'window_days' => $days,
            'pages' => $pages,
            'truncated' => $page->hasMore && $cursor !== null,
            'fetched' => count($orders),
            'parsed' => $parsed,
            'parse_failures' => array_slice(array_values(array_unique($failures)), 0, self::SAMPLE),
            'missing_event_id' => $missingEventId,
            'lines' => $lines,
            'lines_matched' => $matched,
            'unmatched_skus' => array_slice(array_keys($unmatched), 0, self::SAMPLE),
            'samples' => $samples,
        ];
    }

    /** @return array<string, mixed> */
    private function catalog(SupportsCatalogImport $adapter): array
    {
        $page = $adapter->fetchProductPage();

        $skus = [];
        foreach ($page->products as $product) {
            if ($product->hasSku()) {
                $skus[] = (string) $product->sku;
            }
        }

        $known = $skus === [] ? [] : Variant::query()->whereIn('sku', array_unique($skus))->pluck('sku')->all();
        $missing = array_values(array_diff(array_unique($skus), $known));

        return [
            'first_page' => $page->count(),
            'has_more' => $page->hasMore,
            'with_sku' => count($skus),
            'sku_known_here' => count($known),
            'sku_missing_here' => array_slice($missing, 0, self::SAMPLE),
        ];
    }

    /** @return array<string, mixed> */
    private function inventory(ChannelConnection $connection, SupportsInventory $adapter, int $limit): array
    {
        $listings = Listing::query()
            ->with('variant')
            ->where('channel_connection_id', $connection->id)
            ->whereNotNull('external_id')
            ->orderBy('id')
            ->limit(min($limit, $adapter->maxInventoryBatchSize()))
            ->get();

        if ($listings->isEmpty()) {
            return ['listings' => 0, 'note' => 'Bu bağlantıda kanal kimliği olan ilan yok — önce içe aktarma ya da eşleme.'];
        }

        $snapshot = $adapter->fetchInventory($listings->all());
        $levels = SellableStock::forVariants($connection->tenant_id, $listings->pluck('variant_id')->unique()->values()->all());

        $equal = 0;
        $notFound = [];
        $diffs = [];

        foreach ($listings as $listing) {
            $remote = $snapshot->quantityFor((string) $listing->external_id);
            $expected = ($levels[$listing->variant_id] ?? null)?->outbound() ?? 0;
            $sku = $listing->variant?->sku ?? (string) $listing->external_id;

            if ($remote === null) {
                $notFound[] = $sku;
            } elseif ($remote === $expected) {
                $equal++;
            } else {
                $diffs[] = ['sku' => $sku, 'here' => $expected, 'channel' => $remote];
            }
        }

        return [
            'listings' => $listings->count(),
            'equal' => $equal,
            'different' => array_slice($diffs, 0, self::SAMPLE),
            'different_count' => count($diffs),
            'not_found_on_channel' => array_slice($notFound, 0, self::SAMPLE),
        ];
    }

    /**
     * Kısa hüküm: neyin OKUNARAK doğrulandığı, neyin hâlâ yazma denemesi
     * beklediği. Yazma yetenekleri (stok gönderme, kargo bildirimi) bu
     * komutla ASLA "doğrulandı" sayılmaz.
     *
     * @param  array<string, mixed>  $r
     * @return list<string>
     */
    private function verdict(array $r): array
    {
        $out = [];
        $out[] = ($r['health']['ok'] ?? false) ? '✓ Bağlantı (AUTH) çalışıyor' : '✗ Bağlantı kurulamadı — kimlik bilgilerini kontrol et';

        $o = $r['orders'];
        if ($o['supported'] ?? false) {
            if (! ($o['ok'] ?? false)) {
                $out[] = '✗ Sipariş okunamadı';
            } elseif (($o['fetched'] ?? 0) === 0) {
                $out[] = '… Pencerede sipariş yok — sipariş düşünce tekrar çalıştır';
            } else {
                $out[] = sprintf('%s Sipariş: %d/%d ayrıştı, satır eşleşmesi %d/%d',
                    $o['parsed'] === $o['fetched'] && $o['missing_event_id'] === 0 ? '✓' : '✗',
                    $o['parsed'], $o['fetched'], $o['lines_matched'], $o['lines']);
            }
        }

        $i = $r['inventory'];
        if (($i['supported'] ?? false) && ($i['ok'] ?? false) && ($i['listings'] ?? 0) > 0) {
            $out[] = sprintf('%s Stok okuma: %d ilanın %d tanesi bizimle aynı',
                ($i['different_count'] ?? 0) === 0 && ($i['not_found_on_channel'] ?? []) === [] ? '✓' : '!',
                $i['listings'], $i['equal']);
        }

        $out[] = '→ Stok gönderme ve kargo bildirimi bu komutla DOĞRULANMAZ; tek ürünle elle dene (docs/PILOT-DOGRULAMA.md).';

        return $out;
    }
}
