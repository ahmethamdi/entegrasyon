<?php

declare(strict_types=1);

namespace App\Domain\Channels\Models;

use App\Domain\Channels\Support\BetaChannelNotifier;
use App\Support\Tenancy\BelongsToTenant;
use App\Support\Uuid\HasUuidV7;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;

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
 * @property bool $product_publish_enabled
 * @property bool $price_sync_enabled
 * @property bool $stock_sync_enabled
 * @property bool $return_decision_enabled
 * @property bool $question_answer_enabled
 *
 * Yazma izinleri (ADR-010) `$fillable` DIŞINDADIR: form ya da toplu
 * atama yoluyla açılamaz; yalnız `SetOutboundWrite` açık eylemiyle.
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

    /**
     * Beta kanal bildirimi — bağlantı İLK KEZ kurulunca (`connected_at`
     * boştan dolar). Satır yaratılışı değil kurulma anı izlenir: OAuth
     * akışları satırı geçici kimlikle önceden açar.
     */
    protected static function booted(): void
    {
        static::saved(function (self $connection): void {
            if ($connection->wasChanged('connected_at')
                && $connection->getOriginal('connected_at') === null
                && $connection->connected_at !== null) {
                DB::afterCommit(fn () => app(BetaChannelNotifier::class)->connected($connection));
            }
        });
    }

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'last_healthy_at' => 'datetime',
            'connected_at' => 'datetime',
            'product_publish_enabled' => 'boolean',
            'price_sync_enabled' => 'boolean',
            'stock_sync_enabled' => 'boolean',
            'return_decision_enabled' => 'boolean',
            'question_answer_enabled' => 'boolean',
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
