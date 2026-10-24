<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Models;

use App\Support\Tenancy\BelongsToTenant;
use App\Support\Uuid\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Satıcının fatura entegratörü hesabı — kiracı başına tek satır.
 *
 * `credentials` ŞİFRELİDİR (`encrypted:array`, anahtar rotasyonu
 * `APP_PREVIOUS_KEYS` ile) ve `$hidden`'dadır: model yanlışlıkla
 * Inertia'ya ya da JSON'a giderse token sızmaz.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $provider
 * @property array<string, mixed>|null $credentials
 * @property Carbon|null $token_expires_at
 * @property string|null $company_id
 * @property string|null $company_name
 * @property array<string, mixed>|null $settings
 * @property string $status
 * @property string|null $last_error
 */
class InvoiceAccount extends Model
{
    use BelongsToTenant;
    use HasUuidV7;

    public const PROVIDER_PARASUT = 'parasut';

    /**
     * Nilvera — satıcı portalda kendi API anahtarını üretir; 34Pazar'ın
     * bir uygulama anahtarına ya da aracı sözleşmesine ihtiyaç YOKTUR.
     * Yalnız e-belge keser: muhasebe (cari, tahsilat, iade kaydı) yoktur.
     */
    public const PROVIDER_NILVERA = 'nilvera';

    public const PROVIDERS = [self::PROVIDER_PARASUT, self::PROVIDER_NILVERA];

    /** Ayar: Nilvera ortamı — `live` (varsayılan) | `test`. */
    public const SETTING_ENVIRONMENT = 'environment';

    public const ENVIRONMENT_LIVE = 'live';

    public const ENVIRONMENT_TEST = 'test';

    public const STATUS_CONNECTED = 'connected';

    /** Token alındı ama kullanıcının birden çok firması var — seçim bekleniyor. */
    public const STATUS_NEEDS_COMPANY = 'needs_company';

    /** Yenileme reddedildi; satıcı yeniden bağlamalı. */
    public const STATUS_REVOKED = 'revoked';

    /** Ayar: fatura serisi (ör. "TRY"). Boşsa entegratörün varsayılanı. */
    public const SETTING_SERIES = 'invoice_series';

    /**
     * Ayar: kip. `e_document` (varsayılan) e-fatura/e-arşiv keser;
     * `books_only` yalnız muhasebeye işler (cari + satış faturası +
     * tahsilat) — e-belgesini başka yerden (ör. pazar yerinin kendi fatura
     * hizmetinden) kesen satıcı için. O satıcıya e-belge kesilseydi aynı
     * satışa İKİ RESMÎ belge düşerdi.
     */
    public const SETTING_MODE = 'mode';

    public const MODE_E_DOCUMENT = 'e_document';

    public const MODE_BOOKS_ONLY = 'books_only';

    public const MODES = [self::MODE_E_DOCUMENT, self::MODE_BOOKS_ONLY];

    /** Ayar: otomatik aktarım — kapalı | kargoya verilince | teslim edilince. */
    public const SETTING_AUTO_ISSUE = 'auto_issue';

    public const AUTO_OFF = 'off';

    public const AUTO_SHIPPED = 'shipped';

    public const AUTO_DELIVERED = 'delivered';

    public const AUTO_ISSUE_OPTIONS = [self::AUTO_OFF, self::AUTO_SHIPPED, self::AUTO_DELIVERED];

    /**
     * Ayar: otomatik aktarımın açıldığı an (ISO 8601). Yalnız bu andan SONRA
     * verilen siparişler otomatik faturalanır — bkz. `MaybeAutoInvoice`.
     */
    public const SETTING_AUTO_ISSUE_SINCE = 'auto_issue_since';

    /** Ayar: kanal türü kodu → entegratördeki kasa/banka hesabı kimliği. */
    public const SETTING_PAYMENT_ACCOUNTS = 'payment_accounts';

    protected $fillable = [
        'tenant_id',
        'provider',
        'credentials',
        'token_expires_at',
        'company_id',
        'company_name',
        'settings',
        'status',
        'last_error',
    ];

    protected $hidden = ['credentials'];

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
            'token_expires_at' => 'datetime',
            'settings' => 'array',
        ];
    }

    public function isUsable(): bool
    {
        return $this->status === self::STATUS_CONNECTED && $this->company_id !== null;
    }

    public function setting(string $key): ?string
    {
        $value = $this->settings[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * Bilinmeyen/eksik değer varsayılana düşer: e-belge kesilir (1. dilim davranışı).
     *
     * Muhasebesi olmayan entegratörde (Nilvera) kip HER ZAMAN e-belgedir:
     * "yalnız muhasebeye işle" orada hiçbir şey yapmaz ve sipariş
     * faturalanmış görünüp hiçbir yere işlenmemiş olurdu.
     */
    public function mode(): string
    {
        if (! $this->keepsBooks()) {
            return self::MODE_E_DOCUMENT;
        }

        $mode = $this->setting(self::SETTING_MODE);

        return in_array($mode, self::MODES, true) ? $mode : self::MODE_E_DOCUMENT;
    }

    /**
     * Entegratör satıcının muhasebesini tutuyor mu — cari, tahsilat, iade
     * kaydı. Paraşüt evet; Nilvera yalnız e-belge keser.
     */
    public function keepsBooks(): bool
    {
        return $this->provider === self::PROVIDER_PARASUT;
    }

    public function providerName(): string
    {
        return match ($this->provider) {
            self::PROVIDER_NILVERA => 'Nilvera',
            default => 'Paraşüt',
        };
    }

    public function issuesEDocument(): bool
    {
        return $this->mode() === self::MODE_E_DOCUMENT;
    }

    /** Bilinmeyen/eksik değer KAPALI sayılır: emin olunamayınca fatura kesilmez. */
    public function autoIssue(): string
    {
        $value = $this->setting(self::SETTING_AUTO_ISSUE);

        return in_array($value, self::AUTO_ISSUE_OPTIONS, true) ? $value : self::AUTO_OFF;
    }

    public function autoIssueSince(): ?Carbon
    {
        $value = $this->setting(self::SETTING_AUTO_ISSUE_SINCE);

        if ($value === null) {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /** Kanalın tahsilat hesabı; eşleme yoksa null (fatura açık hesap kalır). */
    public function paymentAccountFor(?string $channelCode): ?string
    {
        if ($channelCode === null || ! $this->keepsBooks()) {
            return null;
        }

        $value = ((array) ($this->settings[self::SETTING_PAYMENT_ACCOUNTS] ?? []))[$channelCode] ?? null;

        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }
}
