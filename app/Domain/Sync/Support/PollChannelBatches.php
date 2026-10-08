<?php

declare(strict_types=1);

namespace App\Domain\Sync\Support;

use App\Domain\Sync\Actions\ResolveChannelBatch;
use App\Domain\Sync\Models\ChannelBatch;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Bekleyen kanal toplu işlerinin yoklama turu.
 *
 * `TrackApprovalForConnections`'ın deseni: MANTIK BURADA, komut ince kabuk.
 *
 * DEĞİŞMEZ KURAL — TUR KİRACI BAĞLAMINI KENDİ KURAR:
 *   Aday listesi `runAsSystem` ile tüm kiracılardan okunur, ama her iş
 *   KENDİ kiracısının bağlamında işlenir: listing ve sync state sorguları
 *   kiracı scope'una tabidir; sistem bağlamında işlenseydi bir kiracının
 *   hükmü diğerinin satırına yazılabilirdi.
 *
 * DEĞİŞMEZ KURAL — BİR İŞİN HATASI TURU DURDURMAZ:
 *   Hata günlüğe yazılır, tur devam eder.
 *
 * AYNI İŞ EN ÇOK DAKİKADA BİR: Çiçeksepeti `batch-status`'u aynı iş için
 * dakikada bir kabul ediyor (API notları §5). Tur beş dakikalık olduğu için
 * normalde zaten aşılmaz; elle çalıştırılan tur da bu kapıdan geçer.
 *
 * TUR BAŞINA ÜST SINIR: birikim tek turda erimezse kalan bir sonraki turda
 * gider; en uzun süredir bakılmayan önce gelir (`last_polled_at NULLS
 * FIRST`) ve hiçbir iş aç kalmaz.
 */
final class PollChannelBatches
{
    public const MIN_POLL_INTERVAL_SECONDS = 60;

    public const MAX_PER_RUN = 200;

    public function __construct(
        private readonly ResolveChannelBatch $resolve,
    ) {}

    /** @return array<string, int> Sonuç türü → adet */
    public function sweep(): array
    {
        $due = TenantContext::runAsSystem(
            fn () => ChannelBatch::query()
                ->where('status', ChannelBatch::STATUS_PENDING)
                ->where(fn ($q) => $q
                    ->whereNull('last_polled_at')
                    ->orWhere('last_polled_at', '<=', now()->subSeconds(self::MIN_POLL_INTERVAL_SECONDS)))
                ->orderByRaw('last_polled_at NULLS FIRST')
                ->orderBy('created_at')
                ->limit(self::MAX_PER_RUN)
                ->get(['id', 'tenant_id']),
        );

        $counts = [
            ResolveChannelBatch::RESULT_COMPLETED => 0,
            ResolveChannelBatch::RESULT_PENDING => 0,
            ResolveChannelBatch::RESULT_EXPIRED => 0,
            ResolveChannelBatch::RESULT_SKIPPED => 0,
            ResolveChannelBatch::RESULT_ERROR => 0,
        ];

        foreach ($due as $row) {
            try {
                $result = TenantContext::runFor($row->tenant_id, function () use ($row): string {
                    // Bağlam altında YENİDEN okunur: yukarıdaki sorgu iki kolon seçti.
                    $batch = ChannelBatch::query()->find($row->id);

                    return $batch === null
                        ? ResolveChannelBatch::RESULT_SKIPPED
                        : $this->resolve->run($batch);
                });
            } catch (Throwable $e) {
                $result = ResolveChannelBatch::RESULT_ERROR;

                Log::warning('batch.poll_failed', [
                    'batch' => $row->id,
                    'error' => mb_substr($e->getMessage(), 0, 500),
                ]);
            }

            $counts[$result] = ($counts[$result] ?? 0) + 1;
        }

        return $counts;
    }
}
