<?php

declare(strict_types=1);

namespace App\Domain\Questions\Actions;

use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Questions\Enums\QuestionReason;
use App\Domain\Questions\Enums\QuestionStatus;
use App\Domain\Questions\Models\ChannelQuestion;
use App\Domain\Questions\Support\RemoteQuestion;
use Illuminate\Support\Facades\DB;

/**
 * Kanalın müşteri sorusunu kanonik kayda yazar — idempotent (S4).
 *
 * KİMLİK `(bağlantı, external_question_id)`: aynı soru kaç kez okunursa
 * okunsun tek satır.
 *
 * GÖNDERİLEN CEVABIN SONUCU kanaldan okunur:
 *   kanal bizim cevabımızı REDDETTİ (rejectedAnswer = gönderdiğimiz cevap)
 *     → `rejected` + QUESTION_ANSWER_REJECTED; soru yeniden cevap bekler
 *   kanalda cevap var / onaya düştü → `submitted` (sonuç belli)
 *   belirsiz cevapta soru hâlâ cevapsız → belirsiz KALIR
 */
final class StoreRemoteQuestion
{
    public function run(ChannelConnection $connection, RemoteQuestion $remote): ChannelQuestion
    {
        return DB::transaction(function () use ($connection, $remote): ChannelQuestion {
            $question = ChannelQuestion::query()
                ->where('channel_connection_id', $connection->id)
                ->where('external_question_id', $remote->externalId)
                ->lockForUpdate()
                ->first() ?? new ChannelQuestion([
                    'channel_connection_id' => $connection->id,
                    'external_question_id' => $remote->externalId,
                ]);

            $status = QuestionStatus::interpret($connection->channel_type_code, $remote->channelStatus);

            $question->forceFill([
                'tenant_id' => $connection->tenant_id,
                'channel_type_code' => $connection->channel_type_code,
                'product_ref' => $remote->productRef ?? $question->product_ref,
                'product_name' => $remote->productName ?? $question->product_name,
                'body' => $remote->body,
                'asked_at' => $remote->askedAt ?? $question->asked_at,
                'expires_at' => $remote->expiresAt ?? $question->expires_at,
                'status' => $status->value,
                'channel_status' => $remote->channelStatus,
                'answer_body' => $remote->answerBody,
                'answer_external_id' => $remote->answerExternalId,
                'answered_at' => $remote->answeredAt,
                'rejected_answer_external_id' => $remote->rejectedAnswerExternalId,
                'rejected_answer_reason' => $remote->rejectedAnswerReason,
                'last_seen_at' => now(),
            ]);

            $this->settleAnswer($question, $status, $remote);

            $question->save();

            return $question;
        }, attempts: 3);
    }

    private function settleAnswer(ChannelQuestion $question, QuestionStatus $status, RemoteQuestion $remote): void
    {
        if (! in_array($question->answer_status, [ChannelQuestion::ANSWER_SUBMITTED, ChannelQuestion::ANSWER_UNKNOWN], true)) {
            return;
        }

        $ours = $question->submitted_answer_external_id;

        if ($ours !== null && $remote->rejectedAnswerExternalId === $ours && $remote->answerExternalId !== $ours) {
            $question->forceFill([
                'answer_status' => ChannelQuestion::ANSWER_REJECTED,
                'answer_reason_code' => QuestionReason::QUESTION_ANSWER_REJECTED->value,
                'answer_error' => $remote->rejectedAnswerReason,
                'answer_requested_at' => null,
            ]);

            return;
        }

        if ($remote->answerExternalId !== null || in_array($status, [QuestionStatus::ANSWER_PENDING_APPROVAL, QuestionStatus::ANSWERED], true)) {
            $question->forceFill([
                'answer_status' => ChannelQuestion::ANSWER_SUBMITTED,
                'answer_reason_code' => null,
            ]);
        }
    }
}
