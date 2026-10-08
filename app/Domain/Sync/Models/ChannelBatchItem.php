<?php

declare(strict_types=1);

namespace App\Domain\Sync\Models;

use App\Support\Tenancy\BelongsToTenant;
use App\Support\Uuid\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Toplu işin taşıdığı tek satır — operasyon × listing.
 *
 * `reason` sync state'e YAZILAN metnin birebir aynısıdır; başarılı bir
 * sonraki iş satırdaki hatanın toplu işten geldiğini bu eşitlikle anlar.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $channel_batch_id
 * @property string $listing_id
 * @property string|null $sync_operation_id
 * @property string $external_id
 * @property int $entity_version
 * @property string $outcome
 * @property string|null $reason
 * @property string|null $error_class
 * @property Carbon|null $resolved_at
 */
class ChannelBatchItem extends Model
{
    use BelongsToTenant;
    use HasUuidV7;

    public const OUTCOME_PENDING = 'pending';

    public const OUTCOME_EXPIRED = 'expired';

    protected $fillable = [
        'tenant_id',
        'channel_batch_id',
        'listing_id',
        'sync_operation_id',
        'external_id',
        'entity_version',
        'outcome',
        'reason',
        'error_class',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'entity_version' => 'integer',
            'resolved_at' => 'datetime',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ChannelBatch::class, 'channel_batch_id');
    }
}
