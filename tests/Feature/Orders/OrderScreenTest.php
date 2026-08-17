<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Domain\Catalog\Models\Variant;
use App\Domain\Channels\Adapters\WooCommerce\WooCommerceAdapter;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Models\ChannelType;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Actions\ApplyMovement;
use App\Domain\Inventory\Actions\LockInventoryRows;
use App\Domain\Inventory\Enums\MovementType;
use App\Domain\Inventory\Support\MovementKey;
use App\Domain\Orders\Actions\IngestChannelOrder;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\OrderLine;
use App\Domain\Orders\Support\IncomingOrder;
use App\Domain\Orders\Support\IncomingOrderLine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\UuidV7;
use Tests\Concerns\AssertsLedgerIntegrity;
use Tests\TestCase;

/**
 * Sipariş listesi ekranı — kullanıcının siparişi göreceği tek yer.
 *
 * Mimari Karar Dokümanı v2.2 · §13 · faz 1.6 · "Panelde sipariş listesi ve
 * fazla satış uyarısı", §17 · P0 · "Fazla satış ekranı".
 *
 * DEĞİŞMEZ KURAL — FAZLA SATIŞ PANELDE GİZLENMEZ (§17 · P0):
 *   OVERSOLD satırlar UYARIYLA listelenir (§4 · order_lines). Ekranın varlık
 *   sebebi bu: sipariş alımı fazla satışı sessizce kabul ediyor ve satıcının
 *   bunu göreceği başka yer yok.
 *
 * DEĞİŞMEZ KURAL — EŞLEŞMEMİŞ SKU SİPARİŞİ KAYBETTİRMEZ:
 *   `variant_id` NULL olan satır PENDING kalır ve stok düşülmez. Ekran bunu
 *   AYRI bir uyarı olarak göstermeli: satıcı eşleştirmeyi yapana kadar o
 *   kalemin stoğu hiç düşmeyecek ve sessiz kalırsa bunu hiç öğrenmez.
 *
 * DEĞİŞMEZ KURAL — INERTIA'YA MODEL GÖNDERİLMEZ: yalnızca görünen alanlar.
 *   Sipariş modeli `customer_ref` taşır (§11 · maskelenmiş kişisel veri) ve
 *   bağlantı ilişkisi kimlik bilgisine açılır.
 */
final class OrderScreenTest extends TestCase
{
    use AssertsLedgerIntegrity;
    use RefreshDatabase;

    // ─────────────────────────────────────────────────── erişim

    /** Misafir sipariş ekranını göremez. */
    #[Test]
    public function guest_cannot_reach_the_order_screen(): void
    {
        $this->get('/orders')->assertRedirect('/login');
    }

    /**
     * BAŞKA KİRACININ SİPARİŞİ LİSTEDE GÖRÜNMEZ.
     *
     * Sipariş kişisel veri taşır (§11); sızıntı burada en pahalıdır.
     */
    #[Test]
    public function orders_of_other_tenants_are_not_visible(): void
    {
        [$tenantA, $userA, $warehouseA] = $this->makeTenant('A');
        [$tenantB, , $warehouseB] = $this->makeTenant('B');

        $this->placeOrder($tenantA, $warehouseA, sku: 'BENIM-1', onHand: 5, quantity: 1,
            externalNumber: 'A-1001');
        $this->placeOrder($tenantB, $warehouseB, sku: 'BASKASININ-1', onHand: 5, quantity: 1,
            externalNumber: 'B-2002');

        $rows = $this->rows($this->actingAs($userA)->get('/orders'));

        $this->assertCount(1, $rows);
        $this->assertSame('A-1001', $rows[0]['externalNumber']);
    }

    // ─────────────────────────────────────────────────── liste

