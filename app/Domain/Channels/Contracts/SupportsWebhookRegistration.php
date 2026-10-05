<?php

declare(strict_types=1);

namespace App\Domain\Channels\Contracts;

/**
 * Webhook aboneliğini KANALDA kendisi kurabilen adapter.
 *
 * Siparişini webhook'la gönderen kanal (`supports_webhooks = true`) sipariş
 * yoklamasından ÇIKARILIR (`PollChannelOrders`). Abonelik kanalda yoksa o
 * bağlantıya hiçbir sipariş GELMEZ — ve hiçbir yer hata vermez: stok ve
 * ürün gönderimi çalıştığı için bağlantı panelde yeşil görünür.
 *
 * Satıcıdan elle webhook kurmasını beklemek bu hatayı ürünün varsayılanı
 * yapıyordu (5 Eki 2026: Woo formunda imza anahtarı alanı bile yoktu, adres
 * panelde hiç gösterilmiyordu). Bu yüzden kurulum bizim işimizdir.
 *
 * İDEMPOTENT OLMALI: bağlantı yeniden kurulduğunda (anahtar yenileme akışı)
 * yeniden çağrılır. İkinci çağrı kopya abonelik AÇMAMALI — Woo/Shopify aynı
 * olayı her kopyaya ayrı gönderir; tekilleştirme kopyayı ele alır ama kanal
 * kotası ve teslim kuyruğu boşuna dolar.
 */
interface SupportsWebhookRegistration
{
    /**
     * @param  string  $deliveryUrl  Bu bağlantının alıcı adresi (`webhooks.receive`)
     * @param  string  $secret  İmza anahtarı — kanal kendi anahtarını
     *                          dayatıyorsa (Shopify uygulama sırrı) yok sayılır
     */
    public function registerWebhooks(string $deliveryUrl, string $secret): AdapterResult;
}
