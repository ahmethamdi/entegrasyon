<?php

declare(strict_types=1);

namespace Tests\Feature\Fulfillment;

use App\Domain\Catalog\Models\OptionDefinition;
use App\Domain\Catalog\Models\OptionValue;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Variant;
use App\Domain\Catalog\Models\VariantOption;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Registry\AdapterRegistry;
use App\Domain\Fulfillment\Jobs\PrepareShippingLabel;
use App\Domain\Fulfillment\Models\ShippingLabel;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\OrderLine;
use App\Domain\Sync\Support\ChannelErrorText;
use App\Support\Tenancy\TenantContext;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Tests\Support\Finance\BuildsFinanceScenario;

/**
 * S2 depo akışı testleri: Trendyol paketi + ortak etiket uçlarının sahte
 * cevapları, kargo bekleyen siparişler ve çok varyantlı katalog.
 *
 * Etiket gövdeleri belgedeki biçimdir (`{data: [{label, format}]}`); ret
 * gövdesi canlı hesapta 11 Eki'de gözlenen cevabın kendisidir.
 */
trait BuildsFulfillmentScenario
{
    use BuildsFinanceScenario;

    /** Paket kimliği → Trendyol paket gövdesi. */
    protected array $packages = [];

    /** Sırayla dönecek GET common-label cevapları; boşsa `{data: []}`. */
    protected array $labelGets = [];

    /** POST common-label cevabı (closure ya da yanıt); null = 200 gövdesiz. */
    protected mixed $onLabelPost = null;

    protected int $labelPostCount = 0;

    protected int $labelGetCount = 0;

    protected const ZPL_1 = '^XA^FO50,50^ADN,36,20^FDKUTU-1 7330000000001^FS^XZ';

    protected const ZPL_2 = '^XA^FO50,50^ADN,36,20^FDKUTU-2 7330000000001^FS^XZ';

    /** Canlı hesapta gözlenen ret (GET, 11 Eki 2026). */
    protected const NOT_ALLOWED = ['error' => ['status' => 400, 'errors' => [['code' => 400, 'type' => 'COMMON_LABEL_NOT_ALLOWED', 'title' => 'Bu servisi kullanmak için yetkiniz bulunmamaktadır. Kategori sorumlunuz ile iletişime geçiniz.']]]];

    protected function setUpFulfillment(): void
    {
        $this->travelTo('2026-10-12 10:00:00');
        // Devre kesici ve hız kovası Redis'te — testler arasında taşınmasın.
        Redis::connection()->flushdb();
        $this->fakeTrendyol();
    }

    protected function fakeTrendyol(): void
    {
        Http::fake(function (Request $request) {
            $url = $request->url();

            if (str_contains($url, '/common-label/')) {
                if ($request->method() === 'POST') {
                    $this->labelPostCount++;
                    $answer = $this->onLabelPost instanceof \Closure ? ($this->onLabelPost)($request) : $this->onLabelPost;

                    return $answer ?? Http::response('', 200);
                }

                $this->labelGetCount++;
                $next = array_shift($this->labelGets);

                return ($next instanceof \Closure ? $next($request) : $next) ?? Http::response(['data' => []], 200);
            }

            if (str_contains($url, '/order/sellers/') && str_contains($url, '/v2/orders')) {
                parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
                $rows = array_values(array_filter($this->packages, static fn (array $p): bool => (string) $p['shipmentPackageId'] === (string) ($q['shipmentPackageIds'] ?? '')));

                return Http::response(['page' => 0, 'size' => 50, 'totalPages' => 1, 'totalElements' => count($rows), 'content' => $rows]);
            }

            return Http::response(['message' => 'beklenmeyen istek '.$url], 599);
        });
    }

