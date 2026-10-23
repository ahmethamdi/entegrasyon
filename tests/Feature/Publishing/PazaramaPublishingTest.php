<?php

declare(strict_types=1);

namespace Tests\Feature\Publishing;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Variant;
use App\Domain\Channels\Adapters\Pazarama\Catalog\PazaramaCatalog;
use App\Domain\Channels\Models\ChannelCategory;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Sync\Actions\TrackApprovalStatus;
use App\Domain\Sync\Enums\PublishReason;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * S10 · Pazarama `product/create` — kanonik ürün + varyant → barkod
 * kimlikli öğeler (`GroupCode` = ürün SKU'su), marka kimliği kanaldan, öznitelik
 * YALNIZ kimlikle, onay takibi (onaylı/onaysız liste + ürün işi hatası).
 */
final class PazaramaPublishingTest extends TestCase
{
    use BuildsPublishingScenario;
    use RefreshDatabase;

    /** @var list<array<string, mixed>> */
    private array $created = [];

    private array $createAnswers = [];

    /** barkod → onaylı satır */
    private array $approved = [];

    /** barkod → onaysız satır */
    private array $unapproved = [];

    /** batchId → getProductBatchResult gövdesi */
    private array $batches = [];

    /** @var list<array<string, mixed>> */
    private array $brands = [['id' => 'brand-guid-1', 'name' => 'Kanonik']];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPublishing();
        Cache::flush();

        Http::fake(function (Request $request) {
            $url = $request->url();
            parse_str((string) parse_url($url, PHP_URL_QUERY), $q);

            if (str_contains($url, '/product/create')) {
                foreach (json_decode($request->body(), true)['products'] ?? [] as $item) {
                    $this->created[] = $item;
                }

                $answer = array_shift($this->createAnswers);

                return $answer ?? Http::response(['data' => ['batchRequestId' => 'batch-'.count($this->created), 'error' => ['errors' => []]], 'success' => true, 'userMessage' => 'Ürün ekleme isteği iletildi.']);
            }

            if (str_contains($url, '/brand/getBrands')) {
                return Http::response(['data' => $this->brands, 'success' => true]);
            }

            if (str_contains($url, '/product/products/approved')) {
                $row = $this->approved[$q['Code'] ?? ''] ?? null;

                return Http::response(['data' => ['sellerProducts' => $row === null ? [] : [$row], 'nextCursor' => null], 'success' => true]);
            }

            if (str_contains($url, '/product/products/unapproved')) {
                $row = $this->unapproved[$q['code'] ?? ''] ?? null;

                return Http::response(['data' => $row === null ? [] : [$row], 'success' => true]);
            }

            if (str_contains($url, '/product/getProductBatchResult')) {
                return Http::response($this->batches[$q['BatchRequestId'] ?? ''] ?? ['data' => ['status' => 1], 'success' => true]);
            }

            return Http::response(['message' => 'beklenmeyen '.$url], 599);
        });
    }

    #[Test]
    public function each_variant_is_created_with_its_barcode_brand_id_and_attribute_ids(): void
    {
        [$tenant, $connection, $product, $variants] = $this->readyPazarama();

        foreach ($this->publish($tenant, $product, $connection) as $op) {
            $this->runPush($tenant, $op)->assertNotReleased();
            // Ürün ekleme arası 10 sn kapısı — her öğe ayrı istek.
            Cache::flush();
        }

        $this->assertCount(3, $this->created);
        $byBarcode = collect($this->created)->keyBy('Code');

        foreach (self::VARIANTS as $sku => [$color, $size, $price, $stock, $barcode]) {
            $item = $byBarcode[$barcode];
            $this->assertSame('TS-1', $item['GroupCode']);
            $this->assertSame($sku, $item['StockCode']);
            $this->assertSame('brand-guid-1', $item['BrandId']);
            $this->assertSame((float) $price, (float) $item['SalePrice'], "{$sku} kendi fiyatı");
            $this->assertSame($stock, $item['StockCount'], "{$sku} kendi stoğu");
            $this->assertSame(20, $item['VatRate']);
            $this->assertSame(2, $item['Desi']);
            $this->assertSame('cat-guid', $item['CategoryId']);
            $this->assertSame('Kanonik Pamuklu Basic Tişört', $item['DisplayName']);
            $this->assertSame([['imageurl' => "https://cdn.example.com/{$sku}.jpg"], ['imageurl' => 'https://cdn.example.com/ortak.jpg']], $item['images']);

            $attrs = collect($item['attributes'])->keyBy('attributeId');
            $this->assertSame('men-tr', $attrs['mensei']['attributeValueId'], 'Betimleyici ürün düzeyinden.');
            $this->assertSame(['Kırmızı' => 'renk-k', 'Mavi' => 'renk-m'][$color], $attrs['renk']['attributeValueId']);
            $this->assertSame(['S' => 'beden-s', 'M' => 'beden-m'][$size], $attrs['beden']['attributeValueId']);

            $listing = $this->listing($tenant, $variants[$sku], $connection);
            $this->assertSame($barcode, $listing->external_id, 'Kimlik BARKOD (stok/fiyat da barkodla).');
            $this->assertSame('pending_approval', $listing->lifecycle_status);
        }

        $this->assertSame(0, DB::table('inventory_movements')->count());
    }

    /** ⚠️ Barkodsuz varyant Pazarama'ya AÇILAMAZ; ayarı eksik bağlantı istek atmaz. */
    #[Test]
    public function a_variant_without_barcode_or_missing_settings_blocks_without_a_request(): void
    {
        [$tenant, $connection, $product, $variants] = $this->readyPazarama();
        TenantContext::runFor($tenant->id, fn () => $variants['TS-RED-S']->forceFill(['barcode' => null])->save());
        TenantContext::runFor($tenant->id, fn () => $connection->forceFill(['settings' => [...$connection->settings, PazaramaCatalog::SETTING_DESI => null]])->save());

        $this->assertSame([], $this->publish($tenant, $product, $connection));

        $state = $this->contentState($tenant, $this->listing($tenant, $variants['TS-BLU-S'], $connection));
        $this->assertNotNull($state);
        $this->assertStringContainsString(PazaramaCatalog::SETTING_DESI, (string) $state->last_error);
        $this->assertStringContainsString('TS-RED-S', (string) $state->last_error);
        Http::assertNothingSent();
    }

    /** Marka kanalda birebir yoksa TAHMİN edilmez (benzerleri söylenir). */
    #[Test]
    public function a_brand_not_found_exactly_blocks_instead_of_guessing(): void
    {
        [$tenant, $connection, $product, $variants] = $this->readyPazarama();
        $this->brands = [['id' => 'x', 'name' => 'Kanonik Kids']];

        foreach ($this->publish($tenant, $product, $connection) as $op) {
            $this->runPush($tenant, $op);
        }

        $state = $this->contentState($tenant, $this->listing($tenant, $variants['TS-RED-S'], $connection));
        $this->assertSame(PublishReason::BRAND_MAPPING_REQUIRED->value, $state->reason_code);
        $this->assertStringContainsString('Kanonik Kids', (string) $state->last_error);
        $this->assertSame([], $this->created);
    }

    /** `success: false` kesin red; kanal hatası satıcıya okunur yazılır. */
    #[Test]
    public function a_refused_create_is_permanent(): void
    {
        [$tenant, $connection, $product, $variants] = $this->readyPazarama();
        $this->createAnswers = [Http::response(['data' => ['error' => ['errors' => [['message' => 'Kategori pasif']]]], 'success' => false])];

        $first = $this->publish($tenant, $product, $connection)[0];
        $this->runPush($tenant, $first)->assertNotReleased();

        $states = collect($variants)
            ->map(fn ($variant) => $this->contentState($tenant, $this->listing($tenant, $variant, $connection)))
            ->filter(fn ($state) => $state?->status === 'error_permanent')
            ->values();
        $this->assertCount(1, $states);
        $this->assertStringContainsString('Kategori pasif', (string) $states[0]->last_error);
    }

    #[Test]
    public function approval_reads_approved_unapproved_and_batch_failures(): void
    {
        [$tenant, $connection, $product, $variants] = $this->readyPazarama();

        foreach ($this->publish($tenant, $product, $connection) as $op) {
            $this->runPush($tenant, $op);
            Cache::flush();
        }

        [$red, $redM, $blue] = [self::VARIANTS['TS-RED-S'][4], self::VARIANTS['TS-RED-M'][4], self::VARIANTS['TS-BLU-S'][4]];
        $this->approved[$red] = ['code' => $red, 'groupCode' => 'TS-1', 'state' => 3];
        $this->unapproved[$redM] = ['code' => $redM, 'status' => 'Reddedildi', 'waitingApproveExp' => 'Görsel uygun değil'];
        $blueBatch = $this->listing($tenant, $variants['TS-BLU-S'], $connection)->channel_metadata['pazarama_product_batch_ids'][0];
        $this->batches[$blueBatch] = ['data' => ['status' => 2, 'failedProducts' => [['productCode' => $blue, 'errorReason' => "Product'a ait attribute bulunamadı!"]]], 'success' => true];

        TenantContext::runFor($tenant->id, fn () => app(TrackApprovalStatus::class)->run($connection));

        $this->assertSame('live', $this->listing($tenant, $variants['TS-RED-S'], $connection)->lifecycle_status);
        $this->assertSame('Görsel uygun değil', $this->listing($tenant, $variants['TS-RED-M'], $connection)->approval_rejection_reason);
        $blueListing = $this->listing($tenant, $variants['TS-BLU-S'], $connection);
        $this->assertSame('rejected', $blueListing->lifecycle_status);
        $this->assertSame("Product'a ait attribute bulunamadı!", $blueListing->approval_rejection_reason);
    }

    #[Test]
    public function the_taxonomy_is_parsed_from_the_documented_shapes(): void
    {
        $tree = PazaramaCatalog::tree(['data' => [
            ['id' => 'p1', 'parentId' => null, 'parentCategories' => ['Spor'], 'name' => 'Spor', 'displayName' => 'Spor', 'leaf' => false],
            ['id' => 'c1', 'parentId' => 'p1', 'parentCategories' => ['Spor', 'Adımsayar'], 'name' => 'Adımsayar', 'displayName' => 'Adımsayar', 'leaf' => true],
        ]]);

        $this->assertSame([
            ['external_id' => 'p1', 'parent_external_id' => null, 'name' => 'Spor', 'path' => 'Spor', 'is_leaf' => false],
            ['external_id' => 'c1', 'parent_external_id' => 'p1', 'name' => 'Adımsayar', 'path' => 'Spor > Adımsayar', 'is_leaf' => true],
        ], $tree->categories);

        $attrs = PazaramaCatalog::attributes(['data' => ['id' => 'c1', 'attributes' => [
            ['id' => 'renk', 'name' => 'Renk', 'displayName' => 'Renk', 'isVariantable' => true, 'isRequired' => true, 'attributeValues' => [['id' => 'v1', 'value' => 'Siyah']]],
        ]]]);

        $this->assertSame(['renk', 'Renk', true, true, 'enum'], [$attrs[0]['external_attribute_id'], $attrs[0]['name'], $attrs[0]['is_required'], $attrs[0]['is_variant_defining'], $attrs[0]['data_type']]);
        $this->assertSame([['id' => 'v1', 'label' => 'Siyah']], $attrs[0]['allowed_values']);
    }

    /** @return array{0: Tenant, 1: ChannelConnection, 2: Product, 3: array<string, Variant>, 4: ChannelCategory} */
    private function readyPazarama(): array
    {
        $tenant = $this->tenant();
        $connection = $this->pazaramaConnection($tenant);
        [$product, $variants] = $this->tshirt($tenant);
        $category = $this->channelCategory('pazarama', 'cat-guid', [
            ['mensei', 'Menşei', true, false, 'enum', [['id' => 'men-tr', 'label' => 'Türkiye']]],
            ['renk', 'Renk', true, true, 'enum', [['id' => 'renk-k', 'label' => 'Kırmızı'], ['id' => 'renk-m', 'label' => 'Mavi']]],
            ['beden', 'Beden', true, true, 'enum', [['id' => 'beden-s', 'label' => 'S'], ['id' => 'beden-m', 'label' => 'M']]],
        ]);
        $this->mapCategory($tenant, $category, 'renk', 'beden', ['Renk:Kırmızı' => 'renk-k', 'Renk:Mavi' => 'renk-m', 'Beden:S' => 'beden-s', 'Beden:M' => 'beden-m']);
        $this->productValue($tenant, $product, $category, 'mensei', 'men-tr', null);

        return [$tenant, $connection, $product, $variants, $category];
    }
}
