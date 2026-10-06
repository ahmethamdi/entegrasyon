<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Domain\Billing\Actions\EnforceQuota;
use App\Domain\Billing\Models\Subscription;
use App\Domain\Channels\Adapters\Shopify\ShopifyAdapter;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Models\ChannelType;
use App\Domain\Channels\Support\CredentialVault;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use App\Domain\Messaging\Actions\IngestInboxMessage;
use App\Domain\Messaging\Jobs\ProcessInboxMessage;
use App\Domain\Orders\Models\Order;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Shopify Billing — Shopify mağazası olan satıcı planını Shopify
 * faturasıyla öder (App Store kuralı 1.2.1; Stripe'a gitmek ret sebebi).
 *
 * Kurallar: fiyat USD (Shopify TL kesmez) · onay Shopify'da · yerel durum
 * YALNIZ Shopify'dan okunanla değişir · tek aktif abonelik · ücretsize
 * dönüş self-servis · kaldırmada abonelik kapanır.
 */
final class ShopifyBillingTest extends TestCase
{
    use RefreshDatabase;

    private const SUB_1 = 'gid://shopify/AppSubscription/111';

    private const SUB_2 = 'gid://shopify/AppSubscription/222';

    /** Sahte Shopify'ın vereceği abonelik durumları (kimlik → durum). */
    private array $remoteStatus = [];

    private bool $devStore = true;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.shopify.client_id', 'istemci-123');
        config()->set('services.shopify.client_secret', 'sir');
        config()->set('services.shopify.billing_test', false);
        (new PlanSeeder)->run();
    }

    // ═════════════════════════════════════════════════════ ekran

    #[Test]
    public function a_shopify_merchant_sees_usd_prices_and_shopify_billing(): void
    {
        [, $user] = $this->shopifyTenant();

        $props = $this->actingAs($user)->get('/billing')->assertOk()->viewData('page')['props'];

        $this->assertSame('shopify', $props['billing']['provider']);
        $this->assertTrue($props['paymentsEnabled']);
        $prices = collect($props['plans'])->pluck('shopifyPrice', 'code')->all();
        $this->assertSame(['free' => null, 'starter' => '9.99', 'pro' => '29.99', 'business' => '79.99'], $prices);
    }

    // ═════════════════════════════════════════════════════ satın alma

    /**
     * Satın alma Shopify'a gider (Stripe'a DEĞİL), fiyat USD, geliştirme
     * mağazasında test aboneliği; yerel satır `pending` — kota AÇILMAZ.
     */
    #[Test]
    public function buying_creates_a_usd_shopify_subscription_not_a_stripe_checkout(): void
    {
        [$tenant, $user] = $this->shopifyTenant();
        $this->fakeShopify();

        $this->buy($user, 'starter')
            ->assertStatus(409)
            ->assertHeader('X-Inertia-Location', 'https://magaza.myshopify.com/admin/charges/onay/111');

        $this->assertSame([], $this->payments->checkouts, 'Shopify satıcısı Stripe\'a gitmemeli.');

        Http::assertSent(function (Request $r): bool {
            if (! str_contains((string) $r['query'], 'appSubscriptionCreate')) {
                return false;
            }

            $price = $r['variables']['lineItems'][0]['plan']['appRecurringPricingDetails']['price'];

            return $price === ['amount' => '9.99', 'currencyCode' => 'USD']
                && $r['variables']['test'] === true
                && $r['variables']['returnUrl'] === route('billing.shopify.return')
                // Panel Türkçe olsa da Shopify onay ekranı/faturası İngilizce.
                && $r['variables']['name'] === '34Pazar Starter';
        });

        $sub = $this->subscriptions($tenant)->sole();
        $this->assertSame(['pending', 'shopify', 'starter', self::SUB_1], [$sub->status, $sub->provider, $sub->plan_code, $sub->external_ref]);
        $this->assertSame('free', $this->planOf($tenant), 'Onay gelmeden plan açılmaz.');
    }

    #[Test]
    public function a_live_store_gets_a_real_charge(): void
    {
        [, $user] = $this->shopifyTenant();
        $this->devStore = false;
        $this->fakeShopify();

        $this->buy($user, 'pro');

        Http::assertSent(fn (Request $r): bool => str_contains((string) $r['query'], 'appSubscriptionCreate')
            && $r['variables']['test'] === false);
    }

    #[Test]
    public function the_agreement_must_be_accepted(): void
    {
        [$tenant, $user] = $this->shopifyTenant();
        $this->fakeShopify();

        $this->actingAs($user)->post('/billing/checkout', ['plan_code' => 'starter'])
            ->assertSessionHasErrors('accept_terms');

        $this->assertSame(0, $this->subscriptions($tenant)->count());
    }

    // ═════════════════════════════════════════════════════ dönüş

    /** Onaylandı: durum Shopify'dan OKUNUR, plan açılır. */
    #[Test]
    public function returning_after_approval_activates_the_plan(): void
    {
        [$tenant, $user] = $this->shopifyTenant();
        $this->fakeShopify();
        $this->buy($user, 'starter');

        $this->remoteStatus[self::SUB_1] = 'ACTIVE';

        // İngilizce panel: plan adı da çevrilir ("Your Başlangıç plan" çıkıyordu).
        $this->actingAs($user)->withHeader('Accept-Language', 'en-US')->get('/billing/shopify/return?charge_id=111')
            ->assertRedirect('/billing')
            ->assertSessionHas('success', 'Your Starter plan is active. The charge is added to your Shopify bill.');

        $sub = $this->subscriptions($tenant)->sole();
        $this->assertSame('active', $sub->status);
        $this->assertNotNull($sub->current_period_end);
        $this->assertSame('starter', $this->planOf($tenant));
    }

    /** Reddedildi: plan değişmez. Adres çubuğundaki parametre plan AÇAMAZ. */
    #[Test]
    public function a_declined_or_forged_return_does_not_open_a_plan(): void
    {
        [$tenant, $user] = $this->shopifyTenant();
        $this->fakeShopify();
        $this->buy($user, 'starter');

        $this->remoteStatus[self::SUB_1] = 'DECLINED';
        $this->actingAs($user)->get('/billing/shopify/return?charge_id=111')->assertRedirect('/billing');

        $this->assertSame('cancelled', $this->subscriptions($tenant)->sole()->status);
        $this->assertSame('free', $this->planOf($tenant));

        // Bizim açmadığımız bir kimlik: hiçbir şey yazılmaz.
        $this->actingAs($user)->get('/billing/shopify/return?charge_id=999')->assertRedirect('/billing');
        $this->assertSame(1, $this->subscriptions($tenant)->count());
    }

    /** Plan yükseltme: yeni abonelik aktifleşince eskisi kapanır — tek aktif. */
    #[Test]
    public function upgrading_leaves_a_single_active_subscription(): void
    {
        [$tenant, $user] = $this->shopifyTenant();
        $this->fakeShopify();

        $this->buy($user, 'starter');
        $this->remoteStatus[self::SUB_1] = 'ACTIVE';
        $this->actingAs($user)->get('/billing/shopify/return?charge_id=111');

        $this->buy($user, 'pro');
        $this->remoteStatus[self::SUB_2] = 'ACTIVE';
        $this->actingAs($user)->get('/billing/shopify/return?charge_id=222');

        $statuses = $this->subscriptions($tenant)->pluck('status', 'external_ref')->all();
        ksort($statuses);
        $this->assertSame(['gid://shopify/AppSubscription/111' => 'cancelled', 'gid://shopify/AppSubscription/222' => 'active'], $statuses);
        $this->assertSame('pro', $this->planOf($tenant));
    }

    /** Ücretsize dönüş self-servis (kural 1.2.3): Shopify'da iptal + yerelde kapanış. */
    #[Test]
    public function downgrading_to_free_cancels_in_shopify(): void
    {
        [$tenant, $user] = $this->shopifyTenant();
        $this->fakeShopify();
        $this->buy($user, 'starter');
        $this->remoteStatus[self::SUB_1] = 'ACTIVE';
        $this->actingAs($user)->get('/billing/shopify/return?charge_id=111');

        $this->actingAs($user)->post('/billing/checkout', ['plan_code' => 'free'])
            ->assertRedirect('/billing')->assertSessionHas('success');

        Http::assertSent(fn (Request $r): bool => str_contains((string) $r['query'], 'appSubscriptionCancel')
            && $r['variables']['id'] === self::SUB_1);
        $this->assertSame('cancelled', $this->subscriptions($tenant)->sole()->status);
        $this->assertSame('free', $this->planOf($tenant));
    }

    // ═════════════════════════════════════════════════════ webhook

    /**
     * `app_subscriptions/update` faturalamaya gider — sipariş yoluna
     * DÜŞMEZ (bilinmeyen konu `updated` sayılır, abonelik kimliği sipariş
     * kimliği sanılırdı).
     */
    #[Test]
    public function the_subscription_webhook_updates_status_and_never_reaches_orders(): void
    {
        [$tenant, $user, $connection] = $this->shopifyTenant();
        $this->fakeShopify();
        $this->buy($user, 'starter');
        $this->remoteStatus[self::SUB_1] = 'ACTIVE';

        $this->webhook($tenant, $connection, 'app_subscriptions/update', 'evt-1', [
            'app_subscription' => ['admin_graphql_api_id' => self::SUB_1, 'status' => 'ACTIVE', 'name' => '34Pazar Başlangıç'],
        ]);
        $this->assertSame('active', $this->subscriptions($tenant)->sole()->status);

        $this->webhook($tenant, $connection, 'app_subscriptions/update', 'evt-2', [
            'app_subscription' => ['admin_graphql_api_id' => self::SUB_1, 'status' => 'FROZEN'],
        ]);
        $this->assertSame('past_due', $this->subscriptions($tenant)->sole()->status);

        $this->assertSame(0, $this->asTenant($tenant, fn (): int => Order::query()->count()));
    }

    /** Uygulama kaldırıldı: abonelik kapanır VE erişim kapanır (ikisi birden). */
    #[Test]
    public function uninstalling_closes_the_subscription_and_the_connection(): void
    {
        [$tenant, $user, $connection] = $this->shopifyTenant();
        $this->fakeShopify();
        $this->buy($user, 'starter');
        $this->remoteStatus[self::SUB_1] = 'ACTIVE';
        $this->actingAs($user)->get('/billing/shopify/return?charge_id=111');

        $this->webhook($tenant, $connection, 'app/uninstalled', 'evt-u', ['id' => 1, 'domain' => 'magaza.myshopify.com']);

        $this->assertSame('cancelled', $this->subscriptions($tenant)->sole()->status);
        $this->assertSame('free', $this->planOf($tenant));
        $this->assertNotSame('active', TenantContext::runAsSystem(fn () => $connection->fresh()->status));
    }

    // ═════════════════════════════════════════════════════ Stripe tarafı

    /** Shopify'ı olmayan satıcı Stripe'ta kalır; yönlendirme tam sayfa (Inertia). */
    #[Test]
    public function a_merchant_without_shopify_still_uses_stripe(): void
    {
        config()->set('entegrasyon.stripe.secret', 'sk_test_x');
        $user = User::factory()->create();
        (new CreateTenant)->run(name: 'Stripe '.uniqid(), owner: $user);

        $this->actingAs($user)->post('/billing/checkout', [
            'plan_code' => 'starter', 'accept_terms' => true,
        ], ['X-Inertia' => 'true'])
            ->assertStatus(409)
            ->assertHeader('X-Inertia-Location', 'https://checkout.stripe.test/starter');

        $this->assertCount(1, $this->payments->checkouts);
    }

    // ═════════════════════════════════════════════════════ yardımcılar

    private function buy(User $user, string $plan): TestResponse
    {
        return $this->actingAs($user)->post('/billing/checkout', [
            'plan_code' => $plan,
            'accept_terms' => true,
        ], ['X-Inertia' => 'true']);
    }

    private function webhook(Tenant $tenant, ChannelConnection $connection, string $topic, string $eventId, array $payload): void
    {
        $message = $this->asTenant($tenant, fn () => app(IngestInboxMessage::class)->run(
            connection: $connection,
            source: 'webhook',
            externalEventId: $eventId,
            eventType: $topic,
            payload: (string) json_encode($payload),
            signatureValid: true,
        ));

        (new ProcessInboxMessage($tenant->id, $message->id))->handle();
    }

    private function fakeShopify(): void
    {
        $counter = 0;

        Http::fake(['*/admin/api/*/graphql.json' => function (Request $r) use (&$counter) {
            $q = (string) $r['query'];

            if (str_contains($q, 'ShopPlan')) {
                return Http::response(['data' => ['shop' => ['plan' => ['partnerDevelopment' => $this->devStore]]]]);
            }

            if (str_contains($q, 'appSubscriptionCreate')) {
                $id = ++$counter === 1 ? self::SUB_1 : self::SUB_2;
                $this->remoteStatus[$id] = 'PENDING';

                return Http::response(['data' => ['appSubscriptionCreate' => [
                    'appSubscription' => ['id' => $id],
                    'confirmationUrl' => 'https://magaza.myshopify.com/admin/charges/onay/'.basename($id),
                    'userErrors' => [],
                ]]]);
            }

            if (str_contains($q, 'AppSubscriptionNode')) {
                $id = $r['variables']['id'];

                return Http::response(['data' => ['node' => isset($this->remoteStatus[$id]) ? [
                    'id' => $id,
                    'status' => $this->remoteStatus[$id],
                    'currentPeriodEnd' => '2026-11-05T12:00:00Z',
                    'test' => true,
                ] : null]]);
            }

            if (str_contains($q, 'appSubscriptionCancel')) {
                return Http::response(['data' => ['appSubscriptionCancel' => [
                    'appSubscription' => ['id' => $r['variables']['id'], 'status' => 'CANCELLED'],
                    'userErrors' => [],
                ]]]);
            }

            return Http::response(['data' => []]);
        }]);
    }

    /** @return array{0: Tenant, 1: User, 2: ChannelConnection} */
    private function shopifyTenant(): array
    {
        $this->asSystem(fn () => ChannelType::query()->updateOrCreate(['code' => 'shopify'], [
            'name' => 'Shopify', 'kind' => 'storefront', 'adapter_class' => ShopifyAdapter::class,
            'supports_webhooks' => true, 'is_active' => true,
        ]));

        $user = User::factory()->create();
        $tenant = (new CreateTenant)->run(name: 'Shopify '.uniqid(), owner: $user);

        $connection = $this->asTenant($tenant, function (): ChannelConnection {
            $connection = ChannelConnection::factory()->create([
                'channel_type_code' => 'shopify',
                'external_account_id' => 'magaza.myshopify.com',
                'status' => 'active',
                'settings' => ['location_gid' => 'gid://shopify/Location/1'],
            ]);

            app(CredentialVault::class)->store($connection, ['access_token' => 'erisim', 'webhook_secret' => 'whsec']);

            return $connection;
        });

        return [$tenant, $user, $connection];
    }

    private function subscriptions(Tenant $tenant)
    {
        return TenantContext::runAsSystem(fn () => Subscription::query()->where('tenant_id', $tenant->id)->orderBy('created_at')->get());
    }

    private function planOf(Tenant $tenant): ?string
    {
        return $this->asTenant($tenant, fn () => app(EnforceQuota::class)->planForCurrentTenant()?->code);
    }
}
