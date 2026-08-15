<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Models;

use App\Domain\Catalog\Models\Variant;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Inventory\Enums\MovementType;
use App\Support\Tenancy\BelongsToTenant;
use App\Support\Uuid\HasUuidV7;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only stok hareketi.
 *
 * Mimari Karar Dokümanı v2.2 · §4 · tablo 012.
 *
 * on_hand_after ve reserved_after KIRPILMAMIŞ gerçek bakiyeyi taşır; fazla
 * satışta negatiftir ve projeksiyondaki değerle birebir aynıdır. Bu sayede
 * ledger tek başına okunarak bakiye doğrulanabilir.
 *
 * Bu tablo yalnızca ekleme alır — güncelleme ve silme yapılmaz.
 *
 * @property string $id
 * @property string $tenant_id
 * @property MovementType $type
 * @property int $on_hand_delta
 * @property int $reserved_delta
 * @property int $on_hand_after
 * @property int $reserved_after
 * @property string $idempotency_key
 */
class InventoryMovement extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuidV7;

    protected $fillable = [
        'tenant_id',
        'warehouse_id',
        'variant_id',
        'type',
        'on_hand_delta',
        'reserved_delta',
        'on_hand_after',
        'reserved_after',
        'source_type',
        'source_id',
        'channel_connection_id',
        'idempotency_key',
        'occurred_at',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'type' => MovementType::class,
            'on_hand_delta' => 'integer',
            'reserved_delta' => 'integer',
            'on_hand_after' => 'integer',
            'reserved_after' => 'integer',
            'occurred_at' => 'datetime',
        ];
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(Variant::class);
    }

    public function channelConnection(): BelongsTo
    {
        return $this->belongsTo(ChannelConnection::class);
    }
}
