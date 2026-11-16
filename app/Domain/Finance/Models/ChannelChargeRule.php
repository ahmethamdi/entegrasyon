<?php

declare(strict_types=1);

namespace App\Domain\Finance\Models;

use App\Domain\Channels\Models\ChannelConnection;
use App\Support\Tenancy\BelongsToTenant;
use App\Support\Uuid\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Kanal kesinti varsayılanı — bağlantı başına bir varsayılan satır
 * (`internal_category_id = ''`) ve kategori istisnaları (yalnız komisyon).
 *
 * @property string $channel_connection_id
 * @property string $internal_category_id
 * @property string|null $commission_percent
 * @property string|null $shipping_fee
 * @property string|null $packaging_fee
 */
class ChannelChargeRule extends Model
{
    use BelongsToTenant;
    use HasUuidV7;

    public const DEFAULT_CATEGORY = '';

    protected $fillable = [
        'tenant_id', 'channel_connection_id', 'internal_category_id',
        'commission_percent', 'shipping_fee', 'packaging_fee',
    ];

    protected function casts(): array
    {
        return [
            'commission_percent' => 'decimal:2',
            'shipping_fee' => 'decimal:2',
            'packaging_fee' => 'decimal:2',
        ];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(ChannelConnection::class, 'channel_connection_id');
    }
}
