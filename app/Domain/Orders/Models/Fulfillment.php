<?php

declare(strict_types=1);

namespace App\Domain\Orders\Models;

use App\Support\Tenancy\BelongsToTenant;
use App\Support\Uuid\HasUuidV7;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Kargo bildirimi.
 *
 * Mimari Karar Dokümanı v2.2 · §4 · fulfillments.
 *
 * Kargo STOK HAREKETİ ÜRETMEZ: mal zaten satışta düşülmüştür. Bu tablo
 * yalnızca teslim durumunu izler.
 *
 * @property string $id
 * @property string $status
 */
class Fulfillment extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuidV7;

    protected $fillable = [
        'tenant_id',
        'order_id',
        'external_id',
        'carrier',
        'tracking_number',
        'status',
        'shipped_at',
        'delivered_at',
    ];

    protected function casts(): array
    {
        return [
            'shipped_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
