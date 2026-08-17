<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Consumers;

use App\Domain\Messaging\Models\OutboxEvent;
use App\Domain\Sync\Actions\OpenSyncOperation;
use App\Domain\Sync\Enums\SyncDomain;
use App\Domain\Sync\Enums\SyncIntent;
use App\Domain\Sync\Jobs\PushInventory;
use App\Domain\Sync\Models\Listing;

/**
 * Stok değişimi fan-out tüketicisi.
 *
 * Mimari Karar Dokümanı v2.2 · §6 · Fan-out tüketicisi, §1 · Karar 11.
 *
 * DEĞİŞMEZ KURAL — FAN-OUT VE GRUPLAMA AYRIDIR:
 *   Fan-out BURADA yapılır: bir olay, varyantın canlı listing sayısı kadar
 *   ayrı sync_operation üretir. Her biri kendi sürüm kapısından geçer, kendi
 *   listing_sync_states satırını günceller, kendi hatasını taşır.
 *
 *   Gruplama InventoryBatchBuilder'ın işidir: aynı bağlantıya ait bekleyen
 *   operasyonları tek API çağrısında birleştirir. Operasyon sayısı değişmez.
 *
 *   Bu sınıf GRUPLAMA YAPMAZ; batch builder FAN-OUT YAPMAZ.
 *
 * Üç kanallı bir varyantta bu tüketici üç operasyon üretir. Biri 429 alıp
 * yeniden denemeye girdiğinde diğer ikisi bağımsız tamamlanır. Tek operasyon
 * modelinde bir kanalın hatası diğerlerinin durumunu kirletirdi ve başarılı
 * kanalların sürümleri hiç ilerlemezdi.
 */
final class InventoryLevelChangedConsumer
{
    public function __construct(
        private readonly OpenSyncOperation $openSyncOperation,
    ) {}

    public function handle(OutboxEvent $event): void
    {
        $payload = $event->payload;

        // (1) Hedef listing'ler: varyantın TÜM canlı listeleri.
        $listings = Listing::query()
            ->where('tenant_id', $event->tenant_id)
            ->where('variant_id', $payload['variant_id'])
            ->live()
            ->get();

        /** @var list<string> $pending Kuyruğa atılacak operasyon kimlikleri */
        $pending = [];

        foreach ($listings as $listing) {
            // (2) Anlık yankı bastırma — bir ENİYİLEME, doğruluk kuralı değil.
            //     Stok değişimi bu kanaldan geldiyse ona geri yazmak gereksiz.
            //     Kanal otorite dışına ÇIKARILMIYOR: mutabakat onu da kontrol
            //     eder ve gerçek bir sürüklenme bulursa onarım açar (§10).
            if ($this->isOriginConnection($listing, $payload)) {
                continue;
            }

            // (3) Listing başına AYRI operasyon.
            //     Granülerlik: listing × domain × version.
            $operation = $this->openSyncOperation->run(
                listing: $listing,
                domain: SyncDomain::INVENTORY,
                eventVersion: (int) $payload['version'],
                intent: SyncIntent::NORMAL_SYNC,
                sourceEvent: $event,
            );

            if ($operation !== null) {
                // (4) Kimlik TOPLANIR, iş burada ATILMAZ.
                //
                //     Dispatch planlama bittikten SONRA yapılır. Gerekçe
                //     kiracı bağlamıdır: kuyruk kancaları (Queue::looping,
                //     JobProcessing) her iş sınırında bağlamı temizler ve
                //     sync sürücüde iş DERHAL çalışır. Döngünün ortasında
                //     atılırsa kalan listing'ler bağlamsız kalır ve
                //     tenant-scoped sorgu istisna fırlatır — P0 izolasyon
                //     korumasının doğru davranışı.
                $pending[] = $operation->id;
            }
        }

        // (4) PLANLAMA bitti. Downstream başarısını BEKLEMEZ.
        //
        //     consumed_at senkronun başarısını göstermez. Bir bağlantının
        //     token'ı süresi dolduğu için tüm operasyonları kalıcı hataya
        //     düşse bile olay consumed kalır ve yeniden yayınlanmaz; o hata
        //     operasyon seviyesinde yaşar ve panelde çözülür.
        //
        //     Erken çıkışta da (hiç listing yok, hepsi kapıya takıldı)
        //     damgalanmak ZORUNDADIR: aksi halde seviye 1 bütünlük taraması
        //     olayı kayıp sanar ve sonsuza kadar yeniden yayınlar.
        $event->markConsumed(operationsPlanned: count($pending));

        // (5) İşler EN SON atılır — planlama ve damgalama bittikten sonra.
        //
        //     Operasyon başına AYRI iş: gruplama kuyrukta değil
        //     InventoryBatchBuilder'da yapılır. Aynı bağlantıdaki diğer
        //     operasyonlar ilk işin yükünde gittiyse kalan işler boş yükle
        //     erken çıkar — ucuzdur ve teslim garantisini tek bir işe
        //     bağlamaktan güvenlidir.
        foreach ($pending as $operationId) {
            PushInventory::dispatch($operationId, $event->tenant_id)->onQueue('inventory:high');
        }
    }

    /**
     * Olayın kaynağı bu listing'in bağlantısı mı?
     *
     * origin_connection_id yükte yoksa (elle düzeltme, import, mutabakat)
     * hiçbir kanal dışlanmaz.
     *
     * @param  array<string, mixed>  $payload
     */
    private function isOriginConnection(Listing $listing, array $payload): bool
    {
        $origin = $payload['origin_connection_id'] ?? null;

        return $origin !== null && $listing->channel_connection_id === $origin;
    }
}
