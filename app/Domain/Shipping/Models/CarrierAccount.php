<?php

declare(strict_types=1);

namespace App\Domain\Shipping\Models;

use App\Support\Tenancy\BelongsToTenant;
use App\Support\Uuid\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Satıcının kendi kargo sözleşmesi (Yurtiçi / Aras / HepsiJet / MNG) — kiracı + firma başına tek satır.
 *
 * `credentials` ŞİFRELİDİR (`encrypted:array`) ve `$hidden`'dadır: model
 * yanlışlıkla Inertia'ya ya da JSON'a giderse şifre sızmaz.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $provider
 * @property string $environment
 *                               Firmaya özgü ek alanlar (HepsiJet firma kodu, gönderici adresi …)
 *                               aynı şifreli `credentials` dizisinde durur — `credential()` okur.
 * @property array<string, string> $credentials
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

    /** HepsiJet — REST/JSON; kaynak firmanın partner belgesi (docs/HEPSIJET-API-NOTLARI.md). */
    public const PROVIDER_HEPSIJET = 'hepsijet';

    /** MNG Kargo — API Zone REST/JSON; resmî Swagger (docs/MNG-API-NOTLARI.md). */
    public const PROVIDER_MNG = 'mng';

    public const PROVIDERS = [self::PROVIDER_YURTICI, self::PROVIDER_ARAS, self::PROVIDER_HEPSIJET, self::PROVIDER_MNG];

    /**
     * Gerçek hesapta ya da firmanın test sunucusunda HİÇ denenmemiş firmalar
     * (yalnız belgeye göre yazıldı, sahte HTTP ile sınandı).
     *
     * Protokol: harici API'yle doğrulanmamış özellik çalışıyormuş gibi
     * gösterilmez. "Beta" rozeti KULLANILMAZ (24 Eki kararı, bkz.
     * `NoBetaBadgeContractTest`); bunun yerine Amazon/OTTO/Etsy gibi
     * VARSAYILAN GİZLİDİR ve pilot için `CARRIERS_UNVERIFIED_ENABLED` ile
     * açılır — açıkken kartta "gerçek hesapta henüz denenmedi" notu durur.
     */
    public const UNVERIFIED = [self::PROVIDER_HEPSIJET, self::PROVIDER_MNG];

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

    /** Firmaya özgü ek alan; boşsa null. */
    public function credential(string $key): ?string
    {
        $value = $this->credentials[$key] ?? null;

        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }

    public static function isUnverified(string $provider): bool
    {
        return in_array($provider, self::UNVERIFIED, true);
    }

    public static function displayName(string $provider): string
    {
        return match ($provider) {
            self::PROVIDER_YURTICI => 'Yurtiçi Kargo',
            self::PROVIDER_ARAS => 'Aras Kargo',
            self::PROVIDER_HEPSIJET => 'HepsiJet',
            self::PROVIDER_MNG => 'MNG Kargo',
            default => $provider,
        };
    }
}
