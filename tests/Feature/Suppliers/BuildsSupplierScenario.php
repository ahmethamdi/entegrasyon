<?php

declare(strict_types=1);

namespace Tests\Feature\Suppliers;

use App\Domain\Billing\Models\Plan;
use App\Domain\Billing\Models\Subscription;
use App\Domain\Catalog\Models\Variant;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Actions\ApplyMovement;
use App\Domain\Inventory\Actions\LockInventoryRows;
use App\Domain\Inventory\Enums\MovementType;
use App\Domain\Inventory\Models\InventoryLevel;
use App\Domain\Inventory\Support\MovementKey;
use App\Domain\Suppliers\Actions\StartSupplierSync;
use App\Domain\Suppliers\Models\SupplierSource;
use App\Domain\Suppliers\Models\SupplierSyncRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Uid\UuidV7;

/**
 * Tedarikçi senaryoları için ortak kurulum: kiracı, kaynak, sahte akış.
 *
 * Akış `https://feed.example.com/urunler.xml` adresinden döner; gövde
 * `$this->feedBody` (her testte değiştirilebilir). Sahte çözümleyici
 * (TestCase) adı genel bir IP'ye çözer.
 */
trait BuildsSupplierScenario
{
    protected string $feedBody = '';

    protected int $feedStatus = 200;

    protected const FEED_URL = 'https://feed.example.com/urunler.xml';

    protected function fakeFeed(): void
    {
        Http::fake([
            'feed.example.com/*' => fn () => Http::response($this->feedBody, $this->feedStatus, ['Content-Type' => 'application/xml']),
        ]);
    }

    /**
     * @return array{0: Tenant, 1: User, 2: string}
     */
    protected function makeTenant(string $name = 'Tedarik'): array
    {
        $user = User::factory()->create();
        $tenant = (new CreateTenant)->run(name: $name.' '.uniqid(), owner: $user);
        $warehouseId = $this->asTenant($tenant, fn () => $tenant->defaultWarehouse()->id);

        return [$tenant, $user, $warehouseId];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function makeSource(Tenant $tenant, array $overrides = []): SupplierSource
    {
        return $this->asTenant($tenant, function () use ($overrides): SupplierSource {
            $source = new SupplierSource(array_merge([
                'name' => 'Toptancı',
                'feed_url' => self::FEED_URL,
                'feed_host' => 'feed.example.com',
                'record_path' => 'Urunler/Urun',
                'mapping' => [
                    'fields' => [
                        'key' => 'Kod',
                        'sku' => 'Kod',
                        'barcode' => 'Barkod',
                        'name' => 'Ad',
                        'stock' => 'Stok',
                        'cost' => 'Alis',
                        'price' => 'Satis',
                        'images' => 'Resimler/Resim',
                    ],
                ],
                'number_format' => 'dot',
                'currency' => 'TRY',
                'stock_policy' => SupplierSource::STOCK_AUTHORITATIVE,
                'price_policy' => SupplierSource::PRICE_FEED,
                'create_products' => true,
            ], $overrides));
            $source->save();

            return $source->refresh();
        });
    }

    /**
     * Basit düz akış: her ürün bir <Urun>.
     *
     * @param  list<array<string, string|null>>  $products  alan → değer (null = alanı yazma)
     */
    protected function xml(array $products): string
    {
        $out = '<?xml version="1.0" encoding="UTF-8"?><Urunler>';

        foreach ($products as $product) {
            $out .= '<Urun>';

            foreach ($product as $field => $value) {
                if ($value === null) {
                    continue;
                }

                if ($field === 'Resim') {
                    $out .= '<Resimler><Resim>'.htmlspecialchars($value).'</Resim></Resimler>';

                    continue;
                }

                $out .= "<{$field}>".htmlspecialchars($value)."</{$field}>";
            }

            $out .= '</Urun>';
        }

        return $out.'</Urunler>';
    }

    /**
     * @param  array<string, string|null>  $overrides
     * @return array<string, string|null>
     */
    protected function product(string $code, array $overrides = []): array
    {
        return array_merge([
            'Kod' => $code,
            'Barkod' => null,
            'Ad' => "Ürün {$code}",
            'Stok' => '10',
            'Alis' => '50.00',
            'Satis' => '100.00',
        ], $overrides);
    }

    protected function sync(Tenant $tenant, SupplierSource $source, string $mode = SupplierSyncRun::MODE_APPLY): SupplierSyncRun
    {
        return $this->asTenant($tenant, function () use ($source, $mode): SupplierSyncRun {
            $run = app(StartSupplierSync::class)->run($source->refresh(), $mode, SupplierSyncRun::TRIGGER_MANUAL);

            return $run->refresh();
        });
    }

    /**
     * @param  array<string, int|null>  $limits
     */
    protected function onPlan(Tenant $tenant, array $limits): void
    {
        $plan = Plan::query()->create([
            'code' => 'plan-'.uniqid(),
            'name' => 'Test',
            'price_monthly' => 99,
            'limits' => $limits,
        ]);

        $this->asSystem(fn () => Subscription::query()->create([
            'tenant_id' => $tenant->id,
            'plan_code' => $plan->code,
            'status' => 'active',
            'started_at' => now(),
        ]));
    }

    protected function onHand(Tenant $tenant, string $variantId): int
    {
        return (int) $this->asTenant($tenant, fn () => InventoryLevel::query()->where('variant_id', $variantId)->value('on_hand'));
    }

    protected function variantBySku(Tenant $tenant, string $sku): ?Variant
    {
        return $this->asTenant($tenant, fn () => Variant::query()->where('sku', $sku)->first());
    }

    protected function stockedVariant(Tenant $tenant, string $warehouseId, int $onHand, array $attributes = []): Variant
    {
        return $this->asTenant($tenant, function () use ($warehouseId, $onHand, $attributes): Variant {
            $variant = Variant::factory()->create($attributes);

            DB::transaction(function () use ($warehouseId, $variant, $onHand): void {
                (new LockInventoryRows)->run($warehouseId, [$variant->id]);

                if ($onHand > 0) {
                    (new ApplyMovement)->run(
                        warehouseId: $warehouseId,
                        variantId: $variant->id,
                        type: MovementType::IMPORT,
                        quantity: $onHand,
                        idempotencyKey: MovementKey::import((string) new UuidV7),
                        sourceType: 'import_row',
                    );
                }
            });

            return $variant;
        });
    }
}
