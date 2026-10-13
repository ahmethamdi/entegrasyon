<?php

declare(strict_types=1);

namespace App\Domain\Questions\Enums;

/**
 * Müşteri sorusunun KANONİK durumu — S4. Ham durum `channel_status`'ta.
 *
 * Trendyol (belge "Getting/Answering Customer Questions"):
 *   WAITING_FOR_ANSWER → cevap bekliyor (YALNIZ bu durumda cevaplanabilir)
 *   WAITING_FOR_APPROVE → cevap gönderildi, kanal denetliyor
 *   ANSWERED → cevap yayında
 *   REPORTED → satıcı sorun bildirdi
 *   REJECTED → kapatıldı (belgede iki ayrı tanım var; ikisinde de cevaplanamaz)
 *   UNANSWERED → süre doldu / yasak kelime sınırı, KAPANDI
 */
enum QuestionStatus: string
{
    case AWAITING_ANSWER = 'awaiting_answer';

    /** Cevap kanala ulaştı, yayın onayı bekleniyor. */
    case ANSWER_PENDING_APPROVAL = 'answer_pending_approval';

    case ANSWERED = 'answered';

    case REPORTED = 'reported';

    case REJECTED = 'rejected';

    /** Cevaplanmadan kapandı (süre doldu). */
    case CLOSED = 'closed';

    case UNKNOWN = 'unknown';

    private const MAP = [
        'trendyol' => [
            'WAITING_FOR_ANSWER' => self::AWAITING_ANSWER,
            'WAITING_FOR_APPROVE' => self::ANSWER_PENDING_APPROVAL,
            'ANSWERED' => self::ANSWERED,
            'REPORTED' => self::REPORTED,
            'REJECTED' => self::REJECTED,
            'UNANSWERED' => self::CLOSED,
        ],
    ];

    public static function interpret(string $channel, ?string $raw): self
    {
        return $raw === null ? self::UNKNOWN : (self::MAP[$channel][$raw] ?? self::UNKNOWN);
    }

    public function answerable(): bool
    {
        return $this === self::AWAITING_ANSWER;
    }

    public function label(): string
    {
        return __(match ($this) {
            self::AWAITING_ANSWER => 'Cevap bekliyor',
            self::ANSWER_PENDING_APPROVAL => 'Cevap kanal onayında',
            self::ANSWERED => 'Cevaplandı',
            self::REPORTED => 'Sorun bildirildi',
            self::REJECTED => 'Kapatıldı',
            self::CLOSED => 'Süresi doldu',
            self::UNKNOWN => 'Durum bilinmiyor',
        });
    }
}
