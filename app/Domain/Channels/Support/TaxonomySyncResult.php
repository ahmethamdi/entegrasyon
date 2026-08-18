<?php

declare(strict_types=1);

namespace App\Domain\Channels\Support;

/**
 * Taksonomi senkronunun sonucu.
 *
 * Mimari Karar Dokümanı v2.2 · §13 · Faz 2 (taksonomi).
 *
 * `supported = false` bir HATA DEĞİLDİR: WooCommerce taksonomi uygulamaz
 * ve o kanal için tur atlanır. Hata ile atlamayı ayırmak, komutun
 * "kaç kanal atlandı" diyebilmesi için gerekli.
 */
final readonly class TaxonomySyncResult
{
    public function __construct(
        public bool $supported,
        public string $version = '',
        public int $categoriesWritten = 0,
        public int $attributesWritten = 0,
        public int $leavesFetched = 0,
    ) {}

    /** Kanal taksonomi desteklemiyor — atlandı, hata değil. */
    public static function unsupported(): self
    {
        return new self(supported: false);
    }
}
