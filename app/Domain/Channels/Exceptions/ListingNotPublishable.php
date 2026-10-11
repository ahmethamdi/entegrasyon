<?php

declare(strict_types=1);

namespace App\Domain\Channels\Exceptions;

use App\Domain\Sync\Enums\PublishReason;
use RuntimeException;

/**
 * Ürün bu kanala gönderilemez — eksik VERİ, geçici hata DEĞİL.
 *
 * Marka kanalda yok, görsel yok, kategori eşleştirilmemiş… Hepsi satıcının
 * düzeltmesi gereken şeylerdir. Adapter bunu `VALIDATION` sınıflar: düz
 * `RuntimeException` olsaydı `NETWORK` sayılır ve iş 24 saat boyunca
 * boşuna yeniden denenirdi — satıcı da sebebi ancak sonunda görürdü.
 *
 * Mesaj SATICIYA gösterilir; ne düzeltileceğini söylemelidir. S3'ten
 * itibaren kararlı sebep kodu da taşınabilir (`reason`); sync durumuna
 * KOD yazılır.
 */
final class ListingNotPublishable extends RuntimeException
{
    public function __construct(string $message = '', public readonly ?PublishReason $reason = null)
    {
        parent::__construct($message);
    }

    public static function because(PublishReason $reason, ?string $subject = null): self
    {
        return new self($subject === null ? $reason->label() : $reason->label().' ('.$subject.')', $reason);
    }
}
