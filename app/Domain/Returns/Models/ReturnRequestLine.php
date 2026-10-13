<?php

declare(strict_types=1);

namespace App\Domain\Returns\Models;

use App\Domain\Orders\Models\OrderLine;
use App\Domain\Returns\Enums\ReturnReason;
use App\Domain\Returns\Enums\ReturnStatus;
use App\Support\Tenancy\BelongsToTenant;
use App\Support\Uuid\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * İade talebinin KALEMİ — ekonomik iade kimliği `(bağlantı, external_item_id)`.
 *
 * STOK: `restock_status`
 *   not_applicable = bu kalem stoğa dönmez (henüz kabul yok, red, iptal…)
 *   pending        = kabul var ama stok eklenemedi (sebep `restock_reason_code`)
 *   restored       = defterde iade hareketi VAR (`restock_order_event_id`)
 *   blocked        = bu yoldan hiç dönmeyecek (kanal desteklemiyor)
 *
 * KARAR: `decision_status`
 *   pending = istek kuyrukta, kanala HENÜZ gitmedi ya da gitmek üzere
 *   submitted = kanal isteği kabul etti (sonuç yoklamada görülür)
 *   failed = gönderilmedi / kesin ret — yeniden denenebilir
 *   remote_state_unknown = gitmiş olabilir, sonucu bilinmiyor — kör tekrar YOK
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $return_request_id
 * @property string $channel_connection_id
 * @property string $external_item_id
 * @property string|null $external_order_line_id
 * @property string|null $sku
 * @property string|null $barcode
 * @property int $quantity
 * @property string|null $order_line_id
 * @property string|null $variant_id
 * @property string $match_status
 * @property string $status
 * @property string|null $channel_status
 * @property string $restock_status
 * @property string|null $restock_reason_code
 * @property string|null $restock_order_event_id
 * @property Carbon|null $receipt_proven_at
 * @property string|null $decision
 * @property string|null $decision_status
 * @property string|null $decision_reason_code
 * @property Carbon|null $decision_requested_at
 */
class ReturnRequestLine extends Model
{
    use BelongsToTenant;
    use HasUuidV7;

    public const RESTOCK_NOT_APPLICABLE = 'not_applicable';

    public const RESTOCK_PENDING = 'pending';

    public const RESTOCK_RESTORED = 'restored';

    public const RESTOCK_BLOCKED = 'blocked';

    public const DECISION_APPROVE = 'approve';

    public const DECISION_REJECT = 'reject';

    public const DECISION_PENDING = 'pending';

    public const DECISION_SUBMITTED = 'submitted';

    public const DECISION_FAILED = 'failed';

    public const DECISION_UNKNOWN = 'remote_state_unknown';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'receipt_proven_at' => 'datetime',
            'restocked_at' => 'datetime',
            'decision_requested_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(ReturnRequest::class, 'return_request_id');
    }

    public function orderLine(): BelongsTo
    {
        return $this->belongsTo(OrderLine::class);
    }

    public function statusEnum(): ReturnStatus
    {
        return ReturnStatus::tryFrom($this->status) ?? ReturnStatus::UNKNOWN;
    }

    public function restockReason(): ?ReturnReason
    {
        return $this->restock_reason_code === null ? null : ReturnReason::tryFrom($this->restock_reason_code);
    }

    public function decisionReason(): ?ReturnReason
    {
        return $this->decision_reason_code === null ? null : ReturnReason::tryFrom($this->decision_reason_code);
    }

    /** Karar isteği yolda ya da sonucu belirsiz — yeni karar açılmaz. */
    public function decisionInFlight(): bool
    {
        return in_array($this->decision_status, [self::DECISION_PENDING, self::DECISION_SUBMITTED, self::DECISION_UNKNOWN], true);
    }
}
