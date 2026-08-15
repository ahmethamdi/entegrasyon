<?php

declare(strict_types=1);

namespace App\Domain\Sync\Models;

use App\Domain\Sync\Enums\SyncDomain;
use App\Support\Tenancy\BelongsToTenant;
use App\Support\Uuid\HasUuidV7;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Listing × alan senkron durumu.
 *
 * Mimari Karar Dokümanı v2.2 · §4 · listing_sync_states, §1 · Karar 15.
 *
 * ÜÇ AYRI SÜRÜM ALANI — tek alanla çakışma tespiti imkânsızdır:
 *   desired_version  ne istendi     → OpenSyncOperation yazar
 *   synced_version   ne gönderildi  → SyncResultRecorder yazar
 *   remote_version   ne gözlendi    → mutabakat yazar
 *
 * is_dirty veritabanı tarafından üretilir (desired > synced) ve salt
 * okunurdur; yazılmaya çalışılırsa PostgreSQL reddeder. Ayrı bir durum
 * kaynağı değildir, bu yüzden tutarsızlaşamaz.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $listing_id
 * @property string $domain
 * @property int $desired_version
 * @property int $synced_version
 * @property int|null $remote_version
 * @property bool $is_dirty Salt okunur — veritabanı üretir.
 * @property string $status
 */
class ListingSyncState extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuidV7;

    protected $fillable = [
        'tenant_id',
        'listing_id',
        'domain',
        'desired_hash',
        'synced_hash',
        'remote_hash',
        'desired_version',
        'synced_version',
        'remote_version',
        'status',
        'last_repair_at',
        'last_requested_at',
        'last_synced_at',
        'last_observed_at',
        'last_error',
        'error_count',
    ];

    /** is_dirty generated column'dur; asla yazılmaz. */
    protected $guarded = ['is_dirty'];

    protected function casts(): array
    {
        return [
            'domain' => SyncDomain::class,
            'desired_version' => 'integer',
            'synced_version' => 'integer',
            'remote_version' => 'integer',
            'is_dirty' => 'boolean',
            'error_count' => 'integer',
            'last_repair_at' => 'datetime',
            'last_requested_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'last_observed_at' => 'datetime',
        ];
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }

    /**
     * Bekleyen iş var mı — gönderilen sürüm istenenin gerisinde.
     *
     * ADLANDIRMA NOTU: Eloquent'in Model::isDirty() metodu "kaydedilmemiş
     * öznitelik değişikliği var mı" demektir ve bununla hiç ilgisi yoktur.
     * Ezmek imza uyuşmazlığı verir ve anlam karışıklığı yaratırdı.
     */
    public function hasPendingWork(): bool
    {
        return $this->is_dirty;
    }
}
