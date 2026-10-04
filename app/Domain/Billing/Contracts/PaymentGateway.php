<?php

declare(strict_types=1);

namespace App\Domain\Billing\Contracts;

use App\Domain\Billing\Models\Plan;

/**
 * Ödeme sağlayıcısına giden TEK kapı.
 *
 * Denetleyici ve webhook Stripe istemcisini doğrudan kurmaz: kurduğunda
 * yükseltme/iptal yolları testte hiç sürülemiyordu ve çift abonelik
 * (yükseltmede eski Stripe aboneliği kesilmeye devam ediyordu) tam bu
 * görünmezlikte yaşadı.
 *
 * YAZMA YOK: bu kapı yalnız sağlayıcıyı çağırır; yerel abonelik HER ZAMAN
 * webhook ile yazılır (SyncSubscriptionFromStripe).
 */
interface PaymentGateway
{
    public function isConfigured(): bool;

    /** Yeni abonelik için ödeme sayfası açar; yönlendirilecek adresi döner. */
    public function startCheckout(Plan $plan, string $tenantId, string $successUrl, string $cancelUrl): string;

    /**
     * MEVCUT aboneliğin planını değiştirir (orantılı faturalama). Yeni
     * abonelik AÇMAZ — açsaydı eskisi kesilmeye devam ederdi.
     */
    public function changePlan(string $subscriptionRef, Plan $plan, string $tenantId): void;

    /** Aboneliği sağlayıcıda hemen iptal eder (kalan süre orantılı alacak). */
    public function cancelSubscription(string $subscriptionRef): void;
}
