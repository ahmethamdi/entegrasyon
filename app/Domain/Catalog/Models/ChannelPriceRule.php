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
 * @property bool $fx_enabled
 * @property string|null $fx_currency
 * @property string $fx_markup_percent
 * @property string $fx_rounding
 * @property string|null $fx_min_price
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

    /** Bir üst ,00 ya da ,50 (12,31 → 12,50 · 12,61 → 13,00). Yalnız kur yuvarlamasında. */
    public const ROUNDING_HALF = 'half';

    /** ,49 ile bitir. Yalnız kur yuvarlamasında. */
    public const ROUNDING_X49 = 'x49';

    /** Kur çevirisinden SONRA uygulanan yuvarlamalar (kanal biriminde). */
    public const FX_ROUNDINGS = [
        self::ROUNDING_NONE,
        self::ROUNDING_WHOLE,
        self::ROUNDING_HALF,
        self::ROUNDING_X49,
        self::ROUNDING_X90,
        self::ROUNDING_X99,
    ];

    /**
     * TCMB'nin yayımladığı ve çevrilebilen birimler (24 Eki 2026 bülteni).
     * PLN, CZK, HUF bültende YOK — o kanallarda kanal fiyatı elle girilir.
     */
    public const FX_CURRENCIES = ['EUR', 'USD', 'GBP', 'CHF', 'SEK', 'NOK', 'DKK', 'CAD', 'AUD', 'JPY', 'RON', 'AED', 'SAR', 'QAR', 'KWD', 'AZN', 'CNY'];

    protected $fillable = [
        'tenant_id',
        'channel_connection_id',
        'markup_percent',
        'markup_amount',
        'rounding',
        'min_margin_percent',
        'fx_enabled',
        'fx_currency',
        'fx_markup_percent',
        'fx_rounding',
        'fx_min_price',
    ];

    protected function casts(): array
    {
        return [
            'markup_percent' => 'decimal:2',
            'markup_amount' => 'decimal:2',
            'min_margin_percent' => 'decimal:2',
            'fx_enabled' => 'boolean',
            'fx_markup_percent' => 'decimal:2',
            'fx_min_price' => 'decimal:2',
        ];
    }

    /** Fiyatı değiştiren bir şey var mı (yalnız zarar koruması olan kural fiyata dokunmaz). */
    public function changesPrice(): bool
    {
        return (float) $this->markup_percent !== 0.0
            || (float) $this->markup_amount !== 0.0
            || $this->rounding !== self::ROUNDING_NONE;
    }

    /** Kur çevirisi açık ve hedef birim seçilmiş mi. */
    public function convertsCurrency(): bool
    {
        return $this->fx_enabled && $this->fx_currency !== null && $this->fx_currency !== '';
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(ChannelConnection::class, 'channel_connection_id');
    }
}
