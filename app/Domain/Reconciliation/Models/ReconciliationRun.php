<?php

declare(strict_types=1);

namespace App\Domain\Reconciliation\Models;

use App\Domain\Channels\Models\ChannelConnection;
use App\Support\Tenancy\BelongsToTenant;
use App\Support\Uuid\HasUuidV7;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Bir mutabakat turu — ne kadar bakıldı, ne bulundu.
 *
 * Mimari Karar Dokümanı v2.2 · §4 · Reconciliation, §10.
 *
 * TUR BAĞLANTI BAŞINADIR: bütçe ve hız sınırı bağlantı başına uygulanır
 * (§7 · koruma katmanı) ve bir kanalın yavaşlığı diğerinin turunu bloke
 * etmemelidir.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $scope
 * @property string $status
 * @property int $candidates_count
 * @property int $checked_count
 * @property int $drift_count
 * @property int $repaired_count
 */
class ReconciliationRun extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuidV7;

    protected $fillable = [
        'tenant_id',
        'channel_connection_id',
        'scope',
        'trigger_reason',
        'started_at',
        'finished_at',
        'candidates_count',
        'checked_count',
        'drift_count',
        'repaired_count',
        'status',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'candidates_count' => 'integer',
            'checked_count' => 'integer',
            'drift_count' => 'integer',
            'repaired_count' => 'integer',
        ];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(ChannelConnection::class, 'channel_connection_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ReconciliationItem::class);
    }
}
