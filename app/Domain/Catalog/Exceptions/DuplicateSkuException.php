<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Exceptions;

use RuntimeException;

/**
 * SKU kiracı içinde tekildir.
 *
 * Mimari Karar Dokümanı v2.2 · §4 · products / variants ·
 * `UNIQUE(tenant_id, sku)`.
 *
 * İki kiracı aynı SKU'yu kullanabilir, aynı kiracı kullanamaz. Kısıt
 * veritabanındadır; bu istisna onun yerini ALMAZ, kullanıcıya anlatılabilir
 * bir hataya çevirir. Son söz veritabanında ve yarış durumunda kısıt devreye
 * girer.
 */
final class DuplicateSkuException extends RuntimeException
{
    public static function for(string $sku): self
    {
        return new self("Bu SKU zaten kullanılıyor: {$sku}");
    }
}
