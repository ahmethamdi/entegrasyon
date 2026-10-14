<?php

declare(strict_types=1);

namespace App\Domain\Suppliers\Models;

use App\Domain\Catalog\Models\Variant;
use App\Support\Tenancy\BelongsToTenant;
use App\Support\Uuid\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Tedarikçinin TEK kaydı — kimlik `(kaynak, source_key)`.
 *
 * Ham gerçek (`raw`, `*_state`, `*_value`) ile yorum (`match_status`,
 * `reason_code`) ve BAĞ (`variant_id`) aynı satırdadır ama birbirine
 * karışmaz: tedarikçinin "stok 0" demesi (`stock_state = zero`), stok
 * alanının olmaması (`missing`), kaydın akıştan kalkması
 * (`presence = missing`) ayrı ayrı görünür.
 *
 * `last_applied_*`: varyanta EN SON uygulanan tedarikçi değeri. Yeni değer
 * buna eşitse hiçbir şey yazılmaz — tekrar eden tur ve çökme sonrası
 * yeniden deneme ikinci hareket üretmez; satıcının elle yaptığı fiyat
 * değişikliği tedarikçi değeri değişmedikçe ezilmez.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $supplier_source_id
 * @property string $source_key
 * @property string|null $group_key
 * @property string|null $variant_id
 * @property string|null $sku
 * @property string|null $barcode
 * @property string|null $name
 * @property array<string, mixed> $raw
 * @property string $stock_state
 * @property int|null $stock_value
 * @property string $cost_state
 * @property string|null $cost_value
 * @property string $price_state
 * @property string|null $price_value
 * @property string|null $currency
 * @property string $presence
 * @property string $match_status
 * @property string|null $reason_code
 * @property int|null $last_applied_stock
 * @property string|null $last_applied_cost
 * @property string|null $last_applied_price
 * @property string|null $first_seen_run_id
 * @property string|null $last_seen_run_id
 * @property Carbon|null $last_seen_at
 */
class SupplierRecord extends Model
{
    use BelongsToTenant;
    use HasUuidV7;

    public const PRESENT = 'present';

    public const MISSING = 'missing';

    public const BOUND = 'bound';

    public const UNMATCHED = 'unmatched';

    public const AMBIGUOUS = 'ambiguous';

    public const CONFLICT = 'conflict';

    public const INVALID = 'invalid';

    public const DUPLICATE = 'duplicate';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'raw' => 'array',
            'stock_value' => 'integer',
            'last_applied_stock' => 'integer',
            'last_seen_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<SupplierSource, $this> */
    public function source(): BelongsTo
    {
        return $this->belongsTo(SupplierSource::class, 'supplier_source_id');
    }

    /** @return BelongsTo<Variant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(Variant::class);
    }
}
