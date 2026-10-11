<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Models;

use App\Support\Tenancy\BelongsToTenant;
use App\Support\Uuid\HasUuidV7;
use Illuminate\Database\Eloquent\Model;

/**
 * Ürünün bir kanal kategorisindeki VARYANT BELİRLEMEYEN öznitelik değeri
 * (S3). Değer ya kanal listesinden kimlik ya serbest metindir (DB CHECK).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $product_id
 * @property string $channel_category_id
 * @property string $external_attribute_id
 * @property string|null $external_value_id
 * @property string|null $custom_value
 */
class ProductChannelAttributeValue extends Model
{
    use BelongsToTenant;
    use HasUuidV7;

    protected $fillable = [
        'tenant_id', 'product_id', 'channel_category_id', 'external_attribute_id',
        'external_value_id', 'custom_value',
    ];
}
