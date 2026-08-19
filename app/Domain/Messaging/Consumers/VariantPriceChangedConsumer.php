<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Consumers;

use App\Domain\Messaging\Models\OutboxEvent;
use App\Domain\Sync\Actions\OpenSyncOperation;
use App\Domain\Sync\Enums\SyncDomain;
use App\Domain\Sync\Enums\SyncIntent;
use App\Domain\Sync\Jobs\PushPrices;
use App\Domain\Sync\Models\Listing;

/**
 * Fiyat değişimi fan-out tüketicisi.
 *
 * Mimari Karar Dokümanı v2.2 · §6 · Fan-out tüketicisi, §7 · SupportsPricing,
 * §8 · fan-out ve gruplama.
 *
 * STOK TÜKETİCİSİNİN BİREBİR KARDEŞİ: fan-out BURADA yapılır (1 olay →
 * varyantın canlı listing sayısı kadar operasyon), gruplama
 * `PriceBatchBuilder`'ın işidir. Her operasyon kendi satırı, kendi durumu ve
 * kendi hatasıyla yaşar; tek operasyon modelinde bir kanalın hatası
 * diğerlerinin durumunu kirletirdi.
 *
 * YANKI BASTIRMA YOK ve bu bilinçlidir. Stok değişimi bir kanaldan gelebilir
 * (sipariş webhook'u) ve o kanala geri yazmak gereksizdir; fiyat değişimi
 * PANELDEN gelir. Bastırılacak kaynak kanal yoktur, bu yüzden yükte
 * `origin_connection_id` de yoktur.
 */
final class VariantPriceChangedConsumer
{
    public function __construct(
        private readonly OpenSyncOperation $openSyncOperation,
    ) {}

    public function handle(OutboxEvent $event): void
    {
        $payload = $event->payload;

        // Hedef: varyantın TÜM canlı listeleri. Taslak/delisted satıra fiyat
        // gönderilmez — kanalda karşılığı yoktur ve çağrı her turda hata alır.
        $listings = Listing::query()
            ->where('tenant_id', $event->tenant_id)
            ->where('variant_id', $payload['variant_id'])
            ->live()
            ->get();

        /** @var list<string> $pending */
        $pending = [];

        foreach ($listings as $listing) {
            $operation = $this->openSyncOperation->run(
                listing: $listing,
                domain: SyncDomain::PRICE,
                eventVersion: (int) $payload['version'],
                intent: SyncIntent::NORMAL_SYNC,
                sourceEvent: $event,
            );

            if ($operation !== null) {
                // Kimlik TOPLANIR, iş burada ATILMAZ: kuyruk kancaları her iş
                // sınırında kiracı bağlamını temizler ve `sync` sürücüde iş
                // DERHAL çalışır. Döngü ortasında atılırsa kalan listing'ler
                // bağlamsız kalır ve tenant-scoped sorgu istisna fırlatır.
                $pending[] = $operation->id;
            }
        }

        // PLANLAMA BİTTİ — downstream başarısını BEKLEMEZ. Erken çıkışta da
        // (hiç canlı listing yok, hepsi kapıya takıldı) damgalanmak
        // ZORUNLUDUR: aksi halde seviye 1 bütünlük taraması olayı kayıp sanar
        // ve sonsuza kadar yeniden yayınlar.
        $event->markConsumed(operationsPlanned: count($pending));

        foreach ($pending as $operationId) {
            PushPrices::dispatch($operationId, $event->tenant_id)->onQueue('price:high');
        }
    }
}
