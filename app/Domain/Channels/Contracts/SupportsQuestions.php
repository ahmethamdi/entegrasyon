<?php

declare(strict_types=1);

namespace App\Domain\Channels\Contracts;

use App\Domain\Questions\Exceptions\QuestionAnswerRejected;
use App\Domain\Questions\Support\RemoteQuestion;
use App\Domain\Questions\Support\RemoteQuestionPage;
use Carbon\CarbonInterface;

/**
 * Kanalın müşteri sorularını okuma ve cevaplama — S4.
 *
 * Okuma izin gerektirmez; `answerQuestion` DIŞARI YAZMADIR ve çağıran
 * `OutboundWrite::QUESTION_ANSWER` kapısından geçer.
 */
interface SupportsQuestions
{
    /** Tek istekte sorulabilecek en uzun aralık (gün). */
    public function questionWindowDays(): int;

    /** Aralıkta SORULAN soruların bir sayfası (sayfa 0'dan). */
    public function fetchQuestionPage(CarbonInterface $from, CarbonInterface $to, int $page): RemoteQuestionPage;

    /** Tek sorunun GÜNCEL hâli; yoksa null. */
    public function fetchQuestion(string $externalQuestionId): ?RemoteQuestion;

    /**
     * Cevabı gönderir; kanalın cevap kimliğini döndürür (yoksa null).
     *
     * @throws QuestionAnswerRejected kesin ret (zaten cevaplanmış, süre doldu, metin geçersiz)
     */
    public function answerQuestion(string $externalQuestionId, string $text): ?string;
}
