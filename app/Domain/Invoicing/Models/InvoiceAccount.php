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

    public const STATUS_CONNECTED = 'connected';

    /** Token alındı ama kullanıcının birden çok firması var — seçim bekleniyor. */
    public const STATUS_NEEDS_COMPANY = 'needs_company';

    /** Yenileme reddedildi; satıcı yeniden bağlamalı. */
    public const STATUS_REVOKED = 'revoked';

    /** Ayar: fatura serisi (ör. "TRY"). Boşsa entegratörün varsayılanı. */
    public const SETTING_SERIES = 'invoice_series';

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
}
