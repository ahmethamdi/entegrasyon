<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Billing\Actions\EnforceQuota;
use App\Domain\Billing\Enums\QuotaMetric;
use App\Domain\Billing\Models\Plan;
use App\Domain\Billing\Models\Subscription;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;

/**
 * Abonelik ekranı — plan seçimi, kullanım ve ödeme başlatma.
 *
 * Mimari Karar Dokümanı v2.2 · §13 · Faz 4.
 *
 * DEĞİŞMEZ KURAL — PANEL ABONELİK YAZMAZ:
 *   Burada yalnızca Stripe Checkout oturumu açılır ve kullanıcı
 *   yönlendirilir. Aboneliği WEBHOOK açar. Panel yazsaydı ödeme
 *   alınmadan kota açılır ve satıcı ücretsiz kullanmaya başlardı;
 *   üstelik kullanıcı ödeme sayfasında vazgeçse bile abonelik açık
 *   kalırdı.
 *
 * DEĞİŞMEZ KURAL — KULLANIM VE LİMİT BİRLİKTE GÖSTERİLİR:
 *   "Kotan doldu" tek başına ne yapacağını söylemez.
 *
 * DEĞİŞMEZ KURAL — INERTIA'YA MODEL GÖNDERİLMEZ: yalnızca görünen
 *   alanlar. Abonelik modeli `external_ref` taşıyor ve o, ödeme
 *   sağlayıcısındaki kimliktir.
 */
final class BillingController extends Controller
{
    public function index(Request $request, EnforceQuota $quota): InertiaResponse
    {
        $plan = $quota->planForCurrentTenant();

        $subscription = Subscription::query()
            ->whereIn('status', Subscription::ACTIVE_STATUSES)
            ->first();

        return Inertia::render('Billing/Index', [
            'plans' => $this->publicPlans(),
            'current' => [
                'planCode' => $subscription?->plan_code ?? $plan?->code,
                'planName' => $subscription?->plan?->name ?? $plan?->name,
                'status' => $subscription?->status,
                'currentPeriodEnd' => $subscription?->current_period_end?->toIso8601String(),
                'cancelledAt' => $subscription?->cancelled_at?->toIso8601String(),
            ],
            'usage' => $this->usage($quota, $plan),
            // Stripe yapılandırılmamışsa ekran bunu SÖYLER; "satın al"
            // düğmesine basıp sessizce hata almak, sebebi hiç
            // anlaşılmayan bir başarısızlıktır.
            'paymentsEnabled' => $this->stripeConfigured(),
        ]);
    }

    /**
     * Stripe Checkout oturumu açar ve kullanıcıyı yönlendirir.
     *
     * ABONELİK BURADA YAZILMAZ — webhook yazar.
     */
    public function checkout(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'plan_code' => ['required', 'string'],
        ]);

        $plan = Plan::query()
            ->whereKey($validated['plan_code'])
            // GİZLİ PLAN SATIN ALINAMAZ: listede görünmeyen bir plana
            // ödeme açmak, satışa kapatılmış bir fiyatı geri açardı.
            ->where('is_public', true)
            ->first();

        if ($plan === null) {
            throw ValidationException::withMessages([
                'plan_code' => 'Bu plan satın alınamaz.',
            ]);
        }

        // ÜCRETSİZ PLAN İÇİN ÖDEME AÇILMAZ: Stripe sıfır tutarlı
        // abonelik oturumunu reddeder ve kullanıcı anlamsız bir hata
        // görürdü.
        if ($plan->priceInMinorUnits() <= 0) {
            throw ValidationException::withMessages([
                'plan_code' => 'Ücretsiz plan için ödeme gerekmez.',
            ]);
        }

        if (! $this->stripeConfigured()) {
            throw ValidationException::withMessages([
                'plan_code' => 'Ödeme altyapısı henüz yapılandırılmadı.',
            ]);
        }

        $tenantId = TenantContext::idOrFail();

        try {
            $session = $this->stripe()->checkout->sessions->create([
                'mode' => 'subscription',
                'line_items' => [[
                    'quantity' => 1,
                    'price_data' => [
                        'currency' => mb_strtolower($plan->currency),
                        'unit_amount' => $plan->priceInMinorUnits(),
                        'recurring' => ['interval' => 'month'],
                        'product_data' => ['name' => $plan->name],
                    ],
                ]],
                // KİRACI VE PLAN METADATA İLE TAŞINIR — Stripe bizim
                // kimliklerimizi bilmez ve webhook onları buradan okur.
                // Yazılmazsa ödeme alınır ama abonelik AÇILAMAZ.
                'metadata' => [
                    'tenant_id' => $tenantId,
                    'plan_code' => $plan->code,
                ],
                // Abonelik nesnesine de yazılır: `customer.subscription.*`
                // olayları oturum metadata'sını TAŞIMAZ.
                'subscription_data' => [
                    'metadata' => [
                        'tenant_id' => $tenantId,
                        'plan_code' => $plan->code,
                    ],
                ],
                'success_url' => url('/billing?durum=basarili'),
                'cancel_url' => url('/billing?durum=iptal'),
                'client_reference_id' => $tenantId,
            ]);
        } catch (ApiErrorException $e) {
            report($e);

            throw ValidationException::withMessages([
                'plan_code' => 'Ödeme sayfası açılamadı. Lütfen tekrar deneyin.',
            ]);
        }

        // Stripe'a yönlendirme — Inertia dışı, tam sayfa.
        return redirect()->away($session->url);
    }

    // ─────────────────────────────────────────────────── yardımcılar

    /** @return list<array<string, mixed>> */
    private function publicPlans(): array
    {
        return TenantContext::runAsSystem(
            fn (): array => Plan::query()
                ->where('is_public', true)
                ->orderBy('price_monthly')
                ->get()
                ->map(fn (Plan $plan): array => [
                    'code' => $plan->code,
                    'name' => $plan->name,
                    'price' => $plan->price_monthly,
                    'currency' => $plan->currency,
                    'limits' => [
                        'products' => $plan->limitFor(QuotaMetric::PRODUCTS),
                        'channels' => $plan->limitFor(QuotaMetric::CHANNELS),
                    ],
                ])
                ->all(),
        );
    }

    /** @return array<string, array{current: int, limit: int|null}> */
    private function usage(EnforceQuota $quota, ?Plan $plan): array
    {
        $usage = [];

        foreach (QuotaMetric::cases() as $metric) {
            $usage[$metric->value] = [
                'current' => $quota->currentUsage($metric),
                // SINIRSIZ `null` TAŞINIR, sıfır DEĞİL: sıfır gösterilse
                // ekran "0 hakkın var" der ve sınırsız plan en kısıtlı
                // plan gibi görünürdü.
                'limit' => $plan?->limitFor($metric),
                'label' => $metric->label(),
            ];
        }

        return $usage;
    }

    private function stripeConfigured(): bool
    {
        $secret = config('entegrasyon.stripe.secret');

        return is_string($secret) && $secret !== '';
    }

    private function stripe(): StripeClient
    {
        return new StripeClient((string) config('entegrasyon.stripe.secret'));
    }
}
