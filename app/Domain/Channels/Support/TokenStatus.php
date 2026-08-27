<?php

declare(strict_types=1);

namespace App\Domain\Channels\Support;

use App\Support\Observability\CaptureMetrics;
use DateTimeInterface;

/**
 * Bağlantının token ömrü — `/channels` rozeti (V3.0 · §25).
 *
 * §25'in üç hâli:
 *   🟢 Geçerli   🟡 14 gün içinde dolacak   🔴 Yeniden yetkilendirme gerekli
 *
 * ─────────────────────────────────────────────────────────────────────
 * NEDEN `status` KOLONU BU SORUYU CEVAPLAMAZ
 * ─────────────────────────────────────────────────────────────────────
 * `channel_connections.status` kanalın SON cevabını taşır: sağlık
 * kontrolü geçtiyse `active`. Token ömrü BAŞKA bir sorudur — bugün
 * çalışan bir bağlantının token'ı yarın ölebilir ve o an hiçbir kolon
 * değişmez. İkisi tek alanda birleştirilseydi ya bugün çalışan bağlantı
 * "bozuk" gösterilir ya da ölmek üzere olan token hiç görünmezdi.
 *
 * ─────────────────────────────────────────────────────────────────────
 * ⚠️ PENCERE `CaptureMetrics`'TEN OKUNUR, BURADA YENİDEN YAZILMAZ
 * ─────────────────────────────────────────────────────────────────────
 * Metrik ve rozet AYNI eşiği kullanmak ZORUNDADIR. Ayrışsalardı panel
 * sarı yanarken metrik susar (ya da tersi) olur ve satıcı iki farklı
 * gerçek görürdü — eşiğin `Metric::threshold()` içinde tek kaynak olması
 * kuralının rozet karşılığı.
 */
enum TokenStatus: string
{
    /** Süresi dolmuş — satıcı YENİDEN YETKİLENDİRMELİ. */
    case EXPIRED = 'expired';

    /** Pencere içinde dolacak — satıcı hazırlıklı olmalı. */
    case EXPIRING = 'expiring';

    /** Ömrü var ve uzak. */
    case VALID = 'valid';

    /**
     * Kimlik bilgisinin son kullanma tarihinden rozet durumu.
     *
     * ⚠️ `null` DÖNMEK BİR CEVAPTIR: "bu bağlantıda token ömrü diye bir
     * kavram YOK". Woo/Trendyol kalıcı anahtar taşır ve Shopify'ın
     * offline token'ı SÜRESİZDİR; ayrıca OAuth turunu henüz
     * tamamlamamış bir bağlantının kimlik bilgisi HİÇ yoktur.
     *
     * Bu hâllerde "🟢 Geçerli" yazılsaydı satıcı orada izlenecek bir
     * ömür olduğunu sanır ve rozetin bir gün sarıya dönmesini beklerdi;
     * "🔴 dolmuş" yazılsaydı hiç kurmadığı bir şeyi YENİDEN
     * yetkilendirmeye çağrılırdı. Yokluk, yanlış bir güvenceden iyidir.
     */
    public static function forExpiry(?DateTimeInterface $expiresAt): ?self
    {
        if ($expiresAt === null) {
            return null;
        }

        $secondsLeft = $expiresAt->getTimestamp() - time();

        if ($secondsLeft <= 0) {
            return self::EXPIRED;
        }

        $window = CaptureMetrics::TOKEN_EXPIRY_WINDOW_DAYS * 86_400;

        return $secondsLeft <= $window ? self::EXPIRING : self::VALID;
    }

    /** Satıcıya gösterilen metin — ne yapması gerektiğini SÖYLER. */
    public function label(): string
    {
        return match ($this) {
            self::EXPIRED => 'Yeniden yetkilendirme gerekli',
            self::EXPIRING => 'Yakında dolacak',
            self::VALID => 'Yetki geçerli',
        };
    }
}
