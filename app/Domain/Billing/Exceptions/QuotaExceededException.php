<?php

declare(strict_types=1);

namespace App\Domain\Billing\Exceptions;

use App\Domain\Billing\Enums\QuotaMetric;
use RuntimeException;

/**
 * Plan kotası aşıldı — yaratma engellenir.
 *
 * Mimari Karar Dokümanı v2.2 · §13 · Faz 4 · kota.
 *
 * DEĞİŞMEZ KURAL — LİMİT VE MEVCUT SAYI BİRLİKTE TAŞINIR. "Kotanı
 * aştın" tek başına kullanıcıya ne yapacağını söylemez; hangi sınıra
 * hangi sayıyla dayandığı gösterilmelidir. Uyarı e-postalarının
 * "değer ve eşik birlikte" kuralının aynısı.
 *
 * Bu istisna KULLANICI HATASIDIR, sistem hatası değil: panelde alan
 * hatasına çevrilir ve 500 verilmez (`DuplicateSkuException` ile aynı
 * kalıp).
 */
final class QuotaExceededException extends RuntimeException
{
    public function __construct(
        public readonly QuotaMetric $metric,
        public readonly int $limit,
        public readonly int $current,
        public readonly string $planCode,
    ) {
        parent::__construct(sprintf(
            '%s kotası doldu: %d/%d. %s',
            ucfirst($metric->label()),
            $current,
            $limit,
            $metric->advice(),
        ));
    }

    /** Panelde alan hatası olarak gösterilecek mesaj. */
    public function userMessage(): string
    {
        return $this->getMessage();
    }
}
