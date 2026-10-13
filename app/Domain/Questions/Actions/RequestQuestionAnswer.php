<?php

declare(strict_types=1);

namespace App\Domain\Questions\Actions;

use App\Domain\Channels\Contracts\SupportsQuestions;
use App\Domain\Channels\Enums\OutboundWrite;
use App\Domain\Channels\Registry\AdapterRegistry;
use App\Domain\Channels\Support\OutboundWriteGate;
use App\Domain\Questions\Enums\QuestionReason;
use App\Domain\Questions\Enums\QuestionStatus;
use App\Domain\Questions\Jobs\SubmitQuestionAnswer;
use App\Domain\Questions\Models\ChannelQuestion;
use App\Support\Tenancy\TenantContext;

/**
 * Panelden gelen cevabı DOĞRULAR ve kuyruğa alır — S4. Ağ isteği yapmaz.
 *
 * Soru kiracı scope'u altında bulunur (çağıran controller): başka kiracının
 * ya da olmayan sorunun cevabı aynı 404'tür.
 *
 * KUYRUĞA ALMAK CEVAPLAMAK DEĞİLDİR: satır `pending` olur; `submitted` ancak
 * kanal isteği kabul edince, "cevaplandı" ancak kanal öyle söyleyince.
 */
final class RequestQuestionAnswer
{
    public const MIN_LENGTH = 10;

    public const MAX_LENGTH = 2000;

    public function __construct(
        private readonly AdapterRegistry $adapters,
        private readonly OutboundWriteGate $writes,
    ) {}

    /** @return QuestionReason|null null = kuyruğa alındı */
    public function run(ChannelQuestion $question, string $text, ?string $userId): ?QuestionReason
    {
        $connection = $question->connection;

        if ($connection === null || $connection->status !== 'active') {
            return QuestionReason::QUESTION_CHANNEL_UNAVAILABLE;
        }

        if (! $this->adapters->for($connection) instanceof SupportsQuestions) {
            return QuestionReason::QUESTION_ACTION_UNSUPPORTED;
        }

        if (! $this->writes->allows($connection->id, $question->tenant_id, OutboundWrite::QUESTION_ANSWER)) {
            return QuestionReason::QUESTION_ACTION_DISABLED;
        }

        $text = trim($text);
        $length = mb_strlen($text);

        if ($length < self::MIN_LENGTH || $length > self::MAX_LENGTH) {
            return QuestionReason::QUESTION_ANSWER_INVALID;
        }

        if (! $question->statusEnum()->answerable()) {
            return $question->statusEnum() === QuestionStatus::ANSWERED || $question->statusEnum() === QuestionStatus::ANSWER_PENDING_APPROVAL
                ? QuestionReason::QUESTION_ALREADY_ANSWERED
                : QuestionReason::QUESTION_NOT_ANSWERABLE;
        }

        $now = now();

        // Sonucu belirsiz cevap yalnız satıcının AÇIK tekrarıyla yeniden
        // denenir; iş göndermeden önce soruyu kanaldan okur.
        $claimed = ChannelQuestion::query()
            ->whereKey($question->id)
            ->where('status', QuestionStatus::AWAITING_ANSWER->value)
            ->where(fn ($q) => $q->whereNull('answer_status')
                ->orWhereIn('answer_status', [ChannelQuestion::ANSWER_FAILED, ChannelQuestion::ANSWER_REJECTED, ChannelQuestion::ANSWER_UNKNOWN]))
            ->update([
                'answer_status' => ChannelQuestion::ANSWER_PENDING,
                'answer_reason_code' => null,
                'answer_error' => null,
                'submitted_answer' => $text,
                'submitted_answer_external_id' => null,
                'answer_requested_at' => $now,
                'answered_by' => $userId,
                'updated_at' => $now,
            ]);

        if ($claimed === 0) {
            return QuestionReason::QUESTION_ALREADY_ANSWERED;
        }

        SubmitQuestionAnswer::dispatch($question->id, TenantContext::idOrFail());

        return null;
    }
}
