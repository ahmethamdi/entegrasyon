<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Support;

/**
 * Toplu içe aktarma sonucu — kullanıcıya gösterilen rapor.
 *
 * Mimari Karar Dokümanı v2.2 · §13 · Faz 3 · toplu içe aktarma.
 *
 * YARATILAN İLE GÜNCELLENEN AYRI SAYILIR: satıcı "500 satır yükledim, 480
 * güncellendi" ile "500 satır yükledim, 480 yeni ürün açıldı" arasındaki
 * farkı görmek zorundadır. İkincisi genellikle YANLIŞ DOSYA demektir ve
 * fark edilmezse katalog kopya ürünlerle dolar.
 *
 * HATALAR SATIR NUMARASI TAŞIR: sayı tek başına kullanıcıya ne yapacağını
 * söylemez (eşleştirme ekranındaki "eksik zorunlu öznitelik ADIYLA
 * gösterilir" kuralının aynısı).
 */
final class ImportResult
{
    /** @param  list<array{line: int, message: string}>  $errors */
    public function __construct(
        public readonly int $created = 0,
        public readonly int $updated = 0,
        public readonly array $errors = [],
        public readonly bool $headerValid = true,
        /** @var list<string> */
        public readonly array $missingColumns = [],
    ) {}

    /**
     * Dosya HİÇ işlenmedi — zorunlu kolon eksik.
     *
     * Satır listesi BOŞTUR ve bu bilinçlidir: hiçbir satır denenmedi,
     * "0 hata" demek yanıltıcı olurdu.
     *
     * @param  list<string>  $missingColumns
     */
    public static function rejected(array $missingColumns): self
    {
        return new self(headerValid: false, missingColumns: $missingColumns);
    }

    public function total(): int
    {
        return $this->created + $this->updated;
    }
}
