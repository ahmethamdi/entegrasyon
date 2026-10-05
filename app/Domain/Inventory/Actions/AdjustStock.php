<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Actions;

use App\Domain\Identity\Actions\RecordAuditLog;
use App\Domain\Identity\Enums\AuditAction;
use App\Domain\Inventory\Enums\MovementType;
use App\Domain\Inventory\Models\InventoryLevel;
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
 * `run()` EKLER (MANUAL_ADJUSTMENT, pozitif miktar). Panelin asıl yolu
 * `setTo()`'dur: sayım değeri verilir, fark kilit altında hesaplanır ve
 * eksi farkta MANUAL_REDUCTION yazılır.
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
        private readonly RecordAuditLog $audit,
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

            $movement = $this->applyMovement->run(
                warehouseId: $warehouseId,
                variantId: $variantId,
                type: MovementType::MANUAL_ADJUSTMENT,
                quantity: $quantity,
                idempotencyKey: MovementKey::manualAdjustment((string) new UuidV7),
                sourceType: 'panel_adjustment',
                sourceId: $actorId,
                note: $note,
            );

            // DENETİM KAYDI (§11): "elle stok düzeltme" §11'in altı
            // olayından biridir ve anlaşmazlıkta ilk sorulan sorudur —
            // bakiye neden değişti, kim değiştirdi.
            //
            // Ledger hareketi tek başına yetmez: `inventory_movements`
            // NİCELİĞİ taşır, denetim kaydı AKTÖRÜ ve bağlamı taşır
            // (kullanıcı, IP, not). Aynı transaction içindedir — düzeltme
            // geri alınırsa kaydı da geri alınmalıdır.
            $this->audit->run(
                action: AuditAction::STOCK_ADJUSTED,
                subjectType: 'variants',
                subjectId: $variantId,
                changes: [
                    'warehouse_id' => $warehouseId,
                    'quantity' => $quantity,
                    'movement_id' => $movement->id,
                    'note' => $note,
                ],
                userId: $actorId,
            );

            return $movement;
        });
    }

    /**
     * SAYIM: "rafta X var" — bakiye X'e getirilir.
     *
     * Satıcının düşündüğü işlem budur; "kaç ekleyeyim" hesabını ona
     * yaptırmak hem yanlış girişe davet eder hem de eksiltmeyi imkânsız
     * kılıyordu. Fark KİLİT ALTINDA hesaplanır: okuma ile yazma arasında
     * gelen bir sipariş farkı bayatlatırdı ve sayım satışı geri getirirdi.
     *
     * Fark sıfırsa hareket YAZILMAZ (null) — anlamsız ledger satırı ve
     * kanala boşuna gönderim olmaz. Eksi fark `MANUAL_REDUCTION`'dır.
     */
    public function setTo(
        string $warehouseId,
        string $variantId,
        int $target,
        ?string $note = null,
        ?string $actorId = null,
    ): ?InventoryMovement {
        if ($target < 0) {
            throw new \InvalidArgumentException("Sayım değeri negatif olamaz, {$target} verildi.");
        }

        return DB::transaction(function () use ($warehouseId, $variantId, $target, $note, $actorId): ?InventoryMovement {
            $this->lockRows->run($warehouseId, [$variantId]);

            $onHand = (int) InventoryLevel::query()
                ->where('warehouse_id', $warehouseId)
                ->where('variant_id', $variantId)
                ->value('on_hand');

            $delta = $target - $onHand;

            if ($delta === 0) {
                return null;
            }

            $movement = $this->applyMovement->run(
                warehouseId: $warehouseId,
                variantId: $variantId,
                type: $delta > 0 ? MovementType::MANUAL_ADJUSTMENT : MovementType::MANUAL_REDUCTION,
                quantity: abs($delta),
                idempotencyKey: MovementKey::manualAdjustment((string) new UuidV7),
                sourceType: 'panel_count',
                sourceId: $actorId,
                note: $note,
            );

            $this->audit->run(
                action: AuditAction::STOCK_ADJUSTED,
                subjectType: 'variants',
                subjectId: $variantId,
                changes: [
                    'warehouse_id' => $warehouseId,
                    'from' => $onHand,
                    'to' => $target,
                    'quantity' => $delta,
                    'movement_id' => $movement->id,
                    'note' => $note,
                ],
                userId: $actorId,
            );

            return $movement;
        });
    }
}
