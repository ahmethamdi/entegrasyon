<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Providers\Nilvera;

use App\Domain\Catalog\Models\ExchangeRate;
use App\Domain\Invoicing\Contracts\InvoiceProvider;
use App\Domain\Invoicing\Contracts\ProvidesPdfContent;
use App\Domain\Invoicing\Exceptions\InvoiceProviderException;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Support\InvoiceBuyer;
use App\Domain\Invoicing\Support\InvoiceDraft;
use App\Domain\Invoicing\Support\InvoiceLine;
use App\Domain\Invoicing\Support\IssueOutcome;
use App\Domain\Invoicing\Support\ReturnDraft;
use App\Domain\Invoicing\Support\SubmitOptions;
use App\Domain\Sync\Enums\ErrorClass;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Str;

/**
 * Nilvera ile e-belge — mükellef sorgusu → e-fatura ya da e-arşiv → durum.
 *
 * Kaynak: https://developer.nilvera.com (OAS 3, 24.10.2026). Uçlar:
 *   · `general/GlobalCompany/Check/TaxNumber/{vkn}`  alıcı e-fatura mükellefi mi
 *   · `einvoice/Send/Model`   e-fatura (TEMELFATURA, alıcının etiketine)
 *   · `earchive/Send/Model`   e-arşiv (internet satışı bilgisiyle)
 *   · `…/Status`, `…/Details`, `…/pdf`, `earchive/Invoices/{uuid}/Cancel`
 *
 * ⚠️ ETTN (UUID) BİZDE ÜRETİLİR VE İSTEKTEN ÖNCE SATIRA YAZILIR
 * (`provider_job_id`). Nilvera gönderimi senkron yanıtlar; iş yanıt ile
 * kayıt arasında ölürse yeniden deneme AYNI ETTN'yi gönderir ve Nilvera
 * 409 "ETTN sistemde kayıtlı" der — ikinci RESMÎ belge açılmaz
 * (`InvoiceProvider` başlığındaki kural). ETTN sonradan üretilseydi her
 * yeniden deneme yeni bir e-belge keserdi.
 *
 * Reddedilen belgede (`poll` → failed) iş, `provider_job_id`'yi siler
 * (`IssueInvoice`): yeniden deneme YENİ ETTN alır — reddedilen ETTN
 * Nilvera'da kayıtlıdır ve yeniden gönderilemez.
 *
 * MUHASEBE YOKTUR: cari, tahsilat ve iade kaydı Nilvera'da tutulmaz
 * (`InvoiceAccount::keepsBooks`). "Yalnız muhasebeye işle" kipi ve iade
 * kaydı bu sağlayıcıda istenemez; burada ikinci kapı.
 *
 * ⚠️ GERÇEK HESAPTA DOĞRULANACAK (belgede yazmıyor):
 *   · e-arşivin `StatusCode` değerinin gönderimden sonra ne zaman
 *     `succeed` olduğu — GİB'e raporlama gece toplu yapılır; `waiting`
 *     belge kesilmiş SAYILIR (yalnız `error` başarısızlıktır)
 *   · mükellef sorgusunun `Name`/`Type` alanlarının etiket (`urn:mail:…pk@`)
 *     ve `PK` değerleri taşıdığı
 *   · satır ve genel toplamların Nilvera'da yeniden hesaplanıp
 *     hesaplanmadığı (biz KDV dahil müşterinin ödediğinden geriye
 *     hesaplayıp gönderiyoruz)
 *   · e-arşiv internet satışında taşıyıcı (`TransporterName`,
 *     `TransporterRegisterNumber`) zorunluluğu: belge "kalem hizmet
 *     değilse zorunlu" diyor; sipariş anında taşıyıcı VKN'si elimizde
 *     YOK, gönderilmiyor
 *   · seri boşken portalın varsayılan seriyi kullandığı
 */
final class NilveraInvoiceProvider implements InvoiceProvider, ProvidesPdfContent
{
    private const TIMEZONE = 'Europe/Istanbul';

