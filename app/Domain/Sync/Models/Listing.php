<?php

declare(strict_types=1);

namespace App\Domain\Sync\Models;

use App\Domain\Catalog\Models\Variant;
use App\Domain\Catalog\Support\ChannelPriceRules;
use App\Domain\Catalog\Support\PriceRuleCalculator;
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
        'channel_price',
        'channel_price_currency',
    ];

    protected function casts(): array
    {
        return [
            'listed_at' => 'datetime',
            'delisted_at' => 'datetime',
            'approval_checked_at' => 'datetime',
            'channel_metadata' => 'array',
            'channel_price' => 'decimal:2',
        ];
    }

    /**
     * Bu kanala GİDECEK fiyat — TEK KAYNAK.
     *
     * Sıra: satıcının bu listing'e elle girdiği kanal fiyatı → yoksa
     * varyantın fiyatı, bağlantının fiyat kuralı (+%15, ,90'a yuvarla)
     * uygulanmış hâliyle. Elle girilen fiyata kural UYGULANMAZ: o satıcının
     * bilinçli rakamıdır, üstüne %15 eklemek iki kez zam yapmak olurdu.
     *
     * Gönderim (`PriceBatchBuilder`), ilan açma (adapter eşleyicileri) ve
     * mutabakat (`ReconcileConnection`) HEPSİ buradan okur: biri varyant
     * fiyatını okusaydı kanal fiyatı girilmiş ilan her mutabakatta sahte
     * "fiyat çakışması" verir ya da ilk açılışta yanlış fiyatla çıkardı.
     */
    public function effectivePrice(): ?string
    {
        if ($this->channel_price !== null) {
            return (string) $this->channel_price;
        }

        $price = $this->variant?->price;

        return $price === null ? null : $this->withRule((string) $price);
    }

    /**
     * Üstü çizili fiyat — kanal fiyatı varken GÖNDERİLMEZ: varyantın
     * karşılaştırma fiyatı varyantın para birimindedir ve kanal fiyatının
     * yanında anlamsız (hatta küçük) kalırdı.
     *
     * Kural ona da uygulanır: Trendyol'da satış +%15 olup üstü çizili fiyat
     * olduğu gibi kalsaydı indirim küçülür, hatta satış fiyatının ALTINA
     * düşerdi. Satış fiyatından büyük değilse hiç gönderilmez.
     */
    public function effectiveCompareAtPrice(): ?string
    {
        if ($this->channel_price !== null) {
            return null;
        }

        $compareAt = $this->variant?->compare_at_price;

        if ($compareAt === null) {
            return null;
        }

        $compareAt = $this->withRule((string) $compareAt);
        $price = $this->effectivePrice();

        return $price !== null && PriceRuleCalculator::toMinor($compareAt) <= PriceRuleCalculator::toMinor($price)
            ? null
            : $compareAt;
    }

    /** Giden fiyatın para birimi. */
    public function effectiveCurrency(): ?string
    {
        return $this->channel_price !== null
            ? $this->channel_price_currency
            : $this->variant?->currency;
    }

    /**
     * Zarar koruması — giden fiyat alış maliyeti tabanının altındaysa neden.
     *
     * NULL: gönderilebilir (koruma kapalı, maliyet bilinmiyor ya da fiyat
     * tabanın üstünde). Metin: GÖNDERİLMEZ ve satıcı nedeni görür.
     *
     * Elle girilen kanal fiyatı da denetlenir: korumanın asıl yakaladığı şey
     * 1999 yerine 199 yazılan rakamdır.
     *
     * ⚠️ PARA BİRİMİ FARKLIYSA DENETLENMEZ. Maliyet varyantın birimindedir;
     * USD Etsy fiyatını TL maliyetle kıyaslamak ya her ilanı durdurur ya da
     * hiçbirini durdurmazdı. Kur dönüşümü bilinçli olarak yok.
     */
    public function priceFloorViolation(): ?string
    {
        $rule = app(ChannelPriceRules::class)->forConnection($this->channel_connection_id);

        if ($rule === null || $rule->min_margin_percent === null) {
            return null;
        }

        $price = $this->effectivePrice();

        if ($price === null) {
            return null;
        }

        if (PriceRuleCalculator::toMinor($price) <= 0) {
            return __('Fiyat sıfır; zarar koruması kanala göndermedi.');
        }

        $cost = $this->variant?->cost_price;

        if ($cost === null || $this->effectiveCurrency() !== $this->variant?->currency) {
            return null;
        }

        $floor = PriceRuleCalculator::floor((string) $cost, (string) $rule->min_margin_percent);

        if (PriceRuleCalculator::toMinor($price) >= PriceRuleCalculator::toMinor($floor)) {
            return null;
        }

        return __('Fiyat :price, alış maliyeti :cost ve en az %:margin kâr ile hesaplanan :floor tabanının altında; zarar koruması kanala göndermedi.', [
            'price' => $price,
            'cost' => (string) $cost,
            'margin' => (string) $rule->min_margin_percent,
            'floor' => $floor,
        ]);
    }

    /** Bağlantının fiyat kuralı varsa uygulanmış fiyat. */
    private function withRule(string $price): string
    {
        $rule = app(ChannelPriceRules::class)->forConnection($this->channel_connection_id);

        return $rule !== null && $rule->changesPrice()
            ? PriceRuleCalculator::apply($price, $rule)
            : $price;
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
