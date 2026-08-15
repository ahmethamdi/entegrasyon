<?php

declare(strict_types=1);

namespace App\Domain\Channels\Models;

use App\Support\Tenancy\BelongsToTenant;
use App\Support\Uuid\HasUuidV7;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Kiracının bağladığı gerçek mağaza veya pazaryeri hesabı.
 *
 * Mimari Karar Dokümanı v2.2 · §4 · tablo 015.
 *
 * (channel_type_code, external_account_id) çifti global olarak tekildir:
 * bir mağaza alan adı yalnızca tek bir kiracıya bağlanabilir. Bu kısıt
 * Shopify servis sınırında kiracı çözümünün tekilliğini garanti eder (§11).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $channel_type_code
 * @property string $external_account_id
 * @property string $status
 */
class ChannelConnection extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuidV7;

    protected $fillable = [
        'tenant_id',
        'channel_type_code',
        'label',
        'external_account_id',
        'status',
        'settings',
        'health_status',
        'last_healthy_at',
        'last_error',
        'connected_at',
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'last_healthy_at' => 'datetime',
            'connected_at' => 'datetime',
        ];
    }

    public function channelType(): BelongsTo
    {
        return $this->belongsTo(ChannelType::class, 'channel_type_code', 'code');
    }

    public function credentials(): HasMany
    {
        return $this->hasMany(ChannelCredential::class);
    }

    /** Yalnızca iptal edilmemiş kimlik bilgisi. */
    public function activeCredential(): HasOne
    {
        return $this->hasOne(ChannelCredential::class)->whereNull('revoked_at');
    }
}