    /** UBL-TR birim kodu: adet. */
    private const UNIT_PIECE = 'C62';

    /** 409'da "bu belge zaten kayıtlı" anlamına gelen kodlar (hata-kodlari). */
    private const ALREADY_SENT = [1001, 1004, 1012];

    /** 422: "E-Arşiv Fatura Daha Önce İptal Edilmiştir." */
    private const ALREADY_CANCELLED = 2026;

    public function __construct(
        private readonly NilveraClient $client,
        private readonly ?string $invoiceSeries = null,
    ) {}

    public function submit(Invoice $invoice, InvoiceDraft $draft, ?SubmitOptions $options = null): void
    {
        if ($options !== null && ! $options->issueEDocument) {
            throw new InvoiceProviderException(ErrorClass::VALIDATION, 'Nilvera yalnız e-belge keser; "yalnız muhasebeye işle" kipi kullanılamaz.');
        }

        if ($invoice->provider_job_id === null) {
            $alias = $draft->buyer->hasRealTaxNumber() ? $this->eInvoiceAlias($draft->buyer->taxNumber) : null;

            // Tür ve etiket ETTN ile BİRLİKTE yazılır: yeniden deneme aynı
            // belgeyi aynı türde gönderir (mükellef listesi arada değişse bile).
            $invoice->forceFill([
                'provider_job_id' => (string) Str::uuid(),
                'document_type' => $alias === null ? Invoice::TYPE_E_ARCHIVE : Invoice::TYPE_E_INVOICE,
                'provider_contact_id' => $alias,
            ])->save();
        }

        $uuid = (string) $invoice->provider_job_id;

        $result = $invoice->document_type === Invoice::TYPE_E_INVOICE
            ? $this->client->post('einvoice/Send/Model', [
                'EInvoice' => $this->document($uuid, $draft, eInvoice: true),
                'CustomerAlias' => $invoice->provider_contact_id,
            ])
            : $this->client->post('earchive/Send/Model', [
                'ArchiveInvoice' => $this->document($uuid, $draft, eInvoice: false),
            ]);

        if ($result['status'] === 409 && array_intersect($result['codes'], self::ALREADY_SENT) === []) {
            throw new InvoiceProviderException(ErrorClass::CONFLICT, 'Nilvera belgeyi reddetti (çakışma, kod '.implode(', ', $result['codes']).').');
        }

        $number = $result['body']['InvoiceNumber'] ?? null;

        $invoice->forceFill([
            'invoice_number' => is_string($number) && $number !== '' ? $number : $invoice->invoice_number,
            'status' => Invoice::STATUS_ISSUING,
        ])->save();
    }

    public function poll(Invoice $invoice): IssueOutcome
    {
        $uuid = $invoice->provider_job_id;

        if ($uuid === null) {
            return IssueOutcome::failed('e-belge isteği gönderilmemiş.');
        }

        if ($invoice->document_type === Invoice::TYPE_E_INVOICE) {
            $status = $this->client->get("einvoice/Sale/{$uuid}/Status");
            $code = strtolower((string) ($status['InvoiceStatus']['Code'] ?? ''));
            $reason = (string) ($status['InvoiceStatus']['DetailDescription'] ?? $status['InvoiceStatus']['Description'] ?? '');

            return match ($code) {
                'error' => IssueOutcome::failed('Nilvera/GİB e-faturayı reddetti: '.($reason !== '' ? $reason : 'sebep bildirilmedi')),
                'succeed' => IssueOutcome::issued($uuid, $this->number($invoice, "einvoice/Sale/{$uuid}/Details")),
                default => IssueOutcome::pending(),
            };
        }

        $status = $this->client->get("earchive/Invoices/{$uuid}/Status");

        if (strtolower((string) ($status['StatusCode'] ?? '')) === 'error') {
            $reason = (string) ($status['StatusDetail'] ?? '');

            return IssueOutcome::failed('Nilvera e-arşivi oluşturamadı: '.($reason !== '' ? $reason : 'sebep bildirilmedi'));
        }

        return IssueOutcome::issued($uuid, $this->number($invoice, "earchive/Invoices/{$uuid}/Details"));
    }

