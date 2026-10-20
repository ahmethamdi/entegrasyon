<?php

declare(strict_types=1);

namespace App\Domain\Channels\Contracts;

use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Orders\Models\Order;

/**
 * Fatura BAĞLANTISI yeteneği — `SupportsInvoiceUpload`'ın eşi (S6).
 *
 * Hepsiburada ve n11 dosya almaz, alıcıya gösterecekleri kalıcı bir adres
 * ister. Entegratörün PDF bağlantısı sürelidir; kanala BİZİM vekil
 * adresimiz gider (`InvoicePublicLink`): istek gelince taze bağlantı
 * istenir ve yönlendirilir, dosya bizde durmaz.
 *
 * Adapter yan etkisizdir (§7): durum satırına `UploadInvoiceToChannel`
 * yazar. Başarısızlık İSTİSNA ya da `AdapterResult::failure` olarak
 * yükselir; sınıfı `classifyError()` verir.
 */
interface SupportsInvoiceLink
{
    public function sendInvoiceLink(Order $order, Invoice $invoice, string $url): AdapterResult;
}
