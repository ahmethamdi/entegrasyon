<?php

declare(strict_types=1);

namespace App\Domain\Sync\Models;

use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Sync\Enums\SyncDomain;
use App\Support\Tenancy\BelongsToTenant;
use App\Support\Uuid\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Kanalın asenkron toplu işi — stok/fiyat push'unun döndüğü kimlik.
 *
 * Yazan: `ChannelBatchRecorder` (push anında) ve `ResolveChannelBatch`
 * (yoklama). Kiracıya aittir; global scope her sorguyu kiracıyla sınırlar.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $channel_connection_id
 * @property SyncDomain $domain
 * @property string $external_batch_id
 * @property string $status
 * @property int $item_count
 * @property int $failed_count
 * @property int $poll_count
 * @property Carbon|null $last_polled_at
 * @property string|null $last_poll_error
 * @property Carbon $expires_at
 * @property Carbon|null $completed_at
 */
class ChannelBatch extends Model
{
    use BelongsToTenant;
    use HasUuidV7;

    public const STATUS_PENDING = 'pending';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'tenant_id',
        'channel_connection_id',
        'domain',
        'external_batch_id',
        'status',
        'item_count',
        'failed_count',
        'poll_count',
        'last_polled_at',
        'last_poll_error',
        'expires_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'domain' => SyncDomain::class,
            'item_count' => 'integer',
            'failed_count' => 'integer',
            'poll_count' => 'integer',
            'last_polled_at' => 'datetime',
            'expires_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(ChannelConnection::class, 'channel_connection_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ChannelBatchItem::class);
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }
}
