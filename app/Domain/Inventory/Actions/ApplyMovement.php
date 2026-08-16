<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Actions;

use App\Domain\Inventory\Enums\MovementType;
use App\Domain\Inventory\Exceptions\InsufficientStockException;
use App\Domain\Inventory\Models\InventoryLevel;
use App\Domain\Inventory\Models\InventoryMovement;
use App\Domain\Messaging\Models\OutboxEvent;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Tek bir stok hareketini uygular: ledger + projeksiyon + outbox.
 *
 * Mimari Karar Dokümanı v2.2 · §5 · Hareket uygulama, §1 · Kararlar 04–07.
 *
 * ÜÇ YAZMA, TEK TRANSACTION:
 *   1. inventory_movements  — append-only ledger, gerçeğin kaynağı
 *   2. inventory_levels     — projeksiyon, ledger toplamıyla birebir
 *   3. outbox_events        — kanallara yayılacak değişim
 * Üçü aynı commit'te yazılır; dual write'ın tek çözümü budur.
 *
 * KİLİT ALMAZ — ÖN KOŞULDUR.
 * Çağıran, LockInventoryRows ile satırı zaten kilitlemiş olmalıdır. Burada
 * ikinci bir SELECT ... FOR UPDATE açılsaydı çok-SKU yollarında ikinci bir
 * kilit sıralaması doğar ve deadlock riski geri gelirdi. Bu ön koşul testle
 * doğrulanır (apply_movement_does_not_acquire_its_own_lock).
 *
 * KIRPMA YOK.
 * on_hand ve available negatife düşebilir; negatif available fazla satılan
 * miktarın kendisidir. GREATEST/LEAST/clamp bu sınıfta YASAKTIR. Kırpma
 * yalnızca giden yükte, OutboundQuantity::forChannel() içinde yaşar.
 */
final class ApplyMovement
{
    /**
     * @param  int  $quantity  Daima POZİTİF. Yön hareket türünden gelir —
     *                         çağıranın işaret hesaplaması gerekmez, böylece
     *                         "eksi mi artı mı" hatası imkânsızlaşır.
     *
     * @throws InsufficientStockException Yalnızca RESERVATION / TRANSFER_OUT
     * @throws InvalidArgumentException Miktar pozitif değilse
     */
    public function run(
        string $warehouseId,
        string $variantId,
        MovementType $type,
        int $quantity,
        string $idempotencyKey,
        string $sourceType,
        ?string $sourceId = null,
        ?string $channelConnectionId = null,
        ?string $note = null,
    ): InventoryMovement {
        if ($quantity <= 0) {
            throw new InvalidArgumentException(
                "Hareket miktarı pozitif olmalıdır, {$quantity} verildi. ".
                'Yön hareket türünden türetilir.'
            );
        }

        $tenantId = TenantContext::idOrFail();

        return DB::transaction(function () use (
            $tenantId, $warehouseId, $variantId, $type, $quantity,
            $idempotencyKey, $sourceType, $sourceId, $channelConnectionId, $note,
        ): InventoryMovement {

            // Aynı anahtar daha önce uygulandıysa mevcut hareket döner.
            // Kanal webhook'ları en-az-bir-kez teslim eder; tekrar oynatma
            // stoğu ikinci kez düşürmemelidir.
            $replay = $this->findExisting($tenantId, $idempotencyKey);

            if ($replay !== null) {
                return $replay;
            }

            // Satır kilitli GELMİŞ olmalı; burada kilit alınmaz. Kilit
            // alınmamışsa okuma yine doğru satırı verir, ama eşzamanlılık
            // koruması çağıranın sorumluluğundadır.
            $level = $this->currentLevel($tenantId, $warehouseId, $variantId);

            [$onHandDelta, $reservedDelta] = $this->deltasFor($type, $quantity);

            $this->guardSufficientStock($type, $variantId, $quantity, $level);

            // KIRPMA YOK — toplam olduğu gibi yazılır.
            $onHandAfter = $level->on_hand + $onHandDelta;
            $reservedAfter = $level->reserved + $reservedDelta;

            $movement = InventoryMovement::create([
                'tenant_id' => $tenantId,
                'warehouse_id' => $warehouseId,
                'variant_id' => $variantId,
                'type' => $type,
                'on_hand_delta' => $onHandDelta,
                'reserved_delta' => $reservedDelta,
                'on_hand_after' => $onHandAfter,
                'reserved_after' => $reservedAfter,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'channel_connection_id' => $channelConnectionId,
                'idempotency_key' => $idempotencyKey,
                'occurred_at' => now(),
                'note' => $note,
            ]);

            // Projeksiyon: hareketin yazdığı "after" değerleriyle birebir.
            // available generated stored kolondur, yazılmaz.
            $level->forceFill([
                'on_hand' => $onHandAfter,
                'reserved' => $reservedAfter,
                'version' => $level->version + 1,
                'last_movement_id' => $movement->id,
            ])->save();

            $level->refresh();

            $this->recordOutboxEvent($level, $movement);

            return $movement;
        });
    }

