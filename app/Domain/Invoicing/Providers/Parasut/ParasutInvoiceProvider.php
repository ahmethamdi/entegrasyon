<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Providers\Parasut;

use App\Domain\Invoicing\Contracts\InvoiceProvider;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Support\InvoiceBuyer;
use App\Domain\Invoicing\Support\InvoiceDraft;
use App\Domain\Invoicing\Support\InvoiceLine;
use App\Domain\Invoicing\Support\IssueOutcome;
use App\Domain\Invoicing\Support\SubmitOptions;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

/**
 * Paraşüt ile fatura — cari → satış faturası → e-belge → sonuç.
 *
 * AKIŞ (Paraşüt API v4):
 *   1. `contacts`          alıcı carisi (VKN/TCKN biliniyorsa var olan bulunur)
 *   2. `sales_invoices`    taslak fatura, kalemler KDV HARİÇ birim fiyatla
 *   2b. `sales_invoices/{id}/payments`  tahsilat — yalnız kanal → kasa/banka
 *      eşlemesi varsa (`SubmitOptions::paymentAccountId`); yoksa fatura
 *      açık hesap kalır
 *   3. `e_invoice_inboxes` alıcı e-fatura mükellefi mi? (yalnız gerçek VKN/TCKN)
 *        evet  → `e_invoices` (senaryo TEMEL, alıcının posta kutusuna)
 *        hayır → `e_archives` (internet satışı bilgisiyle)
 *      İkisi de `trackable_jobs` kimliği döner — sonuç asenkron.
 *      "Yalnız muhasebeye işle" kipinde (`issueEDocument = false`) bu adım
 *      ve 4. adım HİÇ çalışmaz.
 *   4. `trackable_jobs/{id}` → bitince `sales_invoices/{id}?include=active_e_document`
 *
 * ⚠️ KOMİSYON VE KARGO GİDERİ SİPARİŞ BAŞINA YAZILMAZ — bilinçli. Trendyol
 * komisyonu ve kargo bedelini satıcıya AYLIK e-fatura olarak keser; o fatura
 * satıcının Paraşüt'ündeki gelen e-faturalar kutusuna KENDİLİĞİNDEN düşer ve
 * gider olarak oradan işlenir. Burada sipariş başına bir de gider faturası
 * açılsaydı aynı gider İKİ KEZ sayılır, satıcının kârı olduğundan düşük ve
 * KDV indirimi şişkin görünürdü. Hakediş (Trendyol hesabından bankaya
 * aktarım, finance API) ayrı bir dilimdir — `docs/RAKIP-YOL-HARITASI.md`.
 *
 * ⚠️ GERÇEK HESAPTA DOĞRULANACAK (belge sitesi otomatik erişime kapalı,
 * alanlar açık kaynak v4 istemcilerinden derlendi):
 *   · `e_invoices` ilişki adı `invoice`, `e_archives`'ta `sales_invoice`
 *   · iş durumu değerleri (`done`/`error` varsayıldı, `succeeded`/`failed` da kabul)
 *   · e-belgenin numara alanı (`invoice_number` varsayıldı)
 *   · kalemde ürün ilişkisi olmadan fatura kabul ediliyor mu
 *   · tahsilat ucu `sales_invoices/{id}/payments`: gövde türü `payments`,
 *     `account_id` niteliği (ilişki DEĞİL) ve yanıtta kimliğin `data.id`'de
 *     dönmesi; TRL dışı para biriminde `exchange_rate` zorunlu mu
 *   · `accounts` listesinin alan adları (`name`, `account_type`) ve arşivli
 *     hesapların listeye gelip gelmediği
 */
final class ParasutInvoiceProvider implements InvoiceProvider
{
    private const TIMEZONE = 'Europe/Istanbul';

    public function __construct(
        private readonly ParasutClient $client,
        private readonly ?string $invoiceSeries = null,
    ) {}

    public function submit(Invoice $invoice, InvoiceDraft $draft, ?SubmitOptions $options = null): void
    {
        $options ??= new SubmitOptions;

        if ($invoice->provider_contact_id === null) {
            $invoice->forceFill(['provider_contact_id' => $this->contactFor($draft->buyer)])->save();
        }

        if ($invoice->provider_invoice_id === null) {
            $invoice->forceFill(['provider_invoice_id' => $this->createSalesInvoice($invoice->provider_contact_id, $draft)])->save();
        }

        // Tahsilat e-belgeden ÖNCE: e-belge reddedilip yeniden denense de
        // satış faturası aynıdır ve tahsilatı bir kez taşır. Kimlik anında
        // yazılır — iş burada ölürse ikinci tahsilat AÇILMAZ.
        if ($options->paymentAccountId !== null && $invoice->provider_payment_id === null) {
            $invoice->forceFill(['provider_payment_id' => $this->createPayment($invoice->provider_invoice_id, $options->paymentAccountId, $draft)])->save();
        }

        if (! $options->issueEDocument) {
            return;
        }

        if ($invoice->provider_job_id === null) {
            [$type, $jobId] = $this->createEDocument($invoice->provider_invoice_id, $draft);

            $invoice->forceFill([
                'document_type' => $type,
                'provider_job_id' => $jobId,
                'status' => Invoice::STATUS_ISSUING,
            ])->save();
        }
    }

