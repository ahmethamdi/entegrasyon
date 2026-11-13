<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Providers\Logo;

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
use DateTimeZone;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;

/**
 * Logo Tiger / Wings — "Logo Objects REST Servis" ile siparişi ERP'ye aktarır.
 *
 * KAPSAM: SATIŞ SİPARİŞİ (`POST api/v1/salesOrders`). Bilinçli seçim:
 *   · Logo'nun herkese açık belgesindeki TEK eksiksiz yazma örneği satış
 *     siparişidir (alanlar birebir oradan: ARP_CODE, TRANSACTIONS.items[]
 *     TYPE 0 / MASTER_CODE / QUANTITY / PRICE / VAT_RATE / UNIT_CODE /
 *     UNIT_CONV1-2 / EDT_CURR, yanıtta INTERNAL_REFERENCE).
 *   · Satış FATURASI (`salesInvoices`) için belgede gövde örneği ve fatura
 *     türü (TYPE) kodları YOK → tahminle resmî belge türü üretilmez.
 *   · Cari kartı (`Arps`) alanları yalnız resmî olmayan kaynaklarda →
 *     cari AÇILMAZ; satıcının Logo'da tanımlı pazar yeri carisinin KODU
 *     ayardan kullanılır (ör. "120.TRENDYOL").
 * Sipariş Logo'da faturaya / irsaliyeye satıcının kendi akışıyla çevrilir;
 * e-belgeyi Logo'nun e-Fatura modülü keser. Bu yüzden kip HER ZAMAN
 * "yalnız muhasebeye işle"dir (`InvoiceAccount::mode`), `poll` çağrılmaz.
 *
 * KİMLİK (belge): `POST api/v1/token`, `Authorization: Basic
 * base64(ClientID:ClientSecret)` (34Pazar'ın çözüm ortağı kimliği,
 * `LogoApp`), gövde `grant_type=password&username=…&password=…&firmno=…`
 * → `access_token`, `expires_in` (sn). Sonraki istekler `Bearer`.
 *
 * ERİŞİM: servis satıcının sunucusunda; satıcı HTTPS + 443 ile dışarı açar
 * (`ErpEndpoint` — SSRF koruması, IP sabitleme).
 *
 * GERÇEK KURULUMDA DOĞRULANMADI (docs/LOGO-API-NOTLARI.md): PRICE'ın KDV
 * hariç yorumlanması, TIME alanının gönderilmemesi, hata gövdesi biçimi,
 * Go 3 / Go Wings uyumu.
 */
final class LogoInvoiceProvider implements InvoiceProvider
{
    private const TIMEZONE = 'Europe/Istanbul';

    /** Belge örneği: TRANSACTIONS satırında TYPE 0 = malzeme satırı. */
    private const LINE_TYPE_ITEM = 0;

    public const SETTING_ARP_CODE = 'arp_code';

    public const SETTING_UNIT_CODE = 'unit_code';

    public function __construct(
        private readonly ErpEndpoint $http,
        private readonly InvoiceAccount $account,
    ) {}

    /**
     * Bağlanırken: token alınabiliyor mu (kullanıcı, şifre, firma no, ClientID).
     *
     * @param  array{base_url: string, username: string, password: string, firm_no: string}  $credentials
     *
     * @throws InvoiceProviderException
     */
    public static function probe(ErpEndpoint $http, array $credentials): void
    {
        self::requestToken($http, $credentials);
    }

