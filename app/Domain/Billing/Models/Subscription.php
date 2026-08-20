<?php

declare(strict_types=1);

namespace App\Domain\Billing\Models;

use App\Domain\Identity\Models\Tenant;
use App\Support\Tenancy\BelongsToTenant;
use App\Support\Uuid\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Abonelik — kiracının hangi plandan yararlandığı.
 *
 * Mimari Karar Dokümanı v2.2 · §3 · Domain/Billing/Models/Subscription,
 * §4 · subscriptions · UNIQUE(tenant_id) WHERE status = 'active'.
 *
 * DEĞİŞMEZ KURAL — İPTAL EDİLEN ABONELİK SİLİNMEZ. Tarihçe olarak durur:
 * "geçen yıl hangi plandaydı, ne zaman iptal etti" soruları faturalamada
 * ve destekte sorulur. Kısmi tekil indeks tam da bu yüzden gerekir —
 * tam tekillik konsaydı plan değiştiren kiracının eski satırı silinmek
 * zorunda kalır ve gelir geçmişi kaybolurdu (§5 · depo kuralıyla aynı
 * kalıp).
 *
 * DEĞİŞMEZ KURAL — `external_ref` SAĞLAYICIDAN BAĞIMSIZ ADLANDIRILMIŞTIR
 * (§4). Stripe'ta `sub_...` taşır ama kolon adı `stripe_subscription_id`
 * DEĞİLDİR: sağlayıcı değişirse şema değişmemelidir. Sağlayıcı seçimi
 * ticari bir karardır ve koda gömülmez — uyarı e-postalarındaki
 * `MAIL_MAILER` kararıyla aynı ilke.
 */
final class Subscription extends Model
{
    use BelongsToTenant;
    use HasUuidV7;

    protected $table = 'subscriptions';

    /** Aktif sayılan durumlar — kota bu ikisinde verilir. */
    public const ACTIVE_STATUSES = ['active', 'trialing'];

    protected $fillable = [
        'tenant_id',
        'plan_code',
        'status',
        'started_at',
        'current_period_end',
        'cancelled_at',
        'external_ref',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'current_period_end' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'plan_code', 'code');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Kota veriyor mu?
     *
     * `trialing` de VERİR: deneme süresindeki kiracı ücretli gibi
     * davranmalıdır, yoksa denemenin bir anlamı kalmaz. `past_due`
     * VERMEZ — ödeme alınamamıştır ve kotayı sürdürmek, ödemeyen
     * kiracıya ücretli limitleri açık tutardı.
     */
    public function grantsQuota(): bool
    {
        return in_array($this->status, self::ACTIVE_STATUSES, true);
    }
}
