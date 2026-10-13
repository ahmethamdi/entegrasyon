<?php

declare(strict_types=1);

namespace App\Domain\Questions\Jobs;

use App\Domain\Channels\Contracts\SupportsQuestions;
use App\Domain\Channels\Enums\OutboundWrite;
use App\Domain\Channels\Registry\AdapterRegistry;
use App\Domain\Channels\Support\ChannelRateLimiter;
use App\Domain\Channels\Support\CircuitBreaker;
use App\Domain\Channels\Support\OutboundWriteGate;
use App\Domain\Questions\Actions\StoreRemoteQuestion;
use App\Domain\Questions\Enums\QuestionReason;
use App\Domain\Questions\Exceptions\QuestionAnswerRejected;
use App\Domain\Questions\Models\ChannelQuestion;
use App\Domain\Sync\Support\AdapterReportedFailure;
use App\Domain\Sync\Support\ChannelErrorText;
use App\Domain\Sync\Support\RetryPolicy;
use App\Support\Tenancy\TenantContext;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Müşteri sorusunun cevabını kanala gönderir — S4.
 *
 * SIRA: izin (TAZE) → bağlantı → devre kesici → hız sınırı → sorunun
 * GÜNCEL hâli (kanalda zaten cevaplanmış/kapanmışsa HİÇBİR ŞEY gitmez) →
 * `pending → remote_state_unknown` işareti → POST.
 *
 * Başarı `submitted` demektir, "cevaplandı" değil: Trendyol cevabı yasak
 * kelime denetiminden geçirir ve reddedebilir; sonuç yoklamada okunur.
 * Belirsiz hata (ağ, 5xx): soru yeniden okunur; kanal cevabı gördüyse
 * `submitted`, görmediyse `remote_state_unknown` — kör tekrar YOK.
 */