    public function poll(Invoice $invoice): IssueOutcome
    {
        if ($invoice->provider_job_id === null) {
            return IssueOutcome::failed('e-belge isteği gönderilmemiş.');
        }

        $job = $this->client->get("trackable_jobs/{$invoice->provider_job_id}");
        $status = strtolower((string) ($job['data']['attributes']['status'] ?? ''));

        if (in_array($status, ['error', 'failed'], true)) {
            $errors = array_filter(array_map('strval', (array) ($job['data']['attributes']['errors'] ?? [])));

            return IssueOutcome::failed('Paraşüt e-belgeyi oluşturamadı: '.($errors === [] ? 'sebep bildirilmedi' : implode(' · ', $errors)));
        }

        if (! in_array($status, ['done', 'succeeded'], true)) {
            return IssueOutcome::pending();
        }

        $sales = $this->client->get("sales_invoices/{$invoice->provider_invoice_id}", ['include' => 'active_e_document']);

        $ref = $sales['data']['relationships']['active_e_document']['data'] ?? null;
        $documentId = is_array($ref) && isset($ref['id']) ? (string) $ref['id'] : null;

        $number = null;

        foreach ((array) ($sales['included'] ?? []) as $item) {
            if (is_array($item) && (string) ($item['id'] ?? '') === $documentId) {
                $raw = $item['attributes']['invoice_number'] ?? null;
                $number = is_scalar($raw) && (string) $raw !== '' ? (string) $raw : null;
            }
        }

        return IssueOutcome::issued($documentId, $number);
    }

    public function pdfUrl(Invoice $invoice): ?string
    {
        if ($invoice->provider_document_id === null) {
            return null;
        }

        $resource = $invoice->document_type === Invoice::TYPE_E_INVOICE ? 'e_invoices' : 'e_archives';

        $response = $this->client->get("{$resource}/{$invoice->provider_document_id}/pdf");
        $url = $response['data']['attributes']['url'] ?? null;

        return is_string($url) && str_starts_with($url, 'https://') ? $url : null;
    }

    // ─────────────────────────────────────────────────── adımlar

    /**
     * Gerçek VKN/TCKN'li alıcının carisi YENİDEN KULLANILIR: aynı firmaya
     * her siparişte yeni cari açılsaydı satıcının muhasebesi kopya
     * carilerle dolardı. 11111111111'li (anonim) alıcıda arama anlamsızdır,
     * her sipariş kendi carisini alır.
     */
    private function contactFor(InvoiceBuyer $buyer): string
    {
        if ($buyer->hasRealTaxNumber()) {
            $found = $this->client->get('contacts', [
                'filter[tax_number]' => $buyer->taxNumber,
                'page[size]' => 1,
            ]);

            $id = $found['data'][0]['id'] ?? null;

            if (is_scalar($id) && (string) $id !== '') {
                return (string) $id;
            }
        }

        $created = $this->client->post('contacts', [
            'data' => [
                'type' => 'contacts',
                'attributes' => array_filter([
                    'name' => $buyer->name,
                    'contact_type' => $buyer->isCompany ? 'company' : 'person',
                    'tax_number' => $buyer->taxNumber,
                    'tax_office' => $buyer->taxOffice,
                    'email' => $buyer->email,
                    'phone' => $buyer->phone,
                    'address' => $buyer->address,
                    'city' => $buyer->city,
                    'district' => $buyer->district,
                    'account_type' => 'customer',
                    'is_abroad' => $buyer->countryCode !== 'TR',
                ], static fn (mixed $v): bool => $v !== null && $v !== ''),
            ],
        ]);

        return (string) ($created['data']['id'] ?? throw new RuntimeException('Paraşüt cari kimliği dönmedi.'));
    }

