<?php

declare(strict_types=1);

namespace App\Domain\Sync\Support;

/**
 * Kanaldan çekilen bir sayfa ürün.
 *
 * Mimari Karar Dokümanı v2.2 · §7 (SupportsCatalogImport), §13 · Faz 3 ·
 * madde 5.
 *
 * `OrderPage`'in KARDEŞİDİR ve imleç biçimi bilinçli olarak aynıdır:
 * imleç ADAPTER'A ÖZGÜ OPAK bir metindir, çekirdek onu YORUMLAMAZ. Woo'da
 * sayfa numarası, başka kanalda bir token olabilir; çekirdek sayı
 * varsayarsa ikinci kanal eklenirken kırılır.
 *
 * `hasMore` AYRI BİR ALANDIR ve `nextCursor !== null` ile aynı şey
 * DEĞİLDİR: bir kanal son sayfada bile imleç döndürebilir. Turu durduran
 * şey `hasMore`'dur; imlece bakılsaydı tur sonsuza kadar boş sayfa çeker
 * ve kotayı yakardı.
 */
final readonly class RemoteProductPage
{
    /** @param list<RemoteProduct> $products */
    public function __construct(
        public array $products,
        public ?string $nextCursor = null,
        public bool $hasMore = false,
    ) {}

    public function count(): int
    {
        return count($this->products);
    }

    public function isEmpty(): bool
    {
        return $this->products === [];
    }
}
