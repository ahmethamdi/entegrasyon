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
 * webhook ile yazılır (SyncSubscriptionFromStripe). Tek istisna müşteri
 * kimliğidir: onu çağıran kaydeder (`tenants.billing_customer_ref`).
 */
interface PaymentGateway
{
    public function isConfigured(): bool;

    /**
     * Kiracının sağlayıcıdaki müşterisini döner; `$knownRef` verilmişse ve
     * sağlayıcıda hâlâ varsa onu, yoksa metadata ile arar, o da yoksa açar.
     * Her checkout'ta yeni müşteri açılmaz (S14: terk edilen ödemeler yetim
     * müşteri bırakıyordu).
     */
    public function ensureCustomer(string $tenantId, ?string $knownRef): string;

    /** Yeni abonelik için ödeme sayfası açar; yönlendirilecek adresi döner. */
    public function startCheckout(Plan $plan, string $tenantId, string $customerRef, string $successUrl, string $cancelUrl): string;

    /**
     * MEVCUT aboneliğin planını değiştirir (orantılı faturalama). Yeni
     * abonelik AÇMAZ — açsaydı eskisi kesilmeye devam ederdi.
     */
    public function changePlan(string $subscriptionRef, Plan $plan, string $tenantId): void;

    /**
     * Aboneliği sağlayıcıda HEMEN iptal eder. `$prorate` = kalan süre
     * alacak olarak yazılsın mı (iade edilmiş abonelikte HAYIR: para zaten
     * geri verildi, üstüne alacak çift iade olurdu).
     */
    public function cancelSubscription(string $subscriptionRef, bool $prorate = true): void;

    /**
     * Aboneliği ödenmiş dönemin SONUNDA bitirir (satıcının kendi iptali).
     * Fiyat sayfası: "İptal, ödemesi yapılmış dönemin sonunda geçerli olur."
     */
    public function cancelAtPeriodEnd(string $subscriptionRef): void;

    /**
     * Müşterinin EN SON başarılı ödemesini tamamen iade eder.
     *
     * @return array{charge: string, refund: string, amount_minor: int, currency: string}
     */
    public function refundLatestPayment(string $customerRef): array;
}
