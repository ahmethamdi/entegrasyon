<?php

declare(strict_types=1);

namespace App\Domain\Billing\Support;

use App\Domain\Billing\Contracts\PaymentGateway;
use App\Domain\Billing\Models\Plan;
use Stripe\StripeClient;

/**
 * Stripe uygulaması.
 *
 * KİRACI VE PLAN METADATA İLE TAŞINIR — hem oturuma hem aboneliğe:
 * `customer.subscription.*` olayları oturum metadata'sını TAŞIMAZ ve plan
 * değişikliğini webhook aboneliğin metadata'sından okur.
 */
final class StripePaymentGateway implements PaymentGateway
{
    public function isConfigured(): bool
    {
        $secret = config('entegrasyon.stripe.secret');

        return is_string($secret) && $secret !== '';
    }

    public function startCheckout(Plan $plan, string $tenantId, string $successUrl, string $cancelUrl): string
    {
        $session = $this->client()->checkout->sessions->create([
            'mode' => 'subscription',
            'line_items' => [[
                'quantity' => 1,
                'price_data' => $this->priceData($plan) + [
                    'product' => $this->productFor($plan),
                ],
            ]],
            'metadata' => $this->metadata($plan, $tenantId),
            'subscription_data' => ['metadata' => $this->metadata($plan, $tenantId)],
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'client_reference_id' => $tenantId,
        ]);

        return (string) $session->url;
    }

    public function changePlan(string $subscriptionRef, Plan $plan, string $tenantId): void
    {
        $client = $this->client();
        $subscription = $client->subscriptions->retrieve($subscriptionRef);

        // Tek kalemli abonelik: kalem YERİNDE değiştirilir. Yeni kalem
        // eklenseydi iki plan birden faturalanırdı.
        $itemId = $subscription->items->data[0]->id;

        $client->subscriptions->update($subscriptionRef, [
            'items' => [[
                'id' => $itemId,
                'price_data' => $this->priceData($plan) + [
                    'product' => $this->productFor($plan),
                ],
            ]],
            'proration_behavior' => 'create_prorations',
            'metadata' => $this->metadata($plan, $tenantId),
        ]);
    }

    public function cancelSubscription(string $subscriptionRef): void
    {
        $this->client()->subscriptions->cancel($subscriptionRef, ['prorate' => true]);
    }

    // ─────────────────────────────────────────────────── yardımcılar

    /** @return array<string, mixed> */
    private function priceData(Plan $plan): array
    {
        return [
            'currency' => mb_strtolower($plan->currency),
            'unit_amount' => $plan->priceInMinorUnits(),
            'recurring' => ['interval' => 'month'],
        ];
    }

    /**
     * Plan başına TEK Stripe ürünü — faturada planın adı görünsün.
     *
     * Abonelik kalemi `product_data` kabul etmez, ürün kimliği ister;
     * her değişiklikte yeni ürün yaratmak Stripe panelini çöplüğe
     * çevirirdi. Metadata ile aranır, yoksa yaratılır.
     */
    private function productFor(Plan $plan): string
    {
        $client = $this->client();

        $found = $client->products->search([
            'query' => sprintf("metadata['plan_code']:'%s'", $plan->code),
            'limit' => 1,
        ]);

        if ($found->data !== []) {
            return $found->data[0]->id;
        }

        return $client->products->create([
            'name' => $plan->name,
            'metadata' => ['plan_code' => $plan->code],
        ])->id;
    }

    /** @return array<string, string> */
    private function metadata(Plan $plan, string $tenantId): array
    {
        return ['tenant_id' => $tenantId, 'plan_code' => $plan->code];
    }

    private function client(): StripeClient
    {
        return new StripeClient((string) config('entegrasyon.stripe.secret'));
    }
}
