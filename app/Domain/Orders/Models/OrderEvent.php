<?php

declare(strict_types=1);

namespace App\Domain\Orders\Models;

use App\Domain\Messaging\Models\InboxMessage;
use App\Domain\Orders\Enums\OrderEventType;
use App\Support\Tenancy\BelongsToTenant;
use App\Support\Uuid\HasUuidV7;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Sipariş yaşam döngüsü olayı.
 *
 * Mimari Karar Dokümanı v2.2 · §4 · order_events, §1 · Karar 10.
 *
 * BU TABLO İKİ İŞ YAPAR:
 *   1. Denetim kaydı — sipariş ne zaman ne oldu
 *   2. Stok hareketlerinin IDEMPOTENCY ÇIPASI
 *
 * Kanaldan gelen her iptal ve iade ÖNCE bir olay satırı yaratır; hareket
 * anahtarı o satırın kimliğinden türetilir. external_ref kanalın olay
 * kimliği olduğu için aynı iptal ikinci kez geldiğinde satır çakışır ve
 * hareket hiç oluşmaz. Farklı iki kısmi iade farklı external_ref taşır,
 * iki ayrı olay ve iki ayrı hareket üretir.
 *
 * Anahtarın SATIR kimliğine değil OLAY kimliğine bağlanmasının sebebi budur:
 * bir sipariş satırı bir kez satılır ama birden fazla kez kısmen iade
 * edilebilir.
 *
 * @property string $id
 * @property OrderEventType $type
 * @property string|null $external_ref
 */
class OrderEvent extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuidV7;

    protected $fillable = [
        'tenant_id',
        'order_id',
        'order_line_id',
        'type',
        'quantity',
        'external_ref',
        'payload',
        'occurred_at',
        'source',
        'inbox_message_id',
    ];

    protected function casts(): array
    {
        return [
            'type' => OrderEventType::class,
            'quantity' => 'integer',
            'payload' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(OrderLine::class, 'order_line_id');
    }

    public function inboxMessage(): BelongsTo
    {
        return $this->belongsTo(InboxMessage::class, 'inbox_message_id');
    }
}
