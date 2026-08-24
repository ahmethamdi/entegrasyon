<?php

declare(strict_types=1);

namespace App\Domain\Identity\Models;

use App\Domain\Identity\Enums\AuditAction;
use App\Support\Tenancy\BelongsToTenant;
use App\Support\Uuid\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Denetim kaydı — "kim, ne zaman, neye dokundu".
 *
 * Mimari Karar Dokümanı v2.2 · §4 (şema) · §11 ("Denetim kaydı").
 *
 * KİRACIYA AİTTİR (`BelongsToTenant`): denetim izi satıcının kendi
 * geçmişidir ve başka kiracının kaydını görmek en ağır izolasyon
 * ihlallerinden olurdu — kayıt tam olarak "kim ne yaptı" bilgisini taşır.
 * `alert_deliveries` ve `metric_snapshots`'ın aksine burada `tenant_id`
 * NOT NULL'dır: sistem geneli denetim olayı YOKTUR, her olayın bir
 * satıcısı vardır.
 *
 * SATIR GÜNCELLENMEZ VE SİLİNMEZ. Denetim izinin tüm değeri
 * değiştirilemezliğindedir; bir kaydı düzeltmek gerekiyorsa yeni bir olay
 * yazılır. Bu kural veritabanı kısıtıyla zorlanmaz — yazan tek yol
 * `RecordAuditLog` action'ıdır ve o yalnızca INSERT yapar.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $user_id
 * @property AuditAction|string $action
 * @property string $subject_type
 * @property string|null $subject_id
 * @property array<string, mixed>|null $changes
 * @property string|null $ip
 * @property Carbon $occurred_at
 */
class AuditLog extends Model
{
    use BelongsToTenant;
    use HasUuidV7;

    protected $fillable = [
        'tenant_id',
        'user_id',
        'action',
        'subject_type',
        'subject_id',
        'changes',
        'ip',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            // `action` ENUM'A CAST EDİLMEZ ve bu bilinçlidir: kolon metindir
            // ve enum'dan kaldırılmış bir değeri taşıyan eski kayıt cast
            // sırasında İSTİSNA fırlatırdı — denetim ekranı o satır yüzünden
            // tamamen açılmazdı. Okuyan taraf `AuditAction::tryFrom()` ile
            // çözer ve tanımadığı değeri ham gösterir.
            'changes' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
