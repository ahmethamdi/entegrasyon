<?php

declare(strict_types=1);

namespace App\Domain\Orders\Models;

use App\Support\Tenancy\BelongsToTenant;
use App\Support\Uuid\HasUuidV7;
use Illuminate\Database\Eloquent\Model;

/** İadenin kalemi — adet, tutar (biliniyorsa), stoğa döndü mü. */
class OrderRefundLine extends Model
{
    use BelongsToTenant;
    use HasUuidV7;

    protected $fillable = [
        'tenant_id', 'order_refund_id', 'order_line_id', 'external_line_id', 'quantity', 'amount', 'restock',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'amount' => 'decimal:2',
            'restock' => 'boolean',
        ];
    }
}
