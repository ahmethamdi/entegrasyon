<?php

declare(strict_types=1);

namespace App\Domain\Orders\Support;

use App\Domain\Channels\Contracts\SupportsOrderReconciliation;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Registry\AdapterRegistry;
use App\Domain\Channels\Support\CircuitBreaker;
use App\Domain\Channels\Support\ConnectionSettingsWriter;
use App\Domain\Messaging\Actions\IngestInboxMessage;
use App\Domain\Messaging\Jobs\ProcessInboxMessage;
use App\Domain\Orders\Models\Order;
use App\Domain\Sync\Support\RecoveredOrderEvent;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Webhook kanallarında sipariş mutabakatı — kaçan siparişi kurtarır (P0-3).
 *
 * Shopify (ve ileride Woo/ikas) siparişi webhook ile gönderir ve YOKLANMAZ.
 * Kaybolan bir webhook (uygulama kesintisi, Shopify'ın 48 saatlik deneme
 * süresinin dolması, imza sırrı değişimi) önceden KALICI sipariş kaybıydı:
 * stok hiç düşmez, mal ikinci kez satılırdı.
 *
 * Bu tur ~30 dakikada bir, `SupportsOrderReconciliation` taşıyan her aktif
 * bağlantının son penceresini okur ve her siparişi webhook'la AYNI hattan
 * geçirir:
 *
 *   IngestInboxMessage (source='reconcile') → ProcessInboxMessage →
 *   OrderEventRouter → IngestChannelOrder (idempotent)
 *
 * İkinci bir sipariş yolu YOKTUR; tekilleştirme, erteleme, yeniden deneme ve
 * kurtarma olduğu gibi geçerlidir.
 *
 * KURALLAR — `PollChannelOrders` ile aynı:
 *   · Pencere imleçten GERİYE örtüşür; başarısız turda imleç İLERLEMEZ.
 *   · Sayfa sınırı dolarsa imleç ilerlemez, sonraki tur kaldığı yerden sürer.
 *   · Bağlantı başına TAM yalıtım: bir mağazanın hatası diğerlerini durdurmaz.
 *   · Devre kesicisi açık bağlantı atlanır (kanal zaten sorunlu; kota yakmaz).
 *   · Kova (Shopify maliyet sınırı) adapter'ın `gql()`'inde gözetilir; uzun
 *     bekleme gerekirse istisna → bu bağlantının imleci ilerlemez.
 *
 * BAĞLANTIDAN ÖNCE VERİLMİŞ SİPARİŞ ATLANIR: o satış içe aktarılan açılış
 * stoğundadır; yaratmak aynı satışı ikinci kez düşerdi (A14 kuralı).
 */
final class ReconcileChannelOrders
{
    /** "Sipariş alımı buraya kadar tamam" — `UnrecordedSaleGuard` da okur. */
    public const CURSOR_KEY = 'orders_reconciled_through';

    public const RESUME_KEY = 'orders_reconcile_resume';

    /** Pencere örtüşmesi — kanalın yazma gecikmesi + tur süresi. */
    private const OVERLAP_MINUTES = 10;

    /** İmleç yokken geriye bakış. */
    private const INITIAL_LOOKBACK_HOURS = 24;

    /** İmleç çok eskiyse (uzun kesinti) en fazla bu kadar geriye bakılır. */
    private const MAX_LOOKBACK_HOURS = 72;

    private const MAX_PAGES = 20;

    public function __construct(
        private readonly AdapterRegistry $adapters,
        private readonly IngestInboxMessage $ingest,
        private readonly CircuitBreaker $breaker,
    ) {}

