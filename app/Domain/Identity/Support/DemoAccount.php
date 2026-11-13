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
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceAccount;
use App\Domain\Orders\Actions\IngestChannelOrder;
use App\Domain\Orders\Models\Fulfillment;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Support\IncomingOrder;
use App\Domain\Orders\Support\IncomingOrderLine;
use App\Domain\Questions\Enums\QuestionStatus;
use App\Domain\Questions\Models\ChannelQuestion;
use App\Domain\Returns\Enums\ReturnStatus;
use App\Domain\Returns\Models\ReturnRequest;
use App\Domain\Returns\Models\ReturnRequestLine;
use App\Domain\Shipping\Models\CarrierAccount;
use App\Domain\Shipping\Models\CarrierShipment;
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

        // 13 Kas 2026 (kullanıcı kararı): demo kullanıcısı SAHİP rolündedir
        // ki ziyaretçi menünün TAMAMINI (Ayarlar, Ekip, Abonelik, Kargo,
        // Fatura) görsün. Yazma yine kapalıdır: `AuthorizeTenantRoute` demo
        // kiracısında rolden bağımsız olarak yalnız okuma isteğini geçirir.
        TenantUser::query()
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)
            ->update(['role' => TenantRole::OWNER->value]);

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
                // Örnek bağlantı "bağlı" görünür; `status` inactive kaldığı
                // için hiçbir iş kanala gitmez (ChannelHttpClient de keser).
                'health_status' => 'healthy',
                'last_healthy_at' => now()->subMinutes(4),
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
            ['MUM-SDR-200', 'El Yapımı Soya Mum · Sedir', '249.00', 5],
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
            // Dünün siparişleri: ana sayfadaki "dünden" kıyası anlamlı kalsın.
            ['hepsiburada', '4471208766', 'Delivered', 25, [['ORT-KTN-140', 2], ['MUM-LAV-200', 2]]],
            ['shopify', '#1041', 'fulfilled', 27, [['TRM-500-SYH', 3], ['KUP-350-BEYAZ', 2]]],
            ['trendyol', '10283902', 'Delivered', 29, [['TAB-BAM-L', 2], ['STD-AHS-01', 3]]],
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

        $this->fillModules($channels);
    }

    /**
     * Kargo, e-fatura, müşteri soruları ve iade — ziyaretçi modüllerin dolu
     * hâlini görsün (13 Kas 2026, kullanıcı isteği).
     *
     * DIŞARI İSTEK ÇIKMAZ:
     *   · kargo kaydında `confirmed_at` boş → `shipping:track` seçmez
     *   · fatura `issued` + `upload_status` sent, `public_token` yok → ne
     *     kesme ne yükleme ne PDF çekme işi tetiklenir
     *   · soru/iade senkronu `inactive` bağlantıya gitmez
     *   · kimlik bilgileri örnektir; demo kiracısında yazma rotası zaten 403
     *
     * @param  array<string, ChannelConnection>  $channels
     */
    private function fillModules(array $channels): void
    {
        $tenantId = $channels['trendyol']->tenant_id;
        $order = fn (string|int $number): Order => Order::query()->where('external_number', (string) $number)->firstOrFail();

        foreach ([CarrierAccount::PROVIDER_YURTICI, CarrierAccount::PROVIDER_ARAS] as $provider) {
            CarrierAccount::query()->create([
                'tenant_id' => $tenantId,
                'provider' => $provider,
                'environment' => CarrierAccount::ENV_LIVE,
                'credentials' => ['username' => 'ornekatolye', 'password' => 'ornek-parola'],
                'status' => CarrierAccount::STATUS_CONNECTED,
                'verified_at' => now()->subDays(18),
            ]);
        }

        // Teslim edilmiş paket + etiketi kesilmiş, kargoya verilmeyi bekleyen paket.
        $delivered = $order('#1043');
        CarrierShipment::query()->create([
            'tenant_id' => $tenantId,
            'order_id' => $delivered->id,
            'fulfillment_id' => Fulfillment::query()->where('order_id', $delivered->id)->value('id'),
            'provider' => CarrierAccount::PROVIDER_YURTICI,
            'environment' => CarrierAccount::ENV_LIVE,
            'cargo_key' => '1043-YK',
            'status' => CarrierShipment::STATUS_DELIVERED,
            'carrier_status_text' => 'Teslim edildi',
            'tracking_number' => '30584712206',
            'package_count' => 1,
            'attempts' => 1,
            'create_requested_at' => now()->subHours(25),
            'shipped_at' => now()->subHours(20),
            'delivered_at' => now()->subHours(2),
        ]);

        CarrierShipment::query()->create([
            'tenant_id' => $tenantId,
            'order_id' => $order('10284498')->id,
            'provider' => CarrierAccount::PROVIDER_ARAS,
            'environment' => CarrierAccount::ENV_LIVE,
            'cargo_key' => '10284498-AR',
            'status' => CarrierShipment::STATUS_CREATED,
            'carrier_status_text' => 'Kargo şubesine teslim bekleniyor',
            'tracking_number' => '6081734452',
            'package_count' => 1,
            'attempts' => 1,
            'create_requested_at' => now()->subHours(5),
        ]);

        InvoiceAccount::query()->create([
            'tenant_id' => $tenantId,
            'provider' => InvoiceAccount::PROVIDER_NILVERA,
            'credentials' => ['api_key' => 'demo'],
            'company_id' => '1234567890',
            'company_name' => 'Örnek Atölye Tic. Ltd. Şti.',
            'settings' => [
                InvoiceAccount::SETTING_ENVIRONMENT => InvoiceAccount::ENVIRONMENT_LIVE,
                'companies' => [['id' => '1234567890', 'name' => 'Örnek Atölye Tic. Ltd. Şti.']],
            ],
            'status' => InvoiceAccount::STATUS_CONNECTED,
        ]);

        foreach (['#1043' => 'EAR2026000000118', '10283977' => 'EAR2026000000117'] as $number => $invoiceNumber) {
            Invoice::query()->create([
                'tenant_id' => $tenantId,
                'order_id' => $order($number)->id,
                'provider' => InvoiceAccount::PROVIDER_NILVERA,
                'status' => Invoice::STATUS_ISSUED,
                'document_type' => Invoice::TYPE_E_ARCHIVE,
                'kind' => Invoice::KIND_SALE,
                'invoice_number' => $invoiceNumber,
                'attempts' => 1,
                'issued_at' => now()->subHours(22),
                'upload_status' => Invoice::UPLOAD_SENT,
                'upload_attempts' => 1,
                'uploaded_at' => now()->subHours(21),
            ]);
        }

        // [kanal, soru, ürün, kaç saat önce, cevap]
        foreach ([
            ['trendyol', 'Kupa bulaşık makinesinde yıkanabilir mi?', 'Seramik Kupa 350 ml · Beyaz', 4, null],
            ['hepsiburada', 'Mumun yanma süresi ne kadar?', 'El Yapımı Soya Mum · Lavanta', 9, null],
            ['trendyol', 'Masa örtüsü yıkanınca çeker mi?', 'Keten Masa Örtüsü 140×180', 30, 'Ön yıkamalı keten kullanıyoruz; 30 derecede yıkandığında çekme olmaz.'],
        ] as $i => [$code, $body, $product, $hoursAgo, $answer]) {
            ChannelQuestion::query()->create([
                'tenant_id' => $tenantId,
                'channel_connection_id' => $channels[$code]->id,
                'channel_type_code' => $code,
                'external_question_id' => 'demo-q-'.$i,
                'product_name' => $product,
                'body' => $body,
                'asked_at' => now()->subHours($hoursAgo),
                'expires_at' => now()->subHours($hoursAgo)->addDays(3),
                'status' => $answer === null ? QuestionStatus::AWAITING_ANSWER->value : QuestionStatus::ANSWERED->value,
                'answer_body' => $answer,
                'answered_at' => $answer === null ? null : now()->subHours($hoursAgo - 2),
                'last_seen_at' => now()->subMinutes(10),
            ]);
        }

        // Teslim edilen siparişten iade: ürün depoya ulaştı, karar bekliyor.
        $returned = $order('10283977');
        $line = $returned->lines()->first();
        $claim = ReturnRequest::query()->create([
            'tenant_id' => $tenantId,
            'channel_connection_id' => $channels['trendyol']->id,
            'channel_type_code' => 'trendyol',
            'external_claim_id' => '48213907',
            'external_order_number' => '10283977',
            'order_id' => $returned->id,
            'match_status' => 'matched',
            'status' => ReturnStatus::AWAITING_ACTION->value,
            'claim_type' => 'return',
            'claimed_at' => now()->subHours(6),
            'last_seen_at' => now()->subMinutes(10),
        ]);
        ReturnRequestLine::query()->create([
            'tenant_id' => $tenantId,
            'return_request_id' => $claim->id,
            'channel_connection_id' => $channels['trendyol']->id,
            'external_item_id' => '48213907-1',
            'sku' => $line?->sku,
            'product_name' => $line?->title,
            'quantity' => 1,
            'order_line_id' => $line?->id,
            'variant_id' => $line?->variant_id,
            'match_status' => 'matched',
            'status' => ReturnStatus::AWAITING_ACTION->value,
            'reason_text' => 'Ürün beklentimi karşılamadı',
            'receipt_proven_at' => now()->subHours(3),
            'restock_status' => ReturnRequestLine::RESTOCK_PENDING,
        ]);
    }
}
