<?php

declare(strict_types=1);

namespace App\Domain\Ai\Models;

use App\Domain\Catalog\Models\Product;
use App\Support\Tenancy\BelongsToTenant;
use App\Support\Uuid\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Modelin önerisi (S8) — satıcı onaylayana kadar HİÇBİR YERE yazılmaz.
 *
 * Durumlar: `pending` (satıcının önünde), `invalid` (kural denetiminden
 * geçmedi; düzenlenip onaylanabilir), `applied`, `dismissed`.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $product_id
 * @property string $kind
 * @property string $channel_type_code
 * @property string|null $channel_category_id
 * @property string $model
 * @property string $input_hash
 * @property string $status
 * @property array<string, mixed> $payload
 * @property list<string> $warnings
 * @property string|null $created_by
 * @property string|null $decided_by
 * @property Carbon|null $decided_at
 */
class AiSuggestion extends Model
{
    use BelongsToTenant;
    use HasUuidV7;

    public const KIND_CONTENT = 'content';

    public const KIND_ATTRIBUTES = 'attributes';

    public const KIND_CATEGORY = 'category';

    public const STATUS_PENDING = 'pending';

    public const STATUS_INVALID = 'invalid';

    public const STATUS_APPLIED = 'applied';

    public const STATUS_DISMISSED = 'dismissed';

    protected $fillable = [
        'tenant_id', 'product_id', 'kind', 'channel_type_code', 'channel_category_id',
        'model', 'input_hash', 'status', 'payload', 'warnings',
        'created_by', 'decided_by', 'decided_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'warnings' => 'array',
            'decided_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_INVALID], true);
    }
}
