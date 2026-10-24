<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Domain\Billing\Models\Plan;
use App\Domain\Billing\Support\StripePaymentGateway;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Tests\TestCase;

/**
 * 24 Eki 2026 açılış denetimi · Stripe müşterisi e-posta ve adla açılır.
 *
 * Stripe SDK kendi curl istemcisini kullanır (`Http::fake` yakalamaz);
 * test SDK'nın HTTP istemcisini değiştirip giden gövdeyi okur.
 */
final class StripeCheckoutCustomerTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array{method: string, url: string, params: array<string, mixed>}> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['entegrasyon.stripe.secret' => 'sk_test_x']);
        $sent = &$this->sent;
        ApiRequestor::setHttpClient(new class($sent) implements ClientInterface
        {
            /** @param list<array<string, mixed>> $sent */
            public function __construct(private array &$sent) {}

            public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
            {
                $this->sent[] = ['method' => $method, 'url' => $absUrl, 'params' => $params];
                $body = match (true) {
                    str_contains($absUrl, '/products/search') => ['object' => 'search_result', 'data' => [['id' => 'prod_1', 'object' => 'product']], 'has_more' => false],
                    str_contains($absUrl, '/customers') => ['id' => 'cus_1', 'object' => 'customer'],
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

    #[Test]
    public function the_customer_carries_the_owner_email_and_company_name(): void
    {
        $owner = User::factory()->create(['email' => 'sahip@ornek.com.tr']);
        $tenant = (new CreateTenant)->run(name: 'Örnek Ticaret', owner: $owner);
        $plan = Plan::create(['code' => 'starter', 'name' => 'Başlangıç', 'price_monthly' => 499, 'currency' => 'TRY', 'limits' => []]);

        $url = (new StripePaymentGateway)->startCheckout($plan, $tenant->id, 'https://34pazar.com/ok', 'https://34pazar.com/iptal');

        $this->assertSame('https://checkout.stripe.com/c/pay/cs_1', $url);
        $customer = collect($this->sent)->first(fn (array $r): bool => $r['method'] === 'post' && str_ends_with($r['url'], '/v1/customers'));
        $this->assertNotNull($customer);
        $this->assertSame('sahip@ornek.com.tr', $customer['params']['email']);
        $this->assertSame('Örnek Ticaret', $customer['params']['name']);
        $this->assertSame($tenant->id, $customer['params']['metadata']['tenant_id']);
        $this->assertStringContainsString('§ 19 UStG', $customer['params']['invoice_settings']['footer']);
    }
}
