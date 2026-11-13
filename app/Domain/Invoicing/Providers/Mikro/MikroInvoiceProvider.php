<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Providers\Mikro;

use App\Domain\Invoicing\Contracts\InvoiceProvider;
use App\Domain\Invoicing\Exceptions\InvoiceProviderException;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceAccount;
use App\Domain\Invoicing\Support\ErpEndpoint;
use App\Domain\Invoicing\Support\InvoiceDraft;
use App\Domain\Invoicing\Support\InvoiceLine;
use App\Domain\Invoicing\Support\IssueOutcome;
use App\Domain\Invoicing\Support\ReturnDraft;
use App\Domain\Invoicing\Support\SubmitOptions;
use App\Domain\Sync\Enums\ErrorClass;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\Client\Response;

/**
 * Mikro (Run / Jump / Fly, v16–v17) — "Mikro Desktop API" ile siparişi
 * ERP'ye SATIŞ SİPARİŞİ olarak aktarır.
 *
 * Kaynak: apidocs.mikro.com.tr (RESMÎ, girişsiz; 13 Kas 2026 okundu —
 * docs/MIKRO-API-NOTLARI.md). Alan adları `SiparisKaydetV2` sayfasından,
 * kod anlamları "TABLO NO: 21 SIPARISLER" sayfasından:
 *   sip_tip  0:Talep 1:Temin   → 0 (müşteriden alınan sipariş)
 *   sip_cins 0:Normal Sipariş  → 0
 * Belge alanların hangisinin zorunlu olduğunu YAZMAZ; örnek gövdedeki
 * alanlar gönderilir. Vergi işaretçisi (`sip_vergi_pntr`) firmanın KDV
 * tablosundaki SIRADIR ve belgede oran karşılığı YOKTUR → satıcı eşlemeyi
 * ayarda yazar (ör. "20:4,10:3,1:2,0:1"); eşlenmemiş oran tahminle
 * gönderilmez (VALIDATION).
 *
 * KİMLİK: V2 uçlarında her istekte `Mikro` nesnesi (ApiKey, FirmaKodu,
 * CalismaYili, KullaniciKodu, Sifre). Şifre = MD5("yyyy-MM-dd" + " " +
 * şifre) — GÜNÜN tarihiyle her istekte yeniden (belge: "Gününtarihi Boşluk
 * Şifre"). Bu yüzden şifre düz (şifreli sütunda) saklanır.
 *
 * KAPSAM: cari AÇILMAZ (satıcının Mikro'daki pazar yeri carisi kodu
 * ayardan), fatura/e-belge Mikro'da kesilir → kip hep "yalnız aktar".
 * Yanıt zarfı `{"result":[{"StatusCode","Data","ErrorMessage","IsError"}]}`;
 * sipariş kaydında `Data` içeriği (GUID / evrak no) belgelenmemiştir.
 */
final class MikroInvoiceProvider implements InvoiceProvider
{
    private const TIMEZONE = 'Europe/Istanbul';

    /** TABLO NO 21: sip_tip 0 = Talep (alınan sipariş). */
    private const ORDER_TYPE_DEMAND = '0';

    /** TABLO NO 21: sip_cins 0 = Normal Sipariş. */
    private const ORDER_KIND_NORMAL = '0';

    /** Belge örneği: sip_birim_pntr 1 = stok kartının 1. birimi. */
    private const UNIT_POINTER = 1;

    public const SETTING_CUSTOMER_CODE = 'customer_code';

    public const SETTING_WAREHOUSE = 'warehouse_no';

    public const SETTING_SERIES = 'document_series';

    public const SETTING_VAT_POINTERS = 'vat_pointers';

    public function __construct(
        private readonly ErpEndpoint $http,
        private readonly InvoiceAccount $account,
    ) {}

    /**
     * Bağlanırken: ayardaki cari kodu `CariListesiV3` ile sorulur — hem
     * kimlik (ApiKey/şifre/firma/yıl) hem cari doğrulanır, iz bırakmaz.
     *
     * @param  array<string, string>  $credentials
     *
     * @throws InvoiceProviderException
     */
    public static function probe(ErpEndpoint $http, array $credentials, string $customerCode): void
    {
        $reply = $http->send('POST', $credentials['base_url'].'/Api/APIMethods/CariListesiV3', [], [
            'Mikro' => self::envelope($credentials, yearAsString: true),
            'CariKod' => $customerCode,
            'Index' => 0,
            'Size' => '1',
        ]);

        self::result($reply, 'Mikro bağlantıyı doğrulamadı.');
    }

