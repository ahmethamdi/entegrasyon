<?php

declare(strict_types=1);

namespace App\Domain\Channels\Contracts;

use App\Domain\Orders\Models\Order;
use App\Domain\Shipping\Exceptions\RecipientUnavailable;
use App\Domain\Shipping\Support\ShipmentRecipient;

/**
 * Kargo alıcısı yeteneği — teslimat adresi kanaldan ANLIK okunur.
 *
 * `SupportsInvoiceData` ile aynı uzlaşma: sipariş alımı alıcının kişisel
 * verisini saklamaz, kargo firması ise ad/adres/telefon olmadan gönderi
 * açmaz. Veri gönderi açılırken kanaldan çekilir, firmaya gider, hiçbir
 * yere yazılmaz.
 *
 * Fatura adresi DEĞİL, teslimat adresi okunur (ikisi farklı olabilir).
 *
 * Yanıt alınamazsa istisna fırlatır (iş sınıflandırıp yeniden dener);
 * gönderilemeyecek sipariş (iptal, adres yok, veri izni yok) için
 * `RecipientUnavailable` fırlatır — kalıcıdır, yeniden denenmez.
 *
 * Trendyol/HB/n11 bu yeteneği UYGULAMAZ: pazar yerinin anlaşmalı kargosu
 * kullanılır (S2 ortak etiket). Satıcının kendi sözleşmesi kendi sitesi
 * (Shopify, WooCommerce) içindir.
 */
interface SupportsShippingRecipient
{
    /** @throws RecipientUnavailable */
    public function fetchShippingRecipient(Order $order): ShipmentRecipient;
}