    /** Liste sipariş numarasını, kanalı ve tutarı gösterir. */
    #[Test]
    public function list_shows_order_number_channel_and_total(): void
    {
        [$tenant, $user, $warehouseId] = $this->makeTenant();

        $this->placeOrder($tenant, $warehouseId, sku: 'ELMA-1', onHand: 10, quantity: 2,
            externalNumber: 'WC-501', grandTotal: '249.90');

        $rows = $this->rows($this->actingAs($user)->get('/orders'));

        $this->assertCount(1, $rows);
        $this->assertSame('WC-501', $rows[0]['externalNumber']);
        $this->assertSame('249.90', $rows[0]['grandTotal']);
        $this->assertSame('TRY', $rows[0]['currency']);
        $this->assertSame('woocommerce', $rows[0]['channel']['type']);
        $this->assertSame(1, $rows[0]['lineCount']);
        $this->assertSame(2, $rows[0]['itemCount']);
    }

    /**
     * INERTIA'YA MODEL GÖNDERİLMEZ.
     *
     * `customer_ref` maskelenmiş olsa bile ekranın işi değil; bağlantı
     * ilişkisi ise kimlik bilgisine açılan kapıdır.
     */
    #[Test]
    public function payload_carries_no_model_internals(): void
    {
        [$tenant, $user, $warehouseId] = $this->makeTenant();

        $this->placeOrder($tenant, $warehouseId, sku: 'GIZLI-1', onHand: 3, quantity: 1);

        $rows = $this->rows($this->actingAs($user)->get('/orders'));

        $this->assertArrayNotHasKey('customer_ref', $rows[0]);
        $this->assertArrayNotHasKey('channel_connection_id', $rows[0]);
        $this->assertArrayNotHasKey('tenant_id', $rows[0]);
    }

    /** En yeni sipariş üstte: satıcı önce bugünün siparişine bakar. */
    #[Test]
    public function newest_order_is_listed_first(): void
    {
        [$tenant, $user, $warehouseId] = $this->makeTenant();

        $this->placeOrder($tenant, $warehouseId, sku: 'ESKI-1', onHand: 5, quantity: 1,
            externalNumber: 'ESKI', placedAt: now()->subDays(3));
        $this->placeOrder($tenant, $warehouseId, sku: 'YENI-1', onHand: 5, quantity: 1,
            externalNumber: 'YENI', placedAt: now());

        $rows = $this->rows($this->actingAs($user)->get('/orders'));

        $this->assertSame(['YENI', 'ESKI'], array_column($rows, 'externalNumber'));
    }

    /**
     * SATIR SAYILARI BAŞKA KİRACININ SATIRLARINI SAYMAZ.
     *
     * Sayım sorgusu `DB::table()` üzerinden gider ve `DB::table()` Eloquent
     * global scope'una TABİ DEĞİLDİR — kiracı filtresi orada AÇIKÇA yazılmak
     * zorundadır. Yazılmazsa başka kiracının satırları bu kiracının
     * siparişinde sayılır: kalem sayısı şişer ve daha kötüsü, B kiracısının
     * OVERSOLD satırı A'nın siparişini fazla satış gibi gösterir.
     *
     * Testin kurgusu bunu görünür kılar: B kiracısının satırı A'nın SİPARİŞ
     * kimliğine bağlanır — çapraz kiracı sızıntısının gerçek biçimi budur.
     */
    #[Test]
    public function line_counts_never_include_another_tenants_lines(): void
    {
        [$tenantA, $userA, $warehouseA] = $this->makeTenant('A');
        [$tenantB] = $this->makeTenant('B');

        // A'nın siparişi stoklu ve temiz: tek kalem, fazla satış YOK.
        $this->placeOrder($tenantA, $warehouseA, sku: 'A-TEMIZ', onHand: 10, quantity: 1,
            externalNumber: 'A-1');

        $orderA = $this->asTenant($tenantA, fn (): Order => Order::query()->firstOrFail());

        // B kiracısı A'nın SİPARİŞ kimliğine OVERSOLD bir satır yazıyor.
        // Kiracı filtresi yoksa A'nın satırı "fazla satış" görünür.
        $this->asTenant($tenantB, fn () => OrderLine::query()->create([
            'tenant_id' => $tenantB->id,
            'order_id' => $orderA->id,          // A'nın siparişi — FK kiracı sınırını zorlamıyor
            'variant_id' => null,
            'external_line_id' => 'SIZINTI',
            'sku' => 'B-SIZINTI',
            'title' => 'Başka kiracının satırı',
            'quantity' => 7,
            'stock_status' => 'OVERSOLD',
        ]));

        $rows = $this->rows($this->actingAs($userA)->get('/orders'));

        $this->assertCount(1, $rows);
        $this->assertSame(
            1,
            $rows[0]['lineCount'],
            'Başka kiracının satırı sayılmamalı — DB::table() global scope\'a tabi değil.',
        );
        $this->assertSame(1, $rows[0]['itemCount'], 'Adet toplamı da sızmamalı.');
        $this->assertFalse(
            $rows[0]['hasOversold'],
            'B kiracısının OVERSOLD satırı A\'nın siparişini fazla satış gösteremez.',
        );
        $this->assertFalse($rows[0]['hasUnmatched']);
        $this->assertSame('APPLIED', $rows[0]['stockBadge']);
    }