    /** @return array{connections: int, recovered: int, failed: int} */
    public function run(): array
    {
        $connections = TenantContext::runAsSystem(fn () => ChannelConnection::query()
            ->where('status', 'active')
            ->with('channelType:code,name,adapter_class,supports_webhooks')
            ->get());

        $summary = ['connections' => 0, 'recovered' => 0, 'failed' => 0];

        foreach ($connections as $connection) {
            try {
                $recovered = $this->reconcileConnection($connection);

                if ($recovered !== null) {
                    $summary['connections']++;
                    $summary['recovered'] += $recovered;
                }
            } catch (Throwable $e) {
                // İMLEÇ İLERLEMEDİ — pencere sonraki turda yeniden sorulur.
                $summary['failed']++;

                Log::warning('orders.reconcile_failed', [
                    'connection' => $connection->id,
                    'channel' => $connection->channel_type_code,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $summary;
    }

    /** @return int|null kurtarılan sipariş sayısı; null = bu bağlantı kapsam dışı */
    private function reconcileConnection(ChannelConnection $connection): ?int
    {
        $adapter = TenantContext::runAsSystem(fn () => $this->adapters->for($connection));

        if (! $adapter instanceof SupportsOrderReconciliation) {
            return null;
        }

        if (! $this->breaker->allows($connection->id)) {
            Log::info('orders.reconcile_skipped_open_circuit', ['connection' => $connection->id]);

            return 0;
        }

        $resume = $this->resumePoint($connection);
        $since = $resume['since'] ?? $this->windowStart($connection);
        $startedAt = $resume['started_at'] ?? Carbon::now();
        $cursor = $resume['cursor'] ?? null;
        $recovered = 0;
        $pages = 0;

        do {
            $page = TenantContext::runAsSystem(fn () => $adapter->fetchOrdersForReconciliation($since, $cursor));

            foreach ($page->events as $event) {
                $recovered += $this->ingestEvent($connection, $event);
            }

            $cursor = $page->nextCursor;
            $pages++;
        } while ($page->hasMore && $cursor !== null && $pages < self::MAX_PAGES);

        if ($page->hasMore && $cursor !== null) {
            ConnectionSettingsWriter::merge($connection, [
                self::RESUME_KEY => [
                    'since' => $since->toIso8601String(),
                    'started_at' => $startedAt->toIso8601String(),
                    'cursor' => $cursor,
                ],
            ]);

            Log::warning('orders.reconcile_page_limit_reached', ['connection' => $connection->id, 'pages' => $pages]);

            return $recovered;
        }

        ConnectionSettingsWriter::merge($connection, [
            self::CURSOR_KEY => $startedAt->toIso8601String(),
        ], forget: [self::RESUME_KEY]);

        return $recovered;
    }

    /** @return int 1 = sipariş bizde yoktu ve kurtarıldı (kuyruğa girdi) */
    private function ingestEvent(ChannelConnection $connection, RecoveredOrderEvent $event): int
    {
        if ($this->placedBeforeConnection($connection, $event)) {
            return 0;
        }

        $known = TenantContext::runFor($connection->tenant_id, fn (): bool => Order::query()
            ->where('channel_connection_id', $connection->id)
            ->where('external_id', $event->externalOrderId)
            ->exists());

        $message = $this->ingest->run(
            connection: $connection,
            source: 'reconcile',
            externalEventId: $event->eventId,
            eventType: $event->eventType,
            payload: json_encode($event->payload, JSON_THROW_ON_ERROR),
            // Gövdeyi kimlikli bir çağrıyla BİZ istedik (yoklamayla aynı gerekçe).
            signatureValid: true,
        );

        if (! $message->wasRecentlyCreated) {
            return 0;
        }

        ProcessInboxMessage::dispatch($connection->tenant_id, $message->id)->onQueue('inbox:process');

        if ($known) {
            return 0;
        }

        // KAÇAN SİPARİŞ — iz bırakılır (eylem merkezi `source='reconcile'`
        // inbox satırlarından türetir; ADR-006).
        Log::warning('orders.reconcile_recovered', [
            'connection' => $connection->id,
            'external_order_id' => $event->externalOrderId,
            'event' => $event->eventType,
            'message' => $message->id,
        ]);

        return $event->eventType === 'orders/create' ? 1 : 0;
    }

    private function placedBeforeConnection(ChannelConnection $connection, RecoveredOrderEvent $event): bool
    {
        $connectedAt = $connection->connected_at;
        $created = $event->payload['created_at'] ?? null;

        if ($connectedAt === null || ! is_string($created) || $created === '') {
            // Bilinmiyorsa yaratılmaz değil: router'ın A14 kuralı ve sipariş
            // tekilliği yine geçerli. Bağlantı anı yoksa (eski kayıt) en
            // güvenli taraf da kurtarmaktır — sipariş kaybı çift düşüşten
            // nadir değildir ama bağlantı anı her canlı bağlantıda dolu.
            return false;
        }

        try {
            return Carbon::parse($created)->lessThan($connectedAt);
        } catch (Throwable) {
            return false;
        }
    }

    private function windowStart(ChannelConnection $connection): Carbon
    {
        $floor = Carbon::now()->subHours(self::MAX_LOOKBACK_HOURS);
        $cursor = $connection->settings[self::CURSOR_KEY] ?? null;

        try {
            $start = is_string($cursor) && $cursor !== ''
                ? Carbon::parse($cursor)->subMinutes(self::OVERLAP_MINUTES)
                : Carbon::now()->subHours(self::INITIAL_LOOKBACK_HOURS);
        } catch (Throwable) {
            $start = Carbon::now()->subHours(self::INITIAL_LOOKBACK_HOURS);
        }

        return $start->lessThan($floor) ? $floor : $start;
    }

    /** @return array{since?: Carbon, started_at?: Carbon, cursor?: string} */
    private function resumePoint(ChannelConnection $connection): array
    {
        $resume = $connection->settings[self::RESUME_KEY] ?? null;

        if (! is_array($resume) || ! is_string($resume['cursor'] ?? null)) {
            return [];
        }

        try {
            return [
                'since' => Carbon::parse((string) $resume['since']),
                'started_at' => Carbon::parse((string) $resume['started_at']),
                'cursor' => (string) $resume['cursor'],
            ];
        } catch (Throwable) {
            return [];
        }
    }
}
