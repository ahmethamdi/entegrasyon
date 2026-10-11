<?php

declare(strict_types=1);

namespace App\Domain\Finance\Models;

use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Orders\Models\Order;
use App\Support\Tenancy\BelongsToTenant;
use App\Support\Uuid\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Kanalın ham finansal işlemi + bizim yorumumuz (S1).
 *
 * Ham kolonlar DB tetikleyicisiyle değişmez; yalnız `type`,
 * `classification*`, `order_id`, `match_*` güncellenir.
 */
class ChannelFinancialTransaction extends Model
{
    use BelongsToTenant;
    use HasUuidV7;

    /** Anlamı canlı hesapta GÖZLENEN davranışla doğrulandı. */
    public const VERIFIED = 'verified';

    /** Tür tanındı (belge/enum) ama anlamı canlıda doğrulanmadı — kâra GİRMEZ. */
    public const UNVERIFIED = 'unverified';

    /** Tür tanınmadı — kâra GİRMEZ, görünür kalır. */
    public const UNKNOWN = 'unknown';

    public const MATCHED = 'matched';

    public const UNMATCHED = 'unmatched';

    public const AMBIGUOUS = 'ambiguous';

    public const NOT_ORDER_LEVEL = 'not_order_level';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'debt' => 'decimal:2',
            'credit' => 'decimal:2',
            'commission_amount' => 'decimal:2',
            'commission_rate' => 'decimal:4',
            'seller_revenue' => 'decimal:2',
            'transaction_date' => 'datetime',
            'payment_date' => 'datetime',
            'matched_at' => 'datetime',
            'raw' => 'array',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(ChannelConnection::class, 'channel_connection_id');
    }
}