    /** Nilvera adres vermez, dosyanın kendisini verir — `pdfContent`. */
    public function pdfUrl(Invoice $invoice): ?string
    {
        return null;
    }

    public function pdfContent(Invoice $invoice, int $maxBytes): ?string
    {
        $uuid = $invoice->provider_document_id ?? null;

        if ($uuid === null) {
            return null;
        }

        $pdf = $this->client->download($invoice->document_type === Invoice::TYPE_E_INVOICE
            ? "einvoice/Sale/{$uuid}/pdf"
            : "earchive/Invoices/{$uuid}/pdf");

        if (strlen($pdf) > $maxBytes) {
            throw new InvoiceProviderException(ErrorClass::VALIDATION, 'Fatura PDF\'i kanalın dosya sınırını ('.intdiv($maxBytes, 1024 * 1024).' MB) aşıyor.');
        }

        if (! str_starts_with($pdf, '%PDF-')) {
            throw new InvoiceProviderException(ErrorClass::VALIDATION, 'Nilvera\'dan gelen dosya PDF değil.');
        }

        return $pdf;
    }

    /**
     * e-ARŞİV iptali — önce durum okunur: zaten iptal edildiyse iş biter
     * (iş PUT'tan sonra ölmüş olabilir). Nilvera "daha önce iptal edildi"
     * (422/2026) derse de iptal tamamdır. Süre ya da rapor durumu yüzünden
     * iptal edilemiyorsa Nilvera'nın metni satıcıya gider (VALIDATION).
     *
     * e-FATURA API'den iptal edilmez (alıcının reddi/iade faturası gerekir)
     * — talep aşamasında reddedilir, burada ikinci kapı.
     */
    public function cancel(Invoice $cancellation, Invoice $sale): void
    {
        $uuid = $sale->provider_document_id ?? $sale->provider_job_id;

        if ($uuid === null) {
            throw new InvoiceProviderException(ErrorClass::VALIDATION, 'İptal edilecek belge Nilvera\'da yok.');
        }

        if ($sale->document_type === Invoice::TYPE_E_INVOICE) {
            throw new InvoiceProviderException(ErrorClass::VALIDATION, 'e-Fatura Nilvera\'dan iptal edilemez; alıcının iade faturası kesmesi gerekir.');
        }

        $status = $this->client->get("earchive/Invoices/{$uuid}/Status");

        if (($status['CancelStatus'] ?? false) === true) {
            return;
        }

        $result = $this->client->put("earchive/Invoices/{$uuid}/Cancel", allow: [422]);

        if ($result['status'] === 422 && ! in_array(self::ALREADY_CANCELLED, $result['codes'], true)) {
            throw new InvoiceProviderException(ErrorClass::VALIDATION, 'Nilvera e-arşivi iptal etmedi (kod '.implode(', ', $result['codes']).'); iptal süresi geçmiş ya da GİB raporu bekleniyor olabilir.');
        }
    }

    public function recordReturn(Invoice $return, Invoice $sale, ReturnDraft $draft): void
    {
        throw new InvoiceProviderException(ErrorClass::VALIDATION, 'Nilvera yalnız e-belge keser; iade kaydını muhasebe programınızda açın.');
    }

    // ─────────────────────────────────────────────────── adımlar