    /**
     * Hareket türünden delta çifti.
     *
     * RESERVATION / RELEASE yalnızca reserved sütununu taşır: rezerve edilen
     * mal hâlâ depodadır, on_hand değişmez — yalnızca satılabilir kısım azalır.
     *
     * @return array{0: int, 1: int} [on_hand_delta, reserved_delta]
     */
    private function deltasFor(MovementType $type, int $quantity): array
    {
        return match ($type) {
            // Depodan çıkan mal
            MovementType::SALE,
            MovementType::TRANSFER_OUT => [-$quantity, 0],

            // Depoya giren mal
            MovementType::CANCELLATION,
            MovementType::RETURN,
            MovementType::IMPORT,
            MovementType::TRANSFER_IN => [$quantity, 0],

            // Yalnızca rezerve sütunu
            MovementType::RESERVATION => [0, $quantity],
            MovementType::RELEASE => [0, -$quantity],

            // Düzeltmeler: yön çağırandan gelemez, işaret burada da pozitiftir.
            // Eksiltme MANUAL_ADJUSTMENT yerine uygun hareket türüyle yapılır;
            // düzeltmenin kendisi sayım farkını EKLER.
            MovementType::MANUAL_ADJUSTMENT,
            MovementType::RECONCILIATION_ADJUSTMENT => [$quantity, 0],
        };
    }

    /**
     * Yetersiz stok kapısı — yalnızca bizim kararımız olan hareketlerde.
     *
     * SALE dış dünyada olmuş bitmiş bir olaydır: pazaryeri siparişi
     * otoriterdir, reddedilirse sipariş hiç kaydedilmez ve müşteri kaybolur.
     * Bakiye negatife düşer, fazla satış negatif available ile işaretlenir.
     *
     * Karşılaştırma available üzerindendir, on_hand üzerinden değil:
     * rezerve edilmiş mal fiziksel olarak depodadır ama satılabilir değildir.
     */
    private function guardSufficientStock(
        MovementType $type,
        string $variantId,
        int $quantity,
        InventoryLevel $level,
    ): void {
        if (! $type->requiresSufficientStock()) {
            return;
        }

        if ($level->available < $quantity) {
            throw InsufficientStockException::forMovement(
                $type,
                $variantId,
                $quantity,
                $level->available,
            );
        }
    }

    /**
     * Projeksiyon satırı — yoksa yaratılır.
     *
     * Normal akışta LockInventoryRows satırı zaten yaratmış ve kilitlemiştir.
     * Burada yaratma yalnızca kilitsiz tek-SKU çağrıları içindir; satır
     * kilitli gelmişse bu sorgu onu aynen döndürür.
     */
    private function currentLevel(string $tenantId, string $warehouseId, string $variantId): InventoryLevel
    {
        $level = InventoryLevel::query()
            ->where('tenant_id', $tenantId)
            ->where('warehouse_id', $warehouseId)
            ->where('variant_id', $variantId)
            ->first();

        if ($level !== null) {
            return $level;
        }

        return InventoryLevel::create([
            'tenant_id' => $tenantId,
            'warehouse_id' => $warehouseId,
            'variant_id' => $variantId,
            'on_hand' => 0,
            'reserved' => 0,
            'version' => 0,
        ]);
    }

    private function findExisting(string $tenantId, string $idempotencyKey): ?InventoryMovement
    {
        return InventoryMovement::query()
            ->where('tenant_id', $tenantId)
            ->where('idempotency_key', $idempotencyKey)
            ->first();
    }

    /**
     * Değişimi outbox'a yazar — aynı transaction içinde.
     *
     * Yayınlama YAPILMAZ: bu relay sürecinin işidir. published_at boş kalır.
     *
     * Yük, sürüm kapısının ihtiyaç duyduğu alanları taşır. Kanala gidecek
     * miktar burada HESAPLANMAZ ve SAKLANMAZ — kırpma giden dönüşümde,
     * OutboundQuantity içinde yapılır. Yük kanonik available'ı taşır.
     *
     * origin_connection_id TAŞINMAK ZORUNDA: fan-out tüketicisi anlık yankıyı
     * bu alanla bastırır. Woo siparişinden doğan değişimi Woo'ya geri yazmak
     * gereksiz bir tur ve gereksiz bir çakışma riskidir. Alan yükte yoksa
     * tüketici hiçbir kanalı eleyemez ve kaynak kanal da hedef olur.
     */
    private function recordOutboxEvent(InventoryLevel $level, InventoryMovement $movement): void
    {
        OutboxEvent::record(
            aggregateType: 'inventory_level',
            aggregateId: $level->id,
            eventType: 'InventoryLevelChanged',
            payload: [
                'warehouse_id' => $level->warehouse_id,
                'variant_id' => $level->variant_id,
                'on_hand' => $level->on_hand,
                'reserved' => $level->reserved,
                'available' => $level->available,   // kanonik, kırpılmamış
                'version' => $level->version,
                'movement_id' => $movement->id,
                'movement_type' => $movement->type->value,
                // Yankı bastırma çıpası — bir ENİYİLEME, doğruluk kuralı
                // değil: kanal otorite dışına ÇIKARILMAZ, mutabakat onu da
                // kontrol eder ve gerçek sürüklenmede onarım açar (§10).
                'origin_connection_id' => $movement->channel_connection_id,
            ],
            tenantId: $level->tenant_id,
        );
    }
}
