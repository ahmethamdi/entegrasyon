<?php

declare(strict_types=1);

namespace Tests\Feature\Publishing;

use App\Domain\Catalog\Models\OptionValue;
use App\Domain\Catalog\Models\Variant;
use App\Domain\Channels\Models\AttributeValueMapping;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Messaging\Models\OutboxEvent;
use App\Domain\Sync\Actions\TrackApprovalStatus;
use App\Domain\Sync\Enums\PublishReason;
use App\Domain\Sync\Enums\SyncDomain;
use App\Domain\Sync\Models\Listing;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * S3 · Hepsiburada ürün girişi — kanonik ürün + varyant → `import`
 * öğeleri, ön doğrulama, belirsiz yaratma koruması, onay takibi.
 */
final class HepsiburadaPublishingTest extends TestCase
{
    use BuildsPublishingScenario;
    use RefreshDatabase;

    /** merchantSku → productStatus (check-product-status). */
    private array $statuses = [];

    /** Sıralı import cevapları; boşsa başarılı trackingId. */
    private array $importAnswers = [];

    /** @var list<array<string, mixed>> gönderilen import öğeleri */
    private array $imported = [];

    /** trackingId → status satırları. */
    private array $tracking = [];

    /** merchantSku → hbSku (Listings sorgusu). */
    private array $listings = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPublishing();
        $this->fakeHb();
    }

    private function fakeHb(): void
    {
        Http::fake(function (Request $request) {
            $url = $request->url();

            if (str_contains($url, '/api/products/check-product-status')) {
                $skus = $request->data()[0]['merchantSkuList'] ?? [];
                $rows = [];

                foreach ($skus as $sku) {
                    if (isset($this->statuses[$sku])) {
                        $rows[] = ['merchantSku' => $sku, 'status' => $this->statuses[$sku]];
                    }
                }

                return Http::response(['success' => true, 'code' => 0, 'data' => [['merchant' => self::MERCHANT, 'skuStatusList' => $rows]]]);
            }

            if (str_contains($url, '/api/products/import')) {
                preg_match('/(\[\{.*\}\])/s', $request->body(), $m);
                foreach (json_decode($m[1] ?? '[]', true) ?? [] as $item) {
                    $this->imported[] = $item;
                }

                $answer = array_shift($this->importAnswers);

                return ($answer instanceof \Closure ? $answer() : $answer)
                    ?? Http::response(['success' => true, 'code' => 0, 'data' => ['trackingId' => 'T-'.count($this->imported)]]);
            }

            if (str_contains($url, '/api/products/status/')) {
                $id = basename(parse_url($url, PHP_URL_PATH));

                return Http::response(['success' => true, 'data' => $this->tracking[$id] ?? []]);
            }

            if (str_contains($url, '/Listings/merchantid/')) {
                parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
                $sku = $q['merchantSkuList'] ?? '';
                $rows = isset($this->listings[$sku]) ? [['merchantSku' => $sku, 'hepsiburadaSku' => $this->listings[$sku]]] : [];

                return Http::response(['totalCount' => count($rows), 'listings' => $rows]);
            }

            return Http::response(['message' => 'beklenmeyen '.$url], 599);
        });
    }

    // ─────────────────────────────────────────── yük doğruluğu

    #[Test]
    public function a_multi_variant_product_is_imported_as_one_group_with_each_siblings_own_values(): void
    {
        [$tenant, $connection, $product, $variants] = $this->readyHb();

        $operations = $this->publish($tenant, $product, $connection);
        $this->assertCount(3, $operations, 'Varyant başına bir listing/operasyon.');

        foreach ($operations as $op) {
            $this->runPush($tenant, $op)->assertNotReleased();
        }

        $this->assertCount(3, $this->imported);
        $bySku = collect($this->imported)->keyBy(fn (array $i) => $i['attributes']['merchantSku']);

        foreach (self::VARIANTS as $sku => [$color, $size, $price, $stock, $barcode]) {
            $item = $bySku[$sku]['attributes'];
            $this->assertSame(18021982, $bySku[$sku]['categoryId']);
            $this->assertSame(self::MERCHANT, $bySku[$sku]['merchant']);
            $this->assertSame('TS-1', $item['VaryantGroupID'], 'Tek ürün grubu.');
            $this->assertSame($barcode, $item['Barcode'], "{$sku} kendi barkodu");
            $this->assertSame(number_format((float) $price, 2, ',', ''), $item['price'], "{$sku} kendi fiyatı");
            $this->assertSame((string) $stock, $item['stock'], "{$sku} kendi stoğu");
            $this->assertSame($color, $item['renk_variant_property']);
            $this->assertSame($size, $item['beden_variant_property']);
            $this->assertSame("https://cdn.example.com/{$sku}.jpg", $item['Image1'], 'Önce varyantın kendi görseli.');
            $this->assertSame('https://cdn.example.com/ortak.jpg', $item['Image2'], 'Sonra ortak görsel.');
            $this->assertArrayNotHasKey('Image3', $item, 'Kardeş görseli gelmez.');
            $this->assertSame('24', $item['GarantiSuresi']);
            $this->assertSame('Kanonik', $item['Marka']);
            $this->assertSame('20', $item['tax_vat_rate']);

            $listing = $this->listing($tenant, $variants[$sku], $connection);
            $this->assertSame($sku, $listing->external_id);
            $this->assertSame('TS-1', $listing->external_parent_id);
            $this->assertSame('pending_approval', $listing->lifecycle_status, '200 ≠ yayında.');
            $this->assertNotNull($listing->channel_metadata['hb_tracking_ids'] ?? null);
        }

        $this->assertSame(1, TenantContext::runFor($tenant->id, fn () => DB::table('products')->count()), 'Kanal ilanı kanonik ürün olmaz.');
        $this->assertSame(0, DB::table('inventory_movements')->count(), 'Yayın stok hareketi yazmaz.');
    }

    #[Test]
    public function a_single_variant_product_publishes_one_item(): void
    {
        [$tenant, $connection, $product, $variants] = $this->readyHb();
        TenantContext::runFor($tenant->id, fn () => $variants['TS-RED-M']->delete() && $variants['TS-BLU-S']->delete());

        foreach ($this->publish($tenant, $product, $connection) as $op) {
            $this->runPush($tenant, $op);
        }

        $this->assertCount(1, $this->imported);
        $this->assertSame('TS-RED-S', $this->imported[0]['attributes']['merchantSku']);
    }

    // ─────────────────────────────────────────── doğrulama (dış yazmadan önce)

    #[Test]
    public function a_missing_required_descriptive_value_blocks_every_variant_without_any_request(): void
    {
        [$tenant, $connection, $product, $variants, $category] = $this->readyHb();
        TenantContext::runFor($tenant->id, fn () => DB::table('product_channel_attribute_values')->delete());

        $this->assertSame([], $this->publish($tenant, $product, $connection));

        foreach ($variants as $variant) {
            $listing = $this->listing($tenant, $variant, $connection);
            $this->assertSame('blocked', $listing->lifecycle_status);
            $this->assertSame(PublishReason::ATTRIBUTE_VALUE_REQUIRED->value, $this->contentState($tenant, $listing)->reason_code);
        }

        Http::assertNothingSent();
    }

    #[Test]
    public function one_unmapped_sibling_value_blocks_the_whole_group(): void
    {
        [$tenant, $connection, $product, $variants] = $this->readyHb();
        // Mavi'nin eşlemesi yok VE kanal listesinde birebir karşılığı yok
        // (liste bağlayıcı): tahmin edilmez.
        TenantContext::runFor($tenant->id, function () {
            $blue = OptionValue::query()->where('value', 'Mavi')->firstOrFail();
            AttributeValueMapping::query()->where('option_value_id', $blue->id)->delete();
        });
        TenantContext::runAsSystem(fn () => DB::table('channel_category_attributes')
            ->where('external_attribute_id', 'renk_variant_property')
            ->update(['allowed_values' => json_encode([['id' => 'r1', 'label' => 'Kırmızı'], ['id' => 'r3', 'label' => 'Lacivert']])]));

        $this->assertSame([], $this->publish($tenant, $product, $connection), 'Yarım grup kısmi dış yazmadır.');
        $this->assertSame(PublishReason::ATTRIBUTE_VALUE_MAPPING_REQUIRED->value, $this->contentState($tenant, $this->listing($tenant, $variants['TS-RED-S'], $connection))->reason_code);
        Http::assertNothingSent();
    }

    #[Test]
    public function an_invalid_barcode_or_missing_image_is_reported_with_a_stable_code(): void
    {
        [$tenant, $connection, $product, $variants] = $this->readyHb();
        TenantContext::runFor($tenant->id, fn () => $variants['TS-RED-S']->forceFill(['barcode' => '8690000000013'])->save());

        $this->publish($tenant, $product, $connection);

        $this->assertSame(PublishReason::BARCODE_INVALID->value, $this->contentState($tenant, $this->listing($tenant, $variants['TS-RED-S'], $connection))->reason_code);
        Http::assertNothingSent();
    }

    #[Test]
    public function siblings_that_cannot_be_told_apart_are_an_invalid_variant(): void
    {
        [$tenant, $connection, $product, $variants] = $this->readyHb();
        // Beden eşlemesi yok: Kırmızı/S ve Kırmızı/M kanalda aynı görünürdü.
        TenantContext::runFor($tenant->id, fn () => DB::table('attribute_mappings')->where('external_attribute_id', 'beden_variant_property')->delete());
        TenantContext::runAsSystem(fn () => DB::table('channel_category_attributes')->where('external_attribute_id', 'beden_variant_property')->update(['is_required' => false]));

        $this->publish($tenant, $product, $connection);

        $this->assertSame(PublishReason::INVALID_VARIANT->value, $this->contentState($tenant, $this->listing($tenant, $variants['TS-RED-S'], $connection))->reason_code);
        Http::assertNothingSent();
    }

    // ─────────────────────────────────────────── tekrar / kopya koruması

    #[Test]
    public function a_repeated_publish_of_the_same_version_sends_nothing_again(): void
    {
        [$tenant, $connection, $product] = $this->readyHb();

        foreach ($this->publish($tenant, $product, $connection) as $op) {
            $this->runPush($tenant, $op);
            $this->runPush($tenant, $op);
        }

        $this->assertSame([], $this->publish($tenant, $product, $connection), 'Aynı sürüm ikinci operasyon açmaz.');
        $this->assertCount(3, $this->imported);
    }

    #[Test]
    public function a_product_already_in_hb_entry_is_adopted_not_imported_again(): void
    {
        [$tenant, $connection, $product, $variants] = $this->readyHb();
        $this->statuses = ['TS-RED-S' => 'WAITING'];

        $ops = $this->publish($tenant, $product, $connection);
        $this->runPush($tenant, $ops[0]);

        $this->assertSame([], array_filter($this->imported, fn ($i) => $i['attributes']['merchantSku'] === 'TS-RED-S'), 'Önce ara: var olan yaratılmaz.');
    }

    #[Test]
    public function a_timed_out_import_is_never_resent_and_is_reconciled_by_lookup(): void
    {
        [$tenant, $connection, $product, $variants] = $this->readyHb();
        $this->importAnswers = [fn () => Http::failedConnection()];
        $op = $this->publish($tenant, $product, $connection)[0];

        $this->runPush($tenant, $op)->assertReleased();
        $listing = $this->listing($tenant, $variants['TS-RED-S'], $connection);
        $this->assertNotNull($listing->create_requested_at, 'Belirsiz istek talebi korur.');
        $this->assertNull($listing->external_id);

        // Kanal henüz göstermiyor: tekrar POST YOK, yalnız arama.
        $this->runPush($tenant, $op)->assertReleased();
        $this->assertSame(PublishReason::REMOTE_STATE_UNKNOWN->value, $this->contentState($tenant, $listing)->reason_code);
        $this->assertCount(1, $this->imported);

        // Kanal ürünü işledi: kimlik benimsenir, yine POST yok.
        $this->statuses = ['TS-RED-S' => 'WAITING'];
        $this->runPush($tenant, $op)->assertNotReleased();

        $listing = $this->listing($tenant, $variants['TS-RED-S'], $connection);
        $this->assertSame('TS-RED-S', $listing->external_id);
        $this->assertSame('pending_approval', $listing->lifecycle_status);
        $this->assertCount(1, $this->imported, 'Toplam tek yükleme.');
    }

    #[Test]
    public function a_5xx_after_upload_is_ambiguous_and_not_resent(): void
    {
        [$tenant, $connection, $product] = $this->readyHb();
        $this->importAnswers = [Http::response('kapı hatası', 502)];
        $op = $this->publish($tenant, $product, $connection)[0];

        $this->runPush($tenant, $op)->assertReleased();
        $this->runPush($tenant, $op)->assertReleased();

        $this->assertCount(1, $this->imported);
    }

    #[Test]
    public function a_429_or_catalog_403_was_not_accepted_and_may_be_retried(): void
    {
        [$tenant, $connection, $product, $variants] = $this->readyHb();
        $this->importAnswers = [Http::response(['message' => 'çok istek'], 429), Http::response('', 403)];
        $op = $this->publish($tenant, $product, $connection)[0];

        $this->runPush($tenant, $op)->assertReleased();
        $this->assertNull($this->listing($tenant, $variants['TS-RED-S'], $connection)->create_requested_at, '429: istek kabul edilmedi, talep geri alınır.');
        $this->assertSame(PublishReason::RATE_LIMITED->value, $this->contentState($tenant, $this->listing($tenant, $variants['TS-RED-S'], $connection))->reason_code);

        // MPOP 403 = hız aşımı (belge), kimlik hatası değil.
        $this->runPush($tenant, $op)->assertReleased();
        $this->assertNull($this->listing($tenant, $variants['TS-RED-S'], $connection)->create_requested_at);

        $this->runPush($tenant, $op)->assertNotReleased();
        $this->assertCount(3, $this->imported);
        $this->assertSame('TS-RED-S', $this->listing($tenant, $variants['TS-RED-S'], $connection)->external_id);
    }

    #[Test]
    public function a_rejected_file_is_permanent_and_releases_the_claim(): void
    {
        [$tenant, $connection, $product, $variants] = $this->readyHb();
        $this->importAnswers = [Http::response(['success' => false, 'code' => 3001, 'message' => 'Dosya içeriği geçersiz.'])];
        $op = $this->publish($tenant, $product, $connection)[0];

        $this->runPush($tenant, $op)->assertNotReleased();

        $listing = $this->listing($tenant, $variants['TS-RED-S'], $connection);
        $this->assertNull($listing->create_requested_at);
        $state = $this->contentState($tenant, $listing);
        $this->assertSame('error_permanent', $state->status);
        $this->assertSame(PublishReason::LISTING_SYNC_FAILED->value, $state->reason_code);
    }

    #[Test]
    public function an_explicit_republish_resets_an_unknown_create_but_a_retry_does_not(): void
    {
        [$tenant, $connection, $product, $variants] = $this->readyHb();
        $this->importAnswers = [fn () => Http::failedConnection()];
        $op = $this->publish($tenant, $product, $connection)[0];
        $this->runPush($tenant, $op);

        TenantContext::runFor($tenant->id, fn () => $product->forceFill(['content_version' => 2])->save());
        $ops = $this->publish($tenant, $product, $connection);

        $this->assertNull($this->listing($tenant, $variants['TS-RED-S'], $connection)->create_requested_at, 'Satıcının açık kararı.');
        $this->runPush($tenant, $ops[0]);
        $this->assertCount(2, array_filter($this->imported, fn ($i) => $i['attributes']['merchantSku'] === 'TS-RED-S'));
    }

    // ─────────────────────────────────────────── yaşam döngüsü

    #[Test]
    public function approval_moves_identity_to_hb_sku_goes_live_and_requests_initial_stock_and_price(): void
    {
        [$tenant, $connection, $product, $variants] = $this->readyHb();
        foreach ($this->publish($tenant, $product, $connection) as $op) {
            $this->runPush($tenant, $op);
        }

        $this->statuses = ['TS-RED-S' => 'MATCHED', 'TS-RED-M' => 'WAITING', 'TS-BLU-S' => 'MATCHED'];
        $this->tracking = ['T-1' => [['merchantSku' => 'TS-RED-S', 'hbSku' => 'HBV0000RS', 'importStatus' => 'SUCCESS']]];
        $this->listings = ['TS-BLU-S' => 'HBV0000BS'];

        $result = TenantContext::runFor($tenant->id, fn () => app(TrackApprovalStatus::class)->run($connection));
        $this->assertSame(2, $result->approved);

        $red = $this->listing($tenant, $variants['TS-RED-S'], $connection);
        $this->assertSame('live', $red->lifecycle_status);
        $this->assertSame('HBV0000RS', $red->external_id);
        $this->assertSame('HBV0000RS', $red->channel_metadata['hb_sku']);
        $this->assertSame('HBV0000BS', $this->listing($tenant, $variants['TS-BLU-S'], $connection)->external_id, 'Kardeş kendi HB kodunu alır.');
        $this->assertSame('pending_approval', $this->listing($tenant, $variants['TS-RED-M'], $connection)->lifecycle_status, 'Bekleyen için durum uydurulmaz.');

        $events = TenantContext::runAsSystem(fn () => OutboxEvent::query()->where('event_type', 'ListingResyncRequested')->where('aggregate_id', $red->id)->pluck('payload'));
        $this->assertEqualsCanonicalizing([SyncDomain::INVENTORY->value, SyncDomain::PRICE->value], $events->map(fn ($p) => is_array($p) ? $p['domain'] : json_decode($p, true)['domain'])->all());
    }

    #[Test]
    public function approval_without_an_hb_code_is_not_live_and_a_taken_code_is_not_reused(): void
    {
        [$tenant, $connection, $product, $variants] = $this->readyHb();
        foreach ($this->publish($tenant, $product, $connection) as $op) {
            $this->runPush($tenant, $op);
        }

        // Daha önce içe aktarılmış ayrı bir satır HBV0000BS'yi taşıyor.
        TenantContext::runFor($tenant->id, function () use ($tenant, $connection) {
            $other = Variant::factory()->create(['tenant_id' => $tenant->id, 'sku' => 'ESKI-1']);
            Listing::query()->create(['tenant_id' => $tenant->id, 'channel_connection_id' => $connection->id, 'variant_id' => $other->id, 'external_id' => 'HBV0000BS', 'lifecycle_status' => 'live']);
        });

        $this->statuses = ['TS-RED-S' => 'MATCHED', 'TS-BLU-S' => 'MATCHED'];
        $this->listings = ['TS-BLU-S' => 'HBV0000BS'];

        TenantContext::runFor($tenant->id, fn () => app(TrackApprovalStatus::class)->run($connection));

        $this->assertSame('pending_approval', $this->listing($tenant, $variants['TS-RED-S'], $connection)->lifecycle_status, 'HB kodu yok: stok gönderilemez, canlı sayılmaz.');
        $blue = $this->listing($tenant, $variants['TS-BLU-S'], $connection);
        $this->assertSame('TS-BLU-S', $blue->external_id, 'Başka satırın kimliği devralınmaz.');
        $this->assertSame('pending_approval', $blue->lifecycle_status, 'Yanlış kimlikle canlı olmaz.');
    }

    #[Test]
    public function missing_info_is_a_rejection_with_hb_reasons_and_pre_match_needs_the_seller(): void
    {
        [$tenant, $connection, $product, $variants] = $this->readyHb();
        foreach ($this->publish($tenant, $product, $connection) as $op) {
            $this->runPush($tenant, $op);
        }

        $this->statuses = ['TS-RED-S' => 'MISSING_INFO', 'TS-RED-M' => 'PRE_MATCHED'];
        $this->tracking = ['T-1' => [['merchantSku' => 'TS-RED-S', 'importStatus' => 'SUCCESS', 'validationResults' => [['attributeName' => 'Renk', 'message' => 'Geçersiz değer']]]]];

        TenantContext::runFor($tenant->id, fn () => app(TrackApprovalStatus::class)->run($connection));

        $red = $this->listing($tenant, $variants['TS-RED-S'], $connection);
        $this->assertSame('rejected', $red->lifecycle_status);
        $this->assertSame('Renk: Geçersiz değer', $red->approval_rejection_reason);
        $this->assertSame('pending_approval', $this->listing($tenant, $variants['TS-RED-M'], $connection)->lifecycle_status);
        $this->assertSame('Hepsiburada: PRE_MATCHED', $this->listing($tenant, $variants['TS-RED-M'], $connection)->approval_rejection_reason);
    }

    #[Test]
    public function a_live_hb_product_content_update_is_explicitly_unsupported(): void
    {
        [$tenant, $connection, $product, $variants] = $this->readyHb();
        foreach ($this->publish($tenant, $product, $connection) as $op) {
            $this->runPush($tenant, $op);
        }
        TenantContext::runFor($tenant->id, fn () => Listing::query()->update(['lifecycle_status' => 'live']));
        TenantContext::runFor($tenant->id, fn () => $product->forceFill(['content_version' => 2])->save());

        $op = $this->publish($tenant, $product, $connection)[0];
        $this->runPush($tenant, $op);

        $this->assertSame(PublishReason::LISTING_UPDATE_UNSUPPORTED->value, $this->contentState($tenant, $this->listing($tenant, $variants['TS-RED-S'], $connection))->reason_code);
        $this->assertCount(3, $this->imported, 'Canlı ürüne yükleme gitmez.');
    }

    // ─────────────────────────────────────────── kiracı

    #[Test]
    public function another_tenants_mapping_does_not_open_publication(): void
    {
        [$tenantA] = $this->readyHb();
        $tenantB = $this->tenant();
        $connectionB = $this->hbConnection($tenantB, merchant: 'baska-satici-guid');
        [$productB, $variantsB] = $this->tshirt($tenantB, 'TS-B');

        $this->assertSame([], $this->publish($tenantB, $productB, $connectionB));
        $this->assertSame(PublishReason::CATEGORY_MAPPING_REQUIRED->value, $this->contentState($tenantB, $this->listing($tenantB, $variantsB['TS-RED-S'], $connectionB))->reason_code);
        $this->assertInstanceOf(Tenant::class, $tenantA);
        Http::assertNothingSent();
    }
}
