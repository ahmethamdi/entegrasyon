<?php

declare(strict_types=1);

namespace App\Domain\Fulfillment\Models;

use App\Support\Tenancy\BelongsToTenant;
use App\Support\Uuid\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Sipariş listesinden seçilen siparişlere uygulanan TOPLU depo işlemi.
 *
 * Sonuç KALEM BAŞINA tutulur; bir kalemin hatası diğerlerini durdurmaz.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $user_id
 * @property string $action
 * @property string $idempotency_key
 * @property int $total
 */
class FulfillmentBatch extends Model
{
    use BelongsToTenant;
    use HasUuidV7;

    public const ACTION_LABELS = 'labels';

    public const ACTION_INVOICE_DOCUMENTS = 'invoice_documents';

    public const ACTION_ISSUE_INVOICES = 'issue_invoices';

    public const ACTION_PICKING = 'picking';

    /** S6 · Satıcının kendi kargo sözleşmesiyle gönderi aç (firma başına bir eylem). */
    public const ACTION_CARRIER_YURTICI = 'carrier_yurtici';

    public const ACTION_CARRIER_ARAS = 'carrier_aras';

    /** Eylem → kargo firması kodu (`CarrierAccount::PROVIDER_*`). */
    public const CARRIER_ACTIONS = [
        self::ACTION_CARRIER_YURTICI => 'yurtici',
        self::ACTION_CARRIER_ARAS => 'aras',
    ];

    public const ACTIONS = [
        self::ACTION_LABELS,
        self::ACTION_INVOICE_DOCUMENTS,
        self::ACTION_ISSUE_INVOICES,
        self::ACTION_PICKING,
        self::ACTION_CARRIER_YURTICI,
        self::ACTION_CARRIER_ARAS,
    ];

    public function isCarrierAction(): bool
    {
        return isset(self::CARRIER_ACTIONS[$this->action]);
    }

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['total' => 'integer'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(FulfillmentBatchItem::class, 'batch_id');
    }
}
