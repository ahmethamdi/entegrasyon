<?php

declare(strict_types=1);

namespace App\Domain\Reconciliation\Actions;

use App\Domain\Inventory\Models\InventoryLevel;
use App\Domain\Reconciliation\Enums\ItemStatus;
use App\Domain\Reconciliation\Models\ReconciliationItem;
use App\Domain\Sync\Actions\OpenSyncOperation;
use App\Domain\Sync\Enums\SyncDomain;
use App\Domain\Sync\Enums\SyncIntent;
use App\Domain\Sync\Jobs\PushInventory;
use App\Domain\Sync\Models\SyncOperation;

/**
 * Sürüklenme bulunan kalem için onarım operasyonu açar.
 *
 * Mimari Karar Dokümanı v2.2 · §10 · Onarım çağrısı, §8 · sürüm kapısı.
 *
 * DEĞİŞMEZ KURAL — SÜRÜM KAPISI ATLANIR:
 *   Mutabakat uzak durumu OKUMUŞ ve farkı KANITLAMIŞTIR; "bu sürüm zaten
 *   gönderildi" bilgisi burada yanlıştır. Normal kapı bu operasyonu elerdi
 *   ve sürüklenme kalıcı olurdu.
 *
 * DEĞİŞMEZ KURAL — `desired_version` ARTIRILMAZ:
 *   Aynı iş sürümü yeniden gönderilir. Yapay sürüm artışı sıra dışı olay
 *   elemesini bozar ve gerçek bir değişikliği bayat gösterir.
 *
 * DEĞİŞMEZ KURAL — ANAHTAR KALEM KİMLİĞİNİ TAŞIR:
 *   `inv:{listing}:{version}:repair:{item_id}` — aynı kalem iki kez işlense
 *   TEK operasyon oluşur. Biçim normal anahtarla çakışmaz: aynı listing ve
 *   sürüm için hem normal hem onarım operasyonu birlikte yaşayabilir; onarım
 *   devam eden normal akışı İPTAL ETMEZ.
 */
final class QueueRepair
{
    public function __construct(
        private readonly OpenSyncOperation $openSyncOperation,
    ) {}

    public function run(ReconciliationItem $item): ?SyncOperation
    {
        $listing = $item->listing;

        if ($listing === null) {
            return null;
        }

        // Kanonik iş sürümü — YAPAY OLARAK ARTIRILMAZ.
        $version = $this->currentVersionFor($listing->variant_id);

        $operation = $this->openSyncOperation->run(
            listing: $listing,
            domain: SyncDomain::INVENTORY,
            eventVersion: $version,
            intent: SyncIntent::REPAIR,          // ← sürüm kapısı atlanır
            sourceEvent: null,                   // outbox kaynaklı değil
            reconciliationItemId: $item->id,     // anahtarı tekilleştirir
        );

        if ($operation === null) {
            return null;
        }

        $item->forceFill([
            'status' => ItemStatus::REPAIR_QUEUED->value,
            'repair_operation_id' => $operation->id,
        ])->save();

        // İş EN SONDA atılır: kuyruk kancaları her iş sınırında kiracı
        // bağlamını temizler ve iş kendi bağlamını yükten kurar.
        PushInventory::dispatch($operation->id, $operation->tenant_id)
            ->onQueue('inventory:high');

        return $operation;
    }

    /**
     * Varyantın kanonik stok sürümü.
     *
     * Sürüm projeksiyondan okunur; mutabakat onu ilerletmez.
     */
    private function currentVersionFor(string $variantId): int
    {
        return (int) (InventoryLevel::query()
            ->where('variant_id', $variantId)
            ->value('version') ?? 0);
    }
}
