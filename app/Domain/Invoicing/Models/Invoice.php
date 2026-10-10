<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Models;

use App\Domain\Orders\Models\Order;
use App\Support\Tenancy\BelongsToTenant;
use App\Support\Uuid\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Siparişe kesilen fatura — entegratördeki belgenin İZİ.
 *
 * Faturanın kendisi (alıcı, tutarlar, PDF) satıcının entegratör hesabında
 * yaşar; burada yalnız kimlikler, durum ve numara vardır. Alıcının kişisel
 * verisi bu satırda YOKTUR.
 *
 * DURUM: pending → issuing → issued | failed. `issuing` = e-belge isteği
 * entegratöre gitti, sonucu bekleniyor (Paraşüt asenkron işler).
 *
 * "YALNIZ MUHASEBEYE İŞLE" kipinde (`InvoiceAccount::MODE_BOOKS_ONLY`)
 * pending → issued doğrudan geçilir: e-belge istenmez, `document_type`
 * NULL kalır ve kanala dosya yüklenmez.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $order_id
 * @property string $provider
 * @property string $status
 * @property string|null $document_type
 * @property string|null $provider_contact_id
 * @property string|null $provider_invoice_id
 * @property string|null $provider_job_id
 * @property string|null $provider_document_id
 * @property string|null $provider_payment_id
 * @property string|null $invoice_number
 * @property int $attempts
 * @property string|null $error
 * @property Carbon|null $issued_at
 * @property string|null $upload_status
 * @property int $upload_attempts
 * @property string|null $upload_error
 * @property Carbon|null $uploaded_at
 *
 * KANALA YÜKLEME ayrı bir durumdur (`upload_*`): NULL = kanal dosya almıyor
 * ya da fatura henüz kesilmedi; pending → sent | failed. Yükleme
 * başarısızlığı kesilmiş faturayı "kesilemedi" yapmaz.
 */
class Invoice extends Model
{
    use BelongsToTenant;
    use HasUuidV7;

    public const STATUS_PENDING = 'pending';

    public const STATUS_ISSUING = 'issuing';

    public const STATUS_ISSUED = 'issued';

    public const STATUS_FAILED = 'failed';

    public const UPLOAD_PENDING = 'pending';

    public const UPLOAD_SENT = 'sent';

    public const UPLOAD_FAILED = 'failed';

    public const TYPE_E_ARCHIVE = 'e_archive';

    public const TYPE_E_INVOICE = 'e_invoice';

    protected $fillable = [
        'tenant_id',
        'order_id',
        'provider',
        'status',
        'document_type',
        'provider_contact_id',
        'provider_invoice_id',
        'provider_job_id',
        'provider_document_id',
        'provider_payment_id',
        'invoice_number',
        'attempts',
        'error',
        'issued_at',
        'requested_by',
        'upload_status',
        'upload_attempts',
        'upload_error',
        'uploaded_at',
    ];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'issued_at' => 'datetime',
            'upload_attempts' => 'integer',
            'uploaded_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_ISSUING], true);
    }
}
