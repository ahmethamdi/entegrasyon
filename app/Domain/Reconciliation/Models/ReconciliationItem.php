<?php

declare(strict_types=1);

namespace App\Domain\Reconciliation\Models;

use App\Domain\Sync\Models\Listing;
use App\Support\Tenancy\BelongsToTenant;
use App\Support\Uuid\HasUuidV7;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tek listing için mutabakat sonucu.
 *
 * Mimari Karar Dokümanı v2.2 · §4 · Reconciliation, §10 · beş adımlı akış.
 *
 * `local_value` / `remote_value` HAM GİRDİYİ saklar. Yalnızca "sürüklenme
 * var" demek yetmez: destek "hangi değer neydi" sorusuna cevap veremez ve
 * sürüklenmenin gerçek mi yoksa okuma gecikmesi mi olduğu bir daha
 * anlaşılamaz. `local_value` hem HAM kanonik bakiyeyi hem karşılaştırma
 * tabanını (`expected_remote`) taşır — ikisi fazla satışta AYRIŞIR.
 *
 * Kalem kimliği onarım anahtarını tekilleştirir: aynı kalem iki kez işlense
 * tek operasyon oluşur (§8).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $listing_id
 * @property string $domain
 * @property string $priority_reason
 * @property string $status
 * @property array<string, mixed>|null $local_value
 * @property array<string, mixed>|null $remote_value
 * @property int|null $drift_magnitude
 * @property string|null $repair_operation_id
 */
class ReconciliationItem extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuidV7;

    protected $fillable = [
        'tenant_id',
        'reconciliation_run_id',
        'listing_id',
        'domain',
        'priority_reason',
        'status',
        'local_value',
        'remote_value',
        'drift_magnitude',
        'repair_operation_id',
        'checked_at',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'local_value' => 'array',
            'remote_value' => 'array',
            'drift_magnitude' => 'integer',
            'checked_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(ReconciliationRun::class, 'reconciliation_run_id');
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }
}
