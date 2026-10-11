<?php

declare(strict_types=1);

namespace App\Domain\Fulfillment\Models;

use App\Domain\Orders\Models\Order;
use App\Support\Tenancy\BelongsToTenant;
use App\Support\Uuid\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Toplu işlemin bir siparişi. `outcome` KABUL anındaki karardır; kuyruğa
 * giden kalemin (queued) son sonucu kaynağından okunur (`BatchResults`).
 *
 * @property string $id
 * @property string $batch_id
 * @property string $order_id
 * @property string $outcome
 * @property string|null $reason_code
 * @property string|null $shipping_label_id
 */
class FulfillmentBatchItem extends Model
{
    use BelongsToTenant;
    use HasUuidV7;

    public const QUEUED = 'queued';

    public const SUCCEEDED = 'succeeded';

    public const ACTION_REQUIRED = 'action_required';

    public const FAILED = 'failed';

    protected $guarded = ['id'];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function label(): BelongsTo
    {
        return $this->belongsTo(ShippingLabel::class, 'shipping_label_id');
    }
}
