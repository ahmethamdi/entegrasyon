<?php

declare(strict_types=1);

namespace App\Domain\Sync\Support;

use App\Domain\Channels\Contracts\SupportsInventory;
use App\Domain\Channels\Registry\AdapterRegistry;
use App\Domain\Inventory\Models\InventoryLevel;
use App\Domain\Inventory\Support\OutboundQuantity;
use App\Domain\Sync\Enums\SyncOperationStatus;
use App\Domain\Sync\Models\Listing;
use App\Domain\Sync\Models\SyncOperation;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Bekleyen stok operasyonlarını tek yüke birleştirir — YALNIZCA GRUPLAMA.
 *
 * Mimari Karar Dokümanı v2.2 · §8 · Fan-out ve gruplama, §12.
 *
 * DEĞİŞMEZ KURAL — BU SINIF FAN-OUT YAPMAZ:
 *   Fan-out outbox TÜKETİCİSİNDE olur: 1 olay → N operasyon. Burada olan şey
 *   N operasyon → az sayıda API çağrısı. OPERASYON SAYISI DEĞİŞMEZ; her
 *   operasyon kendi satırı, kendi durumu, kendi hatasıyla yaşamaya devam
 *   eder. İki sorumluluk tek yerde toplansaydı bir kanalın hatası batch'teki
 *   tüm listinglerin durumunu kirletirdi.
 *
 * SINIR ADAPTER'DAN GELİR: Woo wc/v3 batch 100, Trendyol stok-fiyat 1000.
 * Sınırın üzerindekiler bu turda gitmez; kendi işleri onları alır.
 *
 * KIRPMA: giden miktar OutboundQuantity::forChannel() ile geçer. Kanonik
 * bakiye fazla satış nedeniyle negatif olabilir, kanal negatif kabul etmez.
 * Kırpma YALNIZCA burada uygulanır ve hiçbir yerde saklanmaz.
 */
final class InventoryBatchBuilder
{
    public function __construct(
        private readonly AdapterRegistry $registry,
    ) {}

    /**
     * Tetikleyen operasyonun bağlantısındaki bekleyen operasyonları toplar.
     *
     * Tetikleyen operasyon YÜKTE OLMAK ZORUNDADIR: iş onun için açıldı ve
     * sonuç onun durumuna yazılacak. Listing'i canlı değilse yük boş döner
     * ve çağıran "gönderilecek bir şey yok" olarak işler.
     */
    public function build(SyncOperation $trigger): InventoryPushBatch
    {
        $adapter = $this->registry->for($trigger->connection);

        if (! $adapter instanceof SupportsInventory) {
            throw new RuntimeException(
                "Bağlantı {$trigger->channel_connection_id} stok senkronunu desteklemiyor ".
                '(SupportsInventory uygulanmıyor), ama INVENTORY_PUSH operasyonu açılmış.'
            );
        }

        // Bağlantı bazlı toplama. Kiracı filtresi BelongsToTenant global
        // scope'undan gelir; bağlantı zaten tek kiracıya aittir ama çapraz
        // kiracı sızıntısı sessiz olur, bu yüzden iki katman da durur.
        //
        // clock_timestamp(): scheduled_at karşılaştırması transaction içinde
        // yapılıyor ve now() transaction başlangıcında donuyor. Taze bir
        // operasyon donmuş now()'a göre "geleceğe planlanmış" görünürdü.
        $operations = SyncOperation::query()
            // Yük N listing taşır; ilişkiyi satır satır çekmek N+1 sorgu
            // demektir ve kilit tutulurken yapılırsa çekişmeyi uzatır.
            ->with(['listing.variant'])
            ->where('channel_connection_id', $trigger->channel_connection_id)
            ->where('operation_type', 'INVENTORY_PUSH')
            ->whereIn('status', [
                SyncOperationStatus::PENDING->value,
                SyncOperationStatus::RETRYING->value,
            ])
            ->where(function ($query): void {
                $query->whereNull('scheduled_at')
                    ->orWhereRaw('scheduled_at <= clock_timestamp()');
            })
            ->orderBy('created_at')
            ->limit($adapter->maxInventoryBatchSize())
            ->lockForUpdate()
            ->get();

        // Tetikleyici sınırın dışında kaldıysa (ondan eski çok sayıda
        // operasyon varsa) yükte olmadığı için iş sonsuza kadar hiçbir şey
        // göndermeden döner. Onu yükün başına al.
        $operations = $this->ensureTriggerIncluded($operations, $trigger, $adapter->maxInventoryBatchSize());

        $included = [];
        $items = [];

        foreach ($operations as $operation) {
            $listing = $operation->listing;

            // Fan-out'tan sonra listeden çıkarılmış olabilir; kanal onu
            // tanımaz ve çağrı hata döner.
            if ($listing === null || ! $listing->isLive() || $listing->external_id === null) {
                continue;
            }

            $level = $this->levelFor($listing);

            if ($level === null) {
                continue;
            }

            $included[] = $operation;

            $items[] = new InventoryPushItem(
                listingId: $listing->id,
                externalId: $listing->external_id,
                sku: $listing->variant->sku,
                // GİDEN DÖNÜŞÜM — kırpmanın tek meşru yeri.
                quantity: OutboundQuantity::forChannel($level),
                version: $operation->entity_version,
            );
        }

        return new InventoryPushBatch(
            channelConnectionId: $trigger->channel_connection_id,
            items: $items,
            operations: $included,
        );
    }

    /**
     * Tetikleyiciyi yükün içinde garanti eder.
     *
     * Sıra created_at'e göredir; tetikleyici çok yeni ve bağlantıda sınırdan
     * fazla eski operasyon varsa penceresi hiç açılmazdı. Bu iş ONUN için
     * kuyruğa girdi; en az bir kez gönderilmesi gerekir.
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

    /**
     * Listing'in varsayılan depodaki stok seviyesi.
     *
     * Çok depolu senaryoda gönderilecek bakiye bağlantının depo eşlemesinden
     * gelir; şimdilik kiracının varsayılan deposu kullanılır (§5).
     */
    private function levelFor(Listing $listing): ?InventoryLevel
    {
        $warehouseId = $this->defaultWarehouseId($listing->tenant_id);

        if ($warehouseId === null) {
            return null;
        }

        return InventoryLevel::query()
            ->where('warehouse_id', $warehouseId)
            ->where('variant_id', $listing->variant_id)
            ->first();
    }

    /** @var array<string, string|null> */
    private array $warehouseCache = [];

    private function defaultWarehouseId(string $tenantId): ?string
    {
        if (array_key_exists($tenantId, $this->warehouseCache)) {
            return $this->warehouseCache[$tenantId];
        }

        // Bu sınıf kısa ömürlüdür (iş başına bir örnek); önbellek tek yükün
        // içinde kalır ve kiracılar arası paylaşılmaz. Yine de anahtar
        // kiracı kimliğidir — karışma imkânsız.
        return $this->warehouseCache[$tenantId] = DB::table('warehouses')
            ->where('tenant_id', $tenantId)
            ->where('is_default', true)
            ->value('id');
    }
}
