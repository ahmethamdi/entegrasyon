<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Variant;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Actions\ApplyMovement;
use App\Domain\Inventory\Actions\LockInventoryRows;
use App\Domain\Inventory\Enums\MovementType;
use App\Domain\Inventory\Support\MovementKey;
use App\Domain\Orders\Actions\IngestChannelOrder;
use App\Domain\Orders\Models\Fulfillment;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Support\IncomingOrder;
use App\Domain\Orders\Support\IncomingOrderLine;
use App\Domain\Sync\Enums\SyncIntent;
use App\Domain\Sync\Enums\SyncOperationStatus;
use App\Domain\Sync\Models\Listing;
use App\Domain\Sync\Models\ListingSyncState;
use App\Domain\Sync\Models\SyncOperation;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\Uid\UuidV7;

/**
 * Demo mağaza — tanıtım sitesi ekran görüntüleri ve yerel deneme için.
 *
 * `php artisan db:seed --class=DemoStoreSeeder`
 * Giriş: demo@34pazar.local / Demo-34Pazar-2026
 *
 * ÜRETİMDE ÇALIŞMAZ: gerçek kiracıların yanına sahte sipariş yazmak
 * metrikleri, faturalamayı ve destek ekranlarını kirletirdi.
 *
 * Veri GERÇEK YOLDAN girer (açılış stoğu ledger'a IMPORT, sipariş
 * `IngestChannelOrder`): satır elle yazılsaydı bakiye ile hareketler
 * ayrışır ve ekran görüntüsü olmayan bir durumu gösterirdi.
 */
