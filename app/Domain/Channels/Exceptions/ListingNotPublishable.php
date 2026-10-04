<?php

declare(strict_types=1);

namespace App\Domain\Channels\Exceptions;

use RuntimeException;

/**
 * Ürün bu kanala gönderilemez — eksik VERİ, geçici hata DEĞİL.
 *
 * Marka kanalda yok, görsel yok, kategori eşleştirilmemiş… Hepsi satıcının
 * düzeltmesi gereken şeylerdir. Adapter bunu `VALIDATION` sınıflar: düz
 * `RuntimeException` olsaydı `NETWORK` sayılır ve iş 24 saat boyunca
 * boşuna yeniden denenirdi — satıcı da sebebi ancak sonunda görürdü.
 *
 * Mesaj SATICIYA gösterilir; ne düzeltileceğini söylemelidir.
 */
final class ListingNotPublishable extends RuntimeException {}