final class SubmitQuestionAnswer implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $questionId,
        public readonly string $tenantId,
    ) {}

    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours(6);
    }

    public function handle(
        AdapterRegistry $registry,
        OutboundWriteGate $writes,
        ChannelErrorText $errorText,
        ?CircuitBreaker $breaker = null,
        ?ChannelRateLimiter $limiter = null,
    ): void {
        TenantContext::set($this->tenantId);

        try {
            $this->submit($registry, $writes, $errorText, $breaker ?? app(CircuitBreaker::class), $limiter ?? app(ChannelRateLimiter::class));
        } finally {
            TenantContext::clear();
        }
    }

    public function failed(?Throwable $e): void
    {
        TenantContext::set($this->tenantId);

        try {
            ChannelQuestion::query()
                ->whereKey($this->questionId)
                ->where('answer_status', ChannelQuestion::ANSWER_PENDING)
                ->update([
                    'answer_status' => ChannelQuestion::ANSWER_FAILED,
                    'answer_reason_code' => QuestionReason::QUESTION_ANSWER_FAILED->value,
                    'answer_error' => mb_substr($e?->getMessage() ?? '', 0, 500),
                    'answer_requested_at' => null,
                    'updated_at' => now(),
                ]);
        } finally {
            TenantContext::clear();
        }
    }

    private function submit(AdapterRegistry $registry, OutboundWriteGate $writes, ChannelErrorText $errorText, CircuitBreaker $breaker, ChannelRateLimiter $limiter): void
    {
        $question = ChannelQuestion::query()->with('connection')->find($this->questionId);

        if ($question === null || $question->answer_status !== ChannelQuestion::ANSWER_PENDING) {
            return;
        }

        if (! $writes->allows($question->channel_connection_id, $this->tenantId, OutboundWrite::QUESTION_ANSWER)) {
            $this->fail($question, QuestionReason::QUESTION_ACTION_DISABLED);

            return;
        }

        $connection = $question->connection;

        if ($connection === null || $connection->status !== 'active') {
            $this->fail($question, QuestionReason::QUESTION_CHANNEL_UNAVAILABLE);

            return;
        }

        if (! $breaker->allows($connection->id)) {
            $this->release(CircuitBreaker::PAUSE_SECONDS);

            return;
        }

        $adapter = $registry->for($connection);

        if (! $adapter instanceof SupportsQuestions) {
            $this->fail($question, QuestionReason::QUESTION_ACTION_UNSUPPORTED);

            return;
        }

        if (! $limiter->attempt($connection->id, $adapter->rateLimitProfile())) {
            $this->release(max($limiter->secondsUntilAvailable($connection->id, $adapter->rateLimitProfile()), 1));

            return;
        }

        try {
            $remote = $adapter->fetchQuestion($question->external_question_id);
        } catch (Throwable $e) {
            $class = AdapterReportedFailure::classify($e, $adapter);
            $breaker->recordFailure($connection->id, $class);
            $delay = RetryPolicy::delayFor($class, $this->attempts(), AdapterReportedFailure::retryAfterOf($e));

            if ($delay !== null) {
                $this->release($delay);

                return;
            }

            $this->fail($question, QuestionReason::QUESTION_ANSWER_FAILED, $errorText->redact($connection, $e->getMessage()));

            return;
        }

        if ($remote === null) {
            $this->fail($question, QuestionReason::QUESTION_NOT_ANSWERABLE);

            return;
        }

        $question = app(StoreRemoteQuestion::class)->run($connection, $remote);

        if (! $question->statusEnum()->answerable() || $remote->answerExternalId !== null) {
            $this->fail($question, $remote->answerExternalId !== null ? QuestionReason::QUESTION_ALREADY_ANSWERED : QuestionReason::QUESTION_NOT_ANSWERABLE);

            return;
        }

        // "Gitmiş olabilir" işareti İSTEKTEN ÖNCE.
        $claimed = ChannelQuestion::query()
            ->whereKey($question->id)
            ->where('answer_status', ChannelQuestion::ANSWER_PENDING)
            ->update(['answer_status' => ChannelQuestion::ANSWER_UNKNOWN, 'updated_at' => now()]);

        if ($claimed !== 1) {
            return;
        }

        try {
            $answerId = $adapter->answerQuestion($question->external_question_id, (string) $question->submitted_answer);
        } catch (QuestionAnswerRejected $e) {
            $question->forceFill([
                'answer_status' => ChannelQuestion::ANSWER_FAILED,
                'answer_reason_code' => $e->reason->value,
                'answer_error' => mb_substr((string) $errorText->redact($connection, $e->getMessage()), 0, 500),
                'answer_requested_at' => null,
            ])->save();

            return;
        } catch (RequestException $e) {
            if ($e->response->status() === 429) {
                $question->forceFill(['answer_status' => ChannelQuestion::ANSWER_PENDING])->save();
                $this->release(max((int) $e->response->header('Retry-After'), 30));

                return;
            }

            $this->settleAmbiguous($adapter, $connection, $question, $errorText->redact($connection, $e->getMessage()));

            return;
        } catch (Throwable $e) {
            $breaker->recordFailure($connection->id, AdapterReportedFailure::classify($e, $adapter));
            $this->settleAmbiguous($adapter, $connection, $question, $errorText->redact($connection, $e->getMessage()));

            return;
        }

        $breaker->recordSuccess($connection->id);
        $question->forceFill([
            'answer_status' => ChannelQuestion::ANSWER_SUBMITTED,
            'answer_reason_code' => null,
            'answer_error' => null,
            'submitted_answer_external_id' => $answerId,
        ])->save();
    }

    private function settleAmbiguous(SupportsQuestions $adapter, $connection, ChannelQuestion $question, ?string $detail): void
    {
        try {
            if (($remote = $adapter->fetchQuestion($question->external_question_id)) !== null) {
                app(StoreRemoteQuestion::class)->run($connection, $remote);
            }
        } catch (Throwable) {
            // Okunamadı: belirsiz kalır.
        }

        $question->refresh();

        if ($question->answer_status === ChannelQuestion::ANSWER_UNKNOWN) {
            $question->forceFill([
                'answer_reason_code' => QuestionReason::QUESTION_REMOTE_STATE_UNKNOWN->value,
                'answer_error' => $detail === null ? null : mb_substr($detail, 0, 500),
            ])->save();
        }
    }

    private function fail(ChannelQuestion $question, QuestionReason $reason, ?string $detail = null): void
    {
        $question->forceFill([
            'answer_status' => ChannelQuestion::ANSWER_FAILED,
            'answer_reason_code' => $reason->value,
            'answer_error' => $detail === null ? null : mb_substr($detail, 0, 500),
            'answer_requested_at' => null,
        ])->save();
    }
}
