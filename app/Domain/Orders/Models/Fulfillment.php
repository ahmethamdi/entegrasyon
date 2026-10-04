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
 * PANELDEN GİRİLEN KARGO kanala GÖNDERİLİR (`PushFulfillment`); gönderimin
 * durumu `push_status`'ta yaşar. Kanaldan gelen satırda NULL'dır.
 *
 * @property string $id
 * @property string $order_id
 * @property string|null $external_id
 * @property string|null $carrier
 * @property string|null $tracking_number
 * @property string $status
 * @property string $source
 * @property string|null $push_status
 * @property int $push_attempts
 * @property string|null $push_error
 */
class Fulfillment extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuidV7;

    public const SOURCE_CHANNEL = 'channel';

    public const SOURCE_PANEL = 'panel';

    public const PUSH_PENDING = 'pending';

    public const PUSH_SENT = 'sent';

    public const PUSH_FAILED = 'failed';

    protected $fillable = [
        'tenant_id',
        'order_id',
        'external_id',
        'carrier',
        'tracking_number',
        'status',
        'shipped_at',
        'delivered_at',
        'source',
        'push_status',
        'push_attempts',
        'push_error',
        'pushed_at',
    ];

    protected function casts(): array
    {
        return [
            'shipped_at' => 'datetime',
            'delivered_at' => 'datetime',
            'pushed_at' => 'datetime',
            'push_attempts' => 'integer',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
