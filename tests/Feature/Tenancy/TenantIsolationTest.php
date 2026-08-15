<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Domain\Catalog\Models\Product;
use App\Domain\Identity\Models\Tenant;
use App\Support\Tenancy\Exceptions\MissingTenantContextException;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Test A ve B — kiracı izolasyonu ve fail-closed davranış.
 *
 * Mimari Karar Dokümanı v2.2 · §11 · Karar 28.
 */
final class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function tenant_a_cannot_see_products_of_tenant_b(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        $this->asTenant($tenantA, function () {
            Product::factory()->count(3)->create();
        });

        $this->asTenant($tenantB, function () {
            Product::factory()->count(2)->create();
        });

        $seenByA = $this->asTenant($tenantA, fn () => Product::pluck('tenant_id')->unique()->all());
        $seenByB = $this->asTenant($tenantB, fn () => Product::pluck('tenant_id')->unique()->all());

        $this->assertSame([$tenantA->id], array_values($seenByA));
        $this->assertSame([$tenantB->id], array_values($seenByB));

        $this->assertSame(3, $this->asTenant($tenantA, fn () => Product::count()));
        $this->assertSame(2, $this->asTenant($tenantB, fn () => Product::count()));
    }

    #[Test]
    public function query_without_tenant_context_throws_instead_of_leaking(): void
    {
        $tenant = Tenant::factory()->create();

        $this->asTenant($tenant, function () {
            Product::factory()->count(2)->create();
        });

        // Bağlam yok: sessizce tüm kayıtları döndürmek YERİNE istisna.
        TenantContext::clear();

        $this->expectException(MissingTenantContextException::class);

        Product::count();
    }

    #[Test]
    public function write_without_tenant_context_throws(): void
    {
        TenantContext::clear();

        $this->expectException(MissingTenantContextException::class);

        // tenant_id verilmeden yazma denemesi.
        Product::create([
            'sku' => 'SKU-NO-CONTEXT',
            'title' => 'Bağlamsız ürün',
            'status' => 'active',
        ]);
    }

    #[Test]
    public function system_context_bypasses_scope_explicitly(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        $this->asTenant($tenantA, fn () => Product::factory()->count(3)->create());
        $this->asTenant($tenantB, fn () => Product::factory()->count(2)->create());

        // Bilinçli sistem erişimi: relay, mutabakat, bakım işleri bunu kullanır.
        $total = $this->asSystem(fn () => Product::count());

        $this->assertSame(5, $total);
    }

    #[Test]
    public function system_context_is_restored_after_exception(): void
    {
        $this->assertFalse(TenantContext::isSystemContext());

        try {
            TenantContext::runAsSystem(function (): void {
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
            // yutuldu
        }

        // Sayaç geri alınmalı; aksi halde sonraki sorgular sessizce
        // sistem bağlamında çalışır ve izolasyon kapanır.
        $this->assertFalse(TenantContext::isSystemContext());
    }
}