    // ─────────────────────────────────────────────── fazla satış uyarısı

    /**
     * FAZLA SATIŞ UYARIYLA LİSTELENİR (§13 · faz 1.6, §17 · P0).
     *
     * Sıfır stokta iki adetlik sipariş: sipariş KABUL EDİLİR, bakiye −2'ye
     * düşer ve satır OVERSOLD işaretlenir. Ekran bunu göstermezse satıcı
     * gönderemeyeceği bir siparişi kabul ettiğini hiç öğrenmez.
     */
    #[Test]
    public function oversold_order_is_flagged_with_its_shortfall(): void
    {
        [$tenant, $user, $warehouseId] = $this->makeTenant();

        $variant = $this->placeOrder($tenant, $warehouseId, sku: 'FAZLA-1', onHand: 0,
            quantity: 2, externalNumber: 'FAZLA');

        $rows = $this->rows($this->actingAs($user)->get('/orders'));

        $this->assertTrue($rows[0]['hasOversold'], 'Fazla satılan sipariş işaretlenmeli.');
        $this->assertSame(1, $rows[0]['oversoldLineCount']);
        $this->assertSame('OVERSOLD', $rows[0]['stockBadge']);

        $this->assertLedgerMatchesProjection($tenant->id, $warehouseId, $variant->id);
    }

    /** Stok yeten sipariş uyarı taşımaz. */
    #[Test]
    public function fulfilled_order_carries_no_oversold_warning(): void
    {
        [$tenant, $user, $warehouseId] = $this->makeTenant();

        $variant = $this->placeOrder($tenant, $warehouseId, sku: 'IYI-1', onHand: 10, quantity: 2);

        $rows = $this->rows($this->actingAs($user)->get('/orders'));

        $this->assertFalse($rows[0]['hasOversold']);
        $this->assertSame(0, $rows[0]['oversoldLineCount']);
        $this->assertSame('APPLIED', $rows[0]['stockBadge']);

        $this->assertLedgerMatchesProjection($tenant->id, $warehouseId, $variant->id);
    }

    /**
     * ÜST ÖZET BAŞKA KİRACININ SİPARİŞİNİ SAYMAZ.
     *
     * Özet de `DB::table()` üzerinden gider ve kendi kiracı filtresini
     * taşır — satır sayımından AYRI bir sorgudur, dolayısıyla ayrı bir
     * boşluk olabilir. Sızıntı burada sessizdir: liste doğru görünürken
     * üstteki sayaç başka kiracının siparişlerini de sayar ve satıcı
     * "bende 12 sipariş var" der.
     */
    #[Test]
    public function summary_never_counts_another_tenants_orders(): void
    {
        [$tenantA, $userA, $warehouseA] = $this->makeTenant('A');
        [$tenantB, , $warehouseB] = $this->makeTenant('B');

        $this->placeOrder($tenantA, $warehouseA, sku: 'A-1', onHand: 5, quantity: 1);

        // B'nin iki siparişi var ve biri fazla satış: sızarsa hem toplam
        // hem de fazla satış sayacı şişer.
        $this->placeOrder($tenantB, $warehouseB, sku: 'B-1', onHand: 5, quantity: 1);
        $this->placeOrder($tenantB, $warehouseB, sku: 'B-2', onHand: 0, quantity: 3);

        $summary = $this->summary($this->actingAs($userA)->get('/orders'));

        $this->assertSame(1, $summary['orderCount'], 'Yalnızca A kiracısının siparişi sayılmalı.');
        $this->assertSame(
            0,
            $summary['oversoldOrderCount'],
            'B kiracısının fazla satışı A\'nın özetine sızmamalı.',
        );
    }

