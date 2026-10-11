<?php

declare(strict_types=1);

namespace App\Domain\Orders\Models;

use App\Support\Tenancy\BelongsToTenant;
use App\Support\Uuid\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Kanal kesintisi — komisyon, kargo kesintisi, hizmet bedeli, stopaj (ADR-002).
 *
 * İŞARETLİ tutar: satıcıdan düşen NEGATİF, satıcıya dönen (komisyon iadesi)
 * POZİTİF. İki taban ayrı tutulur ve birbirini ezmez:
 *
 *   expected  sipariş anında kanalın bildirdiği / beklenen
 *   actual    hakedişte kesinleşen (S1 içe aktarması yazar)
 *
 * Kâr hesabı bir tür için `actual` varsa onu, yoksa `expected`'ı kullanır ve
 * hangisini kullandığını söyler. Ekleme-yalnız (DB tetikleyicisi).
 *
 * @property string $kind
 * @property string $basis
 * @property string $amount
 */
class OrderCharge extends Model
{
    use BelongsToTenant;
    use HasUuidV7;

    public const BASIS_EXPECTED = 'expected';

    public const BASIS_ACTUAL = 'actual';

    public const KINDS = ['commission', 'commission_refund', 'shipping_fee', 'service_fee', 'withholding', 'other'];

    protected $fillable = [
        'tenant_id', 'order_id', 'order_line_id', 'channel_connection_id', 'kind', 'basis',
        'amount', 'currency', 'rate', 'source', 'external_ref', 'raw', 'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'rate' => 'decimal:4',
            'raw' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
