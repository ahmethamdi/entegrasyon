<?php

declare(strict_types=1);

namespace App\Support\Tenancy\Exceptions;

use RuntimeException;

/**
 * Kiracı bağlamı olmadan tenant-scoped bir modele erişilmeye çalışıldı.
 *
 * Mimari Karar Dokümanı v2.2 · §11.
 *
 * Bu istisnanın varlık sebebi: bağlamsız sorgunun sessizce tüm kiracıların
 * kayıtlarını döndürmesi, hiçbir günlükte görünmeyen çapraz kiracı veri
 * sızıntısıdır.
 */
final class MissingTenantContextException extends RuntimeException
{
    public static function forQuery(string $model): self
    {
        return new self(
            "Kiracı bağlamı olmadan [{$model}] sorgulanamaz. ".
            'Bilinçli sistem erişimi için TenantContext::runAsSystem() kullanın.'
        );
    }

    public static function forWrite(): self
    {
        return new self(
            'Kiracı bağlamı olmadan tenant_id belirlenemez. '.
            'TenantContext::set() veya TenantContext::runFor() ile bağlam kurun.'
        );
    }
}