    /** Fazla satış filtresi yalnızca OVERSOLD satırlı siparişleri getirir. */
    #[Test]
    public function oversold_filter_narrows_the_list(): void
    {
        [$tenant, $user, $warehouseId] = $this->makeTenant();

        $this->placeOrder($tenant, $warehouseId, sku: 'F-1', onHand: 0, quantity: 1,
            externalNumber: 'FAZLA');
        $this->placeOrder($tenant, $warehouseId, sku: 'IYI-1', onHand: 9, quantity: 1,
            externalNumber: 'IYI');

        $rows = $this->rows($this->actingAs($user)->get('/orders?filter=oversold'));

        $this->assertCount(1, $rows);
        $this->assertSame('FAZLA', $rows[0]['externalNumber']);
    }

    /**
     * ÜST ÖZET FAZLA SATIŞI SAYAR — satıcı önce onu görmeli.
     *
     * Sayım SİPARİŞ bazındadır: "kaç siparişi eksik gönderiyorum" sorusunun
     * cevabı budur. Eksik ADET `inventory_levels.available` üzerinden okunur
     * (§10 metriği); burada ayrı bir sayaç tutulmaz.
     */
    #[Test]
    public function summary_counts_orders_needing_attention(): void
    {
        [$tenant, $user, $warehouseId] = $this->makeTenant();

        $this->placeOrder($tenant, $warehouseId, sku: 'F-1', onHand: 0, quantity: 1);
        $this->placeOrder($tenant, $warehouseId, sku: 'F-2', onHand: 0, quantity: 1);
        $this->placeOrder($tenant, $warehouseId, sku: 'IYI-1', onHand: 5, quantity: 1);

        $summary = $this->summary($this->actingAs($user)->get('/orders'));

        $this->assertSame(3, $summary['orderCount']);
        $this->assertSame(2, $summary['oversoldOrderCount']);
    }

    // ────────────────────────────────────────── eşleşmemiş SKU uyarısı

    /**
     * EŞLEŞMEMİŞ SKU AYRI UYARIDIR.
     *
     * `variant_id` NULL olan satırın stoğu HİÇ düşülmez ve satır PENDING
     * kalır. Bu fazla satıştan farklı bir sorundur: fazla satışta stok
     * düşmüştür ve eksik görünür, burada stok hiç dokunulmamıştır ve tablo
     * "her şey yolunda" der. Satıcı eşleştirmeyi yapana kadar o kalem
     * görünmez kalırsa stok kalıcı olarak fazla gösterilir.
     */
    #[Test]
    public function unmatched_sku_line_is_flagged_separately(): void
    {
        [$tenant, $user, $warehouseId] = $this->makeTenant();

        $connectionId = $this->connectionFor($tenant);

        $this->asTenant($tenant, fn () => (new IngestChannelOrder)->run(
            new IncomingOrder(
                channelConnectionId: $connectionId,
                externalId: 'ORD-ESLESMEYEN',
                lines: [new IncomingOrderLine(
                    externalLineId: 'L1',
                    sku: 'BIZDE-YOK',
                    title: 'Kataloğumuzda olmayan ürün',
                    quantity: 3,
                    variantId: null,          // eşleşme yok
                )],
                externalNumber: 'ESLESMEYEN',
                placedAt: now(),
            ),
            $warehouseId,
        ));

        $rows = $this->rows($this->actingAs($user)->get('/orders'));

        $this->assertTrue($rows[0]['hasUnmatched'], 'Eşleşmemiş SKU işaretlenmeli.');
        $this->assertSame(1, $rows[0]['unmatchedLineCount']);
        $this->assertFalse($rows[0]['hasOversold'], 'Eşleşmemiş satır fazla satış DEĞİLDİR.');
        $this->assertSame(
            'PENDING',
            $rows[0]['stockBadge'],
            'Stoğu hiç düşülmemiş sipariş "uygulandı" gösterilemez.',
        );
    }

