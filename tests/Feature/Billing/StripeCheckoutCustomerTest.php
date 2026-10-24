<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Domain\Billing\Models\Plan;
use App\Domain\Billing\Support\StripePaymentGateway;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Tests\TestCase;

/**
 * Stripe ağ geçidi — giden istekler (SDK'nın HTTP istemcisi değiştirilir;
 * `Http::fake` Stripe'ın curl'ünü yakalamaz). Ağa çıkılmaz.
 *
 * 24 Eki · müşteri e-posta ve adla açılır. S14 · müşteri yeniden kullanılır
 * (yetim müşteri yok), ödeme sayfasında kart reddi notu, dönem sonu iptali,
 * son ödemenin iadesi.
 */
final class StripeCheckoutCustomerTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array{method: string, url: string, params: array<string, mixed>}> */
    private array $sent = [];

    /** @var array<string, array<string, mixed>> yol parçası => yanıt */
    private array $responses = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['entegrasyon.stripe.secret' => 'sk_test_x']);

        $sent = &$this->sent;
        $responses = &$this->responses;
        ApiRequestor::setHttpClient(new class($sent, $responses) implements ClientInterface
        {
            /** @param list<array<string, mixed>> $sent */
            public function __construct(private array &$sent, private array &$responses) {}

            public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
            {
                $this->sent[] = ['method' => $method, 'url' => $absUrl, 'params' => $params];

                foreach ($this->responses as $needle => $body) {
                    if (str_contains($method.' '.$absUrl, $needle)) {
                        return [json_encode($body), $body['__status'] ?? 200, []];
                    }
                }

                $body = match (true) {
                    str_contains($absUrl, '/products/search') => ['object' => 'search_result', 'data' => [['id' => 'prod_1', 'object' => 'product']], 'has_more' => false],
                    str_contains($absUrl, '/customers/search') => ['object' => 'search_result', 'data' => [], 'has_more' => false],
                    str_contains($absUrl, '/customers') => ['id' => 'cus_new', 'object' => 'customer'],
                    str_contains($absUrl, '/checkout/sessions') => ['id' => 'cs_1', 'object' => 'checkout.session', 'url' => 'https://checkout.stripe.com/c/pay/cs_1'],
                    default => ['id' => 'x', 'object' => 'unknown'],
                };

                return [json_encode($body), 200, []];
            }
        });
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(null);

        parent::tearDown();
    }

    private function posted(string $pathEnd): ?array
    {
        return collect($this->sent)->first(fn (array $r): bool => $r['method'] === 'post' && str_ends_with(parse_url($r['url'], PHP_URL_PATH), $pathEnd));
    }

    #[Test]
    public function a_new_customer_carries_the_owner_email_and_company_name(): void
    {
        $owner = User::factory()->create(['email' => 'sahip@ornek.com.tr']);
        $tenant = (new CreateTenant)->run(name: 'Örnek Ticaret', owner: $owner);

        $ref = (new StripePaymentGateway)->ensureCustomer($tenant->id, null);

        $this->assertSame('cus_new', $ref);
        $customer = $this->posted('/v1/customers');
        $this->assertNotNull($customer);
        $this->assertSame('sahip@ornek.com.tr', $customer['params']['email']);
        $this->assertSame('Örnek Ticaret', $customer['params']['name']);
        $this->assertSame($tenant->id, $customer['params']['metadata']['tenant_id']);
        $this->assertStringContainsString('§ 19 UStG', $customer['params']['invoice_settings']['footer']);
    }

    #[Test]
    public function a_known_customer_is_reused_and_no_new_one_is_created(): void
    {
        $this->responses['get https://api.stripe.com/v1/customers/cus_known'] = ['id' => 'cus_known', 'object' => 'customer'];

        $ref = (new StripePaymentGateway)->ensureCustomer('t-1', 'cus_known');

        $this->assertSame('cus_known', $ref);
        $this->assertNull($this->posted('/v1/customers'), 'bilinen müşteri varken yeni müşteri açıldı (yetim müşteri)');
    }

    #[Test]
    public function an_earlier_customer_is_found_by_tenant_metadata(): void
    {
        $this->responses['/customers/search'] = ['object' => 'search_result', 'data' => [['id' => 'cus_eski', 'object' => 'customer']], 'has_more' => false];

        $ref = (new StripePaymentGateway)->ensureCustomer('t-1', null);

        $this->assertSame('cus_eski', $ref);
        $this->assertNull($this->posted('/v1/customers'));
        $search = collect($this->sent)->first(fn ($r) => str_contains($r['url'], '/customers/search'));
        $this->assertSame("metadata['tenant_id']:'t-1'", $search['params']['query']);
    }

    #[Test]
    public function a_deleted_customer_is_replaced(): void
    {
        $this->responses['get https://api.stripe.com/v1/customers/cus_silik'] = ['id' => 'cus_silik', 'object' => 'customer', 'deleted' => true];

        $tenant = (new CreateTenant)->run(name: 'Silik', owner: User::factory()->create());
        $ref = (new StripePaymentGateway)->ensureCustomer($tenant->id, 'cus_silik');

        $this->assertSame('cus_new', $ref);
    }

    #[Test]
    public function checkout_uses_the_given_customer_and_shows_the_card_decline_hint(): void
    {
        $plan = Plan::create(['code' => 'starter', 'name' => 'Başlangıç', 'price_monthly' => 499, 'currency' => 'TRY', 'limits' => []]);

        $url = (new StripePaymentGateway)->startCheckout($plan, 't-1', 'cus_known', 'https://34pazar.com/ok', 'https://34pazar.com/iptal');

        $this->assertSame('https://checkout.stripe.com/c/pay/cs_1', $url);
        $session = $this->posted('/v1/checkout/sessions');
        $this->assertSame('cus_known', $session['params']['customer']);
        $this->assertSame(49900, $session['params']['line_items'][0]['price_data']['unit_amount']);
        $this->assertStringContainsString('yurt dışı ve internet alışverişi', $session['params']['custom_text']['submit']['message']);
        $this->assertLessThanOrEqual(1200, mb_strlen($session['params']['custom_text']['submit']['message']), 'Stripe sınırı 1.200 karakter');
        $this->assertNull($this->posted('/v1/customers'), 'checkout kendi başına müşteri açmamalı');
    }

    #[Test]
    public function cancel_at_period_end_does_not_cancel_immediately(): void
    {
        (new StripePaymentGateway)->cancelAtPeriodEnd('sub_1');

        $update = $this->posted('/v1/subscriptions/sub_1');
        $this->assertNotNull($update);
        $this->assertContains($update['params']['cancel_at_period_end'], [true, 'true']);
        $this->assertFalse(collect($this->sent)->contains(fn ($r) => $r['method'] === 'delete'));
    }

    #[Test]
    public function refund_targets_the_latest_unrefunded_successful_charge(): void
    {
        $this->responses['get https://api.stripe.com/v1/charges'] = ['object' => 'list', 'data' => [
            ['id' => 'ch_basarisiz', 'object' => 'charge', 'status' => 'failed', 'amount' => 49900, 'amount_refunded' => 0, 'refunded' => false, 'currency' => 'try'],
            ['id' => 'ch_iyi', 'object' => 'charge', 'status' => 'succeeded', 'amount' => 49900, 'amount_refunded' => 0, 'refunded' => false, 'currency' => 'try'],
        ], 'has_more' => false];
        $this->responses['post https://api.stripe.com/v1/refunds'] = ['id' => 're_1', 'object' => 'refund'];

        $result = (new StripePaymentGateway)->refundLatestPayment('cus_known');

        $this->assertSame(['charge' => 'ch_iyi', 'refund' => 're_1', 'amount_minor' => 49900, 'currency' => 'TRY'], $result);
        $this->assertSame('ch_iyi', $this->posted('/v1/refunds')['params']['charge']);
    }

    #[Test]
    public function refund_fails_loudly_when_there_is_nothing_to_refund(): void
    {
        $this->responses['get https://api.stripe.com/v1/charges'] = ['object' => 'list', 'data' => [
            ['id' => 'ch_iade', 'object' => 'charge', 'status' => 'succeeded', 'amount' => 49900, 'amount_refunded' => 49900, 'refunded' => true, 'currency' => 'try'],
        ], 'has_more' => false];

        $this->expectException(RuntimeException::class);

        try {
            (new StripePaymentGateway)->refundLatestPayment('cus_known');
        } finally {
            $this->assertNull($this->posted('/v1/refunds'));
        }
    }
}
