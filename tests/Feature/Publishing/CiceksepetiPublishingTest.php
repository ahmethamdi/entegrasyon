<?php

declare(strict_types=1);

namespace Tests\Feature\Publishing;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Variant;
use App\Domain\Channels\Adapters\Ciceksepeti\Catalog\CiceksepetiCatalog;
use App\Domain\Channels\Models\ChannelCategory;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Sync\Actions\TrackApprovalStatus;
use App\Domain\Sync\Enums\PublishReason;
use App\Domain\Sync\Models\Listing;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * S10 · Çiçeksepeti `POST/PUT /Products` — kanonik ürün + varyant →
 * `stockCode` kimlikli öğeler (`mainProductCode` = ürün SKU'su), öznitelik
 * yalnız kimlikle, teslimat ayarları satıcıdan, onay `productStatusType` +
 * ürün işi kalem hatası.
 */
final class CiceksepetiPublishingTest extends TestCase
{
    use BuildsPublishingScenario;
    use RefreshDatabase;

    private const BASE = 'https://apis.ciceksepeti.com/api/v1';

    /** @var list<array<string, mixed>> */
    private array $created = [];

    /** @var list<array<string, mixed>> */
    private array $updated = [];

    private array $createAnswers = [];

    /** stockCode → ürün listesi satırı */
    private array $products = [];

    /** batchId → batch-status gövdesi */
    private array $batches = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPublishing();
        Cache::flush();
        Sleep::fake(syncWithCarbon: true);

        Http::fake(function (Request $request) {
            $url = $request->url();
            parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
            $path = (string) parse_url($url, PHP_URL_PATH);

            if (str_ends_with($path, '/Products') && in_array($request->method(), ['POST', 'PUT'], true)) {
                $items = json_decode($request->body(), true)['products'] ?? [];

                if ($request->method() === 'PUT') {
                    array_push($this->updated, ...$items);

                    return Http::response(['batchId' => 'upd-'.count($this->updated)]);
                }

                array_push($this->created, ...$items);
                $answer = array_shift($this->createAnswers);

                return $answer ?? Http::response(['batchId' => 'batch-'.count($this->created)]);
            }

            if (str_contains($path, '/Products/batch-status/')) {
                return Http::response($this->batches[basename($path)] ?? ['batchId' => basename($path), 'items' => [['data' => [], 'status' => 'Processing']]]);
            }

            if (str_ends_with($path, '/Products') && $request->method() === 'GET') {
                $row = $this->products[$q['StockCode'] ?? ''] ?? null;

                return Http::response(['totalCount' => $row === null ? 0 : 1, 'products' => $row === null ? [] : [$row]]);
            }

            return Http::response(['message' => 'beklenmeyen '.$url], 599);
        });
    }

    #[Test]
    public function each_variant_is_created_with_its_stock_code_group_and_attribute_ids(): void
    {
        [$tenant, $connection, $product, $variants] = $this->readyCiceksepeti();

        foreach ($this->publish($tenant, $product, $connection) as $op) {
            $this->runPush($tenant, $op)->assertNotReleased();
        }

        $this->assertCount(3, $this->created);
        $bySku = collect($this->created)->keyBy('stockCode');

        foreach (self::VARIANTS as $sku => [$color, $size, $price, $stock, $barcode]) {
            $item = $bySku[$sku];
            $this->assertSame('TS-1', $item['mainProductCode']);
            $this->assertSame(9001, $item['categoryId']);
            $this->assertSame((float) $price, (float) $item['salesPrice'], "{$sku} kendi fiyatı");
            $this->assertSame($stock, $item['stockQuantity'], "{$sku} kendi stoğu");
            $this->assertSame($barcode, $item['barcode']);
            $this->assertSame(2, $item['deliveryType']);
            $this->assertSame(5, $item['deliveryMessageType']);
            $this->assertArrayNotHasKey('isActive', $item, 'isActive yalnız güncellemede.');
            $this->assertArrayNotHasKey('listPrice', $item, 'İndirim yoksa liste fiyatı gönderilmez (%1–%80 kuralı).');
            $this->assertSame(["https://cdn.example.com/{$sku}.jpg", 'https://cdn.example.com/ortak.jpg'], $item['images']);

            $attrs = collect($item['Attributes'])->keyBy('Id');
            $this->assertSame(301, $attrs[30]['ValueId'], 'Betimleyici ürün düzeyinden.');
            $this->assertSame(['Kırmızı' => 101, 'Mavi' => 102][$color], $attrs[10]['ValueId']);
            $this->assertSame(['S' => 201, 'M' => 202][$size], $attrs[20]['ValueId']);

            $listing = $this->listing($tenant, $variants[$sku], $connection);
            $this->assertSame($sku, $listing->external_id);
            $this->assertSame('pending_approval', $listing->lifecycle_status);
        }

        $this->assertSame(0, DB::table('inventory_movements')->count());
    }

    /** Teslim süresi satıcının sözüdür — VARSAYILAN KONMAZ; eksikse istek atılmaz. */
    #[Test]
    public function missing_delivery_settings_block_without_a_request(): void
    {
        [$tenant, $connection, $product, $variants] = $this->readyCiceksepeti();
        TenantContext::runFor($tenant->id, fn () => $connection->forceFill(['settings' => [...$connection->settings, CiceksepetiCatalog::SETTING_DELIVERY_MESSAGE_TYPE => null]])->save());

        $this->assertSame([], $this->publish($tenant, $product, $connection));

        $state = $this->contentState($tenant, $this->listing($tenant, $variants['TS-RED-S'], $connection));
        $this->assertSame(PublishReason::CHANNEL_SETTING_REQUIRED->value, $state->reason_code);
        $this->assertStringContainsString(CiceksepetiCatalog::SETTING_DELIVERY_MESSAGE_TYPE, (string) $state->last_error);
        Http::assertNothingSent();
    }

    /** Açıklama sınırı aşılırsa KIRPILMAZ, gönderilmez. */
    #[Test]
    public function a_too_long_description_is_not_truncated(): void
    {
        [$tenant, $connection, $product, $variants] = $this->readyCiceksepeti();
        TenantContext::runFor($tenant->id, fn () => $product->forceFill(['description' => '<p>'.str_repeat('a', CiceksepetiCatalog::MAX_DESCRIPTION_LENGTH).'</p>'])->save());

        foreach ($this->publish($tenant, $product, $connection) as $op) {
            $this->runPush($tenant, $op);
        }

        $this->assertSame([], $this->created);
        $this->assertSame(PublishReason::DESCRIPTION_REQUIRED->value, $this->contentState($tenant, $this->listing($tenant, $variants['TS-RED-S'], $connection))->reason_code);
    }

    /** 400 kesin red; güncelleme yalnız yayındaki ürüne, `isActive` ile. */
    #[Test]
    public function a_rejected_create_is_permanent_and_only_live_listings_are_updated(): void
    {
        [$tenant, $connection, $product, $variants] = $this->readyCiceksepeti();
        $this->createAnswers = [Http::response(['message' => 'Kategori hatalı'], 400)];

        $ops = $this->publish($tenant, $product, $connection);
        $this->runPush($tenant, $ops[0])->assertNotReleased();

        $rejected = collect($variants)
            ->map(fn ($variant) => $this->contentState($tenant, $this->listing($tenant, $variant, $connection)))
            ->filter(fn ($state) => $state?->status === 'error_permanent')
            ->values();
        $this->assertCount(1, $rejected);
        $this->assertStringContainsString('Kategori hatalı', (string) $rejected[0]->last_error);

        foreach (array_slice($ops, 1) as $op) {
            $this->runPush($tenant, $op);
        }

        TenantContext::runFor($tenant->id, fn () => Listing::query()->where('variant_id', $variants['TS-BLU-S']->id)->update(['lifecycle_status' => 'live']));
        TenantContext::runFor($tenant->id, fn () => $product->forceFill(['content_version' => 2, 'description' => '<p>Yeni</p>'])->save());

        foreach ($this->publish($tenant, $product, $connection) as $op) {
            $this->runPush($tenant, $op);
        }

        $this->assertCount(1, $this->updated);
        $this->assertSame('TS-BLU-S', $this->updated[0]['stockCode']);
        $this->assertTrue($this->updated[0]['isActive']);
        $this->assertSame('<p>Yeni</p>', $this->updated[0]['description']);
    }

    #[Test]
    public function approval_reads_product_status_and_batch_failures(): void
    {
        [$tenant, $connection, $product, $variants] = $this->readyCiceksepeti();

        foreach ($this->publish($tenant, $product, $connection) as $op) {
            $this->runPush($tenant, $op);
        }

        $this->products = [
            'TS-RED-S' => ['stockCode' => 'TS-RED-S', 'productStatusType' => 3, 'productCode' => 'kc1234'],
            'TS-RED-M' => ['stockCode' => 'TS-RED-M', 'productStatusType' => 'Reddedildi', 'passiveDescription' => 'Görsel çözünürlüğü düşük'],
        ];
        $blueBatch = $this->listing($tenant, $variants['TS-BLU-S'], $connection)->channel_metadata['ciceksepeti_product_batch_ids'][0];
        $this->batches[$blueBatch] = ['batchId' => $blueBatch, 'items' => [
            ['data' => ['stockCode' => 'TS-BLU-S'], 'status' => 'Failed', 'failureReasons' => [['message' => 'Zorunlu özellik eksik', 'code' => 4012]]],
        ]];

        TenantContext::runFor($tenant->id, fn () => app(TrackApprovalStatus::class)->run($connection));

        $red = $this->listing($tenant, $variants['TS-RED-S'], $connection);
        $this->assertSame('live', $red->lifecycle_status);
        $this->assertSame('kc1234', $red->channel_metadata['ciceksepeti_product_code']);
        $this->assertSame('Görsel çözünürlüğü düşük', $this->listing($tenant, $variants['TS-RED-M'], $connection)->approval_rejection_reason);
        $blue = $this->listing($tenant, $variants['TS-BLU-S'], $connection);
        $this->assertSame('rejected', $blue->lifecycle_status);
        $this->assertStringContainsString('Zorunlu özellik eksik', (string) $blue->approval_rejection_reason);
    }

    #[Test]
    public function the_taxonomy_is_parsed_and_personalizable_attributes_are_skipped(): void
    {
        $tree = CiceksepetiCatalog::tree(['categories' => [
            ['id' => 1, 'name' => 'Giyim', 'parentCategoryId' => null, 'subCategories' => [['id' => 9001, 'name' => 'Tişört', 'parentCategoryId' => 1, 'subCategories' => []]]],
        ]]);

        $this->assertSame([
            ['external_id' => '1', 'parent_external_id' => null, 'name' => 'Giyim', 'path' => 'Giyim', 'is_leaf' => false],
            ['external_id' => '9001', 'parent_external_id' => '1', 'name' => 'Tişört', 'path' => 'Giyim > Tişört', 'is_leaf' => true],
        ], $tree->categories);

        $attrs = CiceksepetiCatalog::attributes(['categoryAttributes' => [
            ['attributeId' => 10, 'attributeName' => 'Renk', 'required' => true, 'varianter' => true, 'type' => 'Variant Özelliği', 'attributeValues' => [['id' => 101, 'name' => 'Kırmızı']]],
            ['attributeId' => 99, 'attributeName' => 'İsim Yazısı', 'required' => false, 'varianter' => false, 'type' => 'Kişiselleştirilebilir Özellik', 'attributeValues' => []],
        ]]);

        $this->assertCount(1, $attrs);
        $this->assertSame(['10', 'Renk', true, true, 'enum'], [$attrs[0]['external_attribute_id'], $attrs[0]['name'], $attrs[0]['is_required'], $attrs[0]['is_variant_defining'], $attrs[0]['data_type']]);
        $this->assertSame([['id' => '101', 'label' => 'Kırmızı']], $attrs[0]['allowed_values']);
    }

    /** @return array{0: Tenant, 1: ChannelConnection, 2: Product, 3: array<string, Variant>, 4: ChannelCategory} */
    private function readyCiceksepeti(): array
    {
        $tenant = $this->tenant();
        $connection = $this->ciceksepetiConnection($tenant);
        [$product, $variants] = $this->tshirt($tenant);
        $category = $this->channelCategory('ciceksepeti', '9001', [
            ['30', 'Materyal', true, false, 'enum', [['id' => '301', 'label' => 'Pamuk']]],
            ['10', 'Renk', true, true, 'enum', [['id' => '101', 'label' => 'Kırmızı'], ['id' => '102', 'label' => 'Mavi']]],
            ['20', 'Beden', true, true, 'enum', [['id' => '201', 'label' => 'S'], ['id' => '202', 'label' => 'M']]],
        ]);
        $this->mapCategory($tenant, $category, '10', '20', ['Renk:Kırmızı' => '101', 'Renk:Mavi' => '102', 'Beden:S' => '201', 'Beden:M' => '202']);
        $this->productValue($tenant, $product, $category, '30', '301', null);

        return [$tenant, $connection, $product, $variants, $category];
    }
}