    /** Eşleşmemiş SKU filtresi ayrı çalışır. */
    #[Test]
    public function unmatched_filter_narrows_the_list(): void
    {
        [$tenant, $user, $warehouseId] = $this->makeTenant();

        $connectionId = $this->connectionFor($tenant);

        $this->asTenant($tenant, fn () => (new IngestChannelOrder)->run(
            new IncomingOrder(
                channelConnectionId: $connectionId,
                externalId: 'ORD-YOK',
                lines: [new IncomingOrderLine(
                    externalLineId: 'L1', sku: 'BIZDE-YOK', title: 'Yok', quantity: 1,
                )],
                externalNumber: 'ESLESMEYEN',
                placedAt: now(),
            ),
            $warehouseId,
        ));

        $this->placeOrder($tenant, $warehouseId, sku: 'IYI-1', onHand: 5, quantity: 1,
            externalNumber: 'IYI');

        $rows = $this->rows($this->actingAs($user)->get('/orders?filter=unmatched'));

        $this->assertCount(1, $rows);
        $this->assertSame('ESLESMEYEN', $rows[0]['externalNumber']);
    }

    /**
     * ROZET SIRASI: FAZLA SATIŞ EŞLEŞMEMİŞTEN ÖNCE GELİR.
     *
     * İkisi de aynı siparişte olabilir. Fazla satış SATILMIŞ ve stoğu eksiye
     * düşmüş bir kalemdir — kargo çıkışı gerçekten tehlikededir. Eşleşmemiş
     * satır ise henüz stoğa dokunmamıştır ve düzeltmesi katalog işidir.
     */
    #[Test]
    public function oversold_outranks_unmatched_in_the_badge(): void
    {
        [$tenant, $user, $warehouseId] = $this->makeTenant();

        $connectionId = $this->connectionFor($tenant);

        $variant = $this->asTenant($tenant, fn (): Variant => Variant::factory()
            ->create(['sku' => 'KARISIK-1']));

        // Stok yok: bu satır OVERSOLD olacak. İkinci satır eşleşmiyor.
        $this->asTenant($tenant, fn () => (new IngestChannelOrder)->run(
            new IncomingOrder(
                channelConnectionId: $connectionId,
                externalId: 'ORD-KARISIK',
                lines: [
                    new IncomingOrderLine(
                        externalLineId: 'L1', sku: 'KARISIK-1', title: 'Var',
                        quantity: 1, variantId: $variant->id,
                    ),
                    new IncomingOrderLine(
                        externalLineId: 'L2', sku: 'BIZDE-YOK', title: 'Yok', quantity: 1,
                    ),
                ],
                externalNumber: 'KARISIK',
                placedAt: now(),
            ),
            $warehouseId,
        ));

        $rows = $this->rows($this->actingAs($user)->get('/orders'));

        $this->assertTrue($rows[0]['hasOversold']);
        $this->assertTrue($rows[0]['hasUnmatched']);
        $this->assertSame(
            'OVERSOLD',
            $rows[0]['stockBadge'],
            'Fazla satış eşleşmemişten önce gelmeli — kargo çıkışı tehlikede.',
        );

        $this->assertLedgerMatchesProjection($tenant->id, $warehouseId, $variant->id);
    }

    // ─────────────────────────────────────────────────── arama