    public function submit(Invoice $invoice, InvoiceDraft $draft, ?SubmitOptions $options = null): void
    {
        if ($invoice->provider_invoice_id !== null) {
            return;
        }

        $arpCode = $this->account->setting(self::SETTING_ARP_CODE)
            ?? throw new InvoiceProviderException(ErrorClass::VALIDATION, 'Logo cari kodu tanımlı değil; fatura ayarlarından girin.');
        $unit = $this->account->setting(self::SETTING_UNIT_CODE) ?? 'ADET';

        $items = array_map(function (InvoiceLine $line) use ($unit): array {
            $code = $line->sku !== null && trim($line->sku) !== '' ? trim($line->sku) : null;

            if ($code === null) {
                throw new InvoiceProviderException(ErrorClass::VALIDATION, "Kalemin stok kodu (SKU) yok: Logo malzeme kodu SKU ile eşlenir ({$line->description}).");
            }

            return [
                'TYPE' => self::LINE_TYPE_ITEM,
                'MASTER_CODE' => $code,
                'QUANTITY' => $line->quantity,
                'PRICE' => (float) $line->netUnitPrice(),
                'VAT_RATE' => $line->vatRate,
                'UNIT_CODE' => $unit,
                'UNIT_CONV1' => 1,
                'UNIT_CONV2' => 1,
                'EDT_CURR' => 1,
            ];
        }, $draft->lines);

        $reply = $this->call('POST', '/api/v1/salesOrders', [
            'INTERNAL_REFERENCE' => 0,
            // "~" = numarayı Logo verir (belge örneği).
            'NUMBER' => '~',
            'DATE' => $draft->orderedAt->setTimezone(new DateTimeZone(self::TIMEZONE))->format('Y-m-d').'T00:00:00',
            'ARP_CODE' => $arpCode,
            'CURRSEL_TOTAL' => 1,
            'TRANSACTIONS' => ['items' => $items],
        ]);

        $data = ErpEndpoint::decode($reply) ?? [];
        $id = $data['INTERNAL_REFERENCE'] ?? null;

        if (! $reply->successful()) {
            throw $this->failure($reply, 'Logo siparişi kaydetmedi.');
        }

        if (! is_scalar($id) || (int) $id <= 0) {
            throw new InvoiceProviderException(ErrorClass::SERVER_ERROR, 'Logo sipariş kimliği (INTERNAL_REFERENCE) dönmedi.');
        }

        // Kimlik ANINDA: iş burada ölürse yeniden deneme ikinci sipariş açmaz.
        $invoice->forceFill([
            'provider_contact_id' => $arpCode,
            'provider_invoice_id' => (string) $id,
            'invoice_number' => is_string($data['NUMBER'] ?? null) && $data['NUMBER'] !== '~' ? $data['NUMBER'] : null,
        ])->save();
    }

    /** E-belge Logo'nun kendi modülünde: sorulacak asenkron iş yok. */
    public function poll(Invoice $invoice): IssueOutcome
    {
        return IssueOutcome::failed('Logo e-belge kesmez; sipariş Logo\'ya aktarıldı.');
    }

    public function pdfUrl(Invoice $invoice): ?string
    {
        return null;
    }

    /** ERP kaydı uzaktan SİLİNMEZ: satıcının defteri, iptali Logo'dan yapılır. */
    public function cancel(Invoice $cancellation, Invoice $sale): void
    {
        throw new InvoiceProviderException(ErrorClass::VALIDATION, 'Logo\'ya aktarılan sipariş buradan iptal edilmez; Logo\'dan iptal edin.');
    }

    public function recordReturn(Invoice $return, Invoice $sale, ReturnDraft $draft): void
    {
        throw new InvoiceProviderException(ErrorClass::VALIDATION, 'İade kaydını Logo\'da açın; iade fişi türü belgede doğrulanmadı.');
    }

    /**
     * Bearer'lı istek; 401 gelirse token bir kez tazelenir, yine 401 → AUTHENTICATION.
     *
     * @param  array<string, mixed>|null  $json
     */
    private function call(string $method, string $path, ?array $json = null): Response
    {
        $credentials = $this->credentials();

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $reply = $this->http->send($method, $credentials['base_url'].$path, ['Authorization' => 'Bearer '.$this->token($credentials)], $json);

            if ($reply->status() !== 401) {
                return $reply;
            }

            Cache::forget($this->tokenKey());
        }

