<?php

declare(strict_types=1);

namespace App\Domain\Sync\Enums;

/**
 * Senkron niyeti — operasyon neden açıldı.
 *
 * Mimari Karar Dokümanı v2.2 · §1 · Karar 16, §8 · Sürüm kapısı.
 *
 * DEĞİŞMEZ KURAL:
 *   NORMAL_SYNC → sürüm kapısı UYGULANIR, desired_version ilerletilir
 *   REPAIR      → sürüm kapısı ATLANIR, desired_version ARTIRILMAZ
 *
 * Onarımda kapı atlanır çünkü mutabakat uzak durumu okumuş ve gerçek farkı
 * kanıtlamıştır; "bu sürüm zaten gönderildi" bilgisi orada yanlıştır.
 * Onarım sürümü artırmaz: yapay sürüm artışı sıra dışı olay elemesini bozar
 * ve gerçek bir değişikliği bayat gösterir.
 */
enum SyncIntent: string
{
    case NORMAL_SYNC = 'NORMAL_SYNC';
    case REPAIR = 'REPAIR';

    /** Sürüm kapısı bu niyette uygulanır mı? */
    public function appliesVersionGate(): bool
    {
        return $this === self::NORMAL_SYNC;
    }
}
