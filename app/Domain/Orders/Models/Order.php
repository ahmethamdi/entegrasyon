<?php

declare(strict_types=1);

namespace App\Domain\Orders\Models;

use App\Domain\Channels\Models\ChannelConnection;
use App\Support\Tenancy\BelongsToTenant;
use App\Support\Uuid\HasUuidV7;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Kanaldan alınan sipariş.
 *
 * Mimari Karar Dokümanı v2.2 · §4 · Orders, §5 · Sipariş alımı.
 *
 * (channel_connection_id, external_id) TEKİLDİR ve sipariş alımının
 * idempotency çıpasıdır: aynı sipariş ikinci kez geldiğinde
 * ON CONFLICT DO NOTHING dalına düşer ve stok ikinci kez düşmez.
 *
 * SİPARİŞ ASLA GERİ ALINMAZ: pazaryeri onu kabul etmiştir, bu otoriter
 * gerçektir. Stok yetmese bile sipariş kaydedilir ve bakiye negatife düşer.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $external_id
 * @property string $status
 */
class Order extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuidV7;

    protected $fillable = [
        'tenant_id',
        'channel_connection_id',
        'external_id',
        'external_number',
        'status',
        'financial_status',
        'currency',
        'subtotal',
        'shipping_total',
        'tax_total',
        'grand_total',
        'placed_at',
        'customer_ref',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'shipping_total' => 'decimal:2',
            'tax_total' => 'decimal:2',
            'grand_total' => 'decimal:2',
            'placed_at' => 'datetime',
            'customer_ref' => 'array',
        ];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(ChannelConnection::class, 'channel_connection_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(OrderLine::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(OrderEvent::class);
    }

    public function fulfillments(): HasMany
    {
        return $this->hasMany(Fulfillment::class);
    }
}
