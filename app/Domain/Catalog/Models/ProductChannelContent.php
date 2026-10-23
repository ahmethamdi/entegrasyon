<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Models;

use App\Support\Tenancy\BelongsToTenant;
use App\Support\Uuid\HasUuidV7;
use Illuminate\Database\Eloquent\Model;

/**
 * Kanal türüne özgü başlık/açıklama (S8).
 *
 * Satıcının onayladığı (AI önerisinden ya da elle düzenlenmiş) metin.
 * Varsa `ListingPayloadBuilder` kanonik ürün metni yerine bunu gönderir;
 * yoksa davranış S8 öncesiyle aynıdır.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $product_id
 * @property string $channel_type_code
 * @property string $title
 * @property string|null $description
 * @property string|null $source_suggestion_id
 */
class ProductChannelContent extends Model
{
    use BelongsToTenant;
    use HasUuidV7;

    protected $fillable = [
        'tenant_id', 'product_id', 'channel_type_code', 'title', 'description', 'source_suggestion_id',
    ];
}
