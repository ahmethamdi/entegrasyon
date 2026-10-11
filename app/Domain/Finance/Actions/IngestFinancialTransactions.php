<?php

declare(strict_types=1);

namespace App\Domain\Finance\Actions;

use App\Domain\Channels\Contracts\SupportsFinancialLedger;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Registry\AdapterRegistry;
use App\Domain\Channels\Support\CircuitBreaker;
use App\Domain\Channels\Support\ConnectionSettingsWriter;
use App\Domain\Finance\Models\ChannelFinancialTransaction as Tx;
use App\Domain\Finance\Support\RemoteFinancialTransaction;
use App\Domain\Finance\Support\TrendyolFinancialTypes;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Kanalın finansal işlem defterini HAM olarak içe alır (S1 · hakediş).
 *
 * İDEMPOTENS: `(bağlantı, kaynak, kanal işlem kimliği)` tekil; satır
 * `insertOrIgnore` ile yazılır. Örtüşen pencere, sayfa tekrarı, API yeniden
 * denemesi, aynı kaydın iki kez dönmesi ikinci bir işlem YARATMAZ.
 *
 * HAM OLGU EZİLMEZ: aynı kimlik FARKLI içerikle gelirse (ham özet farklı)
 * satır değiştirilmez, `conflicts` sayılır ve uyarı yazılır — kanal kaydı
 * düzelttiyse bunu bilmek isteriz ama sessizce üstüne yazmak geçmişi siler.
 * Aynı içerikle gelirse yalnız YORUM (tür/sınıf) güncellenir: kod yeni bir
 * türü tanımayı öğrendiğinde eski satırlar yeniden sınıflanır.
 *
 * PENCERE: kanalın en uzun aralığı (Trendyol 15 gün). Her tur imleçten
 * `OVERLAP_DAYS` geri başlar — geç düşen ve geç tarihlenen kayıtlar
 * kaçmasın. İmleç YALNIZ bir pencere tüm kaynak ve sayfalarıyla bitince
 * ilerler; sayfa hatası turu durdurur, kalan pencere sonraki turda
 * yeniden sorulur (yazılanlar idempotent, tekrar zararsız).
 *
 * YALITIM: bağlantı başına try/catch (P0-4 deseni) — bir satıcının bozuk
 * anahtarı ötekilerin hakedişini durdurmaz.
 */
final class IngestFinancialTransactions
{
    public const CURSOR_KEY = 'finance_ingested_through';

    public const OVERLAP_DAYS = 3;

    /** İlk turda bağlantı tarihinden bu kadar geriye bakılır. */
    public const INITIAL_LOOKBACK_DAYS = 30;

    /** Tur başına en fazla pencere — kalan sonraki turda. */
    public const MAX_WINDOWS = 12;

    public const MAX_PAGES = 50;

    public function __construct(
        private readonly AdapterRegistry $adapters,
        private readonly CircuitBreaker $breaker,
        private readonly MatchFinancialTransactions $matcher,
    ) {}

