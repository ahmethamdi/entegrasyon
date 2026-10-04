<?php

declare(strict_types=1);

namespace App\Domain\Channels\Contracts;

/**
 * Kanalın ürün başına kabul ettiği en fazla görsel sayısı (A15).
 *
 * Panel bunu okuyup "ilk N görsel gider, şu kadarı dışarıda kalır" der.
 * Sınırı aşan görsel SESSİZCE düşmemeli: satıcı en iyi fotoğrafının
 * neden kanalda olmadığını bilemezdi.
 *
 * Yetenek `instanceof` ile okunur; panelde kanal adı kontrol edilmez.
 */
interface DeclaresImageLimit
{
    public function maxImages(): int;
}
