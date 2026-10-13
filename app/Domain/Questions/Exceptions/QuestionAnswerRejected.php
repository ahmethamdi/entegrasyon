<?php

declare(strict_types=1);

namespace App\Domain\Questions\Exceptions;

use App\Domain\Questions\Enums\QuestionReason;
use RuntimeException;

/**
 * Kanal cevabı KESİN olarak reddetti (4xx, 429 hariç): zaten cevaplanmış,
 * süre dolmuş, metin geçersiz. Geçici hatalar HTTP istisnasıdır.
 */
final class QuestionAnswerRejected extends RuntimeException
{
    public function __construct(string $message, public readonly QuestionReason $reason = QuestionReason::QUESTION_ANSWER_REJECTED)
    {
        parent::__construct($message);
    }
}
