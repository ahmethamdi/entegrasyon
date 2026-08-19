<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Support;

/**
 * CSV ayrıştırma sonucu.
 *
 * Mimari Karar Dokümanı v2.2 · §13 · Faz 3 · toplu içe aktarma.
 *
 * BAŞLIK HATASI SATIR HATASINDAN AYRI TAŞINIR: `headerValid = false` "bu
 * dosya hiç işlenemez" demektir ve tek bir mesajla anlatılır; `invalid`
 * ise "şu satırlar atlandı" demektir ve dosyanın geri kalanı yazılır.
 * İkisi tek listede toplansaydı zorunlu kolonu eksik bir dosya 500 ayrı
 * hata satırı üretir ve kullanıcı asıl sebebi göremezdi.
 */
final class CsvParseResult
{
    /**
     * @param  list<array<string, mixed>>  $valid
     * @param  list<array{line: int, message: string}>  $invalid
     * @param  list<string>  $missingColumns
     */
    public function __construct(
        public readonly bool $headerValid,
        public readonly array $valid = [],
        public readonly array $invalid = [],
        public readonly array $missingColumns = [],
    ) {}
}
