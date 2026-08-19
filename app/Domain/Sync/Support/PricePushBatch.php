<?php

declare(strict_types=1);

namespace App\Domain\Sync\Support;

use App\Domain\Sync\Models\SyncOperation;

/**
 * Kanala gönderilecek fiyat yükü.
 *
 * Mimari Karar Dokümanı v2.2 · §7 · SupportsPricing.
 *
 * Fiyatlar MUTLAK değerdir ve string taşınır: float para birimi için
 * güvenilir değildir, yuvarlama hataları kuruş kayması üretir.
 */
final readonly class PricePushBatch
{
    /**
     * @param  list<array{listing_id: string, external_id: string, price: string, compare_at_price?: string|null, version: int}>  $items
     * @param  list<SyncOperation>  $operations  Yükte temsil edilen operasyonlar
     */
    public function __construct(
        public string $channelConnectionId,
        public array $items,
        private array $operations = [],
    ) {}

    /**
     * Bu yükün sonucunun yazılacağı operasyonlar.
     *
     * Yük kalem listesi taşır, sonuç OPERASYON'a yazılır: bir çağrının
     * başarısı N operasyonun durumunu birden ilerletir ve `SyncResultRecorder`
     * bu listeyi kullanır. Yükte OLMAYAN operasyona dokunulmaz.
     *
     * @return list<SyncOperation>
     */
    public function operations(): array
    {
        return $this->operations;
    }

    public function count(): int
    {
        return count($this->items);
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }
}
