<?php

declare(strict_types=1);

namespace App\Domain\Identity\Support;

use App\Domain\Catalog\Models\PriceCampaign;
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
use App\Domain\Orders\Models\OrderCharge;
use App\Domain\Orders\Support\IncomingOrder;
use App\Domain\Orders\Support\IncomingOrderLine;
use App\Domain\Questions\Enums\QuestionStatus;
use App\Domain\Questions\Models\ChannelQuestion;
use App\Domain\Reconciliation\Enums\ItemStatus;
use App\Domain\Reconciliation\Enums\ReconciliationScope;
use App\Domain\Reconciliation\Models\ReconciliationItem;
use App\Domain\Reconciliation\Models\ReconciliationRun;
use App\Domain\Returns\Enums\ReturnStatus;
use App\Domain\Returns\Models\ReturnRequest;
use App\Domain\Returns\Models\ReturnRequestLine;
use App\Domain\Shipping\Models\CarrierAccount;
use App\Domain\Shipping\Models\CarrierShipment;
use App\Domain\Suppliers\Enums\FieldState;
use App\Domain\Suppliers\Enums\RunStatus;
use App\Domain\Suppliers\Models\SupplierRecord;
use App\Domain\Suppliers\Models\SupplierSource;
use App\Domain\Suppliers\Models\SupplierSyncRun;
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
            'trendyol' => ['Örnek Trendyol mağazası', '384712'],
            'hepsiburada' => ['Örnek Hepsiburada mağazası', 'a3f1c9e2-5b7d-4e08-9c61-2d84f0b3a715'],
            'shopify' => ['ornek-magaza.com', 'ornek-magaza.myshopify.com'],
        ] as $code => [$label, $account]) {
            $channels[$code] = ChannelConnection::query()->create([
                'channel_type_code' => $code,
                'label' => $label,
                'external_account_id' => $account,
                'status' => 'inactive',
                // Hepsiburada ilan açmak için KDV oranı ister ("1 ayar eksik" görünmesin).
                'settings' => $code === 'hepsiburada' ? ['vat_rate' => 20] : [],
                // Örnek bağlantı "bağlı" görünür; `status` inactive kaldığı
                // için hiçbir iş kanala gitmez (ChannelHttpClient de keser).
                'health_status' => 'healthy',
                'last_healthy_at' => now()->subMinutes(4),
                'connected_at' => now()->subDays(20),
            ]);
            // Yazma izinleri AÇIK görünür. Bağlantı `inactive` ve demo
            // kiracısı ChannelHttpClient'ta kesildiği için hiçbir şey gitmez.
            $channels[$code]->forceFill([
                'product_publish_enabled' => true,
                'price_sync_enabled' => true,
                'stock_sync_enabled' => true,
                'return_decision_enabled' => true,
                'question_answer_enabled' => true,
            ])->save();
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
                    'external_id' => (string) (40211000 + $n * 17),
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

        $this->fillCatalogModules($channels);
        $this->fillContribution();
        $this->fillAccount($channels);
    }

    /**
     * Ekip, abonelik, gönderilemeyenler ve kategori eşleştirme.
     *
     * Ekip üyeleri sahip DEĞİL (uyarı postası almaz) ve adresleri
     * 34Pazar'ın kendi alt alanında: hiçbir yabancı kutuya posta gitmez.
     *
     * @param  array<string, ChannelConnection>  $channels
     */
    private function fillAccount(array $channels): void
    {
        $tenantId = $channels['trendyol']->tenant_id;

        foreach ([['Ayşe Kaya', 'ayse', TenantRole::ADMIN], ['Mert Demir', 'mert', TenantRole::WAREHOUSE]] as [$name, $local, $role]) {
            $member = User::query()->firstOrCreate(['email' => $local.'@demo.34pazar.com'], [
                'name' => $name,
                'password' => bcrypt(Str::random(64)),
            ]);
            $member->forceFill(['email_verified_at' => $member->email_verified_at ?? now()])->save();

            TenantUser::query()->create([
                'tenant_id' => $tenantId,
                'user_id' => $member->id,
                'role' => $role->value,
                'invited_at' => now()->subDays(15),
                'accepted_at' => now()->subDays(15),
            ]);
        }

        // Abonelik: Profesyonel, ödeme sağlayıcısı yok (hiçbir istek çıkmaz).
        DB::table('subscriptions')->insert([
            'id' => (string) new UuidV7,
            'tenant_id' => $tenantId,
            'plan_code' => 'pro',
            'status' => 'active',
            'started_at' => now()->subDays(20),
            'current_period_end' => now()->addDays(10),
            'created_at' => now()->subDays(20),
            'updated_at' => now()->subDays(20),
        ]);

        // Gönderilemeyenler: biri satıcının düzeltmesi gereken (kalıcı),
        // biri kanalın geçici hatası. Durum `dead` — kuyruk tekrar denemez.
        foreach ([
            ['hepsiburada', 'SBN-ZYT-3L', 'PRICE_PUSH', 'VALIDATION', 5, 'Fiyat, kategori için izin verilen en düşük tutarın altında (min. 175,00 TL).'],
            ['trendyol', 'TAB-BAM-L', 'INVENTORY_PUSH', 'SERVER_ERROR', 8, 'Trendyol servis hatası (503). Kanal o sırada cevap vermedi.'],
        ] as [$code, $sku, $type, $class, $attempts, $message]) {
            $listingId = Listing::query()
                ->where('channel_connection_id', $channels[$code]->id)
                ->where('variant_id', Variant::query()->where('sku', $sku)->value('id'))
                ->value('id');
            $opId = (string) new UuidV7;
            $at = now()->subHours(5);

            DB::table('sync_operations')->insert([
                'id' => $opId, 'tenant_id' => $tenantId, 'channel_connection_id' => $channels[$code]->id,
                'operation_type' => $type, 'intent' => 'NORMAL_SYNC', 'entity_type' => 'listing', 'entity_id' => $listingId,
                'entity_version' => 1, 'idempotency_key' => 'demo:'.$opId, 'status' => 'dead', 'attempt_count' => $attempts,
                'last_error_class' => $class, 'scheduled_at' => $at, 'created_at' => $at, 'updated_at' => $at->copy()->addMinutes(40),
            ]);

            for ($i = 1; $i <= $attempts; $i++) {
                DB::table('sync_attempts')->insert([
                    'id' => (string) new UuidV7, 'tenant_id' => $tenantId, 'sync_operation_id' => $opId, 'attempt_number' => $i,
                    'outcome' => 'failed', 'error_class' => $class, 'error_message' => $message,
                    'started_at' => $at->copy()->addMinutes($i * 5), 'finished_at' => $at->copy()->addMinutes($i * 5)->addSeconds(2),
                    'duration_ms' => 1800, 'created_at' => $at, 'updated_at' => $at,
                ]);
            }
        }

        // Kategori eşleştirme: iç kategoriler + Trendyol ağacında aynı adlı
        // yaprak varsa eşleşme (ağaç ortaktır; yalnız OKUNUR, yazılmaz).
        $categories = [
            'KUP-350' => ['Mutfak > Kupa', 'Kupa'],
            'MUM-' => ['Ev Dekorasyonu > Mum', 'Mum'],
            'TAB-' => ['Mutfak > Kesme Tahtası', 'Kesme Tahtası'],
            'ORT-' => ['Ev Tekstili > Masa Örtüsü', 'Masa Örtüsü'],
            'SBN-' => ['Kişisel Bakım > Sabun', 'Sabun'],
            'ATK-' => ['Aksesuar > Atkı', 'Atkı'],
            'TRM-' => ['Mutfak > Termos', 'Termos'],
            'STD-' => ['Telefon Aksesuarları > Stand', 'Telefon Tutucu'],
        ];

        foreach ($categories as $prefix => [$internal, $leafName]) {
            Product::query()->where('sku', 'like', $prefix.'%')->update(['internal_category_id' => $internal]);

            $leaf = DB::table('channel_categories')
                ->where('channel_type_code', 'trendyol')
                ->where('is_leaf', true)
                ->where('name', $leafName)
                ->orderBy('external_id')
                ->first(['id', 'taxonomy_version']);

            if ($leaf !== null) {
                DB::table('category_mappings')->insert([
                    'id' => (string) new UuidV7, 'tenant_id' => $tenantId, 'internal_category_id' => $internal,
                    'channel_type_code' => 'trendyol', 'channel_category_id' => $leaf->id,
                    'taxonomy_version' => $leaf->taxonomy_version, 'mapped_by' => 'user', 'verified_at' => now()->subDays(10),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    /**
     * Kanal onayları, kampanyalar, tedarikçi XML ve fiyat/stok kontrolü.
     * Zamanlanmış işler demo kiracısını atlar (TickPriceCampaigns,
     * SyncSuppliers); onay takibi `inactive` bağlantıya gitmez.
     *
     * @param  array<string, ChannelConnection>  $channels
     */
    private function fillCatalogModules(array $channels): void
    {
        $tenantId = $channels['trendyol']->tenant_id;
        $variant = fn (string $sku): Variant => Variant::query()->where('sku', $sku)->firstOrFail();
        $listing = fn (string $code, string $sku): Listing => Listing::query()
            ->where('channel_connection_id', $channels[$code]->id)
            ->where('variant_id', $variant($sku)->id)
            ->firstOrFail();

        // --- Kanal onayları: bir red (sebepli), iki onay bekleyen.
        $listing('trendyol', 'ORT-KTN-140')->forceFill([
            'approval_rejection_reason' => 'Ürün görselinde beyaz arka plan zorunludur; ana görsel kurala uymuyor.',
            'approval_checked_at' => now()->subHours(3),
        ])->save();

        foreach (['TRM-500-SYH', 'STD-AHS-01'] as $sku) {
            $listing('hepsiburada', $sku)->forceFill([
                'lifecycle_status' => 'pending_approval',
                'create_requested_at' => now()->subHours(20),
                'approval_checked_at' => now()->subMinutes(40),
            ])->save();
        }

        // --- Kampanyalar: süren, planlanan, biten. İtme zamanları dolu:
        // süren kampanya "kanala gitti" görünür, hiçbir iş tetiklenmez.
        foreach ([
            ['Kasım indirimi', PriceCampaign::TYPE_PERCENT, '15', -3, 10, ['trendyol', 'shopify'], ['MUM-LAV-200', 'MUM-SDR-200', 'KUP-350-BEYAZ', 'KUP-350-YESIL']],
            ['Yılbaşı fırsatı', PriceCampaign::TYPE_AMOUNT, '50', 18, 32, ['trendyol', 'hepsiburada', 'shopify'], ['ORT-KTN-140', 'TRM-500-SYH']],
            ['Ekim sonu', PriceCampaign::TYPE_PERCENT, '10', -30, -20, ['hepsiburada'], ['SBN-ZYT-3L']],
        ] as [$name, $type, $value, $startDays, $endDays, $codes, $skus]) {
            $campaign = (new PriceCampaign)->forceFill([
                'tenant_id' => $tenantId,
                'name' => $name,
                'discount_type' => $type,
                'discount_value' => $value,
                'starts_at' => now()->addDays($startDays)->startOfDay(),
                'ends_at' => now()->addDays($endDays)->endOfDay(),
                'show_compare_at' => true,
                'start_pushed_at' => $startDays <= 0 ? now()->addDays($startDays)->startOfDay()->addMinutes(2) : null,
                'end_pushed_at' => $endDays < 0 ? now()->addDays($endDays)->endOfDay()->addMinutes(2) : null,
            ]);
            $campaign->save();

            foreach ($codes as $code) {
                DB::table('price_campaign_connections')->insert([
                    'tenant_id' => $tenantId, 'price_campaign_id' => $campaign->id, 'channel_connection_id' => $channels[$code]->id,
                ]);
            }

            foreach ($skus as $sku) {
                DB::table('price_campaign_variants')->insert([
                    'tenant_id' => $tenantId, 'price_campaign_id' => $campaign->id, 'variant_id' => $variant($sku)->id,
                ]);
            }
        }

        // --- Tedarikçi XML: saatlik çalışan bir kaynak, üç başarılı tur.
        $source = SupplierSource::query()->create([
            'tenant_id' => $tenantId,
            'name' => 'Örnek Toptancı',
            'feed_url' => 'https://tedarikci.ornek-toptanci.com/xml/urunler.xml',
            'feed_host' => 'tedarikci.ornek-toptanci.com',
            'record_path' => 'Urunler/Urun',
            'mapping' => [
                'variant_path' => null,
                'fields' => ['key' => 'StokKodu', 'sku' => 'StokKodu', 'barcode' => 'Barkod', 'name' => 'UrunAdi', 'stock' => 'Stok', 'cost' => 'AlisFiyati', 'currency' => 'ParaBirimi'],
                'options' => [],
            ],
            'number_format' => SupplierSource::NUMBER_DOT,
            'currency' => 'TRY',
            'stock_policy' => SupplierSource::STOCK_INFORMATIONAL,
            'price_policy' => SupplierSource::PRICE_NONE,
            'create_products' => false,
        ]);

        $runIds = [];
        foreach ([3, 2, 1] as $hoursAgo) {
            $started = now()->subHours($hoursAgo)->subMinutes(35);
            $runIds[] = DB::table('supplier_sync_runs')->insertGetId([
                'id' => (string) new UuidV7,
                'tenant_id' => $tenantId,
                'supplier_source_id' => $source->id,
                'mode' => SupplierSyncRun::MODE_APPLY,
                'trigger' => SupplierSyncRun::TRIGGER_SCHEDULE,
                'status' => RunStatus::COMPLETE->value,
                'started_at' => $started,
                'finished_at' => $started->copy()->addSeconds(2),
                'duration_ms' => 2140,
                'bytes' => 48211,
                'records_seen' => 12,
                'counts' => json_encode(['records' => 12, 'matched' => 10, 'stock_changed' => $hoursAgo === 1 ? 2 : 0, 'cost_changed' => 0, 'unchanged' => $hoursAgo === 1 ? 8 : 10, 'unmatched' => 2]),
                'created_at' => $started,
                'updated_at' => $started,
            ], 'id');
        }

        $lastRun = end($runIds);
        $n = 0;
        foreach (Variant::query()->orderBy('sku')->get() as $v) {
            $n++;
            DB::table('supplier_records')->insert([
                'id' => (string) new UuidV7, 'tenant_id' => $tenantId, 'supplier_source_id' => $source->id,
                'source_key' => $v->sku, 'variant_id' => $v->id, 'sku' => $v->sku, 'barcode' => '86900000'.str_pad((string) $n, 5, '0', STR_PAD_LEFT),
                'name' => Product::query()->whereKey($v->product_id)->value('title'),
                'stock_state' => FieldState::PRESENT->value, 'stock_value' => 20 + $n * 7,
                'cost_state' => FieldState::PRESENT->value, 'cost_value' => number_format((float) $v->price * 0.45, 2, '.', ''),
                'price_state' => FieldState::MISSING->value, 'currency' => 'TRY',
                'presence' => SupplierRecord::PRESENT, 'match_status' => SupplierRecord::BOUND,
                'first_seen_run_id' => $runIds[0], 'last_seen_run_id' => $lastRun, 'last_seen_at' => now()->subMinutes(35),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        foreach ([['SRM-TBK-SET4', 'Seramik Tabak Seti · 4\'lü', 36], ['MUM-VNL-200', 'El Yapımı Soya Mum · Vanilya', 48]] as [$key, $name, $stock]) {
            DB::table('supplier_records')->insert([
                'id' => (string) new UuidV7, 'tenant_id' => $tenantId, 'supplier_source_id' => $source->id,
                'source_key' => $key, 'sku' => $key, 'name' => $name,
                'stock_state' => FieldState::PRESENT->value, 'stock_value' => $stock,
                'cost_state' => FieldState::PRESENT->value, 'cost_value' => '112.00',
                'price_state' => FieldState::MISSING->value, 'currency' => 'TRY',
                'presence' => SupplierRecord::PRESENT, 'match_status' => SupplierRecord::UNMATCHED,
                'first_seen_run_id' => $lastRun, 'last_seen_run_id' => $lastRun, 'last_seen_at' => now()->subMinutes(35),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $source->forceFill([
            'is_active' => true,
            'sync_state' => SupplierSource::STATE_IDLE,
            'last_attempted_at' => now()->subMinutes(35),
            'last_succeeded_at' => now()->subMinutes(35),
            'last_status' => RunStatus::COMPLETE->value,
            'last_duration_ms' => 2140,
            'next_run_at' => now()->addMinutes(25),
        ])->save();

        // --- Fiyat ve stok kontrolü: bir fiyat çakışması (karar bekliyor),
        // bir onarılmış stok farkı, kalanı eşit.
        $checked = now()->subMinutes(12);
        foreach ($channels as $code => $connection) {
            $run = ReconciliationRun::query()->create([
                'tenant_id' => $tenantId,
                'channel_connection_id' => $connection->id,
                'scope' => ReconciliationScope::WARM->value,
                'trigger_reason' => 'schedule',
                'started_at' => $checked,
                'finished_at' => $checked->copy()->addSeconds(4),
                'candidates_count' => 10,
                'checked_count' => 10,
                'drift_count' => $code === 'shopify' ? 0 : 1,
                'repaired_count' => $code === 'trendyol' ? 1 : 0,
                'status' => 'completed',
            ]);

            foreach (Listing::query()->where('channel_connection_id', $connection->id)->where('lifecycle_status', 'live')->get() as $l) {
                $sku = $variant('KUP-350-BEYAZ')->id === $l->variant_id ? 'KUP-350-BEYAZ' : ($variant('MUM-LAV-200')->id === $l->variant_id ? 'MUM-LAV-200' : null);
                $available = (int) DB::table('inventory_levels')->where('variant_id', $l->variant_id)->sum('available');

                [$domain, $status, $local, $remote, $magnitude, $reason] = match (true) {
                    $code === 'hepsiburada' && $sku === 'KUP-350-BEYAZ' => ['PRICE', ItemStatus::PRICE_CONFLICT, ['price' => '189.90'], ['price' => '209.90'], null, 'sampled'],
                    $code === 'trendyol' && $sku === 'MUM-LAV-200' => ['INVENTORY', ItemStatus::REPAIRED, ['available' => $available, 'expected_remote' => max(0, $available)], ['quantity' => max(0, $available) + 2], 2, 'recently_sold'],
                    default => ['INVENTORY', ItemStatus::MATCHED, ['available' => $available, 'expected_remote' => max(0, $available)], ['quantity' => max(0, $available)], 0, 'sampled'],
                };

                ReconciliationItem::query()->create([
                    'tenant_id' => $tenantId,
                    'reconciliation_run_id' => $run->id,
                    'listing_id' => $l->id,
                    'domain' => $domain,
                    'priority_reason' => $reason,
                    'status' => $status->value,
                    'local_value' => $local,
                    'remote_value' => $remote,
                    'drift_magnitude' => $magnitude,
                    'checked_at' => $checked,
                    'resolved_at' => $status === ItemStatus::PRICE_CONFLICT ? null : $checked,
                ]);
            }
        }
    }

    /**
     * Katkı raporu: satış anı net tutar ve maliyet + beklenen komisyon ve
     * kargo kesintisi (KDV %20). Hakediş (gerçekleşen) yazılmaz — "hakediş
     * yok" demo için de doğru durumdur.
     */
    private function fillContribution(): void
    {
        $commission = ['trendyol' => '0.18', 'hepsiburada' => '0.16', 'shopify' => '0.029'];
        $shipping = ['trendyol' => '-42.90', 'hepsiburada' => '-39.90', 'shopify' => '-54.90'];

        foreach (Order::query()->with('lines', 'connection:id,channel_type_code')->get() as $order) {
            $code = $order->connection?->channel_type_code;
            $sale = 0.0;

            foreach ($order->lines as $line) {
                $gross = (float) $line->line_total;
                $net = round($gross / 1.2, 2);
                $sale += $gross;
                $unitCost = $line->variant_id === null ? null : number_format((float) $line->unit_price * 0.45, 2, '.', '');

                $line->forceFill([
                    'vat_rate' => '20.00',
                    'vat_amount' => number_format($gross - $net, 2, '.', ''),
                    'amounts_include_vat' => true,
                    'net_line_total' => number_format($net, 2, '.', ''),
                    'unit_cost' => $unitCost,
                    'cost_currency' => $unitCost === null ? null : 'TRY',
                    'cost_captured_at' => $unitCost === null ? null : $order->placed_at,
                ])->save();
            }

            foreach (['commission' => number_format(-$sale * (float) ($commission[$code] ?? 0), 2, '.', ''), 'shipping_fee' => $shipping[$code] ?? '0.00'] as $kind => $amount) {
                OrderCharge::query()->create([
                    'tenant_id' => $order->tenant_id,
                    'order_id' => $order->id,
                    'channel_connection_id' => $order->channel_connection_id,
                    'kind' => $kind,
                    'basis' => OrderCharge::BASIS_EXPECTED,
                    'amount' => $amount,
                    'currency' => 'TRY',
                    'rate' => $kind === 'commission' ? $commission[$code] ?? null : null,
                    'source' => 'system',
                    'occurred_at' => $order->placed_at,
                ]);
            }
        }
    }
}
