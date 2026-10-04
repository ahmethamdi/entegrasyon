<?php

declare(strict_types=1);

namespace App\Domain\Billing\Models;

use App\Support\Tenancy\BelongsToTenant;
use App\Support\Uuid\HasUuidV7;
use Illuminate\Database\Eloquent\Model;

/**
 * Ödeme öncesi onay kaydı — sözleşme okundu + cayma hakkı tercihi.
 *
 * `withdrawal_waived` false ise müşterinin 14 günlük cayma hakkı
 * SÜRER: hizmet yine hemen açılır ama iade talebi reddedilemez.
 *
 * @property string $plan_code
 * @property bool $withdrawal_waived
 */
class BillingConsent extends Model
{
    use BelongsToTenant;
    use HasUuidV7;

    /**
     * Yasal metin sürümü — mesafeli sözleşme veya kullanım koşulları
     * değişince ARTIRILIR. Eski onaylar hangi metne verildiğini korur.
     */
    public const TERMS_VERSION = '2026-10-04';

    protected $fillable = [
        'tenant_id',
        'user_id',
        'plan_code',
        'terms_version',
        'terms_accepted_at',
        'withdrawal_waived',
        'ip',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'terms_accepted_at' => 'datetime',
            'withdrawal_waived' => 'boolean',
        ];
    }
}