    /**
     * @return array{connections: int, inserted: int, duplicates: int, conflicts: int, failed: int}
     */
    public function run(): array
    {
        $connections = TenantContext::runAsSystem(fn () => ChannelConnection::query()
            ->where('status', 'active')
            ->with('channelType:code,name,adapter_class,supports_webhooks')
            ->get());

        $summary = ['connections' => 0, 'inserted' => 0, 'duplicates' => 0, 'conflicts' => 0, 'failed' => 0];

        foreach ($connections as $connection) {
            try {
                $result = $this->forConnection($connection);

                if ($result !== null) {
                    $summary['connections']++;
                    $summary['inserted'] += $result['inserted'];
                    $summary['duplicates'] += $result['duplicates'];
                    $summary['conflicts'] += $result['conflicts'];
                }
            } catch (Throwable $e) {
                $summary['failed']++;

                Log::warning('finance.ingest_failed', [
                    'connection' => $connection->id,
                    'channel' => $connection->channel_type_code,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $summary;
    }

    /**
     * @return array{inserted: int, duplicates: int, conflicts: int, windows: int}|null null = kanal desteklemiyor
     */
    public function forConnection(ChannelConnection $connection, ?CarbonImmutable $now = null): ?array
    {
        $adapter = TenantContext::runAsSystem(fn () => $this->adapters->for($connection));

        if (! $adapter instanceof SupportsFinancialLedger) {
            return null;
        }

        if (! $this->breaker->allows($connection->id)) {
            Log::info('finance.ingest_skipped_open_circuit', ['connection' => $connection->id]);

            return ['inserted' => 0, 'duplicates' => 0, 'conflicts' => 0, 'windows' => 0];
        }

        $now ??= CarbonImmutable::now();
        $from = $this->windowStart($connection, $now);
        $counts = ['inserted' => 0, 'duplicates' => 0, 'conflicts' => 0, 'windows' => 0];

        while ($from->lessThan($now) && $counts['windows'] < self::MAX_WINDOWS) {
            $to = $from->addDays($adapter->financialWindowDays())->subMillisecond();
            $to = $to->greaterThan($now) ? $now : $to;

            foreach ($adapter->financialSources() as $source) {
                $page = 0;

                do {
                    $result = TenantContext::runFor($connection->tenant_id, fn () => $adapter->fetchFinancialPage($source, $from, $to, $page));

                    foreach ($result->transactions as $transaction) {
                        $counts[$this->store($connection, $transaction)]++;
                    }

                    $page++;
                } while ($result->hasMore() && $page < self::MAX_PAGES);
            }

            // PENCERE TAMAM → imleç ilerler. Ortada hata olsaydı buraya
            // gelinmez; pencere sonraki turda baştan sorulur.
            ConnectionSettingsWriter::merge($connection, [self::CURSOR_KEY => $to->toIso8601String()]);
            $counts['windows']++;
            $from = $to->addMillisecond();
        }

        TenantContext::runFor($connection->tenant_id, fn () => $this->matcher->forConnection($connection));

        return $counts;
    }

    private function windowStart(ChannelConnection $connection, CarbonImmutable $now): CarbonImmutable
    {
        $cursor = $connection->fresh()?->settings[self::CURSOR_KEY] ?? null;

        if (is_string($cursor) && $cursor !== '') {
            return CarbonImmutable::parse($cursor)->subDays(self::OVERLAP_DAYS);
        }

        $base = $connection->connected_at !== null ? CarbonImmutable::parse($connection->connected_at) : $now;

        return $base->subDays(self::INITIAL_LOOKBACK_DAYS);
    }

    /**
     * @return 'inserted'|'duplicates'|'conflicts'
     */
    private function store(ChannelConnection $connection, RemoteFinancialTransaction $tx): string
    {
        $class = self::classify($connection, $tx);
        $hash = $tx->rawHash();

        return TenantContext::runFor($connection->tenant_id, function () use ($connection, $tx, $class, $hash): string {
            $now = now();

            $inserted = DB::table('channel_financial_transactions')->insertOrIgnore([
                'id' => Tx::generateUuidV7(),
                'tenant_id' => $connection->tenant_id,
                'channel_connection_id' => $connection->id,
                'source' => $tx->source,
                'external_id' => $tx->externalId,
                'type_raw' => $tx->typeRaw,
                'debt' => $tx->debt,
                'credit' => $tx->credit,
                'commission_amount' => $tx->commissionAmount,
                'commission_rate' => $tx->commissionRate,
                'seller_revenue' => $tx->sellerRevenue,
                'currency' => $tx->currency === null ? null : strtoupper($tx->currency),
                'order_number' => $tx->orderNumber,
                'shipment_package_id' => $tx->shipmentPackageId,
                'barcode' => $tx->barcode,
                'transaction_date' => $tx->transactionDate,
                'payment_date' => $tx->paymentDate,
                'payment_order_id' => $tx->paymentOrderId,
                'receipt_id' => $tx->receiptId,
                'commission_invoice_serial' => $tx->commissionInvoiceSerial,
                'description' => $tx->description === null ? null : mb_substr($tx->description, 0, 1000),
                'raw' => json_encode($tx->raw, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION),
                'raw_hash' => $hash,
                'type' => $class['type'],
                'classification' => $class['classification'],
                'classification_reason' => $class['reason'],
                'match_status' => $class['order_level'] ? Tx::UNMATCHED : Tx::NOT_ORDER_LEVEL,
                'match_reason' => $class['order_level'] ? 'henüz eşlenmedi' : null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            if ($inserted === 1) {
                return 'inserted';
            }

            $existing = Tx::query()
                ->where('channel_connection_id', $connection->id)
                ->where('source', $tx->source)
                ->where('external_id', $tx->externalId)
                ->first(['id', 'raw_hash', 'type', 'classification', 'classification_reason']);

            if ($existing === null) {
                return 'duplicates';
            }

            if ($existing->raw_hash !== $hash) {
                Log::warning('finance.transaction_changed_at_source', [
                    'connection' => $connection->id,
                    'source' => $tx->source,
                    'external_id' => $tx->externalId,
                ]);

                return 'conflicts';
            }

            // Yalnız YORUM güncellenir (ham kolonlar tetikleyiciyle korunur).
            if ($existing->type !== $class['type'] || $existing->classification !== $class['classification']) {
                $existing->forceFill([
                    'type' => $class['type'],
                    'classification' => $class['classification'],
                    'classification_reason' => $class['reason'],
                ])->save();
            }

            return 'duplicates';
        });
    }

    /**
     * @return array{type: ?string, classification: string, reason: ?string, order_level: bool}
     */
    public static function classify(ChannelConnection $connection, RemoteFinancialTransaction $tx): array
    {
        return match ($connection->channel_type_code) {
            'trendyol' => TrendyolFinancialTypes::classify($tx),
            default => [
                'type' => null,
                'classification' => Tx::UNKNOWN,
                'reason' => "{$connection->channel_type_code} için sınıflandırma yok",
                'order_level' => $tx->shipmentPackageId !== null || $tx->orderNumber !== null,
            ],
        };
    }
}
