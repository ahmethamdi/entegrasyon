<?php

declare(strict_types=1);

namespace App\Domain\Sync\Enums;

/**
 * Senkron operasyonu durumu.
 *
 * Mimari Karar Dokümanı v2.2 · §4 · sync_operations, §8.
 *
 * superseded: daha yeni bir sürüm istendiği için bu operasyon geçersiz kaldı.
 * Silinmez — denetim kaydı olarak durur ve neden gönderilmediği görünür.
 *
 * dead: kalıcı hata; kullanıcı müdahalesi gerekir. Olayın consumed_at alanı
 * DOLU kalır ve olay yeniden yayınlanmaz (§6) — hata operasyon seviyesinde
 * yaşar, panelde görünür ve orada çözülür.
 */
enum SyncOperationStatus: string
{
    case PENDING = 'pending';
    case RETRYING = 'retrying';
    case COMPLETED = 'completed';
    case SUPERSEDED = 'superseded';
    case DEAD = 'dead';

    /** Bu durumdaki operasyon için yeni iş yaratılmaz. */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::COMPLETED, self::SUPERSEDED => true,
            default => false,
        };
    }
}
