<?php

declare(strict_types=1);

namespace App\Domain\Suppliers\Models;

use App\Support\Tenancy\BelongsToTenant;
use App\Support\Uuid\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Tedarikçi XML kaynağı — S5, ADR-012.
 *
 * Akış adresi ŞİFRELİ saklanır (çoğu toptancı erişim anahtarını adrese
 * koyar); ekranda yalnız `feed_host` gösterilir.
 *
 * STOK POLİTİKASI açık seçimdir, varsayılan `informational`:
 *   informational = tedarikçi stoğu yalnız kaydedilir; defter DEĞİŞMEZ.
 *   authoritative = tedarikçi değeri DEĞİŞTİĞİNDE eldeki stok o değere
 *                   ayarlanır (defter, kilit altında).
 *
 * FİYAT POLİTİKASI:
 *   none        = satış fiyatına dokunulmaz
 *   feed_price  = akıştaki satış fiyatı alanı olduğu gibi
 *   cost_markup = MARKUP — alış × (1 + markup_percent/100) + markup_amount,
 *                 sonra yuvarlama (daima yukarı). MARGIN DEĞİLDİR.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property string $feed_url
 * @property string $feed_host
 * @property bool $is_active
 * @property string|null $record_path
 * @property array<string, mixed> $mapping
 * @property string $number_format
 * @property string $currency
 * @property string $stock_policy
 * @property string $price_policy
 * @property string $markup_percent
 * @property string $markup_amount
 * @property string $rounding
 * @property bool $create_products
 * @property string $sync_state
 * @property string|null $running_run_id
 * @property Carbon|null $running_since
 * @property Carbon|null $last_attempted_at
 * @property Carbon|null $last_succeeded_at
 * @property string|null $last_status
 * @property string|null $last_reason_code
 * @property int|null $last_duration_ms
 * @property Carbon|null $next_run_at
 */
class SupplierSource extends Model
{
    use BelongsToTenant;
    use HasUuidV7;

    public const STOCK_INFORMATIONAL = 'informational';

    public const STOCK_AUTHORITATIVE = 'authoritative';

    public const PRICE_NONE = 'none';

    public const PRICE_FEED = 'feed_price';

    public const PRICE_COST_MARKUP = 'cost_markup';

    public const NUMBER_DOT = 'dot';

    public const NUMBER_COMMA = 'comma';

    public const STATE_IDLE = 'idle';

    public const STATE_RUNNING = 'running';

    /** Saatlik güncelleme aralığı (yol haritası: "her saat"). */
    public const INTERVAL_MINUTES = 60;

    protected $fillable = [
        'name',
        'feed_url',
        'feed_host',
        'record_path',
        'mapping',
        'number_format',
        'currency',
        'stock_policy',
        'price_policy',
        'markup_percent',
        'markup_amount',
        'rounding',
        'create_products',
    ];

    protected $hidden = ['feed_url'];

    protected function casts(): array
    {
        return [
            'feed_url' => 'encrypted',
            'mapping' => 'array',
            'is_active' => 'boolean',
            'create_products' => 'boolean',
            'markup_percent' => 'decimal:2',
            'markup_amount' => 'decimal:2',
            'running_since' => 'datetime',
            'last_attempted_at' => 'datetime',
            'last_succeeded_at' => 'datetime',
            'next_run_at' => 'datetime',
        ];
    }

    /** @return HasMany<SupplierSyncRun, $this> */
    public function runs(): HasMany
    {
        return $this->hasMany(SupplierSyncRun::class);
    }

    /** @return HasMany<SupplierRecord, $this> */
    public function records(): HasMany
    {
        return $this->hasMany(SupplierRecord::class);
    }

    public function isAuthoritativeForStock(): bool
    {
        return $this->stock_policy === self::STOCK_AUTHORITATIVE;
    }
}
