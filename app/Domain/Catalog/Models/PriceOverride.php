<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Models;

use App\Domain\Identity\Models\User;
use App\Domain\Sync\Models\Listing;
use App\Support\Tenancy\BelongsToTenant;
use App\Support\Uuid\HasUuidV7;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Satıcının "kanalınkini kabul ediyorum" kararı — bir listing'in fiyat kilidi.
 *
 * Mimari Karar Dokümanı v2.2 · §9 (çakışma tespiti ve domain politikası),
 * §3 (`Pricing/Models/PriceOverride`), §11 (denetim kaydı).
 *
 * ─────────────────────────────────────────────────────────────────────
 * NEDEN `Catalog/` ALTINDA — §3'ÜN AĞACINDAN BİLİNÇLİ SAPMA
 * ─────────────────────────────────────────────────────────────────────
 * §3 bu modeli `Pricing/Models/PriceOverride` diye anıyor ama projede
 * `Pricing/` klasörü YOKTUR: fiyat kanonik olarak `variants.price`
 * kolonunda yaşar ve o kolonu `Catalog` domaini yazar (`UpdateProduct`).
 * Override o kolonun kanal başına istisnasıdır, yani aynı domainin verisi.
 *
 * Tek modellik bir klasör açmak, fiyatın iki domaine bölünmesi demekti:
 * `UpdateProduct` (Catalog) fiyatı yazar, `ResolveChannelPrice` (Pricing)
 * onu okur ve ikisi arasındaki değişmez ("override bayatladı mı") iki
 * domainin ortasında kalırdı. `PrerequisiteGate` kararının aynısı —
 * dokümanın dizin ağacı bir öneridir, modül SINIRI değişmezdir.
 *
 * ─────────────────────────────────────────────────────────────────────
 * SATIR VARSA O LISTING FİYAT FAN-OUT'UNDAN ELENİR
 * ─────────────────────────────────────────────────────────────────────
 * Bu modelin tek davranışsal anlamı budur (§9 · PRICE politikası: "üzerine
 * YAZMA"). Elenmeseydi kabul edilen kanal fiyatı bir sonraki fiyat turunda
 * kanonik fiyatla ezilir ve tüm özellik anlamsızlaşırdı — satıcı "kabul
 * ettim" der, sistem beş dakika sonra üzerine yazardı.
 *
 * Kararı `ResolveChannelPrice` verir; bu model yalnızca veriyi taşır.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $listing_id
 * @property string $channel_price
 * @property string $our_price
 * @property Carbon $accepted_at
 * @property string|null $accepted_by
 * @property Carbon|null $expires_at
 */
class PriceOverride extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuidV7;

    protected $fillable = [
        'tenant_id',
        'listing_id',
        'channel_price',
        'our_price',
        'accepted_at',
        'accepted_by',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'accepted_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }

    public function acceptedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_by');
    }

    /**
     * Süresi dolmuş override ARTIK GEÇERLİ DEĞİLDİR.
     *
     * Karşılaştırma `clock_timestamp()` kuralının PHP tarafındaki karşılığı
     * değildir — burada transaction sınırı yoktur ve `now()` doğrudur.
     *
     * NULL "SÜRESİZ" DEMEKTİR, "SÜRESİ DOLMUŞ" DEĞİL: satıcı kampanyanın
     * bitişini bilmiyorsa alanı boş bırakır ve override elle kaldırılana
     * kadar sürer (migration başlığındaki gerekçe).
     */
    public function isActive(): bool
    {
        return $this->expires_at === null || $this->expires_at->isFuture();
    }
}
