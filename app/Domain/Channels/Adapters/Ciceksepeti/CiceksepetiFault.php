<?php

declare(strict_types=1);

namespace App\Domain\Channels\Adapters\Ciceksepeti;

use App\Domain\Channels\Contracts\CarriesRetryAfter;
use App\Domain\Sync\Enums\ErrorClass;
use RuntimeException;

/**
 * Çiçeksepeti adapter'ının kendi sınıflandırdığı hata.
 *
 * İki yerde doğar:
 *   - ERİŞİM ENGELİ: API yurt dışı IP'ye kimlik kontrolüne hiç ulaşmadan
 *     HTML 403 döner (8 Eki 2026 ölçümü, `docs/YENI-KANALLAR-API-NOTLARI.md`
 *     §5 · Tuzaklar 1). Ham `RequestException` mesajı "HTTP 403" der ve
 *     satıcı anahtarını yeniden girer — hiçbiri işe yaramaz, çünkü sorun
 *     anahtar değil sunucunun IP'sidir. Mesaj bunu açıkça söyler.
 *   - KANALA HİÇ GİTMEYEN İSTEK: "aynı gövde 30 dk'da 1" kuralına takılan
 *     stok/fiyat yükü; çekirdek kalan süre kadar sonra yeniden dener.
 */
final class CiceksepetiFault extends RuntimeException implements CarriesRetryAfter
{
    public function __construct(
        string $message,
        public readonly ErrorClass $errorClass,
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct($message);
    }

    public function retryAfterSeconds(): ?int
    {
        return $this->retryAfter;
    }
}
