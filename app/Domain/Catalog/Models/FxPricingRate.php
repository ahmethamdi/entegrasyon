<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Fiyatlamada kullanılan kur — para birimi başına tek satır ("çapa").
 *
 * Çapa yalnız yeni bülten eşikten (`fx.anchor_threshold_percent`) fazla
 * saparsa değişir; değişince etkilenen ilanların fiyatı yeniden gönderilir
 * (`FetchExchangeRates`). Kiracıya ait DEĞİLDİR.
 *
 * @property string $currency
 * @property string $rate 1 birim yabancı para = kaç TL
 * @property Carbon $rate_date
 * @property string $source
 * @property Carbon $anchored_at
 */
class FxPricingRate extends Model
{
    protected $primaryKey = 'currency';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['currency', 'rate', 'rate_date', 'source', 'anchored_at'];

    protected function casts(): array
    {
        return [
            'rate' => 'decimal:6',
            'rate_date' => 'date',
            'anchored_at' => 'datetime',
        ];
    }
}
