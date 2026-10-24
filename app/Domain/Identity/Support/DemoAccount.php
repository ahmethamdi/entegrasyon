<?php

declare(strict_types=1);

namespace App\Domain\Identity\Support;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Variant;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Enums\TenantRole;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\TenantUser;
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
use App\Domain\Sync\Models\Listing;
use App\Domain\Sync\Models\ListingSyncState;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Uid\UuidV7;

/**
 * S12 · herkese açık demo hesabı — `/demo` tek tıkla salt okunur giriş.
 *
 * ÜÇ KURAL, ÜÇ AYRI KAPI (biri kaçarsa diğeri tutar):
 *   1. YAZMA YOK: demo kullanıcısı `viewer`dır VE demo kiracısında yalnız
 *      GET/HEAD geçer (`AuthorizeTenantRoute`). Rol yetmezdi: herkesin
 *      aynı hesapla girdiği yerde tek bir açık rota hesabı herkese bozardı.
 *   2. DIŞ İSTEK YOK: kanal bağlantıları `inactive` doğar (hiçbir tarama,
 *      yoklama, sağlık kontrolü ona dokunmaz) ve gönderim izinleri kapalıdır;
 *      yine de bir yol `ChannelHttpClient`'a ulaşırsa demo kiracısı orada
 *      reddedilir. Paraşüt ve kargo hesabı hiç kurulmaz.
 *   3. KİŞİSEL VERİ YOK: siparişlerde alıcı bilgisi (`customerRef`) boştur;
 *      ürünler uydurma ve markasızdır. Kiracının sahibi YOK: günlük uyarı
 *      postaları yalnız sahiplere gider (DispatchAlerts), demo kimseye
 *      posta göndermez; demo adresine doğrulama/sıfırlama postası da
 *      gitmez (User).
 *
 * Kanıt ekranı (`/reports/oversell`) demoda BOŞ kalır: fan-out hiç iş
 * açmadığı için uydurma gecikme/koruma sayısı üretilmez — demo ekran
 * görüntüsü satışta "kanıt" diye kullanılamasın.
 *
 * Veri GERÇEK YOLDAN girer (açılış stoğu IMPORT, sipariş
 * `IngestChannelOrder`) — `DemoStoreSeeder` ile aynı gerekçe. Üretimde
 * factory/faker yoktur (`--no-dev`), satırlar modelle yazılır.
 */
final class DemoAccount
{
    public const EMAIL = 'demo@demo.34pazar.com';

    public const TENANT_NAME = 'Örnek Mağaza (demo)';

    public const SITE_CACHE_KEY = 'site:demo-available';

    /** @var array<string, bool> */
    private static array $demoTenants = [];

    public static function isDemoTenant(?string $tenantId): bool
    {
        if ($tenantId === null) {
            return false;
        }

        return self::$demoTenants[$tenantId] ??= (bool) DB::table('tenants')->where('id', $tenantId)->value('is_demo');
    }

    public static function forget(): void
    {
        self::$demoTenants = [];
    }

    public static function user(): ?User
    {
        $user = User::query()->where('email', self::EMAIL)->first();

        if ($user === null) {
            return null;
        }

        $isMember = DB::table('tenant_users')
            ->join('tenants', 'tenants.id', '=', 'tenant_users.tenant_id')
            ->where('tenant_users.user_id', $user->id)
            ->where('tenants.is_demo', true)
            ->exists();

        return $isMember ? $user : null;
    }

    /**
     * Demo kiracısını kurar. `$fresh` eskisini silip yeniden kurar (sipariş
     * saatleri "1 saat önce" kalsın diye günlük yenilenir).
     *
     * @return array{tenant: Tenant, created: bool}
     */
    public function install(bool $fresh = false): array
    {
        $existing = Tenant::query()->where('is_demo', true)->first();

        if ($existing !== null && ! $fresh) {
            return ['tenant' => $existing, 'created' => false];
        }

        if ($existing !== null) {
            TenantContext::runAsSystem(fn () => $existing->delete());
        }

        self::forget();

        $user = User::query()->firstOrCreate(['email' => self::EMAIL], [
            'name' => 'Demo',
            // Parola bilinmez: giriş yalnız `/demo` ile olur.
            'password' => bcrypt(Str::random(64)),
        ]);
        $user->forceFill(['email_verified_at' => $user->email_verified_at ?? now()])->save();

        $tenant = (new CreateTenant)->run(name: self::TENANT_NAME, owner: $user);
        $tenant->forceFill(['is_demo' => true])->save();

        // Sahip yok: demo kullanıcısı salt okunur üyedir.
        TenantUser::query()
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)
            ->update(['role' => TenantRole::VIEWER->value]);

        TenantContext::set($tenant->id);

        try {
            $this->fill($tenant->defaultWarehouse()->id);
        } finally {
            TenantContext::clear();
        }

