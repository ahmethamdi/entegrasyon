<?php

declare(strict_types=1);

namespace App\Domain\Orders\Models;

use App\Domain\Catalog\Models\Variant;
use App\Domain\Orders\Enums\StockStatus;
use App\Support\Tenancy\BelongsToTenant;
use App\Support\Uuid\HasUuidV7;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Sipariş satırı.
 *
 * Mimari Karar Dokümanı v2.2 · §4 · order_lines.
 *
 * variant_id NULL OLABİLİR: kanaldan gelen SKU kataloğumuzla eşleşmeyebilir.
 * Sipariş yine kaydedilir ve satır PENDING kalır; stok düşülmez. Siparişi
 * reddetmek, stok tutarsızlığından çok daha kötüdür — kanal siparişi kabul
 * etmiştir ve bizim eşleştirme eksiğimiz onu yok saymamızı haklı çıkarmaz.
 *
 * CHECK (quantity_cancelled + quantity_returned <= quantity) veritabanında
 * zorlanır: kanal hatalı veri gönderse bile sayaçlar tutarsızlaşamaz.
 *
 * @property string $id
 * @property string|null $variant_id
 * @property int $quantity
 * @property StockStatus $stock_status
 */
class OrderLine extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuidV7;

    protected $fillable = [
        'tenant_id',
        'order_id',
        'variant_id',
        'external_line_id',
        'sku',
        'title',
        'quantity',
        'quantity_cancelled',
        'quantity_returned',
        'quantity_fulfilled',
        'unit_price',
        'line_total',
        'stock_status',
        'stock_applied_at',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'quantity_cancelled' => 'integer',
            'quantity_returned' => 'integer',
            'quantity_fulfilled' => 'integer',
            'unit_price' => 'decimal:2',
            'line_total' => 'decimal:2',
            'stock_status' => StockStatus::class,
            'stock_applied_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(Variant::class);
    }

    /** Stok düşülebilir mi — eşleşmiş varyant şart. */
    public function isStockable(): bool
    {
        return $this->variant_id !== null;
    }

    /** İptal ve iade sonrası kalan geçerli miktar. */
    public function effectiveQuantity(): int
    {
        return $this->quantity - $this->quantity_cancelled - $this->quantity_returned;
    }
}
