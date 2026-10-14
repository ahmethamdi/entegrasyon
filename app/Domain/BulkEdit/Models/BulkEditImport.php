<?php

declare(strict_types=1);

namespace App\Domain\BulkEdit\Models;

use App\Support\Tenancy\BelongsToTenant;
use App\Support\Uuid\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Excel toplu fiyat/stok yüklemesi (S5). Dosya saklanmaz; satırlar
 * `bulk_edit_rows`'ta önizleme olarak durur.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $user_id
 * @property string $warehouse_id
 * @property string $filename
 * @property string $status
 * @property string|null $reason_code
 * @property array<string, int> $counts
 * @property Carbon|null $apply_requested_at
 * @property Carbon|null $applied_at
 */
class BulkEditImport extends Model
{
    use BelongsToTenant;
    use HasUuidV7;

    public const PARSED = 'parsed';

    public const REJECTED = 'rejected';

    public const APPLYING = 'applying';

    public const APPLIED = 'applied';

    public const FAILED = 'failed';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'counts' => 'array',
            'apply_requested_at' => 'datetime',
            'applied_at' => 'datetime',
        ];
    }

    /** @return HasMany<BulkEditRow, $this> */
    public function rows(): HasMany
    {
        return $this->hasMany(BulkEditRow::class);
    }
}
