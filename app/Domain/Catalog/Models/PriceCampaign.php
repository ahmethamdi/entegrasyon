<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Models;

use App\Domain\Channels\Models\ChannelConnection;
use App\Support\Tenancy\BelongsToTenant;
use App\Support\Uuid\HasUuidV7;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * Süreli kampanya: tarih aralığında seçili varyantlara, seçili kanallarda
 * yüzde ya da tutar indirimi.
 *
 * Fiyat SAKLANMAZ — `Listing::effectivePrice()` anlık hesaplar (migration
 * başlığındaki gerekçe).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property string $discount_type
 * @property string $discount_value
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property bool $show_compare_at
 * @property Carbon|null $start_pushed_at
 * @property Carbon|null $end_pushed_at
 * @property Carbon|null $cancelled_at
 */
class PriceCampaign extends Model
{
    use BelongsToTenant;
    use HasUuidV7;

    public const TYPE_PERCENT = 'percent';

    public const TYPE_AMOUNT = 'amount';

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_ENDED = 'ended';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'tenant_id',
        'name',
        'discount_type',
        'discount_value',
        'starts_at',
        'ends_at',
        'show_compare_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'discount_value' => 'decimal:2',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'show_compare_at' => 'boolean',
            'start_pushed_at' => 'datetime',
            'end_pushed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /** @param  Builder<self>  $query */
    public function scopeActiveAt(Builder $query, CarbonInterface $at): void
    {
        $query->whereNull('cancelled_at')
            ->where('starts_at', '<=', $at)
            ->where('ends_at', '>', $at);
    }

    public function status(?CarbonInterface $at = null): string
    {
        $at ??= now();

        return match (true) {
            $this->cancelled_at !== null => self::STATUS_CANCELLED,
            $this->starts_at->gt($at) => self::STATUS_SCHEDULED,
            $this->ends_at->lte($at) => self::STATUS_ENDED,
            default => self::STATUS_ACTIVE,
        };
    }

    public function variants(): BelongsToMany
    {
        return $this->belongsToMany(Variant::class, 'price_campaign_variants')->withPivot('tenant_id');
    }

    public function connections(): BelongsToMany
    {
        return $this->belongsToMany(ChannelConnection::class, 'price_campaign_connections')->withPivot('tenant_id');
    }
}
