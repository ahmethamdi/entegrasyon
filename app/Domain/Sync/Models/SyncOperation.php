<?php

declare(strict_types=1);

namespace App\Domain\Sync\Models;

use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Messaging\Models\OutboxEvent;
use App\Domain\Sync\Enums\SyncIntent;
use App\Domain\Sync\Enums\SyncOperationStatus;
use App\Support\Tenancy\BelongsToTenant;
use App\Support\Uuid\HasUuidV7;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Senkron niyeti — listing × alan × sürüm.
 *
 * Mimari Karar Dokümanı v2.2 · §4 · sync_operations, §8.
 *
 * GRANÜLERLİK: bir operasyon TEK bir listing × domain × sürüm üçlüsünü
 * temsil eder. Üç kanalda listelenen bir varyantın stok değişimi ÜÇ ayrı
 * operasyon üretir. Biri 429 alıp yeniden denemeye girdiğinde diğer ikisi
 * bağımsız tamamlanır ve kendi listing_sync_states satırını günceller.
 *
 * outbox_event_id ÜZERİNDE TEKİLLİK YOKTUR — bir olay N operasyona yayılır.
 * Tekillik kısıtı fan-out'u imkânsız kılardı; yalnızca korelasyon indeksi var.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $channel_connection_id
 * @property string $operation_type
 * @property SyncIntent $intent
 * @property string $entity_id listing_id
 * @property int $entity_version
 * @property string $idempotency_key
 * @property SyncOperationStatus $status
 * @property int $attempt_count
 * @property string|null $outbox_event_id
 * @property string|null $reconciliation_item_id
 */
class SyncOperation extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuidV7;

    protected $fillable = [
        'tenant_id',
        'channel_connection_id',
        'operation_type',
        'intent',
        'entity_type',
        'entity_id',
        'entity_version',
        'idempotency_key',
        'status',
        'attempt_count',
        'priority',
        'scheduled_at',
        'completed_at',
        'last_error_class',
        'outbox_event_id',
        'reconciliation_item_id',
    ];

    protected function casts(): array
    {
        return [
            'intent' => SyncIntent::class,
            'status' => SyncOperationStatus::class,
            'entity_version' => 'integer',
            'attempt_count' => 'integer',
            'priority' => 'integer',
            'scheduled_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(ChannelConnection::class, 'channel_connection_id');
    }

    public function outboxEvent(): BelongsTo
    {
        return $this->belongsTo(OutboxEvent::class, 'outbox_event_id');
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class, 'entity_id');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(SyncAttempt::class);
    }

    public function isRepair(): bool
    {
        return $this->intent === SyncIntent::REPAIR;
    }
}
