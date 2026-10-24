<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Contracts;

use App\Domain\Invoicing\Models\Invoice;

/**
 * PDF'i süreli bağlantı yerine DOSYANIN KENDİSİ olarak veren entegratör.
 *
 * Paraşüt PDF için süreli bir adres verir (`InvoiceProvider::pdfUrl`);
 * Nilvera ise dosyayı doğrudan döner. Bu sağlayıcılarda `pdfUrl` null
 * döner ve PDF'e uzanan her yol (sipariş ekranı, kalıcı bağlantı, kanala
 * yükleme) ÖNCE bu arayüzü sorar — sormasaydı "PDF henüz hazır değil"
 * sanılıp hiç gelmeyecek bir bağlantı beklenirdi.
 */
interface ProvidesPdfContent
{
    /**
     * Belgenin PDF'i; belge henüz yoksa null.
     *
     * `$maxBytes` aşılırsa ya da gelen dosya PDF değilse
     * `InvoiceProviderException` VALIDATION.
     */
    public function pdfContent(Invoice $invoice, int $maxBytes): ?string;
}
