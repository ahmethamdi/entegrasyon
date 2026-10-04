<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Models;

use App\Support\Tenancy\BelongsToTenant;
use App\Support\Uuid\HasUuidV7;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * Ürün görseli.
 *
 * Mimari Karar Dokümanı v2.2 · §4 · tablo 010.
 *
 * @property string $id
 * @property string $storage_path
 * @property list<string>|null $excluded_channels Görselin GİTMEYECEĞİ kanal türleri
 */
class ProductImage extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuidV7;

    protected $fillable = [
        'tenant_id',
        'product_id',
        'variant_id',
        'source_connection_id',
        'storage_path',
        'width',
        'height',
        'bytes',
        'checksum',
        'position',
        'alt',
        'excluded_channels',
    ];

    protected function casts(): array
    {
        return [
            'width' => 'integer',
            'height' => 'integer',
            'bytes' => 'integer',
            'position' => 'integer',
            'excluded_channels' => 'array',
        ];
    }

    /**
     * Bu kanal türüne gidebilecek görseller — hariç tutulanlar düşer.
     *
     * Varsayılan NULL = her kanala gider (migration notu).
     *
     * @param  Builder<ProductImage>  $query
     */
    public function scopeForChannel(Builder $query, string $channelTypeCode): void
    {
        $query->where(fn (Builder $q) => $q
            ->whereNull('excluded_channels')
            // `@>` kullanılır: jsonb `?` operatörü PDO yer tutucusuyla çakışır.
            ->orWhereJsonDoesntContain('excluded_channels', $channelTypeCode));
    }

    /**
     * Herkese açık adres — içe aktarılan görselde kanalın adresi, yüklenende
     * genel diskteki adres. HTTPS değilse null: kanallar onu indirmez.
     */
    public function publicUrl(): ?string
    {
        $path = (string) $this->storage_path;

        $url = preg_match('#^https?://#i', $path) === 1
            ? $path
            : Storage::disk('public')->url($path);

        return str_starts_with(strtolower($url), 'https://') ? $url : null;
    }

    public function isExcludedFrom(string $channelTypeCode): bool
    {
        return in_array($channelTypeCode, $this->excluded_channels ?? [], true);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(Variant::class);
    }
}
