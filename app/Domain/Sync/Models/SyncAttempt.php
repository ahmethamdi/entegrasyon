<?php

declare(strict_types=1);

namespace App\Domain\Sync\Models;

use App\Support\Tenancy\BelongsToTenant;
use App\Support\Uuid\HasUuidV7;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tek bir senkron denemesi.
 *
 * Mimari Karar Dokümanı v2.2 · §4 · sync_attempts, §8.
 *
 * Operasyon "ne yapılmak istendi", deneme "ne denendi" sorusunu yanıtlar.
 * Ayrılık hata geçmişinin operasyon durumu ezildiğinde kaybolmamasını sağlar:
 * üç kez 429 alıp dördüncüde başaran bir operasyon completed görünür ama
 * denemeleri geçici hata geçmişini taşımaya devam eder.
 *
 * @property string $id
 * @property string $sync_operation_id
 * @property int $attempt_number
 * @property string $outcome success | transient | permanent
 */
class SyncAttempt extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuidV7;

    protected $fillable = [
        'tenant_id',
        'sync_operation_id',
        'attempt_number',
        'outcome',
        'error_class',
        'error_message',
        'started_at',
        'finished_at',
        'duration_ms',
    ];

    protected function casts(): array
    {
        return [
            'attempt_number' => 'integer',
            'duration_ms' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function operation(): BelongsTo
    {
        return $this->belongsTo(SyncOperation::class, 'sync_operation_id');
    }
}
