<?php

declare(strict_types=1);

namespace App\Domain\Returns\Models;

use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Orders\Models\Order;
use App\Domain\Returns\Enums\ReturnStatus;
use App\Support\Tenancy\BelongsToTenant;
use App\Support\Uuid\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Pazaryeri iade talebi — talep başına TEK satır (S4).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $channel_connection_id
 * @property string $channel_type_code
 * @property string $external_claim_id
 * @property string|null $external_order_number
 * @property string|null $external_package_id
 * @property string|null $order_id
 * @property string $match_status
 * @property string $status
 * @property string|null $channel_status
 */
class ReturnRequest extends Model
{
    use BelongsToTenant;
    use HasUuidV7;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'claimed_at' => 'datetime',
            'remote_modified_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ReturnRequestLine::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(ChannelConnection::class, 'channel_connection_id');
    }

    public function statusEnum(): ReturnStatus
    {
        return ReturnStatus::tryFrom($this->status) ?? ReturnStatus::UNKNOWN;
    }
}
