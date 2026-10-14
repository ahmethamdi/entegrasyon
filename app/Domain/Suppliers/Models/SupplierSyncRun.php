<?php

declare(strict_types=1);

namespace App\Domain\Suppliers\Models;

use App\Domain\Suppliers\Enums\RunStatus;
use App\Support\Tenancy\BelongsToTenant;
use App\Support\Uuid\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Tedarikçi turu — önizleme (`preview`, hiçbir şey yazmaz) ya da uygulama
 * (`apply`). Sayaçlar `counts`'ta; yalnız değişen/sorunlu kayıtlar
 * `supplier_run_changes`'te satır olur.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $supplier_source_id
 * @property string $mode
 * @property string $trigger
 * @property RunStatus $status
 * @property string|null $reason_code
 * @property string|null $error_detail
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property int|null $duration_ms
 * @property int|null $bytes
 * @property int $records_seen
 * @property array<string, int> $counts
 * @property int|null $peak_memory_bytes
 */
class SupplierSyncRun extends Model
{
    use BelongsToTenant;
    use HasUuidV7;

    public const MODE_PREVIEW = 'preview';

    public const MODE_APPLY = 'apply';

    public const TRIGGER_MANUAL = 'manual';

    public const TRIGGER_SCHEDULE = 'schedule';

    /** Sayaç anahtarları — ekran ve testler aynı listeyi kullanır. */
    public const COUNT_KEYS = [
        'records',
        'created_products',
        'created_variants',
        'matched',
        'stock_changed',
        'cost_changed',
        'price_changed',
        'unchanged',
        'skipped',
        'unmatched',
        'invalid',
        'duplicate',
        'ambiguous',
        'conflict',
        'quota',
        'missing',
        'errors',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => RunStatus::class,
            'counts' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<SupplierSource, $this> */
    public function source(): BelongsTo
    {
        return $this->belongsTo(SupplierSource::class, 'supplier_source_id');
    }

    /** @return HasMany<SupplierRunChange, $this> */
    public function changes(): HasMany
    {
        return $this->hasMany(SupplierRunChange::class);
    }

    public function isPreview(): bool
    {
        return $this->mode === self::MODE_PREVIEW;
    }
}