    /**
     * Alıcının e-fatura etiketi — mükellef değilse null (e-arşiv kesilir).
     *
     * Tür alıcının mükellefiyetiyle belirlenir, satıcıyla DEĞİL: yanlış
     * türde kesilen belgeyi Nilvera reddeder (422/2025 "Alıcı e-fatura
     * mükellefidir. E-Arşiv fatura gönderilemez").
     */
    private function eInvoiceAlias(string $taxNumber): ?string
    {
        try {
            $entries = $this->client->get("general/GlobalCompany/Check/TaxNumber/{$taxNumber}", ['globalUserType' => 'Invoice']);
        } catch (InvoiceProviderException $e) {
            if ($e->class === ErrorClass::NOT_FOUND) {
                return null;
            }

            throw $e;
        }

        $fallback = null;

        foreach (array_is_list($entries) ? $entries : [] as $entry) {
            $alias = is_array($entry) ? (string) ($entry['Name'] ?? '') : '';

            if (! str_starts_with($alias, 'urn:')) {
                continue;
            }

            // Posta kutusu (PK) etiketi; yoksa ilk geçerli etiket.
            if (strtoupper((string) ($entry['Type'] ?? '')) === 'PK') {
                return $alias;
            }

            $fallback ??= $alias;
        }

        return $fallback;
    }

    /** Numara gönderim yanıtından geldiyse odur; 409 yolunda detaydan okunur. */
    private function number(Invoice $invoice, string $detailsEndpoint): ?string
    {
        if ($invoice->invoice_number !== null && $invoice->invoice_number !== '') {
            return $invoice->invoice_number;
        }

        $number = $this->client->get($detailsEndpoint)['InvoiceNumber'] ?? null;

        return is_string($number) && $number !== '' ? $number : null;
    }

    /**
     * Belge gövdesi — e-fatura ve e-arşiv aynı iskeleti paylaşır.
     *
     * `CompanyInfo` GÖNDERİLMEZ: belge "boşsa aktif firmanın bilgisi
     * kullanılır" diyor. Biz göndersek satıcı Nilvera'da unvanını
     * değiştirdiğinde faturada eski unvan kalırdı.
     *
     * @return array<string, mixed>
     */
    private function document(string $uuid, InvoiceDraft $draft, bool $eInvoice): array
    {
        $tz = new DateTimeZone(self::TIMEZONE);
        $currency = strtoupper($draft->currency);
        [$lines, $totals] = self::lines($draft->lines);

        $info = array_filter([
            'UUID' => $uuid,
            'InvoiceType' => 'SATIS',
            'InvoiceSerieOrNumber' => $this->invoiceSeries,
            'IssueDate' => (new DateTimeImmutable('now', $tz))->format(DATE_ATOM),
            'CurrencyCode' => $currency,
            'ExchangeRate' => $currency === 'TRY' ? null : self::exchangeRate($currency),
            'OrderReference' => [
                'IssueDate' => $draft->orderedAt->setTimezone($tz)->format(DATE_ATOM),
                'Value' => $draft->orderNumber,
            ],
            ...$totals,
        ], static fn (mixed $v): bool => $v !== null);

        if ($eInvoice) {
            $info['InvoiceProfile'] = 'TEMELFATURA';
        } else {
            // GİB: internetten satışta ZORUNLU; gönderim ELEKTRONIK olmalı.
            $info['SalesPlatform'] = 'INTERNET';
            $info['SendType'] = 'ELEKTRONIK';
            $info['InternetInfo'] = [
                'WebSite' => $draft->platformUrl,
                'PaymentMethod' => $draft->paymentType,
                'PaymentAgentName' => $draft->platformName,
                'PaymentDate' => $draft->orderedAt->setTimezone($tz)->format(DATE_ATOM),
            ];
        }

        return [
            'InvoiceInfo' => $info,
            'CustomerInfo' => self::customer($draft->buyer),
            'InvoiceLines' => $lines,
            'Notes' => ["{$draft->platformName} siparişi {$draft->orderNumber}"],
        ];
    }

