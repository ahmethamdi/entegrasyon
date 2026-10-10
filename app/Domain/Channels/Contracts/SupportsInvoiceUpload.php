<?php

declare(strict_types=1);

namespace App\Domain\Channels\Contracts;

use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Orders\Models\Order;

/**
 * Fatura yükleme yeteneği — kesilen faturanın dosyası siparişin kanalına gider.
 *
 * Pazaryeri faturayı alıcıya KENDİ gösterir (Trendyol "Faturam" bağlantısı)
 * ve satıcının her pakete fatura eklemesini bekler. Fatura entegratörde
 * kesilir (`SupportsInvoiceData` + `InvoiceProvider`); bu yetenek yalnız
 * hazır PDF'i kanala taşır.
 *
 * DOSYA BELLEKTE GELİR: çağıran (iş) PDF'i entegratörden indirip `$pdf`
 * olarak verir. Adapter onu diske, veritabanına ya da günlüğe YAZMAZ —
 * `ChannelHttpClient::upload()` günlüğe yalnız alan adlarını ve bayt
 * sayısını düşer.
 *
 * Adapter yan etkisizdir (§7): durum satırına `UploadInvoiceToChannel`
 * yazar. Başarısızlık İSTİSNA olarak yükselir; sınıfı `classifyError()`
 * verir, kararı `RetryPolicy`.
 */
interface SupportsInvoiceUpload
{
    /** Kanalın kabul ettiği en büyük dosya (bayt) — iş indirmeyi bununla sınırlar. */
    public function maxInvoiceFileBytes(): int;

    public function uploadInvoice(Order $order, Invoice $invoice, string $pdf, string $filename): AdapterResult;
}
