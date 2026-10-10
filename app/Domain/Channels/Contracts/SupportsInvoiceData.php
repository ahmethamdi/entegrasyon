<?php

declare(strict_types=1);

namespace App\Domain\Channels\Contracts;

use App\Domain\Invoicing\Support\InvoiceDraft;
use App\Domain\Orders\Models\Order;

/**
 * Fatura verisi yeteneği — alıcı ve kalemler kanaldan ANLIK okunur.
 *
 * Sipariş alımı alıcının kişisel verisini bilerek saklamaz
 * (`PersonalDataMask`); fatura ise ad, adres ve TCKN/VKN olmadan
 * kesilemez. Bu yetenek ikisini uzlaştırır: veri fatura anında kanaldan
 * çekilir, entegratöre gider ve hiçbir yere yazılmaz.
 *
 * Kalemler de kanaldan okunur, `order_lines`'tan DEĞİL: KDV oranı orada
 * yoktur ve faturadaki tutar kanalın tahsil ettiği tutarla birebir
 * aynı olmalıdır.
 *
 * Yanıt alınamazsa istisna fırlatır (iş sınıflandırıp yeniden dener);
 * faturalanamayacak sipariş (iptal, alıcı verisi yok) için
 * `InvoiceDataUnavailable` fırlatır — kalıcıdır, yeniden denenmez.
 */
interface SupportsInvoiceData
{
    public function fetchInvoiceDraft(Order $order): InvoiceDraft;
}
