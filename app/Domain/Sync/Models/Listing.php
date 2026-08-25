<?php

declare(strict_types=1);

namespace App\Domain\Sync\Models;

use App\Domain\Catalog\Models\Variant;
use App\Domain\Channels\Models\ChannelConnection;
use App\Support\Tenancy\BelongsToTenant;
use App\Support\Uuid\HasUuidV7;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Bir varyantın bir kanaldaki karşılığı.
 *
 * Mimari Karar Dokümanı v2.2 · §4 · Listings, §1 · Karar 14.
 *
 * KİMLİK VE SENKRON DURUMU AYRI TABLOLARDA: bu tablo "kanalda ne var"
 * sorusunu yanıtlar ve nadiren değişir; listing_sync_states "ne gönderildi,
 * ne gözlendi" sorusunu yanıtlar ve her senkronda yazılır. Tek tabloda
 * birleştirilseydi kimlik satırı senkron trafiğiyle sürekli kilitlenirdi.
 *
 * CHANNEL_METADATA — kanala özgü KALICI uzak kimlikler (V3.0 · §03 · Delta 2).
 * Shopify `inventory_item_gid`, Etsy `offering_id`, eBay `offer_id`. Yalnızca
 * ADAPTER okur ve yazar; çekirdek bu alanı sorgulamaz.
 *
 * ⚠️ SIR TAŞIMAZ (P0-9 · T-V3-20): kolon şifresizdir ve panele gidebilir.
 * Token, secret ve imza `channel_credentials`'ta yaşar. KİMLİK ≠ SIR.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $channel_connection_id
 * @property string $variant_id
 * @property string|null $external_id
 * @property array<string, mixed>|null $channel_metadata
 * @property string $lifecycle_status
 */
class Listing extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuidV7;

    protected $fillable = [
        'tenant_id',
        'channel_connection_id',
        'variant_id',
        'external_id',
        'external_parent_id',
        'channel_metadata',
        'external_url',
        'lifecycle_status',
        'listed_at',
        'delisted_at',
        'approval_rejection_reason',
        'approval_checked_at',
    ];

    protected function casts(): array
    {
        return [
            'listed_at' => 'datetime',
            'delisted_at' => 'datetime',
            'approval_checked_at' => 'datetime',
            'channel_metadata' => 'array',
        ];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(ChannelConnection::class, 'channel_connection_id');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(Variant::class);
    }

    public function syncStates(): HasMany
    {
        return $this->hasMany(ListingSyncState::class);
    }

    /**
     * Fan-out hedefleri: yalnızca CANLI listeler.
     *
     * Taslak ve listeden çıkarılmış satırlara stok gönderilmez; kanal
     * onları tanımaz ve çağrı hata döner.
     */
    public function scopeLive(Builder $query): Builder
    {
        return $query->where('lifecycle_status', 'live');
    }

    public function isLive(): bool
    {
        return $this->lifecycle_status === 'live';
    }
}
