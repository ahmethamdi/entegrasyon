<?php

declare(strict_types=1);

namespace Tests;

use App\Domain\Billing\Contracts\PaymentGateway;
use App\Domain\Channels\Support\OutboundUrlGuard;
use App\Domain\Identity\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use Tests\Support\Billing\FakePaymentGateway;

abstract class TestCase extends BaseTestCase
{
    protected FakePaymentGateway $payments;

    protected function setUp(): void
    {
        parent::setUp();

        // Her test temiz bağlamla başlar. TenantContext statik olduğu için
        // testler arası sızıntı da bir risktir.
        TenantContext::clear();

        // Symfony'nin test isteği varsayılan olarak `Accept-Language: en-us`
        // taşır; panel dili tarayıcıdan türediği için (SetLocale) her test
        // sessizce İngilizce panel görürdü. Varsayılan Türk satıcının
        // tarayıcısı; İngilizce senaryo testi başlığı kendisi verir.
        $this->withHeader('Accept-Language', 'tr-TR,tr;q=0.9');

        // ⚠️ TEST GERÇEK KANALA İSTEK ATAMAZ (A11 ④b'de bulundu).
        //
        // Sahte yanıt tanımı bir uç noktayı kapsamayınca istek SESSİZCE
        // gerçek `apigw.trendyol.com`'a gitmiş ve 401 almıştı. Hem dışarıya
        // anahtar sızdırma riski hem de ağa bağlı, kırılgan test demekti.
        // Eşleşmeyen istek artık testi düşürür.
        Http::preventStrayRequests();

        // Aynı gerekçe, ödeme tarafı: Stripe SDK'sı curl kullanır ve
        // yukarıdaki kural onu YAKALAMAZ. Test ödeme sağlayıcısına çıkmaz.
        $this->app->instance(PaymentGateway::class, $this->payments = new FakePaymentGateway);

        // SSRF koruması istek anında DNS çözer; test ağa ÇIKMAZ. Her ad
        // genel bir adrese çözülür — iç ağ senaryosunu sınayan test kendi
        // çözümleyicisini bağlar.
        $this->app->instance(OutboundUrlGuard::class, new OutboundUrlGuard(static fn (): array => ['93.184.216.34']));
    }

    protected function tearDown(): void
    {
        TenantContext::clear();

        parent::tearDown();
    }

    /**
     * Kiracı bağlamı kurmadan model yaratmak için sistem bağlamı yardımcısı.
     *
     * @template TReturn
     *
     * @param  \Closure(): TReturn  $callback
     * @return TReturn
     */
    protected function asSystem(\Closure $callback): mixed
    {
        return TenantContext::runAsSystem($callback);
    }

    /** Belirli kiracı bağlamında çalıştırır. */
    protected function asTenant(Tenant|string $tenant, \Closure $callback): mixed
    {
        $id = $tenant instanceof Tenant ? $tenant->id : $tenant;

        return TenantContext::runFor($id, $callback);
    }
}
