<?php

declare(strict_types=1);

namespace App\Domain\Sync\Support;

use App\Domain\Channels\Contracts\SupportsOfferLifecycle;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Registry\AdapterRegistry;
use App\Domain\Sync\Jobs\PushListing;
use App\Domain\Sync\Jobs\PushOfferListing;

/**
 * "Hangi içerik işi atılır" sorusunun TEK KAYNAĞI.
 *
 * V3.0 · §03 · Delta 1 · §13.
 *
 * ═════════════════════════════════════════════════════════════════════
 * NEDEN AYRI BİR SINIF — KARAR İKİ YERDE YAŞAYAMAZ
 * ═════════════════════════════════════════════════════════════════════
 * İçerik işi İKİ yerden atılır: panelden gönderim (`PublishListing`) ve
 * yeniden deneme (`ListingResyncRequestedConsumer`). Yetenek kontrolü
 * ikisine de KOPYALANSAYDI biri güncellenip öteki eski kalırdı ve
 * sonucu SESSİZ olurdu: eBay listing'i `/failures` ekranından yeniden
 * denendiğinde `PushListing`'e düşer, o iş `SupportsCatalog`
 * bulamayınca operasyonu `channel_lacks_catalog_capability` diye ATLAR
 * — satır "denendi" görünür ama kanala HİÇ gitmez.
 *
 * Projede bu hata biçimi defalarca yaşandı ("hazır mı" mantığı
 * `PrerequisiteGate` içinde TEK kaynaktır; `ResolveChannelPrice`
 * gönderim ve mutabakat için AYNI cevabı verir).
 *
 * ⚠️ KANAL ADI SORULMAZ — yetenek `instanceof` ile okunur.
 * `if ($code === 'ebay')` yazılsaydı ikinci çok adımlı kanal (Amazon
 * SP-API, §03 · Delta 1) eklendiğinde o satır uzar ve biri eklemeyi
 * unutunca kanal SESSİZCE yanlış işe düşerdi.
 *
 * ⚠️ KUYRUK AYNIDIR (`listing:default`). İki iş de İÇERİK aktarımıdır
 * ve §15'in kuyruk tablosunda `PushOfferListing` için ayrı bir satır
 * YOKTUR; uydurma bir kuyruk adı işin Redis'te sonsuza kadar beklemesi
 * demektir ve hiçbir hata görünmez.
 */
final class ContentPushDispatcher
{
    /** §15 · kuyruk tablosu — içerik aktarımı. */
    public const QUEUE = 'listing:default';

    public function __construct(
        private readonly AdapterRegistry $registry,
    ) {}

    /**
     * Operasyonu doğru içerik işine yollar.
     *
     * ⚠️ KİRACI KİMLİĞİ YÜKTE TAŞINIR: worker'da bağlam YOKTUR ve işin
     * kendisi kurmak zorundadır (§11 · P0).
     */
    public function dispatch(string $operationId, string $tenantId, ChannelConnection $connection): void
    {
        if ($this->usesOfferLifecycle($connection)) {
            PushOfferListing::dispatch($operationId, $tenantId)->onQueue(self::QUEUE);

            return;
        }

        PushListing::dispatch($operationId, $tenantId)->onQueue(self::QUEUE);
    }

    /**
     * Kanal çok adımlı yayın mı kullanıyor?
     *
     * Ayrı bir metot çünkü çağıranın bazen yalnızca SORUYU sorması
     * gerekir (test, panel rozeti); iş atmadan cevabı almak mümkün
     * olmalıdır.
     */
    public function usesOfferLifecycle(ChannelConnection $connection): bool
    {
        return $this->registry->for($connection) instanceof SupportsOfferLifecycle;
    }
}
