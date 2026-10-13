<?php

declare(strict_types=1);

namespace App\Domain\Returns\Actions;

use App\Domain\Channels\Contracts\SupportsReturnDecisions;
use App\Domain\Channels\Contracts\SupportsReturns;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Registry\AdapterRegistry;
use App\Domain\Channels\Support\ChannelRateLimiter;
use App\Domain\Channels\Support\CircuitBreaker;
use App\Domain\Channels\Support\ConnectionSettingsWriter;
use App\Domain\Returns\Enums\ReturnStatus;
use App\Domain\Returns\Models\ReturnRequestLine;
use App\Domain\Returns\Support\ReturnInterpretation;
use App\Domain\Sync\Support\AdapterReportedFailure;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * İade taleplerini yoklar, kanonik kayda yazar ve stok iadesini uzlaştırır — S4.
 *
 * PENCERE/İMLEÇ (finans yoklamasıyla aynı desen): imleç YALNIZ bir pencere
 * tüm sayfalarıyla bittiğinde ilerler. Sayfa hatası, hız sınırı ya da açık
 * devre kesici turu durdurur; pencere sonraki turda yeniden sorulur
 * (yazma idempotent). HTTP hatası "iade yok" SAYILMAZ.
 *
 * UZLAŞTIRMA her turda, yoklamadan bağımsız:
 *   · eşleşmemiş talepler yeniden eşlenir (sipariş sonradan geldiyse)
 *   · kabul edilmiş ama varış kanıtı olmayan kalemde kanalın denetim
 *     kaydına bakılır (durum hiç görülmeden kabul geçtiyse)
 *   · stoğu dönmemiş kabul edilmiş kalemlerde stok iadesi denenir
 * Uzlaştırma DIŞARI YAZMAZ.
 */
final class IngestReturns
{
    public const CURSOR_KEY = 'returns_ingested_through';

    public const INITIAL_LOOKBACK_DAYS = 30;

    public const MAX_WINDOWS = 8;

    public const MAX_PAGES = 40;

    /** Tur başına denetim kaydı sorulacak en çok kalem. */
    public const MAX_AUDITS = 20;

    public function __construct(
        private readonly AdapterRegistry $adapters,
        private readonly CircuitBreaker $breaker,
        private readonly ChannelRateLimiter $limiter,
        private readonly StoreRemoteReturn $store = new StoreRemoteReturn,
        private readonly RestoreReturnedStock $restore = new RestoreReturnedStock,
    ) {}

