<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Jobs;

use App\Domain\Messaging\Consumers\InventoryLevelChangedConsumer;
use App\Domain\Messaging\Models\OutboxEvent;
use App\Support\Tenancy\TenantAwareJob;
use Illuminate\Support\Facades\Log;

/**
 * Yayınlanmış bir outbox olayını tüketiciye yönlendirir.
 *
 * Mimari Karar Dokümanı v2.2 · §6 · Outbox / Inbox.
 *
 * Bağlam yükten kurulur (TenantAwareJob): relay sistem bağlamında çalışır ama
 * tüketici kiracı bağlamında çalışmak zorundadır, yoksa fan-out sorgusu
 * tenant scope'suz kalır.
 *
 * İş yalnızca MODEL taşımaz, kimlik taşır: olay yükü büyük olabilir ve
 * serileştirilmiş model kuyruğu şişirir; ayrıca iş kuyrukta beklerken olay
 * güncellenmiş olabilir.
 */
final class ConsumeOutboxEvent extends TenantAwareJob
{
    public function __construct(
        string $tenantId,
        public readonly string $outboxEventId,
    ) {
        parent::__construct($tenantId);
    }

    protected function handleForTenant(): void
    {
        $event = OutboxEvent::query()->find($this->outboxEventId);

        if ($event === null) {
            // Olay silinmiş — temizlik işi yayınlanmışları yedi gün sonra
            // siler. Yeniden denemek anlamsızdır.
            Log::warning('outbox.event_missing', ['event' => $this->outboxEventId]);

            return;
        }

        if ($event->isConsumed()) {
            // Çökme senaryosunda olay iki kez yayınlanmış olabilir; ikinci
            // tur sessizce çıkar. Tüketici zaten idempotenttir, bu yalnızca
            // gereksiz işi eler.
            return;
        }

        match ($event->event_type) {
            'InventoryLevelChanged' => app(InventoryLevelChangedConsumer::class)->handle($event),

            // Tanınmayan olay türü consumed damgalanır: aksi halde seviye 1
            // bütünlük taraması onu sonsuza kadar yeniden yayınlardı.
            default => $this->skipUnknown($event),
        };
    }

    private function skipUnknown(OutboxEvent $event): void
    {
        Log::warning('outbox.unknown_event_type', [
            'event' => $event->id,
            'type' => $event->event_type,
        ]);

        $event->markConsumed(operationsPlanned: 0);
    }
}
