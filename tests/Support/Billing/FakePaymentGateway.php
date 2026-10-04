<?php

declare(strict_types=1);

namespace Tests\Support\Billing;

use App\Domain\Billing\Contracts\PaymentGateway;
use App\Domain\Billing\Models\Plan;
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
    /** @var list<array{plan: string, tenant: string}> */
    public array $checkouts = [];

    /** @var list<array{ref: string, plan: string, tenant: string}> */
    public array $planChanges = [];

    /** @var list<string> */
    public array $cancellations = [];

    public bool $failNextCall = false;

    public function isConfigured(): bool
    {
        $secret = config('entegrasyon.stripe.secret');

        return is_string($secret) && $secret !== '';
    }

    public function startCheckout(Plan $plan, string $tenantId, string $successUrl, string $cancelUrl): string
    {
        $this->maybeFail();
        $this->checkouts[] = ['plan' => $plan->code, 'tenant' => $tenantId];

        return 'https://checkout.stripe.test/'.$plan->code;
    }

    public function changePlan(string $subscriptionRef, Plan $plan, string $tenantId): void
    {
        $this->maybeFail();
        $this->planChanges[] = ['ref' => $subscriptionRef, 'plan' => $plan->code, 'tenant' => $tenantId];
    }

    public function cancelSubscription(string $subscriptionRef): void
    {
        $this->maybeFail();
        $this->cancellations[] = $subscriptionRef;
    }

    private function maybeFail(): void
    {
        if ($this->failNextCall) {
            $this->failNextCall = false;

            throw new ApiConnectionException('Stripe erişilemedi (test).');
        }
    }
}
