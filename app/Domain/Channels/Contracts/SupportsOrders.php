<?php

declare(strict_types=1);

namespace App\Domain\Channels\Contracts;

use App\Domain\Messaging\Models\InboxMessage;
use App\Domain\Orders\Models\Order;
use App\Domain\Sync\Support\NormalizedOrderEvent;
use App\Domain\Sync\Support\OrderPage;
use Carbon\CarbonInterface;

/**
 * Sipariş alma yeteneği.
 *
 * Mimari Karar Dokümanı v2.2 · §7, §1 · Karar 24.
 *
 * parseOrderEvent() olayın TİPİNİ de belirler: created / updated / cancelled /
 * returned ayrı yollara gider (OrderEventRouter). Tek yola sokulsaydı iptal ve
 * iade siparişin yeniden yaratılması gibi işlenirdi.
 */
interface SupportsOrders
{
    /** Yoklama ile sipariş çeker; inbox'a yazılmak üzere ham olay döner. */
    public function fetchOrders(CarbonInterface $since, ?string $cursor = null): OrderPage;

    /**
     * Yoklanan ham siparişin OLAY KİMLİĞİ — inbox tekilleştirmesinin çıpası.
     *
     * ⚠️ BU METOT ADAPTER'DADIR ÇÜNKÜ KİMLİK ALANININ ADI KANALIN
     * ŞEKLİDİR. Trendyol `orderNumber`, Woo `id`, Etsy `receipt_id`
     * kullanır. Çekirdekte tek bir `??` zinciri olarak tutulsaydı her yeni
     * kanalda o satır uzar, biri eklenmeyi unutur ve kimlik SESSİZCE
     * `null` dönerdi.
     *
     * ⚠️ `null` DÖNMEK MESAJI KAYBETTİRMEZ ama KORUMAYI ZAYIFLATIR:
     * tekilleştirme `payload_hash + saatlik pencere` yoluna düşer ve o yol
     * saat sınırında bölünür. Asıl tehlike şudur — aynı siparişin ardından
     * gelen İPTALİ, gövdesi farklı olduğu için yeni satır sayılır ama
     * kimliğe bağlı olmadığı için sıra garantisi yoktur.
     *
     * ⚠️ KİMLİK DURUMU DA TAŞIMALIDIR. Yalnızca sipariş numarasına
     * bağlansaydı aynı siparişin sonraki İPTALİ birincil tekillik
     * indeksine (`channel_connection_id`, `external_event_id`) takılır ve
     * `insertOrIgnore` tarafından SESSİZCE YUTULURDU — stok geri
     * eklenmez, bakiye kalıcı olarak eksik kalırdı (§1 · Karar 24).
     *
     * ⚠️ YOKLAMA YAPMAYAN KANAL BU METODU DA UYGULAMAZ ve İSTİSNA
     * FIRLATIR — `fetchOrders()` ile AYNI gerekçe: sessizce `null` dönmek,
     * yoklama turunun `supports_webhooks` kapısını atladığını GİZLERDİ.
     *
     * @param  array<string, mixed>  $order  `fetchOrders()`'ın döndürdüğü ham gövde
     */
    public function pollingEventIdFor(array $order): ?string;

    /** Ham gövdeyi kanonik olaya çevirir — tip dahil. */
    public function parseOrderEvent(InboxMessage $message): ?NormalizedOrderEvent;

    public function acknowledgeOrder(Order $order): AdapterResult;
}
