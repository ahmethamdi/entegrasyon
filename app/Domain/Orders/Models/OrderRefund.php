<?php

declare(strict_types=1);

namespace App\Domain\Orders\Models;

use App\Support\Tenancy\BelongsToTenant;
use App\Support\Uuid\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * İade / para iadesi finans olayı — sipariş durumundan AYRI (ADR-002).
 *
 * `kind = refund`: kanal geri ödenen tutarı bildirdi (kısmi ya da tam).
 * `kind = return`: adet bazlı iade; tutar BİLİNMİYOR (`amount` NULL).
 *
 * @property string $kind
 * @property string|null $amount
 */
class OrderRefund extends Model
{
    use BelongsToTenant;
    use HasUuidV7;

    public const KIND_REFUND = 'refund';

    public const KIND_RETURN = 'return';

    protected $fillable = [
        'tenant_id', 'order_id', 'channel_connection_id', 'idempotency_key', 'external_refund_id',
        'kind', 'amount', 'shipping_amount', 'currency', 'status', 'reason', 'raw', 'occurred_at', 'inbox_message_id',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'shipping_amount' => 'decimal:2',
            'raw' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(OrderRefundLine::class);
    }
}
