<?php

declare(strict_types=1);

namespace Tests;

use App\Domain\Identity\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Her test temiz bağlamla başlar. TenantContext statik olduğu için
        // testler arası sızıntı da bir risktir.
        TenantContext::clear();

        // ⚠️ TEST GERÇEK KANALA İSTEK ATAMAZ (A11 ④b'de bulundu).
        //
        // Sahte yanıt tanımı bir uç noktayı kapsamayınca istek SESSİZCE
        // gerçek `apigw.trendyol.com`'a gitmiş ve 401 almıştı. Hem dışarıya
        // anahtar sızdırma riski hem de ağa bağlı, kırılgan test demekti.
        // Eşleşmeyen istek artık testi düşürür.
        Http::preventStrayRequests();
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
