<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Inbox yeniden deneme takvimi — tek kaynak (mimari denetim P0-6).
 *
 * İKİ AYRI BÜTÇE, İKİ AYRI SEBEP:
 *
 *   HATA      Ayrıştırma/DB/deadlock. `attempt_count` sayar, bekleme
 *             10 sn → 1 dk → 5 dk → 15 dk. Önceden bekleme yoktu ve beş deneme
 *             saniyeler içinde biterdi: bir dakikalık DB kesintisi siparişi
 *             kalıcı `failed` yapardı.
 *
 *   ERTELEME  Sipariş henüz yok (iptal/iade siparişten önce geldi). Hata
 *             DEĞİLDİR ve hata bütçesini yemez; süre sınırı mesajın YAŞIDIR.
 *             Pencere, siparişi getirebilecek her yolu kapsayacak kadar
 *             uzundur: yoklama (5 dk), Shopify sipariş mutabakatı (30 dk,
 *             24 sa geriye bakar), webhook yeniden gönderimi (Shopify 48 sa).
 */
final class InboxRetrySchedule
{
    /** Hata sonrası bekleme, saniye — n. denemeden SONRA `[n-1]`. */
    public const ERROR_BACKOFF_SECONDS = [10, 60, 300, 900];

    /** Sipariş bekleyen olay bu yaştan sonra `failed` olur (yine uyandırılabilir). */
    public const DEFER_WINDOW_HOURS = 48;

    /** Ertelemede en kısa / en uzun bekleme. */
    private const DEFER_MIN_SECONDS = 60;

    private const DEFER_MAX_SECONDS = 3600;

    /** `$attempt`. denemeden sonra bir sonraki deneme anı. */
    public static function afterError(int $attempt, ?CarbonInterface $now = null): CarbonInterface
    {
        $index = max(0, min($attempt - 1, count(self::ERROR_BACKOFF_SECONDS) - 1));

        return ($now ?? Carbon::now())->copy()->addSeconds(self::ERROR_BACKOFF_SECONDS[$index]);
    }

    /**
     * Ertelemenin bir sonraki deneme anı — yaşın yarısı, [1 dk, 1 sa] aralığında.
     *
     * Yeni olay sık denenir (sipariş genelde saniyeler/dakikalar içinde
     * gelir), yaşlandıkça seyrekleşir. Sipariş gelirse zaten beklenmez:
     * `WakeDeferredOrderEvents` mesajı hemen uyandırır.
     */
    public static function afterDeferral(CarbonInterface $receivedAt, ?CarbonInterface $now = null): CarbonInterface
    {
        $now ??= Carbon::now();
        $age = max(0, $now->getTimestamp() - $receivedAt->getTimestamp());
        $delay = max(self::DEFER_MIN_SECONDS, min(self::DEFER_MAX_SECONDS, intdiv($age, 2)));

        return $now->copy()->addSeconds($delay);
    }

    /** Erteleme penceresi doldu mu? */
    public static function deferralExpired(CarbonInterface $receivedAt, ?CarbonInterface $now = null): bool
    {
        return $receivedAt->copy()->addHours(self::DEFER_WINDOW_HOURS)->lessThanOrEqualTo($now ?? Carbon::now());
    }
}
