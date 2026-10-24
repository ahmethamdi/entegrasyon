<?php

declare(strict_types=1);

namespace Tests\Support\Billing;

use App\Domain\Billing\Contracts\PaymentGateway;
use App\Domain\Billing\Models\Plan;
use RuntimeException;
use Stripe\Exception\ApiConnectionException;

/**
 * Ödeme sağlayıcısı taklidi — çağrıları kaydeder, ağa ÇIKMAZ.
 *
 * TestCase her testte bunu bağlar: Stripe SDK'sı curl kullanır ve
 * `Http::preventStrayRequests()` onu YAKALAMAZ; gerçek ağ geçidi testte
 * sessizce api.stripe.com'a giderdi.
 *
 * "Yapılandırılmış mı" sorusu gerçeğiyle AYNI kaynaktan okunur: ekranın
 * "ödeme altyapısı yok" yolu testte de sürülebilsin.
 */
final class FakePaymentGateway implements PaymentGateway
{
    /** @var list<array{plan: string, tenant: string, customer: string}> */
    public array $checkouts = [];

    /** @var list<array{ref: string, plan: string, tenant: string}> */
    public array $planChanges = [];

    /** @var list<string> */
    public array $cancellations = [];

    /** @var list<array{ref: string, prorate: bool}> */
    public array $immediateCancellations = [];

    /** @var list<string> */
    public array $periodEndCancellations = [];

    /** @var list<string> */
    public array $refunds = [];

    /** @var array<string, string> kiracı => müşteri (sağlayıcı tarafı) */
    public array $customers = [];

    public int $customersCreated = 0;

    public bool $failNextCall = false;

    /** İade edilecek ödeme yoksa `refundLatestPayment` hata verir. */
    public ?array $refundablePayment = ['charge' => 'ch_test_1', 'amount_minor' => 49900, 'currency' => 'TRY'];

    public function isConfigured(): bool
    {
        $secret = config('entegrasyon.stripe.secret');

        return is_string($secret) && $secret !== '';
    }

    public function ensureCustomer(string $tenantId, ?string $knownRef): string
    {
        $this->maybeFail();

        if ($knownRef !== null && in_array($knownRef, $this->customers, true)) {
            return $knownRef;
        }

        if (isset($this->customers[$tenantId])) {
            return $this->customers[$tenantId];
        }

        $this->customersCreated++;

        return $this->customers[$tenantId] = 'cus_test_'.$this->customersCreated;
    }

    public function startCheckout(Plan $plan, string $tenantId, string $customerRef, string $successUrl, string $cancelUrl): string
    {
        $this->maybeFail();
        $this->checkouts[] = ['plan' => $plan->code, 'tenant' => $tenantId, 'customer' => $customerRef];

        return 'https://checkout.stripe.test/'.$plan->code;
    }

    public function changePlan(string $subscriptionRef, Plan $plan, string $tenantId): void
    {
        $this->maybeFail();
        $this->planChanges[] = ['ref' => $subscriptionRef, 'plan' => $plan->code, 'tenant' => $tenantId];
    }

    public function cancelSubscription(string $subscriptionRef, bool $prorate = true): void
    {
        $this->maybeFail();
        $this->cancellations[] = $subscriptionRef;
        $this->immediateCancellations[] = ['ref' => $subscriptionRef, 'prorate' => $prorate];
    }

    public function cancelAtPeriodEnd(string $subscriptionRef): void
    {
        $this->maybeFail();
        $this->periodEndCancellations[] = $subscriptionRef;
    }

    public function refundLatestPayment(string $customerRef): array
    {
        $this->maybeFail();

        if ($this->refundablePayment === null) {
            throw new RuntimeException('İade edilecek başarılı ödeme bulunamadı.');
        }

        $this->refunds[] = $customerRef;
        $payment = $this->refundablePayment;
        $this->refundablePayment = null;

        return ['charge' => $payment['charge'], 'refund' => 're_test_'.count($this->refunds), 'amount_minor' => $payment['amount_minor'], 'currency' => $payment['currency']];
    }

    private function maybeFail(): void
    {
        if ($this->failNextCall) {
            $this->failNextCall = false;

            throw new ApiConnectionException('Stripe erişilemedi (test).');
        }
    }
}
