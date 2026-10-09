<?php

declare(strict_types=1);

namespace App\Domain\Sync\Models;

use App\Domain\Catalog\Models\ChannelPriceRule;
use App\Domain\Catalog\Models\PriceCampaign;
use App\Domain\Catalog\Models\Variant;
use App\Domain\Catalog\Support\ActiveCampaigns;
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
        $normal = $this->normalPrice();

        if ($normal === null) {
            return null;
        }

        return $this->campaignPrice($normal) ?? $normal;
    }

    /**
     * Üstü çizili fiyat — kanal fiyatı varken GÖNDERİLMEZ: varyantın
     * karşılaştırma fiyatı varyantın para birimindedir ve kanal fiyatının
     * yanında anlamsız (hatta küçük) kalırdı.
     *
     * Kural ona da uygulanır: Trendyol'da satış +%15 olup üstü çizili fiyat
     * olduğu gibi kalsaydı indirim küçülür, hatta satış fiyatının ALTINA
     * düşerdi. Satış fiyatından büyük değilse hiç gönderilmez.
     *
     * KAMPANYADA normal fiyat üstü çizili gider (kampanya istediyse);
     * varyantın kendi karşılaştırma fiyatı daha yüksekse o kalır.
     */
    public function effectiveCompareAtPrice(): ?string
    {
        $compareAt = null;

        if ($this->channel_price === null && $this->variant?->compare_at_price !== null) {
            $compareAt = $this->withRule((string) $this->variant->compare_at_price);
        }

        $price = $this->effectivePrice();
        $normal = $this->normalPrice();

        if ($normal !== null && $price !== $normal && ($this->activeCampaign()?->show_compare_at ?? false)
            && ($compareAt === null || PriceRuleCalculator::toMinor($normal) > PriceRuleCalculator::toMinor($compareAt))) {
            $compareAt = $normal;
        }

        if ($compareAt === null) {
            return null;
        }

        return $price !== null && PriceRuleCalculator::toMinor($compareAt) <= PriceRuleCalculator::toMinor($price)
            ? null
            : $compareAt;
    }

    /**
     * Kampanyasız fiyat: elle girilen kanal fiyatı, yoksa kurallı varyant fiyatı.
     *
     * Elle girilen fiyata kural UYGULANMAZ: o satıcının bilinçli rakamıdır,
     * üstüne %15 eklemek iki kez zam yapmak olurdu.
     */
    public function normalPrice(): ?string
    {
        if ($this->channel_price !== null) {
            return (string) $this->channel_price;
        }

        $price = $this->variant?->price;

        return $price === null ? null : $this->withRule((string) $price);
    }

    /**
     * Bu listing'e şu an uygulanan kampanya — birden çoksa EN DÜŞÜK fiyatı
     * veren. Sıra kuralı olmadan iki kampanya arasında hangisinin gittiği
     * sorgu sırasına kalırdı ve gönderim ile mutabakat farklı rakam görebilirdi.
     */
    public function activeCampaign(): ?PriceCampaign
    {
        $normal = $this->normalPrice();

        if ($normal === null) {
            return null;
        }

        $best = null;
        $bestMinor = PriceRuleCalculator::toMinor($normal);

        foreach (app(ActiveCampaigns::class)->for($this->tenant_id, $this->channel_connection_id, $this->variant_id) as $campaign) {
            $price = $this->discounted($normal, $campaign);

            if ($price !== null && PriceRuleCalculator::toMinor($price) < $bestMinor) {
                $best = $campaign;
                $bestMinor = PriceRuleCalculator::toMinor($price);
            }
        }

        return $best;
    }

    private function campaignPrice(string $normal): ?string
    {
        $campaign = $this->activeCampaign();

        return $campaign === null ? null : $this->discounted($normal, $campaign);
    }

    /**
     * Kampanya indirimi uygulanmış fiyat; uygulanamıyorsa null.
     *
     * ⚠️ TUTAR İNDİRİMİ YALNIZ AYNI PARA BİRİMİNDE. "50 TL indirim" USD Etsy
     * fiyatından 50 düşseydi $12.90'lık ilan sıfıra inerdi. Yüzde birimden
     * bağımsızdır, her fiyata uygulanır.
     *
     * Yuvarlama yalnız kurallı fiyatta (elle fiyat girilmemişse) uygulanır.
     */
    private function discounted(string $normal, PriceCampaign $campaign): ?string
    {
        if ($campaign->discount_type === PriceCampaign::TYPE_AMOUNT
            && $this->effectiveCurrencyWithoutCampaign() !== $this->variant?->currency) {
            return null;
        }

        $rounding = $this->channel_price === null
            ? (app(ChannelPriceRules::class)->forConnection($this->channel_connection_id)?->rounding ?? ChannelPriceRule::ROUNDING_NONE)
            : ChannelPriceRule::ROUNDING_NONE;

        $price = PriceRuleCalculator::discount($normal, $campaign->discount_type, (string) $campaign->discount_value, $rounding);

        // Yuvarlama küçük bir indirimi sıfırlayabilir; indirim yoksa kampanya da yok.
        return PriceRuleCalculator::toMinor($price) < PriceRuleCalculator::toMinor($normal) ? $price : null;
    }

    private function effectiveCurrencyWithoutCampaign(): ?string
    {
        return $this->channel_price !== null ? $this->channel_price_currency : $this->variant?->currency;
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
