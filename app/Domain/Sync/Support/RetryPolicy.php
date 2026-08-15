<?php

declare(strict_types=1);

namespace App\Domain\Sync\Support;

use App\Domain\Sync\Enums\ErrorClass;

/**
 * Hata sınıfına göre yeniden deneme gecikmesi.
 *
 * Mimari Karar Dokümanı v2.2 · §12 · Yeniden deneme politikaları.
 *
 * null DÖNMESİ "yeniden deneme" demektir — operasyon kalıcı hataya düşer veya
 * mutabakata devredilir. Tek bir politika ya israf eder (kalıcı hatayı sonsuz
 * dener) ya erken pes eder (geçici kesintide vazgeçer); bu yüzden sınıf bazlı.
 *
 * DEĞİŞMEZ KURAL — YENİDEN DENEME YENİ OPERASYON YARATMAZ:
 *   Aynı operasyon aynı entity_version ile tekrar denenir. Stok MUTLAK değer
 *   olarak gönderildiği için tekrar zararsızdır. Yeni sürüm oluştuysa eski
 *   operasyon zaten superseded olur ve yeniden deneme durur.
 */
final class RetryPolicy
{
    /** Üstel geri çekilmenin tabanı: ~5, 15, 45, 135, 405 sn. */
    private const BASE_SECONDS = 5;

    private const GROWTH_FACTOR = 3;

    /** Bu sayıdan sonra geçici hata da bırakılır. */
    public const MAX_ATTEMPTS = 5;

    /**
     * Bir sonraki denemeye kaç saniye kalsın?
     *
     * @param  int  $attempt  Kaçıncı deneme yapıldı (1'den başlar)
     * @param  int|null  $retryAfter  Kanalın Retry-After başlığı, varsa
     * @return int|null null = yeniden DENENMEZ
     */
    public static function delayFor(ErrorClass $class, int $attempt, ?int $retryAfter = null): ?int
    {
        // Kalıcı hata hiçbir denemede yeniden denenmez.
        if ($class->isPermanent()) {
            return null;
        }

        // Geçici hata da sonsuza kadar denenmez; bütçe tükenir.
        if ($attempt >= self::MAX_ATTEMPTS) {
            return null;
        }

        return match ($class) {
            // Kanalın söylediği süreye uyulur; yoksa 60 sn.
            // Ayrıca çağıran kanal kuyruğunu duraklatır (§12 · devre kesici).
            ErrorClass::RATE_LIMITED => $retryAfter ?? 60,

            // Üstel + jitter. JITTER ZORUNLU: bir kanal kesintiye girdiğinde
            // yüzlerce iş aynı anda başarısız olur; jitter olmadan hepsi aynı
            // saniyede yeniden dener ve kanal ayağa kalktığı anda tekrar çöker.
            ErrorClass::SERVER_ERROR,
            ErrorClass::TIMEOUT,
            ErrorClass::NETWORK => (int) round(
                self::BASE_SECONDS * self::GROWTH_FACTOR ** ($attempt - 1) * self::jitter()
            ),

            // Çakışma: bir kez, kısa bekleme. İkinci denemede uzak durum
            // okunur — körlemesine tekrar çakışmayı çözmez.
            ErrorClass::CONFLICT => $attempt === 1 ? 10 : null,

            // Mutabakat devralır: uzak durumu okuyup gerçek farkı kanıtlar.
            ErrorClass::NOT_FOUND => null,

            // isPermanent() yukarıda elendi; match tamlığı için.
            ErrorClass::VALIDATION,
            ErrorClass::AUTHENTICATION => null,
        };
    }

    public static function shouldRetry(ErrorClass $class, int $attempt, ?int $retryAfter = null): bool
    {
        return self::delayFor($class, $attempt, $retryAfter) !== null;
    }

    /** ±%20 rastgelelik — eşzamanlı yeniden denemeleri dağıtır. */
    private static function jitter(): float
    {
        return 0.8 + (random_int(0, 400) / 1000);
    }
}
