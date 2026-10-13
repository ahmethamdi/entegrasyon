<?php

declare(strict_types=1);

namespace App\Domain\Questions\Enums;

/** Soru-cevap akışının KARARLI sebep kodları — S4. */
enum QuestionReason: string
{
    /** Bağlantıda "Soru cevabı" izni kapalı (ADR-010). */
    case QUESTION_ACTION_DISABLED = 'QUESTION_ACTION_DISABLED';

    /** Kanal soru cevaplamayı desteklemiyor ya da canlı doğrulanmadı. */
    case QUESTION_ACTION_UNSUPPORTED = 'QUESTION_ACTION_UNSUPPORTED';

    /** Soru zaten cevaplandı ya da cevap yolda. */
    case QUESTION_ALREADY_ANSWERED = 'QUESTION_ALREADY_ANSWERED';

    /** Sorunun kanaldaki durumu cevaba izin vermiyor (kapandı, bildirildi). */
    case QUESTION_NOT_ANSWERABLE = 'QUESTION_NOT_ANSWERABLE';

    /** Cevap kanala ulaşmış olabilir, sonucu bilinmiyor; kör tekrar yapılmaz. */
    case QUESTION_REMOTE_STATE_UNKNOWN = 'QUESTION_REMOTE_STATE_UNKNOWN';

    /** Kanal cevabı reddetti (ör. yasak kelime). */
    case QUESTION_ANSWER_REJECTED = 'QUESTION_ANSWER_REJECTED';

    /** Cevap metni kanal kuralına uymuyor (uzunluk). */
    case QUESTION_ANSWER_INVALID = 'QUESTION_ANSWER_INVALID';

    /** Cevap gönderilemedi (tekrar denemeler tükendi). */
    case QUESTION_ANSWER_FAILED = 'QUESTION_ANSWER_FAILED';

    /** Kanal bağlantısı aktif değil ya da devre kesici açık. */
    case QUESTION_CHANNEL_UNAVAILABLE = 'QUESTION_CHANNEL_UNAVAILABLE';

    public function label(): string
    {
        return __(match ($this) {
            self::QUESTION_ACTION_DISABLED => 'Soru cevabı izni kapalı',
            self::QUESTION_ACTION_UNSUPPORTED => 'Bu kanalda soru cevaplama desteklenmiyor',
            self::QUESTION_ALREADY_ANSWERED => 'Soru zaten cevaplandı',
            self::QUESTION_NOT_ANSWERABLE => 'Bu soru artık cevaplanamaz',
            self::QUESTION_REMOTE_STATE_UNKNOWN => 'Cevabın kanala ulaşıp ulaşmadığı bilinmiyor',
            self::QUESTION_ANSWER_REJECTED => 'Kanal cevabı reddetti',
            self::QUESTION_ANSWER_INVALID => 'Cevap 10–2000 karakter olmalı',
            self::QUESTION_ANSWER_FAILED => 'Cevap gönderilemedi',
            self::QUESTION_CHANNEL_UNAVAILABLE => 'Kanal bağlantısı kullanılamıyor',
        });
    }
}