    /**
     * "20:4,10:3" → [20 => 4, 10 => 3]; bozuk parça yok sayılmaz, null döner.
     *
     * @return array<int, int>|null
     */
    public static function parseVatPointers(?string $value): ?array
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $map = [];

        foreach (explode(',', $value) as $pair) {
            if (preg_match('/^\s*(\d{1,2})\s*[:=]\s*(\d{1,2})\s*$/', $pair, $m) !== 1) {
                return null;
            }

            $map[(int) $m[1]] = (int) $m[2];
        }

        return $map;
    }

    public function submit(Invoice $invoice, InvoiceDraft $draft, ?SubmitOptions $options = null): void
    {
        if ($invoice->provider_invoice_id !== null) {
            return;
        }

        $customer = $this->account->setting(self::SETTING_CUSTOMER_CODE)
            ?? throw new InvoiceProviderException(ErrorClass::VALIDATION, 'Mikro cari kodu tanımlı değil; fatura ayarlarından girin.');
        $pointers = self::parseVatPointers($this->account->setting(self::SETTING_VAT_POINTERS))
            ?? throw new InvoiceProviderException(ErrorClass::VALIDATION, 'Mikro KDV → vergi işaretçisi eşlemesi tanımlı değil (ör. 20:4,10:3,1:2,0:1).');
        $warehouse = (int) ($this->account->setting(self::SETTING_WAREHOUSE) ?? 1);
        $series = $this->account->setting(self::SETTING_SERIES) ?? '';
        $date = $draft->orderedAt->setTimezone(new DateTimeZone(self::TIMEZONE))->format('d.m.Y');

        $lines = array_map(function (InvoiceLine $line) use ($customer, $pointers, $warehouse, $series, $date): array {
            $code = $line->sku !== null && trim($line->sku) !== '' ? trim($line->sku) : null;

            if ($code === null) {
                throw new InvoiceProviderException(ErrorClass::VALIDATION, "Kalemin stok kodu (SKU) yok: Mikro stok kodu SKU ile eşlenir ({$line->description}).");
            }

            $pointer = $pointers[$line->vatRate]
                ?? throw new InvoiceProviderException(ErrorClass::VALIDATION, "%{$line->vatRate} KDV için Mikro vergi işaretçisi eşlenmemiş; fatura ayarlarına ekleyin.");
            $net = (float) $line->netUnitPrice();

            return [
                'sip_tarih' => $date,
                'sip_tip' => self::ORDER_TYPE_DEMAND,
                'sip_cins' => self::ORDER_KIND_NORMAL,
                'sip_evrakno_seri' => $series,
                'sip_musteri_kod' => $customer,
                'sip_stok_kod' => $code,
                'sip_miktar' => $line->quantity,
                'sip_b_fiyat' => $net,
                'sip_tutar' => round($net * $line->quantity, 2),
                'sip_vergi_pntr' => $pointer,
                'sip_vergisiz_fl' => 0,
                'sip_birim_pntr' => self::UNIT_POINTER,
                'sip_depono' => $warehouse,
            ];
        }, $draft->lines);

        $reply = $this->http->send('POST', $this->credentials()['base_url'].'/Api/apiMethods/SiparisKaydetV2', [], [
            'Mikro' => [
                ...self::envelope($this->credentials(), yearAsString: false),
                'evraklar' => [[
                    'evrak_aciklamalari' => [['aciklama' => mb_substr("{$draft->platformName} {$draft->orderNumber}", 0, 50)]],
                    'satirlar' => $lines,
                ]],
            ],
        ]);

        $result = self::result($reply, 'Mikro siparişi kaydetmedi.');

        // Kayıt kimliği belgede yok: Data'da bir GUID/numara varsa o, yoksa bizim referansımız.
        $invoice->forceFill([
            'provider_contact_id' => $customer,
            'provider_invoice_id' => mb_substr(self::firstIdentifier($result['Data'] ?? null) ?? 'kayit:'.$invoice->ref, 0, 64),
        ])->save();
    }

    public function poll(Invoice $invoice): IssueOutcome
    {
        return IssueOutcome::failed('Mikro e-belge kesmez; sipariş Mikro\'ya aktarıldı.');
    }

    public function pdfUrl(Invoice $invoice): ?string
    {
        return null;
    }

    public function cancel(Invoice $cancellation, Invoice $sale): void
    {
        throw new InvoiceProviderException(ErrorClass::VALIDATION, 'Mikro\'ya aktarılan sipariş buradan iptal edilmez; Mikro\'dan iptal edin.');
    }

    public function recordReturn(Invoice $return, Invoice $sale, ReturnDraft $draft): void
    {
        throw new InvoiceProviderException(ErrorClass::VALIDATION, 'İade kaydını Mikro\'da açın.');
    }

    /**
     * `Mikro` kimlik nesnesi. CalismaYili belgede uca göre string (cari/stok)
     * ya da integer (sipariş/fatura) — çağıran seçer.
     *
     * @param  array<string, string>  $credentials
     * @return array<string, string|int>
     */
    public static function envelope(array $credentials, bool $yearAsString, ?DateTimeImmutable $now = null): array
    {
        $year = (string) ($credentials['work_year'] ?? '');

        return [
            'ApiKey' => (string) ($credentials['api_key'] ?? ''),
            'CalismaYili' => $yearAsString ? $year : (int) $year,
            'FirmaKodu' => (string) ($credentials['company_code'] ?? ''),
            'KullaniciKodu' => (string) ($credentials['username'] ?? ''),
            'Sifre' => self::hashPassword((string) ($credentials['password'] ?? ''), $now),
        ];
    }

    /** Belge: MD5("Gününtarihi Boşluk Şifre"), ör. MD5("2023-03-09 123asd"); İstanbul saatiyle. */
    public static function hashPassword(string $password, ?DateTimeImmutable $now = null): string
    {
        $today = ($now ?? new DateTimeImmutable('now'))->setTimezone(new DateTimeZone(self::TIMEZONE))->format('Y-m-d');

        return md5($today.' '.$password);
    }

    /**
     * Zarfı çözer; IsError / StatusCode ≠ 200 / okunamayan gövde istisnadır.
     *
     * Belgelenmiş hatalar: 401 "Geçersiz ApiKey!", "Şifre Hatalı!", 401-05
     * lisans/MAG süresi → AUTHENTICATION; 400-01 "… mevcuttur!" ve diğer
     * satır hataları → VALIDATION (Mikro'nun metniyle).
     *
     * @return array<mixed>
     */
    private static function result(Response $reply, string $fallback): array
    {
        $body = ErpEndpoint::decode($reply) ?? [];
        $first = is_array($body['result'][0] ?? null) ? $body['result'][0] : null;

        if ($first === null) {
            throw new InvoiceProviderException(
                $reply->status() === 401 ? ErrorClass::AUTHENTICATION : ErrorClass::SERVER_ERROR,
                $fallback.' (HTTP '.$reply->status().')',
            );
        }

        $status = (string) ($first['StatusCode'] ?? '');
        $message = is_string($first['ErrorMessage'] ?? null) && trim($first['ErrorMessage']) !== '' ? trim($first['ErrorMessage']) : null;

        if (($first['IsError'] ?? false) !== true && in_array($status, ['200', ''], true) && $reply->successful()) {
            return $first;
        }

        $auth = str_starts_with($status, '401') || $reply->status() === 401;

        throw new InvoiceProviderException($auth ? ErrorClass::AUTHENTICATION : ($reply->serverError() ? ErrorClass::SERVER_ERROR : ErrorClass::VALIDATION), mb_substr($message ?? $fallback, 0, 500));
    }

    /** `Data` içinde ilk GUID ya da "…guid…"/"…evrak…" adlı skaler değer. */
    private static function firstIdentifier(mixed $data): ?string
    {
        if (! is_array($data)) {
            return null;
        }

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $found = self::firstIdentifier($value);

                if ($found !== null) {
                    return $found;
                }

                continue;
            }

            if (is_scalar($value) && trim((string) $value) !== '' && (preg_match('/guid|evrak|sira/i', (string) $key) === 1 || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-/i', (string) $value) === 1)) {
                return trim((string) $value);
            }
        }

        return null;
    }

    /** @return array<string, string> */
    private function credentials(): array
    {
        $c = (array) ($this->account->credentials ?? []);

        foreach (['base_url', 'api_key', 'company_code', 'work_year', 'username'] as $key) {
            if (! is_string($c[$key] ?? null) || trim($c[$key]) === '') {
                throw new InvoiceProviderException(ErrorClass::AUTHENTICATION, 'Mikro bağlantı bilgisi eksik; yeniden bağlayın.');
            }
        }

        return array_map('strval', $c);
    }
}
