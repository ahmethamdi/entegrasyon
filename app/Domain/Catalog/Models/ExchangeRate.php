<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Models;

use App\Support\Uuid\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Bir kur bülteninin ham satırı (TCMB). Kiracıya ait DEĞİLDİR.
 *
 * Fiyatlama bu tabloyu doğrudan okumaz; `fx_pricing_rates` çapasını okur.
 * Bu tablo "en son ne zaman kur geldi" (bayatlık) ve geçmiş için tutulur.
 *
 * @property string $id
 * @property string $source
 * @property Carbon $rate_date
 * @property string $currency
 * @property int $unit
 * @property string|null $forex_buying
 * @property string|null $forex_selling
 * @property string|null $bulletin_no
 */
class ExchangeRate extends Model
{
    use HasUuidV7;

    public const SOURCE_TCMB = 'tcmb';

    protected $fillable = [
        'source',
        'rate_date',
        'currency',
        'unit',
        'forex_buying',
        'forex_selling',
        'bulletin_no',
        'fetched_at',
    ];

    protected function casts(): array
    {
        return [
            'rate_date' => 'date',
            'fetched_at' => 'datetime',
            'unit' => 'integer',
        ];
    }
}
