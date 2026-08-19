<?php

declare(strict_types=1);

namespace App\Domain\Sync\Support;

use App\Domain\Channels\Contracts\SupportsPricing;
use App\Domain\Channels\Registry\AdapterRegistry;
use App\Domain\Sync\Enums\SyncOperationStatus;
use App\Domain\Sync\Models\SyncOperation;
use Illuminate\Database\Eloquent\Collection;
use RuntimeException;

/**
 * Bekleyen fiyat operasyonlarını tek yüke birleştirir — YALNIZCA GRUPLAMA.
 *
 * Mimari Karar Dokümanı v2.2 · §7 · SupportsPricing, §8 · fan-out ve
 * gruplama, §12.
 *
 * `InventoryBatchBuilder`'ın kardeşidir ve aynı değişmez kurala tabidir:
 * BU SINIF FAN-OUT YAPMAZ. Fan-out outbox tüketicisinde olur (1 olay → N
 * operasyon); burada olan şey N operasyon → az sayıda API çağrısı ve
 * OPERASYON SAYISI DEĞİŞMEZ.
 *
 * SINIR ADAPTER'DAN GELİR (`maxPriceBatchSize`): Woo wc/v3 batch 100,
 * Trendyol fiyat-stok 1000. Sınırın üzerindekiler bu turda gitmez; kendi
 * işleri onları alır.
 *
 * KIRPMA YOK — stoktan farklı. Stokta kanonik bakiye fazla satış nedeniyle
 * negatif olabilir ve `OutboundQuantity` onu sıfıra çeker; fiyatın negatif
 * olma hâli yoktur ve kolonun kendisi zaten pozitif tutulur.
 */
final class PriceBatchBuilder
{
    public function __construct(
        private readonly AdapterRegistry $registry,
    ) {}

    /**
     * Tetikleyen operasyonun bağlantısındaki bekleyen fiyat operasyonlarını toplar.
     *
     * Tetikleyen operasyon YÜKTE OLMAK ZORUNDADIR: iş onun için açıldı ve
     * sonuç onun durumuna yazılacak.
     */
    public function build(SyncOperation $trigger): PricePushBatch
    {
        $adapter = $this->registry->for($trigger->connection);

        if (! $adapter instanceof SupportsPricing) {
            throw new RuntimeException(
                "Bağlantı {$trigger->channel_connection_id} fiyat senkronunu desteklemiyor ".
                '(SupportsPricing uygulanmıyor), ama PRICE_PUSH operasyonu açılmış.'
            );
        }

        // clock_timestamp(): `scheduled_at` karşılaştırması transaction içinde
        // yapılıyor ve now() transaction başında donuyor — taze bir operasyon
        // donmuş now()'a göre "geleceğe planlanmış" görünürdü.
        $operations = SyncOperation::query()
            // Yük N listing taşır; ilişkiyi satır satır çekmek N+1 sorgudur.
            ->with(['listing.variant'])
            ->where('channel_connection_id', $trigger->channel_connection_id)
            ->where('operation_type', 'PRICE_PUSH')
            ->whereIn('status', [
                SyncOperationStatus::PENDING->value,
                SyncOperationStatus::RETRYING->value,
            ])
            ->where(function ($query): void {
                $query->whereNull('scheduled_at')
                    ->orWhereRaw('scheduled_at <= clock_timestamp()');
            })
            ->orderBy('created_at')
            ->limit($adapter->maxPriceBatchSize())
            ->lockForUpdate()
            ->get();

        $operations = $this->ensureTriggerIncluded($operations, $trigger, $adapter->maxPriceBatchSize());

        $included = [];
        $items = [];

        foreach ($operations as $operation) {
            $listing = $operation->listing;

            // Fan-out'tan sonra listeden çıkarılmış veya hiç yaratılmamış
            // olabilir; kanal onu tanımaz ve çağrı hata döner.
            if ($listing === null || ! $listing->isLive() || $listing->external_id === null) {
                continue;
            }

            $variant = $listing->variant;

            if ($variant === null) {
                continue;
            }

            $included[] = $operation;

            // FİYAT STRING TAŞINIR: para float taşınmaz, yuvarlama kuruş
            // kayması üretir (§7). Kolon decimal(12,2) ve zaten string döner;
            // dönüşüm yalnızca sözleşmeyi açık kılar.
            $items[] = [
                'listing_id' => $listing->id,
                'external_id' => $listing->external_id,
                'price' => (string) $variant->price,
                'compare_at_price' => $variant->compare_at_price !== null
                    ? (string) $variant->compare_at_price
                    : null,
                'version' => $operation->entity_version,
            ];
        }

        return new PricePushBatch(
            channelConnectionId: $trigger->channel_connection_id,
            items: $items,
            operations: $included,
        );
    }

    /**
     * Tetikleyiciyi yükün içinde garanti eder.
     *
     * Sıra `created_at`'e göredir; tetikleyici çok yeni ve bağlantıda sınırdan
     * fazla eski operasyon varsa penceresi hiç açılmazdı ve iş sonsuza kadar
     * hiçbir şey göndermeden dönerdi. Bu iş ONUN için kuyruğa girdi.
     *
     * @param  Collection<int, SyncOperation>  $operations
     * @return Collection<int, SyncOperation>
     */
    private function ensureTriggerIncluded(
        Collection $operations,
        SyncOperation $trigger,
        int $limit,
    ): Collection {
        if ($operations->contains(fn (SyncOperation $o): bool => $o->id === $trigger->id)) {
            return $operations;
        }

        return $operations
            ->take(max($limit - 1, 0))
            ->prepend($trigger->load(['listing.variant']))
            ->values();
    }
}
