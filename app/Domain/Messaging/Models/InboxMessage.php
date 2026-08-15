<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Models;

use App\Domain\Channels\Models\ChannelConnection;
use App\Support\Tenancy\BelongsToTenant;
use App\Support\Uuid\HasUuidV7;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Kanaldan gelen ham mesaj — tek gelen hat.
 *
 * Mimari Karar Dokümanı v2.2 · §4 · tablo, §6 · Inbox, §1 · Karar 23.
 *
 * TEK GELEN HAT: webhook ve yoklama aynı tabloya yazar. İki ayrı yol olsaydı
 * tekilleştirme iki kez yazılır ve biri unutulurdu; kurtarma taraması da iki
 * yeri bilmek zorunda kalırdı.
 *
 * HAM GÖVDE ÖNCE YAZILIR, SONRA AYRIŞTIRILIR: ayrıştırma hatası siparişin
 * kaybolmasına değil, bu satırın failed durumuna düşmesine yol açar ve
 * yeniden işlenebilir.
 *
 * ÇİFT TEKİLLİK İNDEKSİ:
 *   Birincil  (channel_connection_id, external_event_id) — gerçek olay kimliği
 *   Son çare  (channel_connection_id, payload_hash, dedupe_window)
 *
 * Hash yolu yalnızca external_event_id NULL iken devreye girer ve bilinçli
 * olarak son çaredir: saatlik pencere sınırında bölünme riski taşır. Woo
 * (X-WC-Webhook-Delivery-ID), Shopify (X-Shopify-Event-Id) ve Trendyol
 * (sipariş numarası) kimlik verdiği için o yol pratikte hiç çalışmaz.
 *
 * @property string $id
 * @property string $source webhook | polling
 * @property string|null $external_event_id
 * @property string $status pending | processed | failed
 * @property array<string, mixed> $payload
 */
class InboxMessage extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuidV7;

    protected $fillable = [
        'tenant_id',
        'channel_connection_id',
        'source',
        'external_event_id',
        'event_type',
        'payload',
        'payload_hash',
        'signature_valid',
        'received_at',
        'processed_at',
        'status',
        'attempt_count',
        'last_error',
    ];

    /** dedupe_window generated column'dur; asla yazılmaz. */
    protected $guarded = ['dedupe_window'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'signature_valid' => 'boolean',
            'attempt_count' => 'integer',
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
            'dedupe_window' => 'datetime',
        ];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(ChannelConnection::class, 'channel_connection_id');
    }

    public function isProcessed(): bool
    {
        return $this->status === 'processed';
    }

    /**
     * İşlendi damgası.
     *
     * Erken çıkışta da (tanınmayan olay, eşleşmeyen sipariş) çağrılmak
     * zorundadır: aksi halde RecoverPendingInbox mesajı takılı sanar ve
     * sonsuza kadar yeniden işler.
     */
    public function markProcessed(): bool
    {
        return $this->forceFill([
            'status' => 'processed',
            'processed_at' => now(),
        ])->save();
    }

    public function markFailed(string $error): bool
    {
        return $this->forceFill([
            'status' => 'failed',
            'last_error' => $error,
            'attempt_count' => $this->attempt_count + 1,
        ])->save();
    }

    /** Ham gövdeden tekilleştirme hash'i — son çare yolunun dayanağı. */
    public static function hashPayload(string $raw): string
    {
        return hash('sha256', $raw);
    }
}
