<?php

declare(strict_types=1);

namespace App\Domain\Sms\Models;

use App\Support\Tenancy\BelongsToTenant;
use App\Support\Uuid\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Satıcının SMS sağlayıcı hesabı (Netgsm) — kiracı başına tek satır.
 *
 * `credentials` ve `notify_phone` ŞİFRELİDİR ve `$hidden`'dadır: model
 * yanlışlıkla Inertia'ya ya da JSON'a giderse şifre ve numara sızmaz.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $provider
 * @property string $environment
 * @property array<string, string> $credentials
 * @property string|null $sender
 * @property list<string> $senders
 * @property string $status
 * @property string|null $last_error
 * @property Carbon|null $verified_at
 * @property string|null $notify_phone
 * @property list<string> $events
 * @property bool $quiet_hours
 * @property Carbon|null $notify_since
 */
class SmsAccount extends Model
{
    use BelongsToTenant;
    use HasUuidV7;

    public const PROVIDER_NETGSM = 'netgsm';

    public const ENV_LIVE = 'live';

    public const ENV_TEST = 'test';

    public const ENVIRONMENTS = [self::ENV_LIVE, self::ENV_TEST];

    public const STATUS_CONNECTED = 'connected';

    /** Sağlayıcı kimliği reddetti; satıcı yeniden bağlamalı. */
    public const STATUS_REVOKED = 'revoked';

    // ── Bildirim türleri ─────────────────────────────────────────────
    public const EVENT_NEW_ORDER = 'new_order';

    public const EVENT_STOCK_OUT = 'stock_out';

    public const EVENT_CHANNEL_PROBLEM = 'channel_problem';

    /** Alıcıya "kargoya verildi" — yalnız alıcı telefonunu veren kanallar (Shopify, WooCommerce). */
    public const EVENT_BUYER_SHIPPED = 'buyer_shipped';

    /** Satıcının kendi numarasına gidenler. */
    public const SELLER_EVENTS = [self::EVENT_NEW_ORDER, self::EVENT_STOCK_OUT, self::EVENT_CHANNEL_PROBLEM];

    public const EVENTS = [...self::SELLER_EVENTS, self::EVENT_BUYER_SHIPPED];

    protected $fillable = [
        'tenant_id',
        'provider',
        'environment',
        'credentials',
        'sender',
        'senders',
        'status',
        'last_error',
        'verified_at',
        'notify_phone',
        'events',
        'quiet_hours',
        'notify_since',
    ];

    protected $hidden = ['credentials', 'notify_phone'];

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
            'notify_phone' => 'encrypted',
            'senders' => 'array',
            'events' => 'array',
            'quiet_hours' => 'boolean',
            'verified_at' => 'datetime',
            'notify_since' => 'datetime',
        ];
    }

    public function isUsable(): bool
    {
        return $this->status === self::STATUS_CONNECTED && $this->sender !== null && $this->sender !== '';
    }

    public function username(): string
    {
        return (string) ($this->credentials['username'] ?? '');
    }

    public function password(): string
    {
        return (string) ($this->credentials['password'] ?? '');
    }

    public function wants(string $event): bool
    {
        return in_array($event, $this->events ?? [], true);
    }
}
