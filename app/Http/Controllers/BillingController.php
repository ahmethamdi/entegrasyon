<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Billing\Actions\EnforceQuota;
use App\Domain\Billing\Actions\SyncSubscriptionFromShopify;
use App\Domain\Billing\Contracts\PaymentGateway;
use App\Domain\Billing\Enums\QuotaMetric;
use App\Domain\Billing\Models\BillingConsent;
use App\Domain\Billing\Models\Plan;
use App\Domain\Billing\Models\Subscription;
use App\Domain\Billing\Support\ShopifyBilling;
use App\Domain\Channels\Adapters\Shopify\ShopifyAuth;
use App\Domain\Channels\Models\ChannelConnection;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Stripe\Exception\ApiErrorException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

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
    public function index(Request $request, EnforceQuota $quota, PaymentGateway $gateway, ShopifyBilling $shopify): InertiaResponse
    {
        $plan = $quota->planForCurrentTenant();
        $shop = $shopify->connectionForCurrentTenant();

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
            // Shopify mağazası olan satıcı Shopify faturasıyla öder (App
            // Store kuralı 1.2.1); ekran fiyatı USD ve "Shopify faturana
            // eklenir" diye gösterir.
            'billing' => [
                'provider' => $shop !== null ? 'shopify' : 'stripe',
                'shop' => $shop?->external_account_id,
                'canDowngrade' => $shop !== null && $subscription !== null && $subscription->provider === 'shopify',
            ],
            // Sağlayıcı yapılandırılmamışsa ekran bunu SÖYLER; "satın al"
            // düğmesine basıp sessizce hata almak, sebebi hiç
            // anlaşılmayan bir başarısızlıktır.
            'paymentsEnabled' => $shop !== null ? ShopifyAuth::configured() : $gateway->isConfigured(),
        ]);
    }

    /**
     * Stripe Checkout oturumu açar ve kullanıcıyı yönlendirir; yaşayan
     * abonelik varsa onun planını değiştirir.
     *
     * ABONELİK BURADA YAZILMAZ — webhook yazar.
     */
    public function checkout(Request $request, PaymentGateway $gateway, ShopifyBilling $shopify): RedirectResponse|Response
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

        // SHOPIFY'DAN FATURALANAN SATICI Stripe'a HİÇ GİTMEZ (kural 1.2.1).
        $shop = $shopify->connectionForCurrentTenant();

        if ($shop !== null) {
            return $this->shopifyCheckout($request, $shopify, $shop, $plan);
        }

        // ÜCRETSİZ PLAN İÇİN ÖDEME AÇILMAZ: Stripe sıfır tutarlı
        // abonelik oturumunu reddeder ve kullanıcı anlamsız bir hata
        // görürdü.
        if ($plan->priceInMinorUnits() <= 0) {
            throw ValidationException::withMessages([
                'plan_code' => 'Ücretsiz plan için ödeme gerekmez.',
            ]);
        }

        if (! $gateway->isConfigured()) {
            throw ValidationException::withMessages([
                'plan_code' => 'Ödeme altyapısı henüz yapılandırılmadı.',
            ]);
        }

        $tenantId = TenantContext::idOrFail();

        // ⚠️ YAŞAYAN ABONELİK VARSA YENİ CHECKOUT AÇILMAZ.
        //
        // Açılsaydı webhook yeni aboneliği yazar, eskisini YEREL olarak
        // kapatırdı — ama Stripe'taki eski abonelik kesilmeye devam eder ve
        // satıcı her ay İKİ KEZ ödeme yapardı. Plan değişikliği aynı
        // aboneliğin kalemini değiştirir; yerel plan yine webhook'tan
        // (`customer.subscription.updated` + metadata) yazılır.
        $live = Subscription::query()
            ->whereIn('status', Subscription::LIVE_STATUSES)
            ->whereNotNull('external_ref')
            ->latest('started_at')
            ->first();

        if ($live !== null && $live->plan_code === $plan->code) {
            throw ValidationException::withMessages([
                'plan_code' => 'Zaten bu plandasınız.',
            ]);
        }

        // ÖN BİLGİLENDİRME ONAYI — plan geçerli ve ödeme gerçekten
        // açılacaksa istenir. Daha önce istenseydi gizli/ücretsiz plan
        // denemesi asıl sebep yerine "sözleşmeyi onaylayın" hatası alırdı.
        $consent = $request->validate([
            'accept_terms' => ['accepted'],
            'waive_withdrawal' => ['sometimes', 'boolean'],
        ], [
            'accept_terms.accepted' => 'Devam etmek için sözleşmeyi okuyup onaylaman gerekiyor.',
        ]);

        BillingConsent::query()->create([
            'tenant_id' => $tenantId,
            'user_id' => $request->user()?->id,
            'plan_code' => $plan->code,
            'terms_version' => BillingConsent::TERMS_VERSION,
            'terms_accepted_at' => now(),
            'withdrawal_waived' => (bool) ($consent['waive_withdrawal'] ?? false),
            'ip' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 500),
        ]);

        try {
            if ($live !== null) {
                $gateway->changePlan((string) $live->external_ref, $plan, $tenantId);

                return redirect('/billing')->with(
                    'success',
                    "Plan değişikliği {$plan->name} olarak gönderildi; ödeme sağlayıcısı onaylayınca birkaç saniye içinde görünür. Fark orantılı faturalanır.",
                );
            }

            $url = $gateway->startCheckout(
                $plan,
                $tenantId,
                url('/billing?durum=basarili'),
                url('/billing?durum=iptal'),
            );
        } catch (ApiErrorException $e) {
            report($e);

            throw ValidationException::withMessages([
                'plan_code' => $live !== null
                    ? 'Plan değiştirilemedi. Lütfen tekrar deneyin.'
                    : 'Ödeme sayfası açılamadı. Lütfen tekrar deneyin.',
            ]);
        }

        // Stripe'a yönlendirme — TAM SAYFA. `redirect()->away()` Inertia
        // isteğinde ÇALIŞMAZDI: arka plan isteği başka siteye yönlendirmeyi
        // izleyemez, ödeme sayfası hiç açılmazdı (Stripe gerçek anahtarla
        // hiç denenmediği için görünmedi; 5 Eki).
        return Inertia::location($url);
    }

    /**
     * Shopify aboneliği — onay Shopify'ın sayfasında verilir.
     *
     * Yerel satır `pending` AÇILIR (hangi plan, hangi mağaza bizde kalsın);
     * kotayı açmaz. Durum dönüşte ve webhook'ta Shopify'dan OKUNARAK
     * değişir (`SyncSubscriptionFromShopify`).
     */
    private function shopifyCheckout(Request $request, ShopifyBilling $shopify, ChannelConnection $shop, Plan $plan): RedirectResponse|Response
    {
        $live = Subscription::query()
            ->where('provider', 'shopify')
            ->whereIn('status', Subscription::LIVE_STATUSES)
            ->latest('started_at')
            ->first();

        // ÜCRETSİZE DÖNÜŞ self-servis olmalı (kural 1.2.3): Shopify'daki
        // abonelik iptal edilir, yerel satır kapanır.
        if ($plan->priceInMinorUnits() <= 0) {
            if ($live === null) {
                throw ValidationException::withMessages(['plan_code' => __('Zaten ücretsiz plandasın.')]);
            }

            try {
                $shopify->cancel($shop, (string) $live->external_ref);
            } catch (Throwable $e) {
                report($e);

                throw ValidationException::withMessages(['plan_code' => __('Shopify aboneliği iptal edilemedi. Lütfen tekrar dene.')]);
            }

            app(SyncSubscriptionFromShopify::class)->apply((string) $live->external_ref, 'CANCELLED');

            return redirect('/billing')->with('success', __('Ücretsiz plana geçtin. Shopify aboneliğin iptal edildi.'));
        }

        if ($live !== null && $live->plan_code === $plan->code) {
            throw ValidationException::withMessages(['plan_code' => __('Zaten bu plandasın.')]);
        }

        if ($plan->shopify_price_usd === null) {
            throw ValidationException::withMessages(['plan_code' => __('Bu plan satın alınamaz.')]);
        }

        $consent = $request->validate([
            'accept_terms' => ['accepted'],
            'waive_withdrawal' => ['sometimes', 'boolean'],
        ], [
            'accept_terms.accepted' => __('Devam etmek için sözleşmeyi okuyup onaylaman gerekiyor.'),
        ]);

        $tenantId = TenantContext::idOrFail();

        BillingConsent::query()->create([
            'tenant_id' => $tenantId,
            'user_id' => $request->user()?->id,
            'plan_code' => $plan->code,
            'terms_version' => BillingConsent::TERMS_VERSION,
            'terms_accepted_at' => now(),
            'withdrawal_waived' => (bool) ($consent['waive_withdrawal'] ?? false),
            'ip' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 500),
        ]);

        try {
            $created = $shopify->create($shop, $plan, route('billing.shopify.return'));
        } catch (Throwable $e) {
            report($e);

            throw ValidationException::withMessages(['plan_code' => __('Shopify onay sayfası açılamadı. Lütfen tekrar dene.')]);
        }

        Subscription::query()->create([
            'tenant_id' => $tenantId,
            'plan_code' => $plan->code,
            'provider' => 'shopify',
            'channel_connection_id' => $shop->id,
            'status' => 'pending',
            'external_ref' => $created['id'],
        ]);

        Log::info('billing.shopify.created', ['tenant' => $tenantId, 'plan' => $plan->code, 'test' => $created['test']]);

        return Inertia::location($created['confirmationUrl']);
    }

    /**
     * Shopify onay sayfasından dönüş. `charge_id` yalnız HANGİ aboneliğe
     * bakılacağını söyler; durum Shopify'dan OKUNUR — adres çubuğundaki
     * parametreyle plan açılmaz.
     */
    public function shopifyReturn(Request $request, ShopifyBilling $shopify, SyncSubscriptionFromShopify $sync): RedirectResponse
    {
        $chargeId = $request->string('charge_id')->toString();

        $pending = ctype_digit($chargeId)
            ? Subscription::query()
                ->where('provider', 'shopify')
                ->where('external_ref', 'gid://shopify/AppSubscription/'.$chargeId)
                ->first()
            : null;

        $shop = $pending?->channel_connection_id !== null
            ? ChannelConnection::query()->find($pending->channel_connection_id)
            : null;

        if ($pending === null || $shop === null) {
            return redirect('/billing')->with('success', __('Shopify aboneliği bulunamadı.'));
        }

        try {
            $remote = $shopify->fetch($shop, (string) $pending->external_ref);
        } catch (Throwable $e) {
            report($e);
            $remote = null;
        }

        if ($remote === null) {
            return redirect('/billing')->with('success', __('Shopify onayı birkaç saniye içinde görünecek; sayfayı yenile.'));
        }

        $subscription = $sync->apply((string) $pending->external_ref, $remote['status'], $remote['currentPeriodEnd']);

        return redirect('/billing')->with('success', match ($subscription?->status) {
            'active' => __(':plan planın açıldı. Ücret Shopify faturana eklenir.', ['plan' => $subscription->plan?->name ?? $subscription->plan_code]),
            'cancelled' => __('Shopify\'da onay verilmedi; planın değişmedi.'),
            default => __('Shopify onayı bekleniyor.'),
        });
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
                    'shopifyPrice' => $plan->shopify_price_usd,
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
}
