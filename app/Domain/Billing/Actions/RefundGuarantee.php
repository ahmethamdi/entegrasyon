<?php

declare(strict_types=1);

namespace App\Domain\Billing\Actions;

use App\Domain\Billing\Contracts\PaymentGateway;
use App\Domain\Billing\Models\BillingRefund;
use App\Domain\Billing\Models\Subscription;
use App\Domain\Identity\Actions\RecordAuditLog;
use App\Domain\Identity\Enums\AuditAction;
use App\Domain\Identity\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * S14 · 14 gün iade güvencesi (fiyat sayfası, kullanım koşulları §6,
 * mesafeli satış m.6): ilk ücretli abonelikte, ilk ödemeden itibaren 14 gün
 * içinde, HESAP BAŞINA BİR KEZ tam iade — ardından plan HEMEN ücretsize.
 *
 * Yalnız platform yöneticisi başlatır (admin → kiracı). İade sağlayıcıda
 * yapılır; yerel kayıt + düşürme burada, webhook (`charge.refunded`) aynı
 * ödemeyi ikinci kez işlemez (`billing_refunds` ödeme başına tekil).
 *
 * Yalnız Stripe aboneliği: Shopify ile ödeyenin iadesi Shopify Partner
 * panelinden yapılır (uygulama ücreti Shopify faturasındadır).
 */
final class RefundGuarantee
{
    public const DAYS = 14;

    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly DowngradeToFree $downgrade,
        private readonly RecordAuditLog $audit,
    ) {}

    /** @return array{eligible: bool, reason: ?string, deadline: ?string, subscription: ?Subscription} */
    public function eligibility(string $tenantId): array
    {
        return TenantContext::runAsSystem(function () use ($tenantId): array {
            $deny = fn (string $reason, ?Subscription $s = null, ?string $deadline = null) => ['eligible' => false, 'reason' => $reason, 'deadline' => $deadline, 'subscription' => $s];

            if (BillingRefund::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('guarantee', true)->exists()) {
                return $deny('Bu hesap 14 gün güvencesini daha önce kullandı (hesap başına bir kez).');
            }

            $paid = Subscription::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                // Stripe satırlarında `provider` NULL'dur (eski yazım); `!= 'manual'` NULL'u dışlardı.
                ->where(fn ($q) => $q->whereNull('provider')->orWhere('provider', '!=', 'manual'))
                ->whereNotNull('external_ref')
                ->whereNotNull('started_at')
                ->orderBy('started_at')
                ->get();

            $live = $paid->first(fn (Subscription $s) => in_array($s->status, Subscription::LIVE_STATUSES, true));
            if ($live === null) {
                return $deny('Yaşayan ücretli abonelik yok.');
            }
            if (($live->provider ?? 'stripe') === 'shopify') {
                return $deny('Shopify ile ödenen abonelik: iade Shopify Partner panelinden yapılır.', $live);
            }
            if ($paid->first()->isNot($live)) {
                return $deny('Güvence yalnız İLK ücretli abonelikte geçerli.', $live);
            }

            $deadline = CarbonImmutable::parse($live->started_at)->addDays(self::DAYS);
            if (now()->greaterThan($deadline)) {
                return $deny('İlk ödemenin üzerinden 14 günden fazla geçti.', $live, $deadline->toIso8601String());
            }

            $customer = Tenant::query()->whereKey($tenantId)->value('billing_customer_ref');
            if (! is_string($customer) || $customer === '') {
                return $deny('Kiracının ödeme sağlayıcısındaki müşterisi bilinmiyor.', $live, $deadline->toIso8601String());
            }

            return ['eligible' => true, 'reason' => null, 'deadline' => $deadline->toIso8601String(), 'subscription' => $live];
        });
    }

    /** İadeyi yapar, kaydeder, aboneliği keser ve planı ücretsize düşürür. */
    public function run(string $tenantId, ?string $actorId = null): BillingRefund
    {
        $check = $this->eligibility($tenantId);
        if (! $check['eligible']) {
            throw new RuntimeException((string) $check['reason']);
        }

        /** @var Subscription $subscription */
        $subscription = $check['subscription'];
        $customer = (string) TenantContext::runAsSystem(fn () => Tenant::query()->whereKey($tenantId)->value('billing_customer_ref'));

        // 1) Para iadesi (sağlayıcı). Başarısızsa hiçbir şey yazılmaz.
        $refund = $this->gateway->refundLatestPayment($customer);

        // 2) Kayıt — webhook aynı ödemeyi ikinci kez saymasın diye ÖNCE.
        $record = TenantContext::runAsSystem(fn () => BillingRefund::withoutGlobalScopes()->updateOrCreate(
            ['provider' => 'stripe', 'charge_ref' => $refund['charge']],
            [
                'tenant_id' => $tenantId,
                'subscription_id' => $subscription->id,
                'amount' => self::fromMinor($refund['amount_minor']),
                'currency' => $refund['currency'],
                'full' => true,
                'guarantee' => true,
                'refunded_at' => now(),
            ],
        ));

        // 3) Abonelik sağlayıcıda HEMEN biter — alacak yazılmaz (para zaten geri verildi).
        $this->gateway->cancelSubscription((string) $subscription->external_ref, prorate: false);

        // 4) Yerelde hemen ücretsiz (webhook gecikse de beklemez).
        $this->downgrade->run($tenantId, 'refund_guarantee', $actorId);

        $this->audit->run(
            action: AuditAction::REFUND_RECORDED,
            subjectType: 'billing_refund',
            subjectId: $record->id,
            changes: ['charge' => $refund['charge'], 'refund' => $refund['refund'], 'amount' => $record->amount, 'currency' => $record->currency, 'guarantee' => true],
            userId: $actorId,
            tenantId: $tenantId,
        );

        return $record;
    }

    public static function fromMinor(int $minor): string
    {
        return bcdiv((string) $minor, '100', 2);
    }
}
