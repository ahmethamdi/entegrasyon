<?php

declare(strict_types=1);

namespace Tests\Feature\Publishing;

use App\Domain\Channels\Adapters\N11\Catalog\N11Catalog;
use App\Domain\Sync\Actions\TrackApprovalStatus;
use App\Domain\Sync\Enums\PublishReason;
use App\Domain\Sync\Models\Listing;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * S3 · N11 `product-create` — kanonik ürün + varyant → SKU öğeleri
 * (`productMainId` grubu), marka özelliği (id 1), onay takibi.
 */
final class N11PublishingTest extends TestCase
{
    use BuildsPublishingScenario;
    use RefreshDatabase;

    /** stockCode → product-query satırı. */
    private array $products = [];

    /** @var list<array<string, mixed>> */
    private array $created = [];

    /** @var list<array<string, mixed>> */
    private array $updated = [];

    private array $createAnswers = [];

    /** taskId → task-details gövdesi. */
    private array $tasks = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPublishing();

        Http::fake(function (Request $request) {
            $url = $request->url();

            if (str_contains($url, '/ms/product/tasks/product-create')) {
                foreach (json_decode($request->body(), true)['payload']['skus'] ?? [] as $sku) {
                    $this->created[] = ['raw' => $request->body(), ...$sku];
                }

                $answer = array_shift($this->createAnswers);

                return ($answer instanceof \Closure ? $answer() : $answer)
                    ?? Http::response(['id' => 1000 + count($this->created), 'type' => 'PRODUCT_CREATE', 'status' => 'IN_QUEUE', 'reasons' => ['1 sku işlenmeye alındı.']]);
            }

            if (str_contains($url, '/ms/product/tasks/product-update')) {
                $this->updated[] = json_decode($request->body(), true)['payload']['skus'][0];

                return Http::response(['id' => 2017, 'type' => 'PRODUCT_UPDATE', 'status' => 'IN_QUEUE', 'reasons' => []]);
            }

            if (str_contains($url, '/ms/product-query')) {
                parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
                $row = $this->products[$q['stockCode'] ?? ''] ?? null;

                return Http::response(['content' => $row === null ? [] : [$row], 'totalElements' => $row === null ? 0 : 1]);
            }

            if (str_contains($url, '/ms/product/task-details/page-query')) {
                return Http::response($this->tasks[(string) ($request->data()['taskId'] ?? '')] ?? ['status' => 'IN_QUEUE']);
            }

            return Http::response(['message' => 'beklenmeyen '.$url], 599);
        });
    }

    #[Test]
    public function each_variant_becomes_a_sku_in_one_product_main_id_group_with_its_own_values(): void
    {
        [$tenant, $connection, $product, $variants] = $this->readyN11();

        foreach ($this->publish($tenant, $product, $connection) as $op) {
            $this->runPush($tenant, $op)->assertNotReleased();
        }

        $this->assertCount(3, $this->created);
        $bySku = collect($this->created)->keyBy('stockCode');

        $colors = ['Kırmızı' => 8773717, 'Mavi' => 2523534];
        $sizes = ['S' => 7001, 'M' => 7002];

        foreach (self::VARIANTS as $sku => [$color, $size, $price, $stock, $barcode]) {
            $item = $bySku[$sku];
            $this->assertSame('TS-1', $item['productMainId']);
            $this->assertSame((float) $price, (float) $item['salePrice'], "{$sku} kendi fiyatı");
            $this->assertSame($stock, $item['quantity'], "{$sku} kendi stoğu");
            $this->assertSame($barcode, $item['barcode']);
            $this->assertSame('TL', $item['currencyType']);
            $this->assertSame('STANDART', $item['shipmentTemplate']);
            $this->assertSame(3, $item['preparingDay']);
            $this->assertSame(20, $item['vatRate']);
            $this->assertSame([['url' => "https://cdn.example.com/{$sku}.jpg", 'order' => 1], ['url' => 'https://cdn.example.com/ortak.jpg', 'order' => 2]], $item['images']);

            $attrs = collect($item['attributes'])->keyBy('id');
            $this->assertSame(['id' => 1, 'valueId' => 8372688, 'customValue' => null], $attrs[1], 'Marka listede birebir → valueId.');
            $this->assertSame(9001, $attrs[500]['valueId'], 'Betimleyici ürün düzeyinden.');
            $this->assertSame($colors[$color], $attrs[429]['valueId'], "{$sku} kendi rengi");
            $this->assertSame($sizes[$size], $attrs[430]['valueId'], "{$sku} kendi bedeni");

            $listing = $this->listing($tenant, $variants[$sku], $connection);
            $this->assertSame($sku, $listing->external_id);
            $this->assertSame('pending_approval', $listing->lifecycle_status);
        }

        $this->assertMatchesRegularExpression('/"salePrice":100\.00/', $this->created[0]['raw'], 'Fiyat iki ondalıklı SAYI.');
        $this->assertSame(0, DB::table('inventory_movements')->count());
    }

    #[Test]
    public function n11_specific_rules_come_from_the_channel_not_global_constants(): void
    {
        [$tenant, $connection, $product, $variants] = $this->readyN11();
        TenantContext::runFor($tenant->id, fn () => $product->forceFill(['title' => 'Kısa Başlık'])->save());
        TenantContext::runFor($tenant->id, fn () => $connection->forceFill(['settings' => [...$connection->settings, 'shipment_template' => '']])->save());

        $this->assertSame([], $this->publish($tenant, $product, $connection));

        $state = $this->contentState($tenant, $this->listing($tenant, $variants['TS-RED-S'], $connection));
        $this->assertSame(PublishReason::TITLE_INVALID->value, $state->reason_code, 'N11 başlığı en az 15 karakter.');
        $this->assertStringContainsString('shipment_template', (string) $state->last_error);
        Http::assertNothingSent();
    }

    #[Test]
    public function a_brand_missing_from_a_closed_list_blocks_instead_of_guessing(): void
    {
        [$tenant, $connection, $product, $variants] = $this->readyN11();
        TenantContext::runAsSystem(fn () => DB::table('channel_category_attributes')->where('external_attribute_id', '1')->update(['data_type' => 'enum']));
        TenantContext::runFor($tenant->id, fn () => $product->forceFill(['brand' => 'Kanonik Moda'])->save());

        $this->publish($tenant, $product, $connection);

        $this->assertSame(PublishReason::BRAND_MAPPING_REQUIRED->value, $this->contentState($tenant, $this->listing($tenant, $variants['TS-RED-S'], $connection))->reason_code);
        Http::assertNothingSent();
    }

    #[Test]
    public function a_rejected_task_is_permanent_and_an_ambiguous_one_is_not_resent(): void
    {
        [$tenant, $connection, $product, $variants] = $this->readyN11();
        $this->createAnswers = [
            Http::response(['id' => 9, 'type' => 'PRODUCT_CREATE', 'status' => 'REJECT', 'reasons' => ['stockCode çakışması']]),
            fn () => Http::failedConnection(),
        ];
        [$first, $second] = $this->publish($tenant, $product, $connection);

        $this->runPush($tenant, $first)->assertNotReleased();
        $rejected = $this->listing($tenant, $variants['TS-RED-S'], $connection);
        $this->assertNull($rejected->create_requested_at, 'REJECT: istek işlenmedi.');
        $this->assertSame('error_permanent', $this->contentState($tenant, $rejected)->status);

        $this->runPush($tenant, $second)->assertReleased();
        $this->runPush($tenant, $second)->assertReleased();
        $this->assertCount(2, $this->created, 'Belirsiz görev tekrar gönderilmedi.');

        $this->products['TS-RED-M'] = ['stockCode' => 'TS-RED-M', 'productMainId' => 'TS-1', 'status' => 'InCatalogApproval'];
        $this->runPush($tenant, $second)->assertNotReleased();
        $this->assertSame('TS-RED-M', $this->listing($tenant, $variants['TS-RED-M'], $connection)->external_id);
        $this->assertCount(2, $this->created);
    }

    #[Test]
    public function approval_follows_n11_statuses_and_task_failures(): void
    {
        [$tenant, $connection, $product, $variants] = $this->readyN11();
        foreach ($this->publish($tenant, $product, $connection) as $op) {
            $this->runPush($tenant, $op);
        }

        $this->products = [
            'TS-RED-S' => ['stockCode' => 'TS-RED-S', 'status' => 'Active', 'n11ProductId' => 555],
            'TS-RED-M' => ['stockCode' => 'TS-RED-M', 'status' => 'CatalogRejected', 'rejectInfo' => 'Yasaklı kelime'],
        ];
        $taskOfBlue = $this->listing($tenant, $variants['TS-BLU-S'], $connection)->channel_metadata['n11_task_ids'][0];
        $this->tasks[$taskOfBlue] = ['status' => 'PROCESSED', 'skus' => ['content' => [['itemCode' => 'TS-BLU-S', 'status' => 'FAIL', 'reasons' => ['Fahiş fiyat']]], 'totalPages' => 1]];

        TenantContext::runFor($tenant->id, fn () => app(TrackApprovalStatus::class)->run($connection));

        $this->assertSame('live', $this->listing($tenant, $variants['TS-RED-S'], $connection)->lifecycle_status);
        $this->assertSame('555', $this->listing($tenant, $variants['TS-RED-S'], $connection)->channel_metadata['n11_product_id']);
        $this->assertSame('Yasaklı kelime', $this->listing($tenant, $variants['TS-RED-M'], $connection)->approval_rejection_reason);
        $blue = $this->listing($tenant, $variants['TS-BLU-S'], $connection);
        $this->assertSame('rejected', $blue->lifecycle_status);
        $this->assertSame('Fahiş fiyat', $blue->approval_rejection_reason);
    }

    #[Test]
    public function only_a_live_product_is_updated_and_without_n11_managed_fields(): void
    {
        [$tenant, $connection, $product, $variants] = $this->readyN11();
        foreach ($this->publish($tenant, $product, $connection) as $op) {
            $this->runPush($tenant, $op);
        }
        TenantContext::runFor($tenant->id, fn () => Listing::query()->where('variant_id', $variants['TS-RED-S']->id)->update(['lifecycle_status' => 'live']));
        TenantContext::runFor($tenant->id, fn () => $product->forceFill(['content_version' => 2, 'description' => '<p>Yeni</p>'])->save());

        foreach ($this->publish($tenant, $product, $connection) as $op) {
            $this->runPush($tenant, $op);
        }

        $this->assertCount(1, $this->updated);
        $this->assertSame('TS-RED-S', $this->updated[0]['stockCode']);
        $this->assertSame('<p>Yeni</p>', $this->updated[0]['description']);
        foreach (['title', 'images', 'quantity', 'salePrice'] as $field) {
            $this->assertArrayNotHasKey($field, $this->updated[0], "{$field} N11'de yönetilir.");
        }
        $this->assertSame(PublishReason::LISTING_UPDATE_UNSUPPORTED->value, $this->contentState($tenant, $this->listing($tenant, $variants['TS-RED-M'], $connection))->reason_code, 'Onay bekleyen güncellenemez.');
    }

    #[Test]
    public function the_taxonomy_is_parsed_from_the_documented_shapes(): void
    {
        $tree = N11Catalog::tree(['categories' => [['id' => 1000001, 'parentId' => null, 'name' => 'Banyo', 'subCategories' => [['id' => 1000002, 'parentId' => 1000001, 'name' => 'Setler', 'subCategories' => null]]]]]);

        $this->assertSame([
            ['external_id' => '1000001', 'parent_external_id' => null, 'name' => 'Banyo', 'path' => 'Banyo', 'is_leaf' => false],
            ['external_id' => '1000002', 'parent_external_id' => '1000001', 'name' => 'Setler', 'path' => 'Banyo > Setler', 'is_leaf' => true],
        ], $tree->categories);

        $attrs = N11Catalog::attributes(['categoryAttributes' => [
            ['attributeId' => 1, 'attributeName' => 'Marka', 'isMandatory' => true, 'isVariant' => false, 'isCustomValue' => true, 'attributeValues' => [['id' => 8372688, 'value' => 'Abay']]],
            ['attributeId' => 429, 'attributeName' => 'Renk', 'isMandatory' => true, 'isVariant' => true, 'isCustomValue' => false, 'attributeValues' => []],
        ]]);

        $this->assertSame(['1', 'Marka', true, false, 'string'], [$attrs[0]['external_attribute_id'], $attrs[0]['name'], $attrs[0]['is_required'], $attrs[0]['is_variant_defining'], $attrs[0]['data_type']]);
        $this->assertSame([['id' => '8372688', 'label' => 'Abay']], $attrs[0]['allowed_values']);
        $this->assertTrue($attrs[1]['is_variant_defining']);
        $this->assertSame('enum', $attrs[1]['data_type']);
    }
}
