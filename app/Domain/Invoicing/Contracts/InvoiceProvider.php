<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Contracts;

use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Support\InvoiceDraft;
use App\Domain\Invoicing\Support\IssueOutcome;
use App\Domain\Invoicing\Support\ReturnDraft;
use App\Domain\Invoicing\Support\SubmitOptions;

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
 *
 * `SubmitOptions` satıcının tercihini taşır (e-belge kesilsin mi, tahsilat
 * hangi hesaba). `issueEDocument = false` iken `submit()` e-belge İSTEMEZ
 * ve satırı `issuing`'e geçirmez; kesimi iş (`IssueInvoice`) "muhasebeye
 * işlendi" olarak kapatır — `poll()` çağrılmaz.
 */
interface InvoiceProvider
{
    /** `$options` null = varsayılan: e-belge kes, tahsilat işleme. */
    public function submit(Invoice $invoice, InvoiceDraft $draft, ?SubmitOptions $options = null): void;

    public function poll(Invoice $invoice): IssueOutcome;

    /** Belgenin PDF adresi — süreli bağlantı; hazır değilse null. */
    public function pdfUrl(Invoice $invoice): ?string;

    /**
     * Kesilmiş satış faturasını entegratörde İPTAL eder (S6, ADR-007).
     *
     * `$sale` kesilmiş satış faturasıdır; `$cancellation` onun iptal satırı.
     * Senkrondur: dönerse iptal tamamdır. Daha önce iptal edilmişse yine
     * döner (yeniden deneme ikinci kez iptal istemez). İptal edilemiyorsa
     * (süre geçti, e-fatura) `InvoiceProviderException` VALIDATION.
     */
    public function cancel(Invoice $cancellation, Invoice $sale): void;

    /**
     * Kabul edilen iadeyi satış faturasının carisine KAYDEDER (S6).
     *
     * `submit` gibi kimliği ANINDA satıra yazar (`provider_invoice_id`):
     * iş ölürse yeniden deneme ikinci iade kaydı açmaz.
     */
    public function recordReturn(Invoice $return, Invoice $sale, ReturnDraft $draft): void;
}
