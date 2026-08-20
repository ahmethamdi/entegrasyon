<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Support;

/**
 * Kanaldan içe aktarma turunun sonucu — kullanıcıya gösterilen rapor.
 *
 * Mimari Karar Dokümanı v2.2 · §13 · Faz 3 · madde 5.
 *
 * `ImportResult`'IN KARDEŞİ AMA AYRI SINIF: CSV raporunda "eksik zorunlu
 * kolon" ve satır numarası vardır; kanal turunda ne dosya ne satır vardır,
 * bunun yerine ATLANAN ürün ve ERKEN DURMA vardır. Tek sınıfa
 * sıkıştırılsalardı her iki taraf da diğerinin anlamsız alanlarını taşır
 * ve ekran hangisinin dolu olduğunu tahmin etmek zorunda kalırdı.
 *
 * ATLANAN AYRI SAYILIR: "47 ürün geldi" ile "47 geldi, 3 atlandı" farklı
 * şeylerdir ve ikincisi satıcıya yapacak bir iş verir (kanalda SKU tanımla).
 * Yaratılan/güncellenen ayrımının gerekçesi `ImportResult` ile aynıdır.
 */
final class ChannelImportResult
{
    /** @param  list<array{line: int, message: string}>  $errors */
    public function __construct(
        public readonly int $created = 0,
        public readonly int $updated = 0,
        public readonly int $skipped = 0,
        public readonly array $errors = [],
        /** Tur kanalın tamamını okumadan bitti mi. */
        public readonly bool $stoppedEarly = false,
        public readonly ?string $stopReason = null,
        /** Kanal bu yeteneği hiç desteklemiyor. */
        public readonly bool $supported = true,
    ) {}

    /**
     * Kanal içe aktarmayı DESTEKLEMİYOR.
     *
     * Boş bir başarı sonucu DÖNÜLMEZ: "0 ürün bulundu" satıcıya
     * kataloğunun boş olduğunu düşündürür ve o yanlış bilgiyle kanalda
     * ürün aramaya gider. §7'nin "yazılmamış yetenek SESSİZCE BAŞARILI
     * DÖNMEZ" kuralının bu maddedeki karşılığı.
     */
    public static function unsupported(string $channelName): self
    {
        return new self(
            supported: false,
            stoppedEarly: true,
            stopReason: sprintf('%s kanalı kanaldan ürün çekmeyi desteklemiyor.', $channelName),
        );
    }

    public function total(): int
    {
        return $this->created + $this->updated;
    }
}
