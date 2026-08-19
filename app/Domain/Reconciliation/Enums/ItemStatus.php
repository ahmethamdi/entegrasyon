<?php

declare(strict_types=1);

namespace App\Domain\Reconciliation\Enums;

/**
 * Mutabakat kaleminin sınıflandırması.
 *
 * Mimari Karar Dokümanı v2.2 · §10 · CLASSIFY, §9 · karar tablosu.
 *
 * REMOTE_UNREACHABLE SÜRÜKLENME DEĞİLDİR: API hatası altyapı sorunudur ve
 * fark KANITLANMAMIŞTIR. Onarım açmak, bilinmeyen bir duruma karşı yazmak
 * demektir.
 */
enum ItemStatus: string
{
    /** local == remote — yapılacak bir şey yok. */
    case MATCHED = 'MATCHED';

    /** Fark var, ikisi de mevcut. */
    case DRIFT_DETECTED = 'DRIFT_DETECTED';

    /** Kanalda ürün yok (404 veya yanıtta hiç görünmedi). */
    case REMOTE_MISSING = 'REMOTE_MISSING';

    /** Onarım operasyonu açıldı. */
    case REPAIR_QUEUED = 'REPAIR_QUEUED';

    /** Doğrulama turunda eşleşti — onarım tuttu. */
    case REPAIRED = 'REPAIRED';

    /**
     * Üç tur üst üste sürüklenme — otomatik onarım DURDURULDU (§10).
     *
     * `DRIFT_DETECTED` bırakılsaydı kalem bir sonraki turda yine
     * `drift_detected` sebebiyle aday olur ve panel onu "onarım bekliyor"
     * gibi gösterirdi — oysa hiçbir onarım gelmeyecek. Kullanıcı sonsuza
     * kadar bekleyen bir satıra bakardı.
     */
    case MANUAL_REVIEW = 'MANUAL_REVIEW';

    /** API hatası — sürüklenme DEĞİL, altyapı sorunu. */
    case REMOTE_UNREACHABLE = 'REMOTE_UNREACHABLE';

    /** Bu sınıflandırma sürüklenme sayılır mı — tur sayacını besler. */
    public function isDrift(): bool
    {
        return $this === self::DRIFT_DETECTED
            || $this === self::REPAIR_QUEUED
            || $this === self::MANUAL_REVIEW;
    }
}
