<?php

declare(strict_types=1);

namespace App\Domain\Suppliers\Actions;

use App\Domain\Billing\Actions\EnforceQuota;
use App\Domain\Billing\Enums\QuotaMetric;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Suppliers\Enums\RunStatus;
use App\Domain\Suppliers\Enums\SupplierReason;
use App\Domain\Suppliers\Models\SupplierRecord;
use App\Domain\Suppliers\Models\SupplierSource;
use App\Domain\Suppliers\Models\SupplierSyncRun;
use App\Domain\Suppliers\Support\FeedMapping;
use App\Domain\Suppliers\Support\FetchedFeed;
use App\Domain\Suppliers\Support\SupplierFeedException;
use App\Domain\Suppliers\Support\SupplierFeedFetcher;
use App\Domain\Suppliers\Support\SyncContext;
use App\Domain\Suppliers\Support\XmlFeedReader;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Tedarikçi TURU — ADR-012 · §8 tamlık kuralları.
 *
 *   1. Eşleme geçerli mi?             hayır → VALIDATION_FAILED, sıfır yazma
 *   2. Akış indir (boyut sınırlı)     hata  → FETCH_FAILED, sıfır yazma
 *   3. DOĞRULAMA GEÇİŞİ: akışın TAMAMI okunur (iyi biçimlilik, kayıt
 *      sayısı, kimlik tekrarı). Yarıda bozuk XML → PARSE_FAILED ve kayıt
 *      1–400 bile UYGULANMAZ.
 *   4. UYGULAMA GEÇİŞİ: kayıt başına transaction (ApplySupplierItem).
 *      Beklenmeyen kayıt hatası turu durdurmaz, PARTIAL yapar.
 *   5. Akışın tamamı işlendiyse yokluk işaretlenir (stok DEĞİŞMEZ).
 *   6. Kapanış: yalnız COMPLETE `last_succeeded_at`'i ilerletir.
 *
 * Tur yarıda ölürse (çökme, zaman aşımı) satır `running` kalır; iş
 * `failed()` ya da `suppliers:sync` taraması onu FAILED yapar, kilit
 * bırakılır, başarı damgası ilerlemez. Sonraki tur baştan ve idempotent.
 */
final class RunSupplierSync
{
    public function __construct(
        private readonly SupplierFeedFetcher $fetcher,
        private readonly XmlFeedReader $reader,
        private readonly ApplySupplierItem $apply,
        private readonly EnforceQuota $quota,
    ) {}

    public function run(SupplierSyncRun $run): SupplierSyncRun
    {
        $source = $run->source()->firstOrFail();
        $startedAt = hrtime(true);
        $run->forceFill(['started_at' => now()])->save();
        memory_reset_peak_usage();

        $feed = null;
        $ctx = null;

        try {
            $mapping = FeedMapping::forSource($source);
            $mapping->assertUsable($source);

            $feed = $this->fetcher->fetch($source->feed_url);
            $run->forceFill(['bytes' => $feed->bytes])->save();

            [$total, $duplicates] = $this->validate($feed, $mapping);

            $ctx = new SyncContext(
                source: $source,
                run: $run,
                warehouseId: $this->warehouseId(),
                quotaRemaining: $source->create_products ? $this->quota->remaining(QuotaMetric::PRODUCTS) : null,
            );

            $seen = $this->applyAll($ctx, $feed, $mapping, $duplicates);
            $this->markMissing($ctx, $seen);
            $ctx->flush();

            $partial = $ctx->quotaHit || $ctx->counts['errors'] > 0;

            return $this->finish(
                $run,
                $source,
                $partial ? RunStatus::PARTIAL : RunStatus::COMPLETE,
                $ctx->quotaHit ? SupplierReason::SUPPLIER_QUOTA_REACHED : ($partial ? SupplierReason::SUPPLIER_RECORD_FAILED : null),
                null,
                array_merge($ctx->counts, ['records' => $total]),
                $startedAt,
            );
        } catch (SupplierFeedException $e) {
            $ctx?->flush();

            return $this->finish($run, $source, $this->statusFor($e->reason), $e->reason, $e->getMessage(), $ctx?->counts ?? [], $startedAt);
        } finally {
            $feed?->discard();
        }
    }

    /**
     * Turu yarıda kalmış sayar (iş öldü / zaman aşımı) — kilit bırakılır,
     * başarı damgası ilerlemez.
     */
    public function abandon(SupplierSyncRun $run, ?string $detail = null): void
    {
        if ($run->status !== RunStatus::RUNNING) {
            return;
        }

        $source = $run->source()->first();

        if ($source === null) {
            return;
        }

        $this->finish($run, $source, RunStatus::FAILED, SupplierReason::SUPPLIER_SYNC_INCOMPLETE, $detail, $run->counts ?? [], null);
    }

    /**
     * @return array{0: int, 1: array<string, true>}
     */
    private function validate(FetchedFeed $feed, FeedMapping $mapping): array
    {
        $total = 0;
        $keys = [];

        foreach ($this->reader->items($feed->path, (string) $mapping->recordPath, $mapping->variantPath) as $raw) {
            $total++;
            $key = $mapping->item($raw)->key;

            if ($key !== null) {
                $keys[$key] = ($keys[$key] ?? 0) + 1;
            }
        }

        if ($total === 0) {
            throw new SupplierFeedException(SupplierReason::SUPPLIER_NO_RECORDS, (string) $mapping->recordPath);
        }

        $duplicates = [];

        foreach ($keys as $key => $count) {
            if ($count > 1) {
                $duplicates[(string) $key] = true;
            }
        }

        return [$total, $duplicates];
    }

