<?php

declare(strict_types=1);

namespace App\Domain\Billing\Models;

use App\Support\Tenancy\BelongsToTenant;
use App\Support\Uuid\HasUuidV7;
use Illuminate\Database\Eloquent\Model;

/**
 * S14 · iade kaydı (ödeme başına bir satır, tutar kümülatif).
 *
 * `guarantee` = 14 gün güvencesiyle yapılan iade. Kural "hesap başına bir
 * kez" olduğu için bu bayrak SİLİNMEZ; abonelik iptal edilse de satır kalır.
 */
final class BillingRefund extends Model
{
    use BelongsToTenant;
    use HasUuidV7;

    protected $table = 'billing_refunds';

    protected $fillable = [
        'tenant_id',
        'subscription_id',
        'provider',
        'charge_ref',
        'amount',
        'currency',
        'full',
        'guarantee',
        'refunded_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'full' => 'boolean',
            'guarantee' => 'boolean',
            'refunded_at' => 'datetime',
        ];
    }
}