    /** Sipariş numarasıyla arama çalışır. */
    #[Test]
    public function search_matches_order_number(): void
    {
        [$tenant, $user, $warehouseId] = $this->makeTenant();

        $this->placeOrder($tenant, $warehouseId, sku: 'A-1', onHand: 5, quantity: 1,
            externalNumber: 'WC-9001');
        $this->placeOrder($tenant, $warehouseId, sku: 'B-1', onHand: 5, quantity: 1,
            externalNumber: 'WC-7777');

        $rows = $this->rows($this->actingAs($user)->get('/orders?search=9001'));

        $this->assertCount(1, $rows);
        $this->assertSame('WC-9001', $rows[0]['externalNumber']);
    }

    /** SKU ile arama sipariş satırlarına bakar. */
    #[Test]
    public function search_matches_line_sku(): void
    {
        [$tenant, $user, $warehouseId] = $this->makeTenant();

        $this->placeOrder($tenant, $warehouseId, sku: 'ARANAN-42', onHand: 5, quantity: 1,
            externalNumber: 'BULUNAN');
        $this->placeOrder($tenant, $warehouseId, sku: 'DIGER-7', onHand: 5, quantity: 1,
            externalNumber: 'DIGER');

        $rows = $this->rows($this->actingAs($user)->get('/orders?search=aranan'));

        $this->assertCount(1, $rows);
        $this->assertSame('BULUNAN', $rows[0]['externalNumber']);
    }

    // ─────────────────────────────────────────────────── ayrıntı

    /** Ayrıntı ekranı satırları ve stok durumlarını gösterir. */
    #[Test]
    public function detail_screen_shows_lines_with_stock_status(): void
    {
        [$tenant, $user, $warehouseId] = $this->makeTenant();

        $this->placeOrder($tenant, $warehouseId, sku: 'AYRINTI-1', onHand: 0, quantity: 2,
            externalNumber: 'AYRINTI');

        $order = $this->asTenant($tenant, fn (): Order => Order::query()->firstOrFail());

        $props = $this->actingAs($user)->get("/orders/{$order->id}")
            ->assertOk()
            ->viewData('page')['props'];

        $this->assertSame('AYRINTI', $props['order']['externalNumber']);
        $this->assertCount(1, $props['order']['lines']);
        $this->assertSame('AYRINTI-1', $props['order']['lines'][0]['sku']);
        $this->assertSame(2, $props['order']['lines'][0]['quantity']);
        $this->assertSame('OVERSOLD', $props['order']['lines'][0]['stockStatus']);
    }

    /**
     * BAŞKA KİRACININ SİPARİŞ AYRINTISI 404 VERİR.
     *
     * Kimlik tahmin edilemez olsa bile yetkilendirme kimliğin gizliliğine
     * dayandırılmaz.
     */
    #[Test]
    public function detail_of_another_tenants_order_is_not_found(): void
    {
        [$tenantA, $userA, $warehouseA] = $this->makeTenant('A');
        [$tenantB, , $warehouseB] = $this->makeTenant('B');

        $this->placeOrder($tenantA, $warehouseA, sku: 'A-1', onHand: 5, quantity: 1);
        $this->placeOrder($tenantB, $warehouseB, sku: 'B-1', onHand: 5, quantity: 1);

        $foreign = $this->asTenant($tenantB, fn (): Order => Order::query()->firstOrFail());

        $this->actingAs($userA)->get("/orders/{$foreign->id}")->assertNotFound();
    }

    /** Ayrıntıda sipariş olayları geçmiş olarak listelenir. */
    #[Test]
    public function detail_screen_shows_order_events(): void
    {
        [$tenant, $user, $warehouseId] = $this->makeTenant();

        // Fazla satış OVERSELL_DETECTED denetim olayı yazar (§5).
        $this->placeOrder($tenant, $warehouseId, sku: 'OLAY-1', onHand: 0, quantity: 1);

        $order = $this->asTenant($tenant, fn (): Order => Order::query()->firstOrFail());

        $props = $this->actingAs($user)->get("/orders/{$order->id}")
            ->assertOk()
            ->viewData('page')['props'];

        $types = array_column($props['order']['events'], 'type');

        $this->assertContains('created', $types);
        $this->assertContains('OVERSELL_DETECTED', $types);
    }

