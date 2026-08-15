<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Models;

use App\Domain\Catalog\Models\Variant;
use App\Support\Tenancy\BelongsToTenant;
use App\Support\Uuid\HasUuidV7;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Stok bakiyesi — ledger'ın projeksiyonu.
 *
 * Mimari Karar Dokümanı v2.2 · §4 · tablo 011.
 *
 * INVARIANT:
 *   on_hand   = Σ inventory_movements.on_hand_delta
 *   reserved  = Σ inventory_movements.reserved_delta
 *   available = on_hand − reserved   (veritabanında generated stored)
 *
 * on_hand ve available NEGATİF olabilir; negatif available fazla satılan
 * miktarın kendisidir. Ayrı bir oversold_qty sayacı TUTULMAZ.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $warehouse_id
 * @property string $variant_id
 * @property int $on_hand
 * @property int $reserved
 * @property int $available Salt okunur — veritabanı üretir.
 * @property int $version
 */
class InventoryLevel extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuidV7;

    protected $fillable = [
        'tenant_id',
        'warehouse_id',
        'variant_id',
        'on_hand',
        'reserved',
        'version',
        'last_movement_id',
    ];

    /**
     * available generated column'dur; asla yazılmaz.
     */
    protected $guarded = ['available'];

    protected function casts(): array
    {
        return [
            'on_hand' => 'integer',
            'reserved' => 'integer',
            'available' => 'integer',
            'version' => 'integer',
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

    public function lastMovement(): BelongsTo
    {
        return $this->belongsTo(InventoryMovement::class, 'last_movement_id');
    }

    /** Fazla satış var mı — negatif bakiye tek gerçek kaynaktır. */
    public function isOversold(): bool
    {
        return $this->available < 0;
    }
}