final class DemoStoreSeeder extends Seeder
{
    public const EMAIL = 'demo@34pazar.local';

    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('Demo mağaza üretimde kurulmaz.');
        }

        if (User::query()->where('email', self::EMAIL)->exists()) {
            $this->command?->warn('Demo mağaza zaten var: '.self::EMAIL);

            return;
        }

        $user = User::factory()->create([
            'name' => 'Demo Satıcı',
            'email' => self::EMAIL,
            'password' => bcrypt('Demo-34Pazar-2026'),
            'email_verified_at' => now(),
        ]);

        $tenant = (new CreateTenant)->run(name: 'Atölye Nur', owner: $user);

        TenantContext::set($tenant->id);

        try {
            $this->fill($tenant->defaultWarehouse()->id);
        } finally {
            TenantContext::clear();
        }

        $this->command?->info('Demo mağaza hazır: '.self::EMAIL.' / Demo-34Pazar-2026');
    }

    private function fill(string $warehouseId): void
    {
        $channels = [
            'trendyol' => ChannelConnection::factory()->create([
                'channel_type_code' => 'trendyol', 'label' => 'Atölye Nur Trendyol', 'external_account_id' => '784512',
            ]),
            'shopify' => ChannelConnection::factory()->create([
                'channel_type_code' => 'shopify', 'label' => 'atolyenur.com', 'external_account_id' => 'atolyenur.myshopify.com',
            ]),
            'woocommerce' => ChannelConnection::factory()->create([
                'channel_type_code' => 'woocommerce', 'label' => 'Toptan sitesi', 'external_account_id' => 'toptan.atolyenur.com',
            ]),
        ];

        // [SKU, ad, fiyat, stok]
        $catalog = [
            ['KUP-350-BEYAZ', 'Seramik Kupa 350 ml · Beyaz', '189.90', 64],
            ['KUP-350-YESIL', 'Seramik Kupa 350 ml · Yağ Yeşili', '189.90', 23],
            ['MUM-LAV-200', 'El Yapımı Soya Mum · Lavanta', '249.00', 41],
            ['MUM-SDR-200', 'El Yapımı Soya Mum · Sedir', '249.00', 7],
            ['TAB-BAM-L', 'Bambu Kesme Tahtası · Büyük', '329.00', 18],
            ['ORT-KTN-140', 'Keten Masa Örtüsü 140×180', '899.00', 9],
            ['SBN-ZYT-3L', 'Zeytinyağlı Sabun · 3\'lü', '159.00', 120],
            ['ATK-YUN-GRI', 'Yün Atkı · Antrasit', '549.00', 1],
            ['TRM-500-SYH', 'Paslanmaz Termos 500 ml · Siyah', '429.00', 32],
            ['STD-AHS-01', 'Ahşap Telefon Standı', '119.00', 55],
        ];

        $variants = [];

        foreach ($catalog as [$sku, $title, $price, $stock]) {
            $product = Product::factory()->create(['sku' => $sku, 'title' => $title, 'brand' => 'Atölye Nur', 'description' => $title]);
            $variant = Variant::factory()->create(['product_id' => $product->id, 'sku' => $sku, 'price' => $price]);
            $variants[$sku] = $variant;

            DB::transaction(function () use ($warehouseId, $variant, $stock): void {
                (new LockInventoryRows)->run($warehouseId, [$variant->id]);
                (new ApplyMovement)->run(
                    warehouseId: $warehouseId,
                    variantId: $variant->id,
                    type: MovementType::IMPORT,
                    quantity: $stock,
                    idempotencyKey: MovementKey::import((string) new UuidV7),
                    sourceType: 'import_row',
                );
            });

            foreach ($channels as $code => $connection) {
                Listing::factory()->create([
                    'channel_connection_id' => $connection->id,
                    'variant_id' => $variant->id,
                    // Trendyol bir ürünü reddetmiş: ana sayfadaki "yapman
                    // gerekenler" listesinde gerçek bir madde görünsün.
                    'lifecycle_status' => $code === 'trendyol' && $sku === 'ORT-KTN-140' ? 'rejected' : 'live',
                ]);
            }
        }

        // [kanal, sipariş no, durum, kaç saat önce, [[sku, adet]], tanınmayan sku?]
        $orders = [
            ['trendyol', '10284571', 'Created', 1, [['KUP-350-BEYAZ', 2]]],
            ['trendyol', '10284533', 'Created', 2, [['MUM-LAV-200', 1], ['SBN-ZYT-3L', 1]]],
            ['shopify', '#1047', 'paid', 3, [['ORT-KTN-140', 1]]],
            ['woocommerce', 'T-2231', 'processing', 4, [['TAB-BAM-L', 3]]],
            // Stokta 1 vardı, 2 satıldı: fazla satış örneği.
            ['shopify', '#1046', 'paid', 5, [['ATK-YUN-GRI', 2]]],
            ['trendyol', '10284498', 'Picking', 6, [['TRM-500-SYH', 1], ['STD-AHS-01', 2]]],
            ['woocommerce', 'T-2229', 'processing', 7, [['KUP-350-YESIL', 4]], 'KUP-350-MAVI'],
            ['shopify', '#1043', 'fulfilled', 26, [['MUM-SDR-200', 1]]],
            ['trendyol', '10283977', 'Delivered', 30, [['SBN-ZYT-3L', 2]]],
        ];

        foreach ($orders as $i => $row) {
            [$code, $number, $status, $hoursAgo, $items] = $row;
            $unknownSku = $row[5] ?? null;

            $lines = [];
            $total = 0.0;

            foreach ($items as $n => [$sku, $qty]) {
                $price = (string) $variants[$sku]->price;
                $total += (float) $price * $qty;
                $lines[] = new IncomingOrderLine(
                    externalLineId: (string) ($n + 1),
                    sku: $sku,
                    title: Product::query()->where('sku', $sku)->value('title'),
                    quantity: $qty,
                    variantId: $variants[$sku]->id,
                    unitPrice: $price,
                    lineTotal: number_format((float) $price * $qty, 2, '.', ''),
                );
            }

            if ($unknownSku !== null) {
                $lines[] = new IncomingOrderLine(
                    externalLineId: '9', sku: $unknownSku, title: 'Seramik Kupa 350 ml · Mavi',
                    quantity: 1, variantId: null, unitPrice: '189.90', lineTotal: '189.90',
                );
                $total += 189.90;
            }

            (new IngestChannelOrder)->run(new IncomingOrder(
                channelConnectionId: $channels[$code]->id,
                externalId: 'demo-'.$i.'-'.$number,
                lines: $lines,
                externalNumber: $number,
                status: $status,
                grandTotal: number_format($total, 2, '.', ''),
                subtotal: number_format($total, 2, '.', ''),
                placedAt: now()->subHours($hoursAgo),
            ), $warehouseId);
        }

        // Tamamlanmış gönderimler: kurulum şeridi kapanır (gerçek bir
        // satıcıda ilk ürün kanala ulaştığında da böyle olur).
        foreach (Listing::query()->where('lifecycle_status', 'live')->limit(12)->get() as $listing) {
            SyncOperation::query()->create([
                'tenant_id' => $listing->tenant_id,
                'channel_connection_id' => $listing->channel_connection_id,
                'operation_type' => 'INVENTORY_PUSH',
                'intent' => SyncIntent::NORMAL_SYNC,
                'entity_type' => 'listing',
                'entity_id' => $listing->id,
                'entity_version' => 1,
                'idempotency_key' => 'demo:inv:'.$listing->id,
                'status' => SyncOperationStatus::COMPLETED,
                'attempt_count' => 1,
                'completed_at' => now()->subMinutes(random_int(2, 90)),
            ]);
        }

        // Kanal durumu: canlı ürünlerin stoğu kanallarla aynı. Bu satırlar
        // olmasa stok ekranı her ürünü "listelenmedi" gösterirdi.
        foreach (Listing::query()->where('lifecycle_status', 'live')->get() as $listing) {
            ListingSyncState::query()->create([
                'tenant_id' => $listing->tenant_id,
                'listing_id' => $listing->id,
                'domain' => 'INVENTORY',
                'desired_version' => 1,
                'synced_version' => 1,
                'status' => 'synced',
                'last_synced_at' => now()->subMinutes(random_int(2, 90)),
            ]);
        }

        // Kargolanmış iki sipariş — biri kanaldan gelmiş, biri panelden girilmiş.
        foreach (['#1043' => ['Yurtiçi Kargo', '30584712206', Fulfillment::SOURCE_CHANNEL, null], '10283977' => ['Trendyol Express', '7330018845921', Fulfillment::SOURCE_CHANNEL, null]] as $number => [$carrier, $tracking, $source, $push]) {
            $order = Order::query()->where('external_number', $number)->first();
            Fulfillment::query()->create([
                'tenant_id' => $order->tenant_id,
                'order_id' => $order->id,
                'carrier' => $carrier,
                'tracking_number' => $tracking,
                'status' => 'shipped',
                'shipped_at' => now()->subHours(20),
                'source' => $source,
                'push_status' => $push,
            ]);
        }
    }
}
