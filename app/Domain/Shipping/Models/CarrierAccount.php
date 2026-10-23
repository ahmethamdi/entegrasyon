<?php

declare(strict_types=1);

namespace App\Domain\Shipping\Models;

use App\Support\Tenancy\BelongsToTenant;
use App\Support\Uuid\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Satıcının kendi kargo sözleşmesi (Yurtiçi / Aras) — kiracı + firma başına tek satır.
 *
 * `credentials` ŞİFRELİDİR (`encrypted:array`) ve `$hidden`'dadır: model
 * yanlışlıkla Inertia'ya ya da JSON'a giderse şifre sızmaz.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $provider
 * @property string $environment
 * @property array{username?: string, password?: string} $credentials
 * @property string $status
 * @property string|null $last_error
 * @property Carbon|null $verified_at
 */
class CarrierAccount extends Model
{
    use BelongsToTenant;
    use HasUuidV7;

    public const PROVIDER_YURTICI = 'yurtici';

    public const PROVIDER_ARAS = 'aras';

    public const PROVIDERS = [self::PROVIDER_YURTICI, self::PROVIDER_ARAS];

    public const ENV_LIVE = 'live';

    public const ENV_TEST = 'test';

    public const ENVIRONMENTS = [self::ENV_LIVE, self::ENV_TEST];

    public const STATUS_CONNECTED = 'connected';

    /** Firma kimliği reddetti; satıcı yeniden bağlamalı. */
    public const STATUS_REVOKED = 'revoked';

    protected $fillable = [
        'tenant_id',
        'provider',
        'environment',
        'credentials',
        'status',
        'last_error',
        'verified_at',
    ];

    protected $hidden = ['credentials'];

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
            'verified_at' => 'datetime',
        ];
    }

    public function isUsable(): bool
    {
        return $this->status === self::STATUS_CONNECTED;
    }

    public function username(): string
    {
        return (string) ($this->credentials['username'] ?? '');
    }

    public function password(): string
    {
        return (string) ($this->credentials['password'] ?? '');
    }

    public static function displayName(string $provider): string
    {
        return match ($provider) {
            self::PROVIDER_YURTICI => 'Yurtiçi Kargo',
            self::PROVIDER_ARAS => 'Aras Kargo',
            default => $provider,
        };
    }
}