    private function createSalesInvoice(string $contactId, InvoiceDraft $draft): string
    {
        $tz = new DateTimeZone(self::TIMEZONE);
        $buyer = $draft->buyer;

        $created = $this->client->post('sales_invoices', [
            'data' => [
                'type' => 'sales_invoices',
                'attributes' => array_filter([
                    'item_type' => 'invoice',
                    'description' => "{$draft->platformName} siparişi {$draft->orderNumber}",
                    'issue_date' => (new DateTimeImmutable('now', $tz))->format('Y-m-d'),
                    'currency' => self::currency($draft->currency),
                    'invoice_series' => $this->invoiceSeries,
                    'order_no' => $draft->orderNumber,
                    'order_date' => $draft->orderedAt->setTimezone($tz)->format('Y-m-d'),
                    'billing_address' => $buyer->address,
                    'city' => $buyer->city,
                    'district' => $buyer->district,
                    'tax_number' => $buyer->taxNumber,
                    'tax_office' => $buyer->taxOffice,
                    'is_abroad' => $buyer->countryCode !== 'TR',
                ], static fn (mixed $v): bool => $v !== null && $v !== ''),
                'relationships' => [
                    'contact' => ['data' => ['type' => 'contacts', 'id' => $contactId]],
                    'details' => [
                        'data' => array_map(static fn (InvoiceLine $line): array => [
                            'type' => 'sales_invoice_details',
                            'attributes' => [
                                'description' => $line->description,
                                'quantity' => $line->quantity,
                                'unit_price' => $line->netUnitPrice(),
                                'vat_rate' => $line->vatRate,
                            ],
                        ], $draft->lines),
                    ],
                ],
            ],
        ]);

        return (string) ($created['data']['id'] ?? throw new RuntimeException('Paraşüt fatura kimliği dönmedi.'));
    }

    /**
     * Tahsilat — pazar yeri parayı müşteriden ALMIŞTIR; fatura açık hesap
     * kalsaydı satıcının cari bakiyesi her siparişte şişer ve "tahsil
     * edilmemiş alacak" listesi gerçek dışı olurdu.
     *
     * Tutar kalemlerin KDV DAHİL toplamıdır (`InvoiceDraft::grossTotal`),
     * tarih siparişin Türkiye saatiyle günü — müşterinin ödediği gün.
     */
    private function createPayment(string $salesInvoiceId, string $accountId, InvoiceDraft $draft): string
    {
        $created = $this->client->post("sales_invoices/{$salesInvoiceId}/payments", [
            'data' => [
                'type' => 'payments',
                'attributes' => [
                    'description' => "{$draft->platformName} siparişi {$draft->orderNumber} tahsilatı",
                    'account_id' => $accountId,
                    'date' => $draft->orderedAt->setTimezone(new DateTimeZone(self::TIMEZONE))->format('Y-m-d'),
                    'amount' => $draft->grossTotal(),
                ],
            ],
        ]);

        $id = $created['data']['id'] ?? null;

        if (! is_scalar($id) || (string) $id === '') {
            throw new RuntimeException('Paraşüt tahsilat kimliği dönmedi.');
        }

        return (string) $id;
    }

    /**
     * e-fatura mı e-arşiv mi — alıcının mükellefiyeti belirler, satıcı
     * DEĞİL. Yanlış türde kesilen belge GİB'de reddedilir ya da alıcıya
     * hiç ulaşmaz.
     *
     * @return array{0: string, 1: string} [tür, iş kimliği]
     */
    private function createEDocument(string $salesInvoiceId, InvoiceDraft $draft): array
    {
        $mailbox = $draft->buyer->hasRealTaxNumber() ? $this->eInvoiceMailbox($draft->buyer->taxNumber) : null;

        if ($mailbox !== null) {
            $job = $this->client->post('e_invoices', [
                'data' => [
                    'type' => 'e_invoices',
                    'attributes' => ['scenario' => 'basic', 'to' => $mailbox],
                    'relationships' => ['invoice' => ['data' => ['type' => 'sales_invoices', 'id' => $salesInvoiceId]]],
                ],
            ]);

            return [Invoice::TYPE_E_INVOICE, self::jobId($job)];
        }

        $job = $this->client->post('e_archives', [
            'data' => [
                'type' => 'e_archives',
                'attributes' => [
                    // GİB: internetten satışta ZORUNLU.
                    'internet_sale' => [
                        'url' => $draft->platformUrl,
                        'payment_type' => $draft->paymentType,
                        'payment_platform' => $draft->platformName,
                        'payment_date' => $draft->orderedAt->setTimezone(new DateTimeZone(self::TIMEZONE))->format('Y-m-d'),
                    ],
                ],
                'relationships' => ['sales_invoice' => ['data' => ['type' => 'sales_invoices', 'id' => $salesInvoiceId]]],
            ],
        ]);

        return [Invoice::TYPE_E_ARCHIVE, self::jobId($job)];
    }

    private function eInvoiceMailbox(string $taxNumber): ?string
    {
        $inboxes = $this->client->get('e_invoice_inboxes', ['filter[vkn]' => $taxNumber]);

        $address = $inboxes['data'][0]['attributes']['e_invoice_address'] ?? null;

        return is_string($address) && $address !== '' ? $address : null;
    }

    /** @param array<string, mixed> $response */
    private static function jobId(array $response): string
    {
        $id = $response['data']['id'] ?? null;

        if (! is_scalar($id) || (string) $id === '') {
            throw new RuntimeException('Paraşüt e-belge iş kimliği dönmedi.');
        }

        return (string) $id;
    }

    /** Paraşüt Türk lirasını `TRL` kodlar. */
    private static function currency(string $currency): string
    {
        return strtoupper($currency) === 'TRY' ? 'TRL' : strtoupper($currency);
    }
}