        throw new InvoiceProviderException(ErrorClass::AUTHENTICATION, 'Logo oturumu reddetti; kullanıcı adı/şifre/firma numarasını yeniden girin.');
    }

    /** @param array{base_url: string, username: string, password: string, firm_no: string} $credentials */
    private function token(array $credentials): string
    {
        $cached = Cache::get($this->tokenKey());

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        [$token, $expiresIn] = self::requestToken($this->http, $credentials);

        // Belge örneğinde expires_in 119 sn — süre kurulumda ayarlanır; 30 sn pay.
        if ($expiresIn > 60) {
            Cache::put($this->tokenKey(), $token, now()->addSeconds($expiresIn - 30));
        }

        return $token;
    }

    /**
     * @param  array{base_url: string, username: string, password: string, firm_no: string}  $credentials
     * @return array{0: string, 1: int}
     */
    private static function requestToken(ErpEndpoint $http, array $credentials): array
    {
        $app = LogoApp::credentials()
            ?? throw new InvoiceProviderException(ErrorClass::VALIDATION, 'Logo çözüm ortağı kimliği (ClientID/ClientSecret) tanımlı değil.');

        $reply = $http->send('POST', $credentials['base_url'].'/api/v1/token', [
            'Authorization' => 'Basic '.base64_encode($app['id'].':'.$app['secret']),
        ], form: [
            'grant_type' => 'password',
            'username' => $credentials['username'],
            'password' => $credentials['password'],
            'firmno' => $credentials['firm_no'],
        ]);

        $data = ErpEndpoint::decode($reply) ?? [];
        $token = $data['access_token'] ?? null;

        if ($reply->successful() && is_string($token) && $token !== '') {
            return [$token, (int) ($data['expires_in'] ?? 0)];
        }

        if ($reply->clientError()) {
            throw new InvoiceProviderException(ErrorClass::AUTHENTICATION, self::message($data) ?? 'Logo kullanıcı adı, şifre ya da firma numarasını reddetti.');
        }

        throw new InvoiceProviderException(ErrorClass::SERVER_ERROR, 'Logo token vermedi (HTTP '.$reply->status().').');
    }

    /** @return array{base_url: string, username: string, password: string, firm_no: string} */
    private function credentials(): array
    {
        $c = (array) ($this->account->credentials ?? []);

        foreach (['base_url', 'username', 'password', 'firm_no'] as $key) {
            if (! is_string($c[$key] ?? null) || $c[$key] === '' && $key !== 'password') {
                throw new InvoiceProviderException(ErrorClass::AUTHENTICATION, 'Logo bağlantı bilgisi eksik; yeniden bağlayın.');
            }
        }

        return ['base_url' => $c['base_url'], 'username' => $c['username'], 'password' => (string) $c['password'], 'firm_no' => $c['firm_no']];
    }

    private function tokenKey(): string
    {
        return 'invoicing:logo:token:'.$this->account->id;
    }

    private function failure(Response $reply, string $fallback): InvoiceProviderException
    {
        $message = self::message(ErpEndpoint::decode($reply) ?? []) ?? $fallback;

        // Okunabilir 4xx = Logo'nun kuralı (kalıcı); 5xx / gövdesiz = geçici.
        $class = $reply->clientError() ? ErrorClass::VALIDATION : ErrorClass::SERVER_ERROR;

        return new InvoiceProviderException($class, mb_substr($message, 0, 500));
    }

    /**
     * Hata gövdesi belgede YOK; yaygın .NET Web API biçimleri okunur
     * (`Message`, `error_description`, `ModelState`) — hiçbiri yoksa null.
     *
     * @param  array<mixed>  $data
     */
    private static function message(array $data): ?string
    {
        foreach (['Message', 'message', 'error_description', 'ExceptionMessage', 'error'] as $key) {
            if (is_string($data[$key] ?? null) && trim($data[$key]) !== '') {
                return trim($data[$key]);
            }
        }

        return null;
    }
}