    // ─────────────────────────────────────────────────── yardımcılar

    /** @return array<int, array<string, mixed>> */
    private function rows(TestResponse $response): array
    {
        $response->assertOk();

        return $response->viewData('page')['props']['rows'];
    }

    /** @return array<string, int> */
    private function summary(TestResponse $response): array
    {
        $response->assertOk();

        return $response->viewData('page')['props']['summary'];
    }

    /** @return array{0: Tenant, 1: User, 2: string} */
    private function makeTenant(string $name = 'Sipariş'): array
    {
        $user = User::factory()->create();
        $tenant = (new CreateTenant)->run(name: $name.' '.uniqid(), owner: $user);
        $warehouseId = $this->asTenant($tenant, fn () => $tenant->defaultWarehouse()->id);

        return [$tenant, $user, $warehouseId];
    }

    /** Kiracıya bir WooCommerce bağlantısı açar. */
    private function connectionFor(Tenant $tenant): string
    {
        $this->asSystem(fn () => ChannelType::query()->firstOrCreate(
            ['code' => 'woocommerce'],
            [
                'name' => 'WooCommerce',
                'kind' => 'storefront',
                'adapter_class' => WooCommerceAdapter::class,
                'is_active' => true,
            ],
        ));

        return $this->asTenant($tenant, fn (): string => ChannelConnection::factory()
            ->create(['channel_type_code' => 'woocommerce'])->id);
    }

    /**
     * Stoklu bir varyant açar ve GERÇEK ALIM YOLUNDAN sipariş geçirir.
     *
     * Açılış stoğu LEDGER üzerinden girer (IMPORT hareketi); sipariş
     * `IngestChannelOrder` ile alınır. Order satırını elle yazmak
     * `stock_status` alanını uydurmak demekti ve ekran gerçek veriyi değil
     * testin varsayımını doğrulardı.
     */
    private function placeOrder(
        Tenant $tenant,
        string $warehouseId,
        string $sku,
        int $onHand,
        int $quantity,
        string $externalNumber = 'SIP-1',
        string $grandTotal = '100.00',
        ?\DateTimeInterface $placedAt = null,
    ): Variant {
        $connectionId = $this->connectionFor($tenant);

        return $this->asTenant($tenant, function () use (
            $warehouseId, $sku, $onHand, $quantity, $externalNumber,
            $grandTotal, $placedAt, $connectionId,
        ): Variant {
            $variant = Variant::factory()->create(['sku' => $sku]);

            if ($onHand > 0) {
                DB::transaction(function () use ($warehouseId, $variant, $onHand): void {
                    (new LockInventoryRows)->run($warehouseId, [$variant->id]);

                    (new ApplyMovement)->run(
                        warehouseId: $warehouseId,
                        variantId: $variant->id,
                        type: MovementType::IMPORT,
                        quantity: $onHand,
                        idempotencyKey: MovementKey::import((string) new UuidV7),
                        sourceType: 'import_row',
                    );
                });
            }

            (new IngestChannelOrder)->run(
                new IncomingOrder(
                    channelConnectionId: $connectionId,
                    externalId: 'ORD-'.$externalNumber.'-'.uniqid(),
                    lines: [new IncomingOrderLine(
                        externalLineId: 'L1',
                        sku: $sku,
                        title: $sku.' ürünü',
                        quantity: $quantity,
                        variantId: $variant->id,
                        unitPrice: '50.00',
                        lineTotal: $grandTotal,
                    )],
                    externalNumber: $externalNumber,
                    grandTotal: $grandTotal,
                    placedAt: $placedAt ?? now(),
                ),
                $warehouseId,
            );

            return $variant;
        });
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Stok hareketi outbox olayı yazar; relay/tüketici bu ekranın konusu
        // değil ve sync sürücüde iş derhal çalışıp bağlamı temizlerdi.
        Queue::fake();
    }
}
