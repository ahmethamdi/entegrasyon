<?php

declare(strict_types=1);

namespace App\Domain\Billing\Support;

use App\Domain\Billing\Contracts\PaymentGateway;
use App\Domain\Billing\Models\Plan;
use App\Domain\Identity\Models\Tenant;
use RuntimeException;
use Stripe\Exception\InvalidRequestException;
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
    /**
     * Ödeme sayfasında, düğmenin altında görünen not (Stripe Checkout
     * `custom_text.submit`, en çok 1.200 karakter). Türk bankaları yurt
     * dışı satıcıyı ilk denemede sık reddeder (Stripe hesabı Almanya'da);
     * satıcı "site çalışmıyor" sanmasın.
     */
    public const CARD_DECLINE_HINT = 'Kartın reddedilirse: ödeme Almanya\'daki hizmet sağlayıcımız üzerinden alınır. Bankanın mobil uygulamasından kartının yurt dışı ve internet alışverişi iznini açıp tekrar dene. Sorun sürerse WhatsApp\'tan yaz: +49 176 76798125.';

    public function isConfigured(): bool
    {
        $secret = config('entegrasyon.stripe.secret');

        return is_string($secret) && $secret !== '';
    }

    public function ensureCustomer(string $tenantId, ?string $knownRef): string
    {
        $client = $this->client();

        // 1) Bildiğimiz müşteri hâlâ duruyorsa o (silinmişse Stripe `deleted` döner).
        if ($knownRef !== null && $knownRef !== '') {
            try {
                $existing = $client->customers->retrieve($knownRef);
                if (! ($existing->deleted ?? false)) {
                    return (string) $existing->id;
                }
            } catch (InvalidRequestException) {
                // Müşteri yok (başka hesap/silinmiş): aşağıda aranır ya da açılır.
            }
        }

        // 2) Kolon boş ama Stripe'ta daha önce açılmış (S14 öncesi checkout'lar).
        $found = $client->customers->search([
            'query' => sprintf("metadata['tenant_id']:'%s'", $tenantId),
            'limit' => 1,
        ]);
        if ($found->data !== []) {
            return (string) $found->data[0]->id;
        }

        // 3) Yeni müşteri. MÜŞTERİ ÖNCE AÇILIR: faturanın altbilgisi (34Devs
        // künyesi + § 19 UStG notu) müşterinin `invoice_settings.footer`'ından
        // gelir. E-POSTA VE AD yazılır (24 Eki açılış denetimi).
        $tenant = Tenant::query()->find($tenantId);
        $owner = $tenant?->users()->wherePivot('role', 'owner')->orderBy('tenant_users.created_at')->first();

        return (string) $client->customers->create(array_filter([
            'email' => $owner?->email,
            'name' => $tenant?->name,
            'preferred_locales' => ['tr'],
            'metadata' => ['tenant_id' => $tenantId],
            'invoice_settings' => ['footer' => (string) config('entegrasyon.stripe.invoice_footer')],
        ], fn (mixed $value): bool => $value !== null && $value !== ''))->id;
    }

    public function startCheckout(Plan $plan, string $tenantId, string $customerRef, string $successUrl, string $cancelUrl): string
    {
        $session = $this->client()->checkout->sessions->create([
            'mode' => 'subscription',
            'customer' => $customerRef,
            'line_items' => [[
                'quantity' => 1,
                'price_data' => $this->priceData($plan) + [
                    'product' => $this->productFor($plan),
                ],
            ]],
            'metadata' => $this->metadata($plan, $tenantId),
            'subscription_data' => ['metadata' => $this->metadata($plan, $tenantId)],
            'custom_text' => ['submit' => ['message' => self::CARD_DECLINE_HINT]],
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
            // Dönem sonu iptali planlanmışken plan değiştiren satıcı kalmak
            // istiyordur: iptal geri alınır, yoksa yeni plan da dönem sonunda biterdi.
            'cancel_at_period_end' => false,
            'metadata' => $this->metadata($plan, $tenantId),
        ]);
    }

    public function cancelSubscription(string $subscriptionRef, bool $prorate = true): void
    {
        $this->client()->subscriptions->cancel($subscriptionRef, ['prorate' => $prorate]);
    }

    public function cancelAtPeriodEnd(string $subscriptionRef): void
    {
        $this->client()->subscriptions->update($subscriptionRef, ['cancel_at_period_end' => true]);
    }

    public function refundLatestPayment(string $customerRef): array
    {
        $client = $this->client();

        // Ödeme listesi müşteri üzerinden: API sürümünden bağımsız (basil'de
        // fatura↔ödeme alanları değişti, müşteri↔ödeme ilişkisi değişmedi).
        $charges = $client->charges->all(['customer' => $customerRef, 'limit' => 10]);

        $charge = null;
        foreach ($charges->data as $candidate) {
            if (($candidate->status ?? null) === 'succeeded' && ! ($candidate->refunded ?? false) && (int) $candidate->amount > (int) ($candidate->amount_refunded ?? 0)) {
                $charge = $candidate;
                break;
            }
        }

        if ($charge === null) {
            throw new RuntimeException('İade edilecek başarılı ödeme bulunamadı.');
        }

        $refund = $client->refunds->create([
            'charge' => $charge->id,
            'reason' => 'requested_by_customer',
            'metadata' => ['source' => '34pazar-14-gun-guvencesi'],
        ]);

        return [
            'charge' => (string) $charge->id,
            'refund' => (string) $refund->id,
            'amount_minor' => (int) $charge->amount - (int) ($charge->amount_refunded ?? 0),
            'currency' => mb_strtoupper((string) $charge->currency),
        ];
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
