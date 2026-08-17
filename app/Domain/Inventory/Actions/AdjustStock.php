<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Actions;

use App\Domain\Inventory\Enums\MovementType;
use App\Domain\Inventory\Models\InventoryMovement;
use App\Domain\Inventory\Support\MovementKey;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Uid\UuidV7;

/**
 * Elle stok düzeltme — fazla satışın "düzeltme yolu".
 *
 * Mimari Karar Dokümanı v2.2 · §17 · P0 · "Fazla satış ekranı — eksik miktar
 * ve DÜZELTME YOLU gösterilmeli", §13 · faz 1.2.
 *
 * DEĞİŞMEZ KURAL — DÜZELTME DE LEDGER ÜZERİNDEN GEÇER:
 *   `inventory_levels` satırı DOĞRUDAN GÜNCELLENMEZ. Düzeltme bir
 *   MANUAL_ADJUSTMENT hareketidir; `on_hand = Σ on_hand_delta` eşitliği her
 *   koşulda korunur ve düzeltmeyi kimin ne zaman neden yaptığı ledger'da
 *   kalır. Projeksiyona doğrudan yazmak bu izi yok eder ve eşitliği bozar.
 *
 * DEĞİŞMEZ KURAL — TEK KİLİT SORGUSU:
 *   `LockInventoryRows` kullanılır. Tek SKU'da bile gereklidir: eşzamanlı bir
 *   sipariş alımı aynı satıra yazar ve kilit sırası tutarlı olmalıdır.
 *   `ApplyMovement` kendi kilidini ALMAZ; çağıranın alması ön koşuldur.
 *
 * MANUAL_ADJUSTMENT EKLER: yön hareket türünden gelir ve düzeltme sayım
 * farkını ekler. Eksiltme bu action ile YAPILMAZ — o iş uygun hareket
 * türüyle (SALE, TRANSFER_OUT) yapılır. Miktar bu yüzden pozitif olmalıdır.
 *
 * İDEMPOTENCY ANAHTARI HER ÇAĞRIDA YENİDİR ve bu bilinçlidir: düzeltme
 * kullanıcının açık eylemidir, iki ayrı sayım iki ayrı düzeltmedir. Siparişte
 * çıpa dış olay kimliğidir; burada öyle bir kimlik yoktur ve uydurmak
 * satıcının bilerek yaptığı ikinci düzeltmeyi sessizce yutardı.
 */
final class AdjustStock
{
    public function __construct(
        private readonly LockInventoryRows $lockRows,
        private readonly ApplyMovement $applyMovement,
    ) {}

    public function run(
        string $warehouseId,
        string $variantId,
        int $quantity,
        ?string $note = null,
        ?string $actorId = null,
    ): InventoryMovement {
        // Miktar doğrulaması ApplyMovement'ta da var; buradaki kontrol
        // çağıranın niyetini netleştirir: eksiltme bu yoldan yapılmaz.
        if ($quantity <= 0) {
            throw new \InvalidArgumentException(
                "Düzeltme miktarı pozitif olmalıdır, {$quantity} verildi. ".
                'Eksiltme için uygun hareket türü kullanılır.'
            );
        }

        return DB::transaction(function () use (
            $warehouseId, $variantId, $quantity, $note, $actorId,
        ): InventoryMovement {
            // Kilit ÖNCE: ApplyMovement kilitli satır bekler.
            $this->lockRows->run($warehouseId, [$variantId]);

            return $this->applyMovement->run(
                warehouseId: $warehouseId,
                variantId: $variantId,
                type: MovementType::MANUAL_ADJUSTMENT,
                quantity: $quantity,
                idempotencyKey: MovementKey::manualAdjustment((string) new UuidV7),
                sourceType: 'panel_adjustment',
                sourceId: $actorId,
                note: $note,
            );
        });
    }
}
