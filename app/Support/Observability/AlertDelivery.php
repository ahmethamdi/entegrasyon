<?php

declare(strict_types=1);

namespace App\Support\Observability;

use App\Support\Uuid\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Gönderilmiş bir uyarı bildirimi.
 *
 * Mimari Karar Dokümanı v2.2 · §11, §12.
 *
 * `BelongsToTenant` KULLANMAZ ve bu bilinçlidir: sistem geneli uyarının
 * kiracısı YOKTUR (`tenant_id` nullable) ve global scope uygulansaydı o
 * satırlar bağlam altında HİÇ okunamaz, tarama da kendi yazdığı kaydı
 * bulamazdı. Tarama zaten `runAsSystem()` altında çalışır ve kiracı
 * filtresini AÇIKÇA yazar — `metric_snapshots` ile aynı yaklaşım.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string $alert_key
 * @property string $channel
 * @property int $recipient_count
 * @property string|null $observed_value
 * @property string|null $threshold_value
 * @property Carbon $sent_on
 */
class AlertDelivery extends Model
{
    use HasUuidV7;

    protected $fillable = [
        'tenant_id',
        'alert_key',
        'channel',
        'recipient_count',
        'observed_value',
        'threshold_value',
        'sent_on',
    ];

    protected function casts(): array
    {
        return [
            'recipient_count' => 'integer',
            'sent_on' => 'date',
        ];
    }
}
