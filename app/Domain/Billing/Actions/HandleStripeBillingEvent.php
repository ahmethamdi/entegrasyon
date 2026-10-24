<?php

declare(strict_types=1);

namespace App\Domain\Billing\Actions;

use App\Domain\Billing\Contracts\PaymentGateway;
use App\Domain\Billing\Models\BillingRefund;
use App\Domain\Billing\Models\Subscription;
use App\Domain\Identity\Actions\RecordAuditLog;
use App\Domain\Identity\Enums\AuditAction;
use App\Domain\Identity\Models\Tenant;
use App\Mail\PaymentFailedMail;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * S14 · aboneliğin dışındaki ödeme olayları: başarısız ödeme, alınan
 * ödeme, iade.
 *
 * KİRACI `customer` ÜZERİNDEN BULUNUR: fatura ve ödeme nesneleri checkout
 * metadata'sını taşımaz. Önce `tenants.billing_customer_ref`, sonra
 * faturanın aboneliği (`sub_…` → `subscriptions.external_ref`). Kiracı
 * bulunamazsa olay yutulur (2xx) ve günlüğe yazılır — uydurma kiracıya
 * posta/iade yazılmaz.
 *
 * API SÜRÜMÜNDEN BAĞIMSIZ: 2025-03-31 (basil) ile faturanın aboneliği
 * `invoice.subscription`'dan `invoice.parent.subscription_details
 * .subscription`'a taşındı; ikisi de okunur.
 */
final class HandleStripeBillingEvent
{
    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly DowngradeToFree $downgrade,
        private readonly RecordAuditLog $audit,
    ) {}

    /** @param array<string, mixed> $invoice */
    public function paymentFailed(array $invoice): void
    {
        $tenant = $this->tenantFor($invoice['customer'] ?? null, self::invoiceSubscription($invoice));
        if ($tenant === null) {
            Log::info('stripe.payment_failed_unknown_tenant', ['invoice' => $invoice['id'] ?? null]);

            return;
        }

        $amount = self::money((int) ($invoice['amount_due'] ?? 0), (string) ($invoice['currency'] ?? 'try'));
        $next = is_int($invoice['next_payment_attempt'] ?? null)
            ? CarbonImmutable::createFromTimestamp($invoice['next_payment_attempt'], 'Europe/Istanbul')->format('d.m.Y H:i')
            : null;

        $this->audit->run(
            action: AuditAction::PAYMENT_FAILED,
            subjectType: 'invoice',
            // `subject_id` uuid kolonudur; Stripe kimliği (`in_…`) yükte taşınır.
            changes: ['invoice' => $invoice['id'] ?? null, 'amount' => $amount, 'attempt' => $invoice['attempt_count'] ?? null, 'next_attempt' => $next],
            tenantId: $tenant->id,
        );

        $owner = TenantContext::runAsSystem(fn () => $tenant->users()->wherePivot('role', 'owner')->orderBy('tenant_users.created_at')->first());
        if ($owner === null || ! is_string($owner->email) || $owner->email === '') {
            Log::warning('stripe.payment_failed_no_owner', ['tenant' => $tenant->id]);

            return;
        }

        $payUrl = is_string($invoice['hosted_invoice_url'] ?? null) && str_starts_with($invoice['hosted_invoice_url'], 'https://')
            ? $invoice['hosted_invoice_url']
            : url('/billing');

        try {
            Mail::to($owner->email)->send(new PaymentFailedMail($tenant->name, $amount, $next, $payUrl));
        } catch (Throwable $e) {
            // Posta düşerse olay yeniden işlensin (ledger damgalanmaz): Stripe dener.
            Log::error('stripe.payment_failed_mail_failed', ['tenant' => $tenant->id, 'error' => $e->getMessage()]);

            throw $e;
        }
    }

    /** @param array<string, mixed> $invoice */
    public function paymentSucceeded(array $invoice): void
    {
        $paid = (int) ($invoice['amount_paid'] ?? 0);
        if ($paid <= 0) {
            return; // sıfır tutarlı fatura (deneme, kupon): ödeme değil
        }

        $tenant = $this->tenantFor($invoice['customer'] ?? null, self::invoiceSubscription($invoice));
        if ($tenant === null) {
            return;
        }

        $this->audit->run(
            action: AuditAction::PAYMENT_SUCCEEDED,
            subjectType: 'invoice',
            changes: ['invoice' => $invoice['id'] ?? null, 'amount' => self::money($paid, (string) ($invoice['currency'] ?? 'try')), 'billing_reason' => $invoice['billing_reason'] ?? null],
            tenantId: $tenant->id,
        );
    }

    /**
     * İade — Stripe Dashboard'dan ya da 14 gün güvencesinden.
     *
     * TAM iade → abonelik sağlayıcıda HEMEN kesilir (alacaksız) ve plan
     * ücretsize düşer (kullanıcı kararı). KISMİ iade (iyi niyet indirimi)
     * yalnız kaydedilir; plan değişmez.
     *
     * @param  array<string, mixed>  $charge
     */
    public function chargeRefunded(array $charge): void
    {
        $chargeId = $charge['id'] ?? null;
        $tenant = $this->tenantFor($charge['customer'] ?? null, null);

        if (! is_string($chargeId) || $tenant === null) {
            Log::info('stripe.refund_unknown_tenant', ['charge' => $chargeId]);

            return;
        }

        $refundedMinor = (int) ($charge['amount_refunded'] ?? 0);
        if ($refundedMinor <= 0) {
            return;
        }
        $full = ($charge['refunded'] ?? false) === true || $refundedMinor >= (int) ($charge['amount'] ?? PHP_INT_MAX);

        [$record, $alreadyHandled] = TenantContext::runAsSystem(function () use ($tenant, $chargeId, $refundedMinor, $full, $charge): array {
            $existing = BillingRefund::withoutGlobalScopes()->where('provider', 'stripe')->where('charge_ref', $chargeId)->first();
            $alreadyHandled = $existing !== null && $existing->full && $full;

            $live = Subscription::withoutGlobalScopes()->where('tenant_id', $tenant->id)->whereIn('status', Subscription::LIVE_STATUSES)->latest('started_at')->first();

            $record = BillingRefund::withoutGlobalScopes()->updateOrCreate(
                ['provider' => 'stripe', 'charge_ref' => $chargeId],
                [
                    'tenant_id' => $tenant->id,
                    'subscription_id' => $existing?->subscription_id ?? $live?->id,
                    'amount' => RefundGuarantee::fromMinor($refundedMinor),
                    'currency' => mb_strtoupper((string) ($charge['currency'] ?? 'try')),
                    'full' => $full,
                    'guarantee' => (bool) ($existing?->guarantee ?? false),
                    'refunded_at' => now(),
                ],
            );

            return [$record, $alreadyHandled];
        });

        if ($alreadyHandled) {
            return; // güvence yolu ya da tekrar: kayıt ve düşürme zaten yapıldı
        }

        $this->audit->run(
            action: AuditAction::REFUND_RECORDED,
            subjectType: 'billing_refund',
            subjectId: $record->id,
            changes: ['charge' => $chargeId, 'amount' => $record->amount, 'currency' => $record->currency, 'full' => $full, 'source' => 'stripe_webhook'],
            tenantId: $tenant->id,
        );

        if (! $full) {
            return;
        }

        $live = TenantContext::runAsSystem(fn () => Subscription::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->whereIn('status', Subscription::LIVE_STATUSES)
            ->whereNotNull('external_ref')
            ->where(fn ($q) => $q->whereNull('provider')->orWhere('provider', 'stripe'))
            ->get());

        foreach ($live as $subscription) {
            try {
                $this->gateway->cancelSubscription((string) $subscription->external_ref, prorate: false);
            } catch (Throwable $e) {
                // Zaten iptal edilmiş olabilir (Dashboard'dan iade + iptal birlikte):
                // yerel düşürme yine yapılır, hata görünür kalır.
                Log::warning('stripe.refund_cancel_failed', ['tenant' => $tenant->id, 'ref' => $subscription->external_ref, 'error' => $e->getMessage()]);
            }
        }

        $this->downgrade->run($tenant->id, 'full_refund');
    }

    private function tenantFor(mixed $customer, ?string $subscriptionRef): ?Tenant
    {
        return TenantContext::runAsSystem(function () use ($customer, $subscriptionRef): ?Tenant {
            if (is_string($customer) && $customer !== '') {
                $tenant = Tenant::query()->where('billing_customer_ref', $customer)->first();
                if ($tenant !== null) {
                    return $tenant;
                }
            }

            if ($subscriptionRef !== null) {
                $tenantId = Subscription::withoutGlobalScopes()->where('external_ref', $subscriptionRef)->value('tenant_id');

                return $tenantId !== null ? Tenant::query()->find($tenantId) : null;
            }

            return null;
        });
    }

    /** @param array<string, mixed> $invoice */
    private static function invoiceSubscription(array $invoice): ?string
    {
        $ref = $invoice['parent']['subscription_details']['subscription'] ?? $invoice['subscription'] ?? null;

        return is_string($ref) && $ref !== '' ? $ref : null;
    }

    public static function money(int $minor, string $currency): string
    {
        // Tam sayıyla (kuruş): para float taşımaz, gösterimde de.
        $sign = $minor < 0 ? '-' : '';
        $minor = abs($minor);
        $amount = $sign.number_format(intdiv($minor, 100), 0, ',', '.').','.str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);

        return match (mb_strtoupper($currency)) {
            'TRY' => "{$amount} ₺",
            'EUR' => "{$amount} €",
            'USD' => "\${$amount}",
            default => $amount.' '.mb_strtoupper($currency),
        };
    }
}