    /**
     * @param  array<string, true>  $duplicates
     * @return array<string, true> bu turda görülen kimlikler
     */
    private function applyAll(SyncContext $ctx, FetchedFeed $feed, FeedMapping $mapping, array $duplicates): array
    {
        $seen = [];
        $reportedDuplicates = [];

        foreach ($this->reader->items($feed->path, (string) $mapping->recordPath, $mapping->variantPath) as $raw) {
            $item = $mapping->item($raw);

            if ($item->key === null) {
                $ctx->bump('invalid');
                $ctx->change('invalid', null, reason: SupplierReason::SUPPLIER_IDENTITY_MISSING);

                continue;
            }

            $seen[$item->key] = true;

            if (isset($duplicates[$item->key])) {
                if (! isset($reportedDuplicates[$item->key])) {
                    $reportedDuplicates[$item->key] = true;
                    $this->apply->markDuplicate($ctx, $item->key);
                }

                continue;
            }

            try {
                $this->apply->run($ctx, $item);
            } catch (Throwable $e) {
                $ctx->bump('errors');
                $ctx->change('error', $item->key, reason: SupplierReason::SUPPLIER_RECORD_FAILED);
                Log::warning('supplier.record_failed', [
                    'run_id' => $ctx->run->id,
                    'source_key' => $item->key,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $seen;
    }

    /**
     * Yokluk ≠ sıfır: yalnız işaretlenir. Önizlemede yalnız sayılır.
     *
     * @param  array<string, true>  $seen
     */
    private function markMissing(SyncContext $ctx, array $seen): void
    {
        $query = SupplierRecord::query()
            ->where('supplier_source_id', $ctx->source->id)
            ->where('presence', SupplierRecord::PRESENT);

        if ($ctx->preview()) {
            foreach ($query->pluck('source_key') as $key) {
                if (! isset($seen[$key])) {
                    $ctx->bump('missing');
                    $ctx->change('missing', $key, reason: SupplierReason::SUPPLIER_RECORD_MISSING);
                }
            }

            return;
        }

        $missing = (clone $query)
            ->where(fn ($q) => $q->whereNull('last_seen_run_id')->orWhere('last_seen_run_id', '!=', $ctx->run->id))
            ->get(['id', 'source_key', 'variant_id']);

        foreach ($missing as $record) {
            $ctx->bump('missing');
            $ctx->change('missing', $record->source_key, $record->id, $record->variant_id, reason: SupplierReason::SUPPLIER_RECORD_MISSING);
        }

        if ($missing->isNotEmpty()) {
            SupplierRecord::query()->whereIn('id', $missing->pluck('id'))->update([
                'presence' => SupplierRecord::MISSING,
                'reason_code' => SupplierReason::SUPPLIER_RECORD_MISSING->value,
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * @param  array<string, int>  $counts
     */
    private function finish(
        SupplierSyncRun $run,
        SupplierSource $source,
        RunStatus $status,
        ?SupplierReason $reason,
        ?string $detail,
        array $counts,
        ?int $startedAt,
    ): SupplierSyncRun {
        $durationMs = $startedAt !== null ? (int) ((hrtime(true) - $startedAt) / 1_000_000) : null;

        $run->forceFill([
            'status' => $status,
            'reason_code' => $reason?->value,
            'error_detail' => $detail !== null ? mb_substr($detail, 0, 2000) : null,
            'finished_at' => now(),
            'duration_ms' => $durationMs,
            'records_seen' => (int) ($counts['records'] ?? 0),
            'counts' => $counts,
            'peak_memory_bytes' => memory_get_peak_usage(true),
        ])->save();

        $updates = [
            'sync_state' => SupplierSource::STATE_IDLE,
            'running_run_id' => null,
            'running_since' => null,
            'last_status' => $status->value,
            'last_reason_code' => $reason?->value,
            'last_duration_ms' => $durationMs,
            'updated_at' => now(),
        ];

        if ($status === RunStatus::COMPLETE && ! $run->isPreview()) {
            $updates['last_succeeded_at'] = now();
        }

        if ($source->is_active) {
            $updates['next_run_at'] = now()->addMinutes(SupplierSource::INTERVAL_MINUTES);
        }

        // Kilidi yalnız SAHİBİ bırakır: bayat bir iş yeni turun kilidini açmasın.
        SupplierSource::query()
            ->whereKey($source->id)
            ->where('running_run_id', $run->id)
            ->update($updates);

        return $run;
    }

    private function statusFor(SupplierReason $reason): RunStatus
    {
        return match ($reason) {
            SupplierReason::SUPPLIER_FETCH_FAILED,
            SupplierReason::SUPPLIER_URL_BLOCKED,
            SupplierReason::SUPPLIER_FEED_TOO_LARGE => RunStatus::FETCH_FAILED,
            SupplierReason::SUPPLIER_XML_INVALID => RunStatus::PARSE_FAILED,
            default => RunStatus::VALIDATION_FAILED,
        };
    }

    private function warehouseId(): string
    {
        $id = Warehouse::query()->where('is_default', true)->value('id');

        if ($id === null) {
            throw new SupplierFeedException(SupplierReason::SUPPLIER_MAPPING_INVALID, 'Varsayılan depo yok.');
        }

        return (string) $id;
    }
}
