<?php

declare(strict_types=1);

namespace App\Domain\Billing\Models;

use App\Domain\Billing\Enums\QuotaMetric;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Plan — fiyat ve kota kataloğu.
 *
 * Mimari Karar Dokümanı v2.2 · §3 · Domain/Billing/Models/Plan,
 * §4 · plans (code PK, limits JSONB, KİRACISIZ + seed).
 *
 * DEĞİŞMEZ KURAL — KİRACIYA AİT DEĞİLDİR: `BelongsToTenant` KULLANILMAZ.
 * Plan kataloğu ÜRÜNÜN gerçeğidir, satıcının kararı değil. Kapsansaydı
 * her kiracı kendi plan listesini taşır ve fiyat değişikliği kiracı
 * sayısı kadar yere yazılmak zorunda kalırdı. `channel_categories` ile
 * aynı ayrım: katalog kanalın/ürünün GERÇEĞİ, seçim satıcının KARARI.
 *
 * DEĞİŞMEZ KURAL — ANAHTAR `code`, uuid DEĞİL (§4: "code (PK)").
 * `tenants.plan_code` zaten metin taşır.
 *
 * DEĞİŞMEZ KURAL — PLAN SİLİNMEZ, `is_public = false` YAPILIR. Eski
 * planlardaki kiracıların aboneliği ona bağlıdır ve FK `restrictOnDelete`
 * ile korunur; silme, o kiracıları plansız bırakırdı.
 */
final class Plan extends Model
{
    protected $table = 'plans';

    protected $primaryKey = 'code';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'code',
        'name',
        'price_monthly',
        'currency',
        'limits',
        'is_public',
    ];

    protected function casts(): array
    {
        return [
            'limits' => 'array',
            'is_public' => 'boolean',
            // `price_monthly` CAST EDİLMEZ: `decimal(12,2)` PHP'ye STRING
            // döner ve float'a çevirmek kuruş kayması üretir. Para
            // karşılaştırması kuruş ölçeğinde tam sayı üzerinden yapılır
            // (fiyat senkron kuralıyla aynı).
        ];
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class, 'plan_code', 'code');
    }

    /**
     * Bu planın verdiği limit — TANIMSIZ veya `null` ise SINIRSIZ.
     *
     * DEĞİŞMEZ KURAL — YOKLUK "SINIRSIZ" DEMEKTİR, "SIFIR" DEĞİL.
     * Sıfır sayılsaydı yeni bir kota türü eklendiği an TÜM mevcut
     * planlar o kotada sıfıra düşer ve bütün kiracılar aniden
     * engellenirdi. Yeni kota eklemek geriye dönük olarak kimseyi
     * kilitlememelidir.
     */
    public function limitFor(QuotaMetric $metric): ?int
    {
        $value = $this->limits[$metric->value] ?? null;

        return $value === null ? null : (int) $value;
    }

    /** Fiyat kuruş cinsinden — Stripe da tutarı en küçük birimde ister. */
    public function priceInMinorUnits(): int
    {
        return (int) round(((float) $this->price_monthly) * 100);
    }
}
