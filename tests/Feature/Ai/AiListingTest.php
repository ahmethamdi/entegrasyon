<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Domain\Ai\Models\AiSuggestion;
use App\Domain\Ai\Models\AiUsage;
use App\Domain\Ai\Support\ChannelContentRules;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductChannelContent;
use App\Domain\Catalog\Models\Variant;
use App\Domain\Channels\Models\CategoryMapping;
use App\Domain\Channels\Models\ChannelCategory;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use App\Domain\Sync\Models\Listing;
use App\Domain\Sync\Support\ListingPayloadBuilder;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\PlanSeeder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Publishing\BuildsPublishingScenario;
use Tests\TestCase;

/**
 * S8 · AI ile listeleme — kapı, kural denetimi, liste dışı değer, kota,
 * onay yolu. Model `FakeLanguageModel`'dir; ağa çıkılmaz.
 */
final class AiListingTest extends TestCase
{
    use BuildsPublishingScenario;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPublishing();
    }

    private function enable(): void
    {
        config(['entegrasyon.ai.api_key' => 'test-anahtar', 'entegrasyon.ai.enabled' => true]);
    }

    /** @return array{0: Tenant, 1: ChannelConnection, 2: Product, 3: array<string, Variant>, 4: ChannelCategory} */
    private function scenario(): array
    {
        $this->enable();

        return $this->readyHb();
    }

    #[Test]
    public function without_a_key_the_feature_is_invisible_and_its_routes_404(): void
    {
        [, $connection, $product] = $this->readyHb();

        $this->actingAs($this->owner)->get("/products/{$product->id}/channels")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('ai', null));

        $this->actingAs($this->owner)->post("/products/{$product->id}/ai-suggestions", [
            'kind' => 'content', 'connection_id' => $connection->id,
        ])->assertNotFound();

        $this->assertSame([], $this->ai->calls, 'Anahtar yokken model çağrılmaz.');
    }

    #[Test]
    public function a_valid_content_suggestion_is_stored_pending_and_costed(): void
    {
        [, $connection, $product] = $this->scenario();
        $this->ai->reply(['title' => 'Kanonik Pamuklu Basic Tişört Kırmızı', 'description' => '<p>%100 pamuk.</p><script>x</script>']);

        $this->actingAs($this->owner)->post("/products/{$product->id}/ai-suggestions", [
            'kind' => 'content', 'connection_id' => $connection->id,
        ])->assertRedirect()->assertSessionHas('success');

        $s = $this->suggestions()->sole();
        $this->assertSame('pending', $s->status);
        $this->assertSame('hepsiburada', $s->channel_type_code);
        $this->assertSame('<p>%100 pamuk.</p>x', $s->payload['description'], 'İzinsiz etiket atılır.');

        $usage = $this->usage()->sole();
        $this->assertSame('ok', $usage->status);
        $this->assertSame($s->id, $usage->suggestion_id);
        // Haiku 5.5: 1200 × 0,10 + 300 × 0,50 = 270 mikro-dolar.
        $this->assertSame(270, (int) $usage->cost_micro_usd);
        $this->assertSame('claude-haiku-5-5', $this->ai->calls[0]['model']);

        // Kişisel veri yok: modele giden metin yalnız ürün verisi.
        $sent = json_encode($this->ai->calls[0]['content'], JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString($this->owner->email, (string) $sent);
    }

    #[Test]
    public function an_overlong_title_is_retried_once_and_never_truncated(): void
    {
        [, $connection, $product] = $this->scenario();
        $long = str_repeat('Uzun ', 60);
        $this->ai->reply(['title' => $long, 'description' => ''])->reply(['title' => $long.'daha', 'description' => '']);

        $this->actingAs($this->owner)->post("/products/{$product->id}/ai-suggestions", [
            'kind' => 'content', 'connection_id' => $connection->id,
        ])->assertSessionHas('warning');

        $this->assertCount(2, $this->ai->calls, 'İhlalde model bir kez yeniden istenir.');
        $this->assertStringContainsString('karakteri aşıyor', json_encode($this->ai->calls[1]['content'], JSON_UNESCAPED_UNICODE));

        $s = $this->suggestions()->sole();
        $this->assertSame('invalid', $s->status);
        $this->assertSame(trim($long.'daha'), $s->payload['title'], 'Başlık kırpılmaz.');
        $this->assertNotEmpty($s->warnings);

        // Satıcı düzeltmeden onaylayamaz: sunucu kuralı yeniden uygular.
        $this->actingAs($this->owner)->post("/products/{$product->id}/ai-suggestions/{$s->id}/apply", [])
            ->assertSessionHasErrors('title');
        $this->assertSame(0, $this->asSystem(fn () => ProductChannelContent::query()->count()));
    }

    #[Test]
    public function approving_content_writes_the_channel_text_and_only_that_channel_uses_it(): void
    {
        [$tenant, $connection, $product, $variants] = $this->scenario();
        $n11 = $this->n11Connection($tenant);
        $this->ai->reply(['title' => 'Öneri Başlığı Kırmızı Tişört', 'description' => '<p>Öneri</p>']);

        $this->actingAs($this->owner)->post("/products/{$product->id}/ai-suggestions", ['kind' => 'content', 'connection_id' => $connection->id]);
        $s = $this->suggestions()->sole();

        $this->actingAs($this->owner)->post("/products/{$product->id}/ai-suggestions/{$s->id}/apply", [
            'title' => 'Satıcının Düzelttiği Başlık', 'description' => '<p>Öneri</p>',
        ])->assertSessionHasNoErrors();

        $this->assertSame('applied', $s->fresh()->status);
        $this->assertSame(2, $product->fresh()->content_version, 'Senkron kapısı için sürüm artar.');

        $hb = $this->listingFor($tenant, $variants['TS-RED-S'], $connection);
        $other = $this->listingFor($tenant, $variants['TS-RED-S'], $n11);
        $builder = app(ListingPayloadBuilder::class);

        $this->assertSame('Satıcının Düzelttiği Başlık', TenantContext::runFor($tenant->id, fn () => $builder->build($hb, 2)->title));
        $this->assertSame('Kanonik Pamuklu Basic Tişört', TenantContext::runFor($tenant->id, fn () => $builder->build($other, 2)->title), 'Başka kanal kanonik metni alır.');
    }

    #[Test]
    public function off_list_attribute_values_are_never_kept(): void
    {
        [$tenant, $connection, $product, , $category] = $this->scenario();
        $this->channelCategory('hepsiburada', '18021982', [
            ['Malzeme', 'Malzeme', true, false, 'enum', [['id' => 'm1', 'label' => 'Pamuk'], ['id' => 'm2', 'label' => 'Polyester']]],
            ['Yaka', 'Yaka Tipi', false, false, 'enum', [['id' => 'y1', 'label' => 'Bisiklet']]],
        ]);
        DB::table('product_channel_attribute_values')->delete();

        $this->ai->reply(['attributes' => [
            ['attributeId' => 'Malzeme', 'valueId' => 'm1', 'customValue' => ''],
            ['attributeId' => 'Yaka', 'valueId' => 'UYDURMA', 'customValue' => ''],
            ['attributeId' => 'Yaka', 'valueId' => '', 'customValue' => 'V yaka'],
            ['attributeId' => 'renk_variant_property', 'valueId' => 'r1', 'customValue' => ''],
            ['attributeId' => 'Bilinmeyen', 'valueId' => 'x', 'customValue' => ''],
        ]], model: 'claude-sonnet-5-5');

        $this->actingAs($this->owner)->post("/products/{$product->id}/ai-suggestions", ['kind' => 'attributes', 'connection_id' => $connection->id])
            ->assertSessionHas('success');

        $s = $this->suggestions()->sole();
        $ids = array_column($s->payload['items'], 'attributeId');
        $this->assertSame(['Malzeme'], $ids, 'Liste dışı değer, liste-yalnız özniteliğe serbest metin, varyant belirleyici ve tanımsız öznitelik alınmaz.');
        $this->assertCount(2, $s->warnings);
        $this->assertSame('claude-sonnet-5-5', $this->ai->calls[0]['model']);
        $this->assertSame('image', $this->ai->calls[0]['content'][0]['type'], 'Öznitelikte görsel gider.');

        $this->actingAs($this->owner)->post("/products/{$product->id}/ai-suggestions/{$s->id}/apply", ['attributes' => ['Malzeme']])
            ->assertSessionHasNoErrors();

        $this->assertSame('m1', DB::table('product_channel_attribute_values')->where('external_attribute_id', 'Malzeme')->value('external_value_id'));
        $this->assertSame($category->id, DB::table('product_channel_attribute_values')->value('channel_category_id'));
        $this->assertNotNull($tenant);
    }

    #[Test]
    public function the_category_is_chosen_only_from_our_candidates(): void
    {
        $this->enable();
        $tenant = $this->tenant();
        $connection = $this->hbConnection($tenant);
        [$product] = $this->tshirt($tenant);
        $tisort = $this->hbCategory();
        // Eşleme yok → ekran "Kategori öner" der.

        $this->ai->reply(['categoryId' => 'baska-bir-sey', 'confidence' => 90, 'reason' => 'x'], model: 'claude-sonnet-5-5');
        $this->actingAs($this->owner)->post("/products/{$product->id}/ai-suggestions", ['kind' => 'category', 'connection_id' => $connection->id]);
        $this->assertSame('invalid', $this->suggestions()->sole()->status, 'Aday dışı kimlik alınmaz.');
        $this->assertStringContainsString($tisort->id, json_encode($this->ai->calls[0]['content'], JSON_UNESCAPED_UNICODE));

        $this->ai->reply(['categoryId' => $tisort->id, 'confidence' => 40, 'reason' => 'Tişört'], model: 'claude-sonnet-5-5');
        $this->actingAs($this->owner)->post("/products/{$product->id}/ai-suggestions", ['kind' => 'category', 'connection_id' => $connection->id]);
        $s = $this->suggestions()->latest('created_at')->latest('id')->first();
        $this->assertSame('pending', $s->status);
        $this->assertStringContainsString('Düşük güven', $s->warnings[0]);

        $this->actingAs($this->owner)->post("/products/{$product->id}/ai-suggestions/{$s->id}/apply")->assertSessionHasNoErrors();

        $mapping = $this->asSystem(fn () => CategoryMapping::query()->sole());
        $this->assertSame('ai', $mapping->mapped_by);
        $this->assertNotNull($mapping->verified_at, 'Satıcı onayı doğrulamadır.');
        $this->assertSame($tisort->id, $mapping->channel_category_id);
    }

    #[Test]
    public function without_candidates_the_model_is_not_called(): void
    {
        $this->enable();
        $tenant = $this->tenant();
        $connection = $this->hbConnection($tenant);
        [$product] = $this->tshirt($tenant);
        $this->channelCategory('hepsiburada', '1', []); // "Tişört" adlı tek yaprak
        TenantContext::runAsSystem(fn () => DB::table('channel_categories')->update(['name' => 'Bahçe Hortumu', 'path' => 'Bahçe > Hortum']));

        $this->actingAs($this->owner)->post("/products/{$product->id}/ai-suggestions", ['kind' => 'category', 'connection_id' => $connection->id]);

        $this->assertSame([], $this->ai->calls);
        $this->assertSame(0, $this->usage()->count(), 'Çağrı yoksa hak harcanmaz.');
        $this->assertSame('invalid', $this->suggestions()->sole()->status);
    }

    #[Test]
    public function the_monthly_quota_stops_calls_and_failed_calls_do_not_count(): void
    {
        [$tenant, $connection, $product] = $this->scenario();
        (new PlanSeeder)->run();

        // Yapılamayan çağrı kotaya sayılmaz.
        $this->ai->fail();
        $this->actingAs($this->owner)->post("/products/{$product->id}/ai-suggestions", ['kind' => 'content', 'connection_id' => $connection->id])
            ->assertSessionHasErrors('ai');
        $this->assertSame('error', $this->usage()->sole()->status);

        // Ücretsiz plan: ayda 20. 20 çağrı yapılmış gibi.
        TenantContext::runFor($tenant->id, function (): void {
            foreach (range(1, 20) as $i) {
                AiUsage::query()->create(['kind' => 'content', 'model' => 'claude-haiku-5-5', 'status' => 'ok']);
            }
        });

        $this->actingAs($this->owner)->post("/products/{$product->id}/ai-suggestions", ['kind' => 'content', 'connection_id' => $connection->id])
            ->assertSessionHasErrors('ai');

        $this->assertCount(1, $this->ai->calls, 'Kota doluyken model çağrılmaz.');
    }

    #[Test]
    public function warehouse_and_viewer_members_cannot_ask_or_apply(): void
    {
        [$tenant, $connection, $product] = $this->scenario();

        foreach (['warehouse', 'viewer'] as $role) {
            $user = User::factory()->create(['email_verified_at' => now()]);
            DB::table('tenant_users')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'user_id' => $user->id,
                'role' => $role, 'accepted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);

            $this->actingAs($user)->post("/products/{$product->id}/ai-suggestions", ['kind' => 'content', 'connection_id' => $connection->id])
                ->assertForbidden();
        }

        $this->assertSame([], $this->ai->calls);
    }

    #[Test]
    public function the_rules_table_is_honest_about_what_was_verified(): void
    {
        $this->assertSame(100, ChannelContentRules::summary('trendyol')['titleMax']);
        $this->assertTrue(ChannelContentRules::summary('trendyol')['titleVerified']);
        $this->assertFalse(ChannelContentRules::summary('hepsiburada')['titleVerified']);
        $this->assertSame(80, ChannelContentRules::summary('ebay')['titleMax']);

        $this->assertNotSame([], ChannelContentRules::violations('amazon', 'Harika Tişört!', null), 'Amazon ünlemi yasaklar.');
        $this->assertNotSame([], ChannelContentRules::violations('trendyol', 'Tişört', 'Bilgi için https://ornek.com'), 'Bağlantı 34Pazar kuralıyla yasak.');
        $this->assertNotSame([], ChannelContentRules::violations('n11', 'Kısa', null), 'n11 en az 15 karakter.');
        $this->assertSame([], ChannelContentRules::violations('trendyol', 'Kanonik Pamuklu Basic Tişört', '<p>%100 pamuk.</p>'));
    }

    private function suggestions(): Builder
    {
        return AiSuggestion::query()->withoutGlobalScopes();
    }

    private function usage(): Builder
    {
        return AiUsage::query()->withoutGlobalScopes();
    }

    private function listingFor(Tenant $tenant, Variant $variant, ChannelConnection $connection): Listing
    {
        return TenantContext::runFor($tenant->id, fn () => Listing::factory()->create([
            'variant_id' => $variant->id,
            'channel_connection_id' => $connection->id,
        ]));
    }
}
