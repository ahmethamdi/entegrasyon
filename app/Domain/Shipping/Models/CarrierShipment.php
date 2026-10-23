<?php

declare(strict_types=1);

namespace App\Domain\Shipping\Models;

use App\Domain\Fulfillment\Enums\FulfillmentReason;
use App\Domain\Orders\Models\Order;
use App\Support\Tenancy\BelongsToTenant;
use App\Support\Uuid\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Satıcının kendi kargo sözleşmesiyle açılan gönderi — sipariş başına TEK satır.
 *
 * DURUM:
 *   pending    = satır açıldı, firmaya henüz istek gitmedi
 *   creating   = oluşturma isteği firmaya GİTTİ ya da gitmiş olabilir
 *                (`create_requested_at` dolu); aynı anahtarla tekrar
 *                gönderim firmada ikinci gönderi AÇMAZ (ölçüldü)
 *   created    = firmada kayıtlı, etiket hazır; kargoya henüz verilmedi
 *   shipped    = kargo firması teslim aldı; takip no kanala bildirildi
 *   delivered  = alıcıya teslim edildi
 *   cancelling = iptal isteği gönderiliyor
 *   cancelled  = firmada iptal edildi
 *   failed     = sebep kodu `reason_code`'da, firmanın metni `error_detail`'de
 *
 * DEĞİŞMEZ: ETİKETİN HAZIR OLMASI KARGOYA VERİLDİ DEMEK DEĞİLDİR (S2 kuralı).
 * Kanala takip numarası yalnız firma gönderiyi teslim aldığında (ya da
 * satıcı "kargoya verdim" dediğinde) gider.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $order_id
 * @property string $provider
 * @property string $environment
 * @property string $cargo_key
 * @property string $status
 * @property string|null $reason_code
 * @property string|null $error_detail
 * @property string|null $carrier_status
 * @property string|null $carrier_status_text
 * @property string|null $tracking_number
 * @property string|null $tracking_url
 * @property int $package_count
 * @property string|null $label_zpl
 * @property string|null $label_pdf
 * @property int $attempts
 * @property Carbon|null $create_requested_at
 * @property Carbon|null $confirmed_at
 * @property Carbon|null $shipped_at
 * @property Carbon|null $delivered_at
 * @property Carbon|null $cancel_requested_at
 * @property Carbon|null $cancelled_at
 * @property Carbon|null $last_tracked_at
 * @property string|null $fulfillment_id
 * @property string|null $requested_by
 */
class CarrierShipment extends Model
{
    use BelongsToTenant;
    use HasUuidV7;

    public const STATUS_PENDING = 'pending';

    public const STATUS_CREATING = 'creating';

    public const STATUS_CREATED = 'created';

    public const STATUS_SHIPPED = 'shipped';

    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_CANCELLING = 'cancelling';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_FAILED = 'failed';

    /** Firmada yaşayan, izlenen gönderi. */
    public const TRACKED = [self::STATUS_CREATED, self::STATUS_SHIPPED];

    protected $guarded = ['id'];

    protected $hidden = ['label_zpl', 'label_pdf'];

    protected function casts(): array
    {
        return [
            // Etikette alıcının adı/adresi basılıdır → diskte ŞİFRELİ
            // (App Store "encrypt at rest" sözü; sipariş verisiyle aynı kural).
            'label_zpl' => 'encrypted',
            'label_pdf' => 'encrypted',
            'package_count' => 'integer',
            'attempts' => 'integer',
            'create_requested_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'shipped_at' => 'datetime',
            'delivered_at' => 'datetime',
            'cancel_requested_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'last_tracked_at' => 'datetime',
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

    /** Firmaya iş gidiyor ya da gidecek (kuyrukta). */
    public function isOpen(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_CREATING], true);
    }

    public function hasLabel(): bool
    {
        return $this->label_zpl !== null || $this->label_pdf !== null;
    }

    /** İptal yalnız kargoya verilmeden önce (firma teslim aldıysa iptal firmadan). */
    public function isCancellable(): bool
    {
        return $this->status === self::STATUS_CREATED;
    }

    public function carrierName(): string
    {
        return CarrierAccount::displayName($this->provider);
    }
}
