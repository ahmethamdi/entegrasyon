<?php

declare(strict_types=1);

namespace App\Domain\BulkEdit\Models;

use App\Support\Tenancy\BelongsToTenant;
use App\Support\Uuid\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Yüklenen dosyanın TEK satırının yorumu. Anlam SET: `stock_new` = rafta
 * olacak sayı, `price_new` = yeni fiyat; null = o alan değişmez.
 *
 * @property string $id
 * @property string $bulk_edit_import_id
 * @property int $row_number
 * @property string|null $variant_id
 * @property string|null $sku
 * @property string|null $label
 * @property string $status
 * @property string|null $reason_code
 * @property int|null $stock_file
 * @property int|null $stock_current
 * @property int|null $stock_new
 * @property string|null $price_file
 * @property string|null $price_current
 * @property string|null $price_new
 * @property string|null $currency
 * @property int $stock_channels
 * @property int $price_channels
 * @property Carbon|null $applied_at
 */
class BulkEditRow extends Model
{
    use BelongsToTenant;
    use HasUuidV7;

    public const CHANGE = 'change';

    public const UNCHANGED = 'unchanged';

    public const INVALID = 'invalid';

    public const CONFLICT = 'conflict';

    public const APPLIED = 'applied';

    public const FAILED = 'failed';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'row_number' => 'integer',
            'stock_file' => 'integer',
            'stock_current' => 'integer',
            'stock_new' => 'integer',
            'stock_channels' => 'integer',
            'price_channels' => 'integer',
            'applied_at' => 'datetime',
        ];
    }
}
