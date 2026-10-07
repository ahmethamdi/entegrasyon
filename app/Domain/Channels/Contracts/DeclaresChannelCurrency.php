<?php

declare(strict_types=1);

namespace App\Domain\Channels\Contracts;

/**
 * Kanalın fiyatları hangi para biriminde kabul ettiği.
 *
 * Panel kanal fiyatı alanının yanına bu birimi yazar ve satıcının girdiği
 * fiyat bu birimle saklanır (`listings.channel_price_currency`). Bildirmeyen
 * kanalda (Shopify, Woo) birim ürünün kendi birimi sayılır.
 *
 * Yetenek `instanceof` ile okunur; panelde kanal adı kontrol edilmez.
 */
interface DeclaresChannelCurrency
{
    /**
     * ISO 4217 kodu; bilinmiyor ya da okunamadıysa null.
     *
     * Ağ çağrısı yapabilir (Etsy mağaza birimini bir kez okur, sonra saklar).
     */
    public function channelCurrency(): ?string;
}
