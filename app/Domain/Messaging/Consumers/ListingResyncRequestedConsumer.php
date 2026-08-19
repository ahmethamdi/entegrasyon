<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Consumers;

use App\Domain\Messaging\Models\OutboxEvent;
use App\Domain\Sync\Actions\OpenSyncOperation;
use App\Domain\Sync\Enums\SyncDomain;
use App\Domain\Sync\Enums\SyncIntent;
use App\Domain\Sync\Jobs\PushInventory;
use App\Domain\Sync\Jobs\PushListing;
use App\Domain\Sync\Models\Listing;
use App\Domain\Sync\Models\SyncOperation;
use Illuminate\Support\Facades\Log;

/**
 * Resync talebi tüketicisi — mevcut akışı yeniden kullanır.
 *
 * Mimari Karar Dokümanı v2.2 · §9 · error_permanent durumundan çıkış,
 * §1 · Karar 18, §18 · T10.
 *
 * YENİ BİR TESLİM YOLU KURULMAZ: olay aynı outbox relay'inden geçer, aynı
 * `OpenSyncOperation` ile operasyona dönüşür ve aynı push işleriyle gönderilir.
 * Ayrı bir yol açılsaydı sürüm kapısı, idempotency ve deneme muhasebesi iki
 * yerde yaşar ve biri unutulurdu.
 *
 * NİYET REPAIR — SÜRÜM KAPISI ATLANIR VE BU MADDENİN ÖZÜDÜR.
 * Kanonik veri değişmediği için talebin taşıdığı sürüm zaten gönderilmiş
 * olabilir (`synced_version >= current_version`). NORMAL_SYNC niyetiyle
 * açılsaydı kapı operasyonu SESSİZCE eler, kullanıcının "yeniden dene"si
 * hiçbir şey yapmaz ve o satır sonsuza kadar hata durumunda kalırdı. REPAIR
 * niyeti kapıyı atlar ve `desired_version`'ı ARTIRMAZ — ikisi de §8'in mevcut
 * kurallarıdır ve mutabakatın onarım yolu tam olarak bunu yapar.
 *
 * ÇIPA OLAY KİMLİĞİDİR. REPAIR kapıyı atladığı için anahtar tekilliği "aynı
 * tetik iki kez işlenirse tek operasyon" garantisini taşıyan tek mekanizmadır.
 * Mutabakat kalemi kimliğinden AYRI bir ön ek kullanılır (`resync:`): tek ön ek
 * paylaşsalardı iki farklı tetikten biri sessizce yutulabilirdi.
 */
final class ListingResyncRequestedConsumer
{
    public function __construct(
        private readonly OpenSyncOperation $openSyncOperation,
    ) {}

    public function handle(OutboxEvent $event): void
    {
        $payload = $event->payload;

        $listing = Listing::query()->find($payload['listing_id']);

        // CANLI OLMAYAN LISTING İŞ ÜRETMEZ ama olay CONSUMED damgalanır:
        // damgalanmazsa seviye 1 bütünlük taraması onu kayıp sanar ve sonsuza
        // kadar yeniden yayınlar. Canlı olmayan satıra gönderim her turda hata
        // alırdı — kanalda karşılığı yoktur.
        if ($listing === null || ! $listing->isLive()) {
            $event->markConsumed(operationsPlanned: 0);

            return;
        }

        $domain = SyncDomain::from($payload['domain']);

        $operation = $this->openSyncOperation->run(
            listing: $listing,
            domain: $domain,
            // Sürüm YÜKTEN okunur, yeniden hesaplanmaz: iş kuyrukta beklerken
            // kanonik sürüm değişmiş olabilir ve o değişiklik KENDİ olayını
            // doğurmuştur. Yeniden hesaplayan tüketici, gönderilmemiş bir
            // değişikliği bu talebe iliştirirdi.
            eventVersion: (int) $payload['current_version'],
            intent: SyncIntent::REPAIR,
            sourceEvent: $event,
            resyncAnchor: $event->id,
        );

        // (1) PLANLAMA BİTTİ — downstream başarısını BEKLEMEZ.
        $event->markConsumed(operationsPlanned: $operation !== null ? 1 : 0);

        if ($operation === null) {
            return;
        }

        // (2) İŞ EN SONDA ATILIR: kuyruk kancaları her iş sınırında kiracı
        //     bağlamını temizler ve `sync` sürücüde iş DERHAL çalışır.
        $this->dispatchFor($operation, $domain, $event->tenant_id);
    }

    /**
     * Alan başına doğru iş.
     *
     * Bilinmeyen alan için iş ATILMAZ ve gürültü çıkarılır: yanlış iş yanlış
     * yükü gönderir, sessizce yutmak operasyonu sonsuza kadar takılı bırakır.
     * `DetectStuckSyncOperations` ile aynı davranış biçimi.
     *
     * PRICE burada YOK çünkü çekirdekte fiyat itme yolu (PushPrices) hiç
     * yazılmadı — adapter gövdeleri hazır ama çağıranı yok. Davranış dürüst:
     * operasyon açılır, iş atılmaz ve uyarı yazılır.
     */
    private function dispatchFor(SyncOperation $operation, SyncDomain $domain, string $tenantId): void
    {
        match ($domain) {
            SyncDomain::CONTENT => PushListing::dispatch($operation->id, $tenantId)
                ->onQueue('listing:default'),

            SyncDomain::INVENTORY => PushInventory::dispatch($operation->id, $tenantId)
                ->onQueue('inventory:high'),

            default => Log::warning('sync.resync_no_job_for_domain', [
                'operation' => $operation->id,
                'domain' => $domain->value,
            ]),
        };
    }
}
