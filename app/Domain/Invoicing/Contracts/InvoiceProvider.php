<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Contracts;

use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Support\InvoiceDraft;
use App\Domain\Invoicing\Support\IssueOutcome;

/**
 * Fatura entegratörü — Paraşüt ilk, sonra BirFatura / EDM.
 *
 * İKİ AŞAMA, çünkü e-belge oluşturma entegratörde ASENKRONDUR (GİB'e
 * imzalı gönderim saniyeler-dakikalar sürer):
 *   1. `submit()` — fatura taslağını açar ve e-belge isteğini gönderir
 *   2. `poll()`   — sonucu sorar; sürüyorsa iş biraz sonra yeniden sorar
 *
 * ⚠️ KANAL ADAPTER'LARININ AKSİNE `submit()` SATIRA YAZAR — bilinçli:
 * her entegratör adımının kimliği (cari, fatura, iş) adım biter bitmez
 * `Invoice` satırına kaydedilir. İş adımlar arasında ölürse yeniden deneme
 * KALDIĞI YERDEN devam eder; hepsi sonda yazılsaydı ikinci deneme ikinci
 * bir satış faturası, daha kötüsü ikinci bir RESMÎ e-belge açardı (GİB'de
 * iptali ayrı süreçtir).
 */
interface InvoiceProvider
{
    public function submit(Invoice $invoice, InvoiceDraft $draft): void;

    public function poll(Invoice $invoice): IssueOutcome;

    /** Belgenin PDF adresi — süreli bağlantı; hazır değilse null. */
    public function pdfUrl(Invoice $invoice): ?string;
}
