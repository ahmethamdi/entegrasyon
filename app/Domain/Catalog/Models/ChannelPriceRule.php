<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Models;

use App\Domain\Channels\Models\ChannelConnection;
use App\Support\Tenancy\BelongsToTenant;
use App\Support\Uuid\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bağlantı başına kalıcı fiyat kuralı: "Trendyol'da her şey +%15, ,90'a
 * yuvarla, maliyetin %10 altına düşme".
 *
 * Hesabı `PriceRuleCalculator` yapar; bu model yalnız veriyi taşır.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $channel_connection_id
 * @property string $markup_percent
 * @property string $markup_amount
 * @property string $rounding
 * @property string|null $min_margin_percent
 */
class ChannelPriceRule extends Model
{
    use BelongsToTenant;
    use HasUuidV7;

    public const ROUNDING_NONE = 'none';

    public const ROUNDING_WHOLE = 'whole';

    public const ROUNDING_X90 = 'x90';

    public const ROUNDING_X99 = 'x99';

    public const ROUNDINGS = [self::ROUNDING_NONE, self::ROUNDING_WHOLE, self::ROUNDING_X90, self::ROUNDING_X99];

    protected $fillable = [
        'tenant_id',
        'channel_connection_id',
        'markup_percent',
        'markup_amount',
        'rounding',
        'min_margin_percent',
    ];

    protected function casts(): array
    {
        return [
            'markup_percent' => 'decimal:2',
            'markup_amount' => 'decimal:2',
            'min_margin_percent' => 'decimal:2',
        ];
    }

    /** Fiyatı değiştiren bir şey var mı (yalnız zarar koruması olan kural fiyata dokunmaz). */
    public function changesPrice(): bool
    {
        return (float) $this->markup_percent !== 0.0
            || (float) $this->markup_amount !== 0.0
            || $this->rounding !== self::ROUNDING_NONE;
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(ChannelConnection::class, 'channel_connection_id');
    }
}