    /** @return array{connections: int, stored: int, restored: int, failed: int} */
    public function run(): array
    {
        $connections = TenantContext::runAsSystem(fn () => ChannelConnection::query()
            ->where('status', 'active')
            ->with('channelType:code,name,adapter_class,supports_webhooks')
            ->get());

        $summary = ['connections' => 0, 'stored' => 0, 'restored' => 0, 'failed' => 0];

        foreach ($connections as $connection) {
            try {
                $result = $this->forConnection($connection);

                if ($result !== null) {
                    $summary['connections']++;
                    $summary['stored'] += $result['stored'];
                    $summary['restored'] += $result['restored'];
                }
            } catch (Throwable $e) {
                $summary['failed']++;

                Log::warning('returns.ingest_failed', [
                    'connection' => $connection->id,
                    'channel' => $connection->channel_type_code,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $summary;
    }

    /** @return array{stored: int, restored: int, windows: int, complete: bool}|null null = kanal desteklemiyor */
    public function forConnection(ChannelConnection $connection, ?CarbonImmutable $now = null): ?array
    {
        $adapter = TenantContext::runAsSystem(fn () => $this->adapters->for($connection));

        if (! $adapter instanceof SupportsReturns) {
            return null;
        }

        $counts = ['stored' => 0, 'restored' => 0, 'windows' => 0, 'complete' => false];

        if ($this->breaker->allows($connection->id)) {
            $counts = $this->poll($connection, $adapter, $now ?? CarbonImmutable::now(), $counts);
        } else {
            Log::info('returns.ingest_skipped_open_circuit', ['connection' => $connection->id]);
        }

        $counts['restored'] = TenantContext::runFor($connection->tenant_id, fn () => $this->reconcile($connection, $adapter));

        return $counts;
    }

    /**
     * @param  array{stored: int, restored: int, windows: int, complete: bool}  $counts
     * @return array{stored: int, restored: int, windows: int, complete: bool}
     */
    private function poll(ChannelConnection $connection, SupportsReturns $adapter, CarbonImmutable $now, array $counts): array
    {
        $from = $this->windowStart($connection, $adapter, $now);

        try {
            while ($from->lessThan($now) && $counts['windows'] < self::MAX_WINDOWS) {
                $to = $from->addDays($adapter->returnWindowDays())->subMillisecond();
                $to = $to->greaterThan($now) ? $now : $to;
                $page = 0;

                do {
                    if (! $this->limiter->attempt($connection->id, $adapter->rateLimitProfile())) {
                        // Kota bitti: pencere YARIM — imleç ilerlemez.
                        return $counts;
                    }

                    $result = TenantContext::runFor($connection->tenant_id, fn () => $adapter->fetchReturnPage($from, $to, $page));

                    foreach ($result->returns as $remote) {
                        TenantContext::runFor($connection->tenant_id, fn () => $this->store->run($connection, $remote));
                        $counts['stored']++;
                    }

                    $page++;
                } while ($result->hasMore && $page < self::MAX_PAGES);

                if ($result->hasMore) {
                    // Sayfa sınırına takıldı: pencere bitmedi, imleç ilerlemez.
                    Log::warning('returns.window_page_limit', ['connection' => $connection->id]);

                    return $counts;
                }

                ConnectionSettingsWriter::merge($connection, [self::CURSOR_KEY => $to->toIso8601String()]);
                $counts['windows']++;
                $from = $to->addMillisecond();
            }

            $this->breaker->recordSuccess($connection->id);
            $counts['complete'] = true;
        } catch (Throwable $e) {
            $this->breaker->recordFailure($connection->id, AdapterReportedFailure::classify($e, $adapter));

            throw $e;
        }

        return $counts;
    }

    private function windowStart(ChannelConnection $connection, SupportsReturns $adapter, CarbonImmutable $now): CarbonImmutable
    {
        $cursor = $connection->fresh()?->settings[self::CURSOR_KEY] ?? null;
        $rescan = $now->subDays(max(1, $adapter->returnRescanDays()));

        if (is_string($cursor) && $cursor !== '') {
            $fromCursor = CarbonImmutable::parse($cursor)->subDays(1);

            return $fromCursor->lessThan($rescan) ? $fromCursor : $rescan;
        }

        $base = $connection->connected_at !== null ? CarbonImmutable::parse($connection->connected_at) : $now;

        return $base->subDays(self::INITIAL_LOOKBACK_DAYS);
    }

    /** @return int bu turda stoğu dönen kalem sayısı */
    private function reconcile(ChannelConnection $connection, SupportsReturns $adapter): int
    {
        // Sipariş sonradan geldiyse eşleşme yeniden denenir (tazeleme
        // kanala gitmez: kayıttaki ham olgu yeniden işlenir).
        $unmatched = ReturnRequestLine::query()
            ->where('channel_connection_id', $connection->id)
            ->where('match_status', '!=', 'matched')
            ->where('restock_status', '!=', ReturnRequestLine::RESTOCK_RESTORED)
            ->pluck('return_request_id')->unique();

        foreach ($unmatched as $requestId) {
            $this->store->rematch($connection, $requestId);
        }

        $this->proveReceiptFromHistory($connection, $adapter);

        $restored = 0;

        ReturnRequestLine::query()
            ->where('channel_connection_id', $connection->id)
            ->where('status', ReturnStatus::ACCEPTED->value)
            ->whereIn('restock_status', [ReturnRequestLine::RESTOCK_NOT_APPLICABLE, ReturnRequestLine::RESTOCK_PENDING])
            ->orderBy('id')
            ->each(function (ReturnRequestLine $line) use (&$restored): void {
                if ($this->restore->run($line) === ReturnRequestLine::RESTOCK_RESTORED) {
                    $restored++;
                }
            });

        return $restored;
    }

    /**
     * Kabul edilmiş ama varışı görülmemiş kalem: kanalın denetim kaydında
     * "depoya ulaştı" durumu var mı? (Ör. iki yoklama arasında
     * WaitingInAction → Accepted geçtiyse ara durumu hiç görmedik.)
     */
    private function proveReceiptFromHistory(ChannelConnection $connection, SupportsReturns $adapter): void
    {
        if (! $adapter instanceof SupportsReturnDecisions || ! $this->breaker->allows($connection->id)) {
            return;
        }

        $lines = ReturnRequestLine::query()
            ->where('channel_connection_id', $connection->id)
            ->where('status', ReturnStatus::ACCEPTED->value)
            ->whereNull('receipt_proven_at')
            ->where('restock_status', '!=', ReturnRequestLine::RESTOCK_RESTORED)
            ->limit(self::MAX_AUDITS)
            ->get();

        foreach ($lines as $line) {
            if (! $this->limiter->attempt($connection->id, $adapter->rateLimitProfile())) {
                return;
            }

            try {
                $history = $adapter->returnItemStatusHistory($line->external_item_id);
            } catch (Throwable $e) {
                Log::info('returns.audit_unavailable', ['connection' => $connection->id, 'line' => $line->id, 'error' => $e->getMessage()]);

                continue;
            }

            foreach ($history as $raw) {
                if (ReturnInterpretation::provesReceipt($connection->channel_type_code, $raw)) {
                    $line->forceFill(['receipt_proven_at' => now()])->save();

                    break;
                }
            }
        }
    }
}
