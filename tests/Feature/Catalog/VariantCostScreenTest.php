<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Variant;
use App\Domain\Identity\Enums\TenantRole;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Finance\BuildsFinanceScenario;
use Tests\TestCase;

/**
 * Toplu maliyet girişi — liste, kayıt, kiracı yalıtımı, yetki.
 */
final class VariantCostScreenTest extends TestCase
{
    use BuildsFinanceScenario;
    use RefreshDatabase;

    #[Test]
    public function it_lists_variants_without_cost_by_default(): void
    {
        [$tenant] = $this->trendyolTenant();
        [$missing, $priced] = TenantContext::runFor($tenant->id, fn (): array => [
            Variant::factory()->create(['product_id' => Product::factory()->create()->id, 'cost_price' => null]),
            Variant::factory()->create(['product_id' => Product::factory()->create()->id, 'cost_price' => '12.00']),
        ]);

        $this->actingAs($this->owner)
            ->get('/products/costs')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Products/Costs')
                ->where('counts.missing', 1)
                ->where('counts.all', 2)
                ->has('variants.data', 1)
                ->where('variants.data.0.id', $missing->id));

        $this->actingAs($this->owner)
            ->get('/products/costs?filter=all')
            ->assertInertia(fn (AssertableInertia $page) => $page->has('variants.data', 2));
        $this->assertNotNull($priced);
    }

    #[Test]
    public function it_saves_changed_costs_and_clears_an_emptied_one(): void
    {
        [$tenant] = $this->trendyolTenant();
        [$a, $b] = TenantContext::runFor($tenant->id, fn (): array => [
            Variant::factory()->create(['product_id' => Product::factory()->create()->id, 'cost_price' => null]),
            Variant::factory()->create(['product_id' => Product::factory()->create()->id, 'cost_price' => '5.00']),
        ]);

        $this->actingAs($this->owner)
            ->from('/products/costs')
            ->post('/products/costs', ['costs' => [['id' => $a->id, 'cost' => '42.5'], ['id' => $b->id, 'cost' => null]]])
            ->assertRedirect('/products/costs')
            ->assertSessionHas('success', '2 ürünün alış maliyeti kaydedildi.');

        $this->assertSame('42.50', (string) DB::table('variants')->where('id', $a->id)->value('cost_price'));
        $this->assertNull(DB::table('variants')->where('id', $b->id)->value('cost_price'));
    }

    #[Test]
    public function it_rejects_a_negative_cost_and_ignores_other_tenants_variants(): void
    {
        [$tenantA] = $this->trendyolTenant();
        $foreign = TenantContext::runFor($tenantA->id, fn () => Variant::factory()->create(['product_id' => Product::factory()->create()->id, 'cost_price' => '1.00']));

        $this->trendyolTenant(seller: '777');

        $this->actingAs($this->owner)
            ->post('/products/costs', ['costs' => [['id' => $foreign->id, 'cost' => '-1']]])
            ->assertSessionHasErrors('costs.0.cost');

        $this->actingAs($this->owner)
            ->post('/products/costs', ['costs' => [['id' => $foreign->id, 'cost' => '99']]])
            ->assertSessionHas('success', 'Değişiklik yok.');

        $this->assertSame('1.00', (string) DB::table('variants')->where('id', $foreign->id)->value('cost_price'), 'Başka kiracının maliyeti değişmez.');
    }

    #[Test]
    public function a_read_only_role_can_see_but_not_save(): void
    {
        [$tenant] = $this->trendyolTenant();
        DB::table('tenant_users')->where('tenant_id', $tenant->id)->update(['role' => TenantRole::VIEWER->value]);
        $variant = TenantContext::runFor($tenant->id, fn () => Variant::factory()->create(['product_id' => Product::factory()->create()->id]));

        $this->actingAs($this->owner)->get('/products/costs')->assertOk();
        $this->actingAs($this->owner)
            ->post('/products/costs', ['costs' => [['id' => $variant->id, 'cost' => '3']]])
            ->assertForbidden();
    }
}
