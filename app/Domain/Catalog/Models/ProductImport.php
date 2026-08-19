<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Models;

use App\Support\Tenancy\BelongsToTenant;
use App\Support\Uuid\HasUuidV7;
use Illuminate\Database\Eloquent\Model;

/**
 * Bir toplu içe aktarma yüklemesi ve sonucu.
 *
 * Mimari Karar Dokümanı v2.2 · §13 · Faz 3 · toplu içe aktarma.
 *
 * İş ASENKRON olduğu için kullanıcı sonucu yalnızca bu satırdan öğrenir.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $filename
 * @property string $warehouse_id
 * @property string $status
 * @property int $created_count
 * @property int $updated_count
 * @property array<int, array{line: int, message: string}>|null $errors
 * @property string|null $last_error
 * @property string $payload
 */
class ProductImport extends Model
{
    use BelongsToTenant;
    use HasUuidV7;

    protected $fillable = [
        'tenant_id',
        'filename',
        'warehouse_id',
        'status',
        'created_count',
        'updated_count',
        'errors',
        'last_error',
        'payload',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'errors' => 'array',
            'created_count' => 'integer',
            'updated_count' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