    /** Trendyol paket gövdesi (yalnız S2'nin okuduğu alanlar). */
    protected function package(string $id, array $overrides = []): array
    {
        return $this->packages[$id] = [
            'shipmentPackageId' => (int) $id,
            'orderNumber' => 'N-'.$id,
            'shipmentPackageStatus' => 'Picking',
            'status' => 'Picking',
            'cargoProviderName' => 'Trendyol Express Marketplace',
            'cargoTrackingNumber' => 7330000000000 + (int) $id,
            'boxQuantity' => 1,
            'lines' => [],
            ...$overrides,
        ];
    }

    /** Kargo bekleyen Trendyol siparişi + aynı kimlikli sahte paket. */
    protected function awaitingOrder(Tenant $tenant, ChannelConnection $connection, string $package, array $lines = [['sku' => 'SKU-1']], array $packageOverrides = [], string $state = 'awaiting_shipment'): Order
    {
        $order = $this->trendyolOrder($tenant, $connection, $package, 'N-'.$package, $lines);
        TenantContext::runFor($tenant->id, fn () => Order::query()->whereKey($order->id)->update(['state' => $state, 'status' => 'Picking', 'placed_at' => now()->subDay()]));
        $this->package($package, $packageOverrides);

        return $order->fresh();
    }

    protected function runLabelJob(Tenant $tenant, string $labelId): PrepareShippingLabel
    {
        $job = (new PrepareShippingLabel($labelId, $tenant->id))->withFakeQueueInteractions();
        // Hız kovası Redis SAATİNE bağlı (seyahat etmez); her tur boş kovayla.
        Redis::connection()->flushdb();
        $job->handle(app(AdapterRegistry::class), app(ChannelErrorText::class));

        return $job;
    }

    protected function labelFor(Tenant $tenant, Order $order): ?ShippingLabel
    {
        return TenantContext::runFor($tenant->id, fn () => ShippingLabel::query()->where('order_id', $order->id)->first());
    }

    protected function zplResponse(string ...$labels): PromiseInterface
    {
        return Http::response(['data' => array_map(static fn (string $l): array => ['label' => $l, 'format' => 'ZPL'], $labels)], 200);
    }

    /**
     * Çok varyantlı ürün: "Tişört" — Renk Kırmızı/Mavi × Beden M.
     *
     * @return array{0: Variant, 1: Variant}
     */
    protected function tshirtVariants(Tenant $tenant): array
    {
        return TenantContext::runFor($tenant->id, function () use ($tenant): array {
            $product = Product::factory()->create(['tenant_id' => $tenant->id, 'title' => 'Tişört']);
            $color = OptionDefinition::query()->create(['tenant_id' => $tenant->id, 'name' => 'Renk', 'position' => 0]);
            $size = OptionDefinition::query()->create(['tenant_id' => $tenant->id, 'name' => 'Beden', 'position' => 1]);
            $m = OptionValue::query()->create(['tenant_id' => $tenant->id, 'option_definition_id' => $size->id, 'value' => 'M']);
            $variants = [];

            foreach (['Kırmızı' => ['TS-KIR-M', '8690000000011'], 'Mavi' => ['TS-MAV-M', '8690000000028']] as $colorName => [$sku, $barcode]) {
                $value = OptionValue::query()->create(['tenant_id' => $tenant->id, 'option_definition_id' => $color->id, 'value' => $colorName]);
                $variant = Variant::factory()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'sku' => $sku, 'barcode' => $barcode]);
                VariantOption::query()->create(['tenant_id' => $tenant->id, 'variant_id' => $variant->id, 'option_definition_id' => $color->id, 'option_value_id' => $value->id]);
                VariantOption::query()->create(['tenant_id' => $tenant->id, 'variant_id' => $variant->id, 'option_definition_id' => $size->id, 'option_value_id' => $m->id]);
                $variants[] = $variant;
            }

            return $variants;
        });
    }

    protected function linkLine(Tenant $tenant, Order $order, int $index, Variant $variant, int $qty): void
    {
        TenantContext::runFor($tenant->id, fn () => OrderLine::query()
            ->where('order_id', $order->id)
            ->where('external_line_id', (string) $index)
            ->update(['variant_id' => $variant->id, 'quantity' => $qty, 'sku' => 'KANAL-'.$index]));
    }
}
