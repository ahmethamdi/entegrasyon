<?php

declare(strict_types=1);

namespace App\Domain\Questions\Actions;

use App\Domain\Channels\Contracts\SupportsQuestions;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Registry\AdapterRegistry;
use App\Domain\Channels\Support\ChannelRateLimiter;
use App\Domain\Channels\Support\CircuitBreaker;
use App\Domain\Channels\Support\ConnectionSettingsWriter;
use App\Domain\Questions\Enums\QuestionStatus;
use App\Domain\Questions\Models\ChannelQuestion;
use App\Domain\Sync\Support\AdapterReportedFailure;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Müşteri sorularını yoklar ve açık soruları uzlaştırır — S4.
 *
 * KEŞİF: soru tarihine göre pencereler (Trendyol en çok 14 gün); imleç
 * YALNIZ pencere tüm sayfalarıyla bitince ilerler. HTTP hatası "soru yok"
 * SAYILMAZ.
 *
 * UZLAŞTIRMA: penceresi geçmiş ama durumu değişebilecek sorular (cevap
 * bekleyen, onayda, cevabı gönderilmiş ya da sonucu belirsiz) tek tek
 * yeniden okunur — kanalda verilen cevap yerel ekranda "cevapsız" kalmaz.
 * Uzlaştırma DIŞARI YAZMAZ.
 */
final class IngestQuestions
{
    public const CURSOR_KEY = 'questions_ingested_through';

    public const INITIAL_LOOKBACK_DAYS = 28;

    public const OVERLAP_DAYS = 1;

    public const MAX_WINDOWS = 6;

    public const MAX_PAGES = 40;

    public const MAX_REFRESH = 50;

    public const REFRESH_AFTER_MINUTES = 10;

    public function __construct(
        private readonly AdapterRegistry $adapters,
        private readonly CircuitBreaker $breaker,
        private readonly ChannelRateLimiter $limiter,
        private readonly StoreRemoteQuestion $store = new StoreRemoteQuestion,
    ) {}

    /** @return array{connections: int, stored: int, refreshed: int, failed: int} */
    public function run(): array
    {
        $connections = TenantContext::runAsSystem(fn () => ChannelConnection::query()
            ->where('status', 'active')
            ->with('channelType:code,name,adapter_class,supports_webhooks')
            ->get());

        $summary = ['connections' => 0, 'stored' => 0, 'refreshed' => 0, 'failed' => 0];

        foreach ($connections as $connection) {
            try {
                $result = $this->forConnection($connection);

                if ($result !== null) {
                    $summary['connections']++;
                    $summary['stored'] += $result['stored'];
                    $summary['refreshed'] += $result['refreshed'];
                }
            } catch (Throwable $e) {
                $summary['failed']++;

                Log::warning('questions.ingest_failed', [
                    'connection' => $connection->id,
                    'channel' => $connection->channel_type_code,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $summary;
    }

    /** @return array{stored: int, refreshed: int, windows: int, complete: bool}|null */
    public function forConnection(ChannelConnection $connection, ?CarbonImmutable $now = null): ?array
    {
        $adapter = TenantContext::runAsSystem(fn () => $this->adapters->for($connection));

        if (! $adapter instanceof SupportsQuestions) {
            return null;
        }

        $counts = ['stored' => 0, 'refreshed' => 0, 'windows' => 0, 'complete' => false];

        if (! $this->breaker->allows($connection->id)) {
            return $counts;
        }

        $now ??= CarbonImmutable::now();
        $from = $this->windowStart($connection, $now);

        try {
            while ($from->lessThan($now) && $counts['windows'] < self::MAX_WINDOWS) {
                $to = $from->addDays($adapter->questionWindowDays())->subMillisecond();
                $to = $to->greaterThan($now) ? $now : $to;
                $page = 0;

                do {
                    if (! $this->limiter->attempt($connection->id, $adapter->rateLimitProfile())) {
                        return $counts;
                    }

                    $result = TenantContext::runFor($connection->tenant_id, fn () => $adapter->fetchQuestionPage($from, $to, $page));

                    foreach ($result->questions as $remote) {
                        TenantContext::runFor($connection->tenant_id, fn () => $this->store->run($connection, $remote));
                        $counts['stored']++;
                    }

                    $page++;
                } while ($result->hasMore && $page < self::MAX_PAGES);

                if ($result->hasMore) {
                    Log::warning('questions.window_page_limit', ['connection' => $connection->id]);

                    return $counts;
                }

                ConnectionSettingsWriter::merge($connection, [self::CURSOR_KEY => $to->toIso8601String()]);
                $counts['windows']++;
                $from = $to->addMillisecond();
            }

            $counts['complete'] = true;
            $counts['refreshed'] = TenantContext::runFor($connection->tenant_id, fn () => $this->refreshOpen($connection, $adapter));
            $this->breaker->recordSuccess($connection->id);
        } catch (Throwable $e) {
            $this->breaker->recordFailure($connection->id, AdapterReportedFailure::classify($e, $adapter));

            throw $e;
        }

        return $counts;
    }

    private function windowStart(ChannelConnection $connection, CarbonImmutable $now): CarbonImmutable
    {
        $cursor = $connection->fresh()?->settings[self::CURSOR_KEY] ?? null;

        if (is_string($cursor) && $cursor !== '') {
            return CarbonImmutable::parse($cursor)->subDays(self::OVERLAP_DAYS);
        }

        return $now->subDays(self::INITIAL_LOOKBACK_DAYS);
    }

    private function refreshOpen(ChannelConnection $connection, SupportsQuestions $adapter): int
    {
        $open = ChannelQuestion::query()
            ->where('channel_connection_id', $connection->id)
            ->where(fn ($q) => $q
                ->whereIn('status', [QuestionStatus::AWAITING_ANSWER->value, QuestionStatus::ANSWER_PENDING_APPROVAL->value, QuestionStatus::UNKNOWN->value])
                ->orWhereIn('answer_status', [ChannelQuestion::ANSWER_SUBMITTED, ChannelQuestion::ANSWER_UNKNOWN]))
            ->where(fn ($q) => $q->whereNull('last_seen_at')->orWhere('last_seen_at', '<', now()->subMinutes(self::REFRESH_AFTER_MINUTES)))
            ->orderBy('last_seen_at')
            ->limit(self::MAX_REFRESH)
            ->get();

        $refreshed = 0;

        foreach ($open as $question) {
            if (! $this->limiter->attempt($connection->id, $adapter->rateLimitProfile())) {
                break;
            }

            $remote = $adapter->fetchQuestion($question->external_question_id);

            if ($remote !== null) {
                $this->store->run($connection, $remote);
                $refreshed++;
            }
        }

        return $refreshed;
    }
}
