<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use App\Support\Tenancy\Exceptions\MissingTenantContextException;
use Closure;

/**
 * Kiracı bağlamı.
 *
 * Mimari Karar Dokümanı v2.2 · §11 · Karar 21.
 *
 * Fail-closed: bağlam yokken tenant-scoped sorgu sessizce tüm kayıtları
 * döndürmez, istisna fırlatır. Sistem işleri (relay, mutabakat, migration)
 * bağlamı açıkça {@see self::runAsSystem()} ile devre dışı bırakmak zorundadır.
 *
 * Kuyruk worker'ları uzun ömürlüdür; bağlamın işler arasında sızmaması için
 * QueueServiceProvider her döngüde {@see self::clear()} çağırır.
 */
final class TenantContext
{
    private static ?string $tenantId = null;

    private static int $systemDepth = 0;

    /** Aktif kiracı kimliği; yoksa null. */
    public static function id(): ?string
    {
        return self::$tenantId;
    }

    /**
     * Aktif kiracı kimliği; yoksa istisna.
     *
     * @throws MissingTenantContextException
     */
    public static function idOrFail(): string
    {
        if (self::$tenantId === null) {
            throw MissingTenantContextException::forWrite();
        }

        return self::$tenantId;
    }

    public static function set(string $tenantId): void
    {
        self::$tenantId = $tenantId;
    }

    /**
     * Bağlamı koşulsuz temizler.
     *
     * Sistem derinliği de sıfırlanır: bir iş `runAsSystem()` içinde istisna
     * fırlatıp çıkarsa, sayaç sıfırlanmazsa sonraki iş yanlışlıkla sistem
     * bağlamında çalışır.
     */
    public static function clear(): void
    {
        self::$tenantId = null;
        self::$systemDepth = 0;
    }

    public static function hasTenant(): bool
    {
        return self::$tenantId !== null;
    }

    public static function isSystemContext(): bool
    {
        return self::$systemDepth > 0;
    }

    /**
     * Belirli bir kiracı bağlamında çalıştırır ve önceki bağlamı geri yükler.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public static function runFor(string $tenantId, Closure $callback): mixed
    {
        $previous = self::$tenantId;
        self::$tenantId = $tenantId;

        try {
            return $callback();
        } finally {
            self::$tenantId = $previous;
        }
    }

    /**
     * Kiracı kapsamı olmadan çalıştırır — bilinçli ve açık sistem erişimi.
     *
     * Outbox relay, mutabakat taramaları ve bakım işleri bu API'yi kullanır.
     * İç içe çağrılara güvenlidir.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public static function runAsSystem(Closure $callback): mixed
    {
        $previousTenant = self::$tenantId;

        self::$tenantId = null;
        self::$systemDepth++;

        try {
            return $callback();
        } finally {
            self::$systemDepth--;
            self::$tenantId = $previousTenant;
        }
    }
}
