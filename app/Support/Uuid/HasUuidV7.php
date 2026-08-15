<?php

declare(strict_types=1);

namespace App\Support\Uuid;

use Symfony\Component\Uid\UuidV7;

/**
 * Zaman sıralı UUIDv7 birincil anahtar.
 *
 * Mimari Karar Dokümanı v2.2 · §1 · Karar 23.
 *
 * symfony/uid Laravel 12 ile zaten geldiği için ek paket kurulmaz.
 * UUIDv7 zaman sıralıdır: indeks bölünmesi yaratmaz, kanaldan gelen
 * kimliklerle çakışmaz, kiracı bazlı bölümlemeye hazırdır.
 *
 * Yüksek hacimli teknik günlük tabloları (api_calls) bu trait'i KULLANMAZ;
 * onlar bigserial anahtar taşır.
 */
trait HasUuidV7
{
    protected static function bootHasUuidV7(): void
    {
        static::creating(function ($model): void {
            $keyName = $model->getKeyName();

            if ($model->getAttribute($keyName) === null) {
                $model->setAttribute($keyName, self::generateUuidV7());
            }
        });
    }

    public static function generateUuidV7(): string
    {
        return (string) new UuidV7;
    }

    public function initializeHasUuidV7(): void
    {
        $this->casts[$this->getKeyName()] = 'string';
    }

    public function getIncrementing(): bool
    {
        return false;
    }

    public function getKeyType(): string
    {
        return 'string';
    }
}