        // Sitedeki "Demoyu incele" bağlantısı önbellekten okunur (10 dk);
        // kurulumdan hemen sonra görünsün.
        Cache::forget(self::SITE_CACHE_KEY);

        return ['tenant' => $tenant, 'created' => true];
    }

    private function fill(string $warehouseId): void
    {
        $channels = [];

        foreach ([
            'trendyol' => ['Örnek Trendyol mağazası', 'demo-trendyol'],
            'hepsiburada' => ['Örnek Hepsiburada mağazası', 'demo-hepsiburada'],
            'shopify' => ['ornek-magaza.com', 'demo-shopify'],
        ] as $code => [$label, $account]) {
            $channels[$code] = ChannelConnection::query()->create([
                'channel_type_code' => $code,
                'label' => $label,
                'external_account_id' => $account,
                'status' => 'inactive',
                'settings' => [],
                'health_status' => 'unknown',
                'connected_at' => now()->subDays(20),
                'product_publish_enabled' => false,
                'price_sync_enabled' => false,
                'stock_sync_enabled' => false,
            ]);
        }

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
        $n = 0;

        foreach ($catalog as [$sku, $title, $price, $stock]) {
            $product = Product::query()->create([
                'sku' => $sku, 'title' => $title, 'description' => $title,
                'brand' => 'Örnek Atölye', 'status' => 'active', 'content_version' => 0,
            ]);
            $variant = Variant::query()->create([
                'product_id' => $product->id, 'sku' => $sku, 'price' => $price,
                'currency' => 'TRY', 'status' => 'active', 'content_version' => 0,
            ]);
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
                $n++;
                Listing::query()->create([
                    'channel_connection_id' => $connection->id,
                    'variant_id' => $variant->id,
                    'external_id' => 'demo-'.$n,
                    // Trendyol bir ürünü reddetmiş: "yapman gerekenler"
                    // listesinde gerçek bir madde görünsün.
                    'lifecycle_status' => $code === 'trendyol' && $sku === 'ORT-KTN-140' ? 'rejected' : 'live',
                    'listed_at' => now()->subDays(19),
                ]);
            }
        }

        // [kanal, sipariş no, durum, kaç saat önce, [[sku, adet]], tanınmayan sku?]
        $orders = [
            ['trendyol', '10284571', 'Created', 1, [['KUP-350-BEYAZ', 2]]],
            ['hepsiburada', '4471209833', 'Open', 2, [['MUM-LAV-200', 1], ['SBN-ZYT-3L', 1]]],
            ['shopify', '#1047', 'paid', 3, [['ORT-KTN-140', 1]]],
            ['trendyol', '10284540', 'Created', 4, [['TAB-BAM-L', 3]]],
            // Stokta 1 vardı, 2 satıldı: fazla satış örneği.
            ['shopify', '#1046', 'paid', 5, [['ATK-YUN-GRI', 2]]],
            ['trendyol', '10284498', 'Picking', 6, [['TRM-500-SYH', 1], ['STD-AHS-01', 2]]],
            ['hepsiburada', '4471209101', 'Open', 7, [['KUP-350-YESIL', 4]], 'KUP-350-MAVI'],
            ['shopify', '#1043', 'fulfilled', 26, [['MUM-SDR-200', 1]]],
            ['trendyol', '10283977', 'Delivered', 30, [['SBN-ZYT-3L', 2]]],
        ];

        foreach ($orders as $i => $row) {
            [$code, $number, $status, $hoursAgo, $items] = $row;
            $unknownSku = $row[5] ?? null;

            $lines = [];
            $total = 0.0;

            foreach ($items as $k => [$sku, $qty]) {
                $price = (string) $variants[$sku]->price;
                $total += (float) $price * $qty;
                $lines[] = new IncomingOrderLine(
                    externalLineId: (string) ($k + 1),
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

        // Kanal durumu: canlı ürünlerin stoğu "kanalla aynı" görünsün; yoksa
        // stok ekranı her ürünü "listelenmedi" gösterirdi. Senkron İŞİ
        // (sync_operations) yazılmaz — kanıt ekranı uydurma sayı göstermesin.
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

        foreach (['#1043' => ['Yurtiçi Kargo', '30584712206'], '10283977' => ['Trendyol Express', '7330018845921']] as $number => [$carrier, $tracking]) {
            $order = Order::query()->where('external_number', $number)->first();
            Fulfillment::query()->create([
                'tenant_id' => $order->tenant_id,
                'order_id' => $order->id,
                'carrier' => $carrier,
                'tracking_number' => $tracking,
                'status' => 'shipped',
                'shipped_at' => now()->subHours(20),
                'source' => Fulfillment::SOURCE_CHANNEL,
                'push_status' => null,
            ]);
        }
    }
}
