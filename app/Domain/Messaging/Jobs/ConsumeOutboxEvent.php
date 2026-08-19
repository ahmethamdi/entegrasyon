<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Jobs;

use App\Domain\Messaging\Consumers\InventoryLevelChangedConsumer;
use App\Domain\Messaging\Consumers\ListingResyncRequestedConsumer;
use App\Domain\Messaging\Consumers\VariantPriceChangedConsumer;
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

            // §9 · error_permanent'tan çıkış. BU DAL OLMADAN resync olayı
            // "tanınmayan tür" sayılır, sessizce consumed damgalanır ve
            // kullanıcının düzeltmesi hiçbir iş üretmez — durum pending
            // görünür ama kanala hiçbir şey gitmez.
            'ListingResyncRequested' => app(ListingResyncRequestedConsumer::class)->handle($event),

            // §7 · fiyat senkronu. Bu dal olmadan fiyat olayı "tanınmayan
            // tür" sayılır, sessizce consumed damgalanır ve panelden yapılan
            // fiyat düzeltmesi kanala HİÇ gitmez.
            'VariantPriceChanged' => app(VariantPriceChangedConsumer::class)->handle($event),

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
