<?php

declare(strict_types=1);

namespace App\Domain\Suppliers\Models;

use App\Support\Tenancy\BelongsToTenant;
use App\Support\Uuid\HasUuidV7;
use Illuminate\Database\Eloquent\Model;

/**
 * Turda DEĞİŞEN ya da sorunlu bir kaydın satırı (özetten ayrıntıya
 * inmek için). Değişmeyen kayıt satır üretmez.
 *
 * @property string $id
 * @property string $supplier_sync_run_id
 * @property string|null $supplier_record_id
 * @property string|null $source_key
 * @property string|null $variant_id
 * @property string $kind
 * @property string|null $before
 * @property string|null $after
 * @property string|null $reason_code
 */
class SupplierRunChange extends Model
{
    use BelongsToTenant;
    use HasUuidV7;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];
}
