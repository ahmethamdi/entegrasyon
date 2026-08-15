<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Models;

use App\Support\Tenancy\BelongsToTenant;
use App\Support\Tenancy\TenantContext;
use App\Support\Uuid\HasUuidV7;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Transactional outbox olayı.
 *
 * Mimari Karar Dokümanı v2.2 · §4 · tablo 013, §6.
 *
 * consumed_at SEMANTİĞİ: tüketici FAN-OUT / PLANLAMA işini bitirdi.
 * Downstream API çağrılarının başarısını GÖSTERMEZ. Kalıcı bir kanal hatası
 * bu alanı boş bırakmaz; o hata sync_operations seviyesinde yaşar.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $aggregate_type
 * @property string $aggregate_id
 * @property string $event_type
 * @property array<string, mixed> $payload
 * @property Carbon|null $published_at
 * @property Carbon|null $consumed_at
 * @property int|null $operations_planned
 */
class OutboxEvent extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuidV7;

    protected $fillable = [
        'tenant_id',
        'aggregate_type',
        'aggregate_id',
        'event_type',
        'payload',
        'available_at',
        'published_at',
        'consumed_at',
        'operations_planned',
        'publish_attempts',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'available_at' => 'datetime',
            'published_at' => 'datetime',
            'consumed_at' => 'datetime',
            'operations_planned' => 'integer',
            'publish_attempts' => 'integer',
        ];
    }

    /**
     * Olayı MEVCUT transaction içinde kaydeder.
     *
     * Bu metot kasten ince tutulmuştur:
     *   - Kendi transaction'ını AÇMAZ. Çağıran zaten bir domain
     *     transaction'ı içindedir; olay onunla aynı commit'te yazılmalıdır.
     *     Dual write'ın tek çözümü budur.
     *   - Redis'e dispatch YAPMAZ. Yayınlama ayrı bir relay sürecinin işidir.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function record(
        string $aggregateType,
        string $aggregateId,
        string $eventType,
        array $payload,
        ?string $tenantId = null,
        ?\DateTimeInterface $availableAt = null,
    ): self {
        return static::create([
            'tenant_id' => $tenantId ?? TenantContext::idOrFail(),
            'aggregate_type' => $aggregateType,
            'aggregate_id' => $aggregateId,
            'event_type' => $eventType,
            'payload' => $payload,
            'available_at' => $availableAt ?? now(),
        ]);
    }

    /**
     * Planlamanın bittiğini damgalar.
     *
     * Tüketici bunu, ürettiği operasyonların TAMAMLANMASINI beklemeden
     * çağırır. Erken çıkışta (sürüm kapısı elemesi) da çağrılmak zorundadır;
     * aksi halde bütünlük taraması olayı kayıp sanar ve sonsuza kadar
     * yeniden yayınlar.
     */
    public function markConsumed(int $operationsPlanned = 0): bool
    {
        return $this->forceFill([
            'consumed_at' => now(),
            'operations_planned' => $operationsPlanned,
        ])->save();
    }

    public function isPublished(): bool
    {
        return $this->published_at !== null;
    }

    public function isConsumed(): bool
    {
        return $this->consumed_at !== null;
    }
}
