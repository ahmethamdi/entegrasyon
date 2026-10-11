<?php

declare(strict_types=1);

namespace App\Domain\Fulfillment\Models;

use App\Domain\Fulfillment\Enums\FulfillmentReason;
use App\Domain\Orders\Models\Order;
use App\Support\Tenancy\BelongsToTenant;
use App\Support\Uuid\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Pazaryeri ortak kargo etiketi — paket başına TEK satır.
 *
 * DURUM: pending → requested → ready | failed.
 *   pending   = satır açıldı, kanala henüz istek gitmedi
 *   requested = etiket isteği (POST) kanala GİTTİ ya da gitmiş olabilir
 *               (`requested_at` dolu); bundan sonra yalnız etiket sorulur
 *   ready     = etiket alındı, `payload`'da ASLI duruyor
 *   failed    = sebep kodu `reason_code`'da
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $order_id
 * @property string $channel_connection_id
 * @property string $package_external_id
 * @property string $cargo_tracking_number
 * @property string|null $cargo_provider
 * @property string $status
 * @property string|null $reason_code
 * @property string|null $error_detail
 * @property int $box_quantity
 * @property string|null $format
 * @property string|null $payload
 * @property string|null $payload_sha256
 * @property int $attempts
 * @property Carbon|null $requested_at
 * @property Carbon|null $ready_at
 * @property string|null $requested_by
 */
class ShippingLabel extends Model
{
    use BelongsToTenant;
    use HasUuidV7;

    public const STATUS_PENDING = 'pending';

    public const STATUS_REQUESTED = 'requested';

    public const STATUS_READY = 'ready';

    public const STATUS_FAILED = 'failed';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'box_quantity' => 'integer',
            'attempts' => 'integer',
            'requested_at' => 'datetime',
            'ready_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function reason(): ?FulfillmentReason
    {
        return $this->reason_code === null ? null : FulfillmentReason::tryFrom($this->reason_code);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_REQUESTED], true);
    }

    /**
     * Saklanan etiket(ler), kanalın verdiği biçimde.
     *
     * @return list<array{format: string, label: string}>
     */
    public function labels(): array
    {
        $decoded = json_decode((string) $this->payload, true);

        return is_array($decoded) ? array_values(array_filter($decoded, 'is_array')) : [];
    }
}