    /**
     * Kalemler ve toplamlar — KURUŞ TAMSAYISIYLA.
     *
     * Kalemin KDV dahil tutarı (müşterinin ödediği) esastır: net ondan
     * geriye hesaplanır, KDV fark olarak bulunur. Böylece ödenecek tutar
     * kuruşu kuruşuna `InvoiceDraft::grossTotal` olur; net ve KDV ayrı ayrı
     * yuvarlanıp toplansaydı fatura müşterinin ödediğinden 1 kuruş sapabilirdi.
     *
     * @param  list<InvoiceLine>  $draftLines
     * @return array{0: list<array<string, mixed>>, 1: array<string, float>}
     */
    private static function lines(array $draftLines): array
    {
        $lines = [];
        $net = 0;
        $vat = 0;
        $byRate = [];

        foreach ($draftLines as $index => $line) {
            $lineGross = (int) round((float) $line->grossUnitPrice * 100) * $line->quantity;
            $lineNet = (int) round($lineGross / (1 + $line->vatRate / 100));
            $lineVat = $lineGross - $lineNet;

            $net += $lineNet;
            $vat += $lineVat;
            $byRate[$line->vatRate] = ($byRate[$line->vatRate] ?? 0) + $lineVat;

            $lines[] = array_filter([
                'Index' => (string) ($index + 1),
                'SellerCode' => $line->sku,
                'Name' => mb_substr($line->description, 0, 250),
                'Quantity' => $line->quantity,
                'UnitType' => self::UNIT_PIECE,
                'Price' => (float) $line->netUnitPrice(),
                'AllowanceTotal' => 0,
                'KDVPercent' => $line->vatRate,
                'KDVTotal' => $lineVat / 100,
            ], static fn (mixed $v): bool => $v !== null);
        }

        $totals = [
            'LineExtensionAmount' => $net / 100,
            'KdvTotal' => $vat / 100,
            'PayableAmount' => ($net + $vat) / 100,
            'GeneralAllowanceTotal' => 0,
        ];

        foreach ([1, 10, 20] as $rate) {
            if (isset($byRate[$rate])) {
                $totals["GeneralKDV{$rate}Total"] = $byRate[$rate] / 100;
            }
        }

        return [$lines, $totals];
    }

    /**
     * Alıcı — ilçe ve şehir Nilvera'da ZORUNLU; kanal vermediyse "-"
     * (e-arşiv boş adres kabul etmez, `InvoiceBuyer::fromChannel` kuralı).
     *
     * @return array<string, mixed>
     */
    private static function customer(InvoiceBuyer $buyer): array
    {
        return array_filter([
            'TaxNumber' => $buyer->taxNumber,
            'Name' => $buyer->name,
            'TaxOffice' => $buyer->taxOffice,
            'Address' => $buyer->address,
            'District' => $buyer->district ?? $buyer->city ?? '-',
            'City' => $buyer->city ?? '-',
            'Country' => $buyer->countryCode === 'TR' ? 'Türkiye' : $buyer->countryCode,
            'Mail' => $buyer->email,
            'Phone' => $buyer->phone,
        ], static fn (mixed $v): bool => $v !== null && $v !== '');
    }

    /**
     * Döviz faturasında kur ZORUNLU (VUK 280: TCMB döviz ALIŞ kuru).
     *
     * Bülten yoksa fatura KESİLMEZ: kursuz döviz faturası GİB'de hatalı
     * tutar demektir; tahmini kurla kesmek resmî belgeyi bozardı.
     */
    private static function exchangeRate(string $currency): float
    {
        $rate = ExchangeRate::query()
            ->where('source', ExchangeRate::SOURCE_TCMB)
            ->where('currency', $currency)
            ->whereNotNull('forex_buying')
            ->whereDate('rate_date', '<=', now(self::TIMEZONE)->toDateString())
            ->orderByDesc('rate_date')
            ->first();

        if ($rate === null) {
            throw new InvoiceProviderException(ErrorClass::VALIDATION, "{$currency} için TCMB kuru yok; döviz faturası kesilemedi.");
        }

        return round((float) $rate->forex_buying / max($rate->unit, 1), 6);
    }
}
