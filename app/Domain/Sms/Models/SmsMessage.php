<?php

declare(strict_types=1);

namespace App\Domain\Sms\Models;

use App\Support\Tenancy\BelongsToTenant;
use App\Support\Uuid\HasUuidV7;
use Illuminate\Database\Eloquent\Model;

/**
 * Gönderilen (ya da gönderilemeyen) tek SMS'in kaydı.
 *
 * Numara yalnız MASKELİ durur. Gövde satıcının kendi bildirimi ya da
 * alıcıya giden "kargoya verildi" metnidir; alıcının adı/adresi içinde
 * YOKTUR (metin onları hiç taşımaz).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $kind
 * @property string $recipient_masked
 * @property string $body
 * @property string $status
 * @property string|null $provider_job_id
 * @property string|null $error
 * @property int $event_count
 */
class SmsMessage extends Model
{
    use BelongsToTenant;
    use HasUuidV7;

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    /** Gönderilmedi (alıcı telefonu yok ya da cep telefonu değil). */
    public const STATUS_SKIPPED = 'skipped';

    public const KIND_TEST = 'test';

    protected $fillable = [
        'tenant_id',
        'kind',
        'recipient_masked',
        'body',
        'status',
        'provider_job_id',
        'error',
        'event_count',
    ];
}
