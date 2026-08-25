<?php

declare(strict_types=1);

namespace Tests\Feature\Channels;

use App\Domain\Channels\Adapters\Shopify\ShopifyAdapter;
use App\Domain\Channels\Adapters\Shopify\ShopifyOrderNormalizer;
use App\Domain\Channels\Contracts\SupportsOrders;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Models\ChannelType;
use App\Domain\Channels\Support\ChannelHttpClient;
use App\Domain\Channels\Support\CredentialVault;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use App\Domain\Messaging\Models\InboxMessage;
use App\Domain\Orders\Models\Order;
use App\Domain\Sync\Support\NormalizedOrderEvent;
use App\Support\Logging\PayloadRedactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Shopify sipariş webhook'u — slice 1.7.
 *
 * V3.0 · §06.6 · §19 · v2.2 §1 · Karar 24 · §6.
 *
 * ─────────────────────────────────────────────────────────────────────
 * ⚠️ İPTAL AYRI KONUDA GELİR — WOO'NUN TERSİ
 * ─────────────────────────────────────────────────────────────────────
 * Woo'da iptal `order.updated` topic'iyle gelir ve `WooOrderNormalizer`
 * DURUM ALANININ TOPIC'İ EZMESİNİ gerektirir. Shopify'da `orders/cancelled`
 * AYRI BİR KONUDUR ve o ezme kuralı BURAYA KOPYALANMAZ: kopyalansaydı
 * `cancelled_at` dolu bir `orders/updated` olayı (iptal edilmiş siparişin
 * sonraki güncellemesi) YENİDEN iptal sanılır ve stok İKİNCİ KEZ geri
 * eklenirdi — bakiye kalıcı olarak ŞİŞERDİ.
 *
 * ⚠️ İADE `refunds/create` KONUSUNDADIR. Yalnızca sipariş konusu
 * dinlenseydi iade HİÇ görülmez ve stok geri eklenmezdi.
 */
final class ShopifyOrderTest extends TestCase
{
    use RefreshDatabase;

    // ────────────────────────────────────────────────────────── tip ayrımı

    /** `orders/create` yeni sipariş yaratır — stok SALE ile düşer. */
    #[Test]
    public function the_create_topic_becomes_a_created_event(): void
    {
        $event = $this->normalize('orders/create');

        $this->assertNotNull($event);
        $this->assertSame('created', $event->type);
    }

    /** `orders/updated` stok hareketi ÜRETMEZ (§06.6 tablosu). */
    #[Test]
    public function the_updated_topic_becomes_an_updated_event(): void
    {
        $event = $this->normalize('orders/updated');

        $this->assertSame('updated', $event?->type);
    }

    /** `orders/cancelled` AYRI konudur — stok geri eklenir. */
    #[Test]
    public function the_cancelled_topic_becomes_a_cancelled_event(): void
    {
        $event = $this->normalize('orders/cancelled', [
            'cancelled_at' => '2026-08-25T12:00:00Z',
        ]);

        $this->assertSame('cancelled', $event?->type);
    }

    /**
     * ⚠️ BU TESTİN VARLIK SEBEBİ — WOO'NUN EZME KURALI BURAYA KOPYALANMAZ.
     *
     * `cancelled_at` DOLU ama konu `orders/updated`: bu, iptal edilmiş bir
     * siparişin SONRAKİ güncellemesidir (etiket eklenmesi, not yazılması).
     * Woo'nun "durum topic'i ezer" kuralı uygulansaydı bu olay YENİDEN
     * iptal sanılır ve stok İKİNCİ KEZ geri eklenirdi.
     *
     * İptal idempotency çıpası (`order_events`) ikinci hareketi eler ama
     * buna GÜVENİLMEZ: çıpa aynı `external_ref` içindir ve bu olayın
     * kendi olay kimliği FARKLIDIR.
     */
    #[Test]
    public function an_update_on_a_cancelled_order_is_not_re_cancelled(): void
    {
        $event = $this->normalize('orders/updated', [
            'cancelled_at' => '2026-08-25T12:00:00Z',
            'financial_status' => 'refunded',
        ]);

        $this->assertSame(
            'updated',
            $event?->type,
            'İptal edilmiş siparişin güncellemesi yeniden iptal sanıldı — '
            .'stok ikinci kez geri eklenir ve bakiye kalıcı şişer.',
        );
    }

    /** `refunds/create` iade yoludur — sipariş konusunda DEĞİL. */
    #[Test]
    public function the_refund_topic_becomes_a_returned_event(): void
    {
        $event = $this->normalizeRefund();

        $this->assertSame('returned', $event?->type);
    }

    /**
     * ⚠️ İADE GÖVDESİNDE SİPARİŞ KİMLİĞİ `order_id`'DİR, `id` DEĞİL.
     *
     * `id` iadenin KENDİ kimliğidir. Okunsaydı iade HİÇ VAR OLMAYAN bir
     * siparişe bağlanır, `OrderEventRouter` siparişi bulamaz ve stok geri
     * EKLENMEZDİ — bakiye kalıcı eksik kalırdı. Üstelik hata sessizdir:
     * router yalnızca "eşleşmeyen sipariş" uyarısı yazar.
     *
     * Bu ayrım iade gövdesinin kökünün FARKLI olmasından doğar ve sipariş
     * konularında karşılığı yoktur; ayrı test ister.
     */
    #[Test]
    public function the_refund_order_id_is_the_order_not_the_refund(): void
    {
        $event = $this->normalizeRefund();

        $this->assertSame(
            '9001',
            $event?->externalOrderId,
            'İadenin kendi kimliği sipariş kimliği sanıldı — iade var olmayan '
            .'bir siparişe bağlanır ve stok geri eklenmez.',
        );
    }

    /** `fulfillments/*` kargo yoludur ve stok hareketi ÜRETMEZ. */
    #[Test]
    public function the_fulfillment_topics_become_fulfilled_events(): void
    {
        $this->assertSame('fulfilled', $this->normalize('fulfillments/create')?->type);
        $this->assertSame('fulfilled', $this->normalize('fulfillments/update')?->type);
    }

    /**
     * ⚠️ BİLİNMEYEN KONU `updated` SAYILIR — `created` DEĞİL.
     *
     * Yoklama kuralının aynısı: `created` var olan siparişi yeniden
     * yaratmayı denerdi, `cancelled` satılmış stoğu geri eklerdi; ikisi de
     * bakiyeyi bozar. `updated` stok hareketi ÜRETMEZ ve güvenli taraftır.
     */
    #[Test]
    public function an_unknown_topic_falls_back_to_updated(): void
    {
        $this->assertSame('updated', $this->normalize('orders/edited')?->type);
    }

    // ─────────────────────────────────────────────────────────────── kimlik

    /**
     * Sipariş kimliği YOKSA olay üretilmez.
     *
     * Sessizce yutulmaz: inbox satırı hata durumuna düşer ve elle
     * incelenir (Woo'daki kuralın aynısı).
     */
    #[Test]
    public function a_payload_without_an_order_id_yields_nothing(): void
    {
        $message = $this->message('orders/create', ['line_items' => []]);

        $this->assertNull(ShopifyOrderNormalizer::normalize($message));
    }

    /**
     * ⚠️ OLAY ÇIPASI BAŞLIKTAN GELEN `X-Shopify-Event-Id`'DİR.
     *
     * §19'un tablosu bunu BİRİNCİL kimlik olarak yazar. Türetilmiş kimlik
     * (`{id}:{type}`) yalnızca başlık YOKSA kullanılır — kanal gerçek bir
     * olay kimliği veriyorken uydurmak, aynı olayın iki farklı çıpayla iki
     * kez işlenmesine kapı açardı.
     */
    #[Test]
    public function the_event_ref_comes_from_the_shopify_event_id_header(): void
    {
        $event = $this->normalize('orders/create', externalEventId: 'evt-abc-123');

        $this->assertSame('evt-abc-123', $event?->externalRef);
    }

    /**
     * ⚠️ BAŞLIK YOKSA ÇIPA TİPİ DE TAŞIR — yalnızca sipariş kimliği DEĞİL.
     *
     * Yalnızca numaraya bağlansaydı aynı siparişin sonraki İPTALİ birincil
     * tekillik indeksine takılır ve `insertOrIgnore` tarafından SESSİZCE
     * YUTULURDU — stok geri eklenmez, bakiye kalıcı eksik kalırdı
     * (v2.2 · Karar 24'ün açıkça uyardığı hata biçimi).
     */
    #[Test]
    public function without_the_header_the_ref_carries_the_type_too(): void
    {
        $created = $this->normalize('orders/create', externalEventId: null);
        $cancelled = $this->normalize('orders/cancelled', externalEventId: null);

        $this->assertSame('9001:created', $created?->externalRef);
        $this->assertSame('9001:cancelled', $cancelled?->externalRef);
        $this->assertNotSame(
            $created?->externalRef,
            $cancelled?->externalRef,
            'İptal ve yaratma aynı çıpayı taşıdı — iptal sessizce yutulur.',
        );
    }

    // ──────────────────────────────────────────────────────────────── kalem

    /** Kalemler SKU, miktar ve fiyatla taşınır. */
    #[Test]
    public function line_items_are_mapped_to_canonical_lines(): void
    {
        $event = $this->normalize('orders/create');

        $line = $event?->payload['lines'][0] ?? [];

        $this->assertSame('TSH-KIRMIZI-M', $line['sku'] ?? null);
        $this->assertSame(2, $line['quantity'] ?? null);
        $this->assertSame('19.90', $line['unit_price'] ?? null);
    }

    /**
     * ⚠️ SKU'SUZ KALEM DÜŞÜRÜLMEZ — boş SKU ile taşınır.
     *
     * Shopify'da SKU zorunlu DEĞİLDİR. Kalem atılsaydı sipariş EKSİK
     * kaydedilir ve satıcı neyin gelmediğini bulamazdı; boş SKU ile
     * taşındığında `order_lines.variant_id` NULL kalır, satır PENDING olur
     * ve stok düşülmez. SİPARİŞ KAYBETMEK STOK TUTARSIZLIĞINDAN KÖTÜDÜR
     * (Karar 24).
     */
    #[Test]
    public function a_line_without_a_sku_is_kept_not_dropped(): void
    {
        $event = $this->normalize('orders/create', [
            'line_items' => [
                ['id' => 1, 'sku' => null, 'title' => 'SKU\'suz ürün', 'quantity' => 1, 'price' => '5.00'],
            ],
        ]);

        $lines = $event?->payload['lines'] ?? [];

        $this->assertCount(1, $lines, 'SKU\'suz kalem düşürüldü — sipariş eksik kaydedilir.');
        $this->assertSame('', $lines[0]['sku']);
    }

    /**
     * ⚠️ İADE KALEMLERİ `refund_line_items`'TAN OKUNUR ve MİKTAR POZİTİFTİR.
     *
     * `ApplyMovement` DAİMA pozitif miktar bekler ve yönü hareket
     * TÜRÜNDEN türetir; negatif taşınsaydı çağıranın işaret hesaplaması
     * gerekir ve "eksi mi artı mı" hatası mümkün olurdu.
     *
     * ⚠️ KALEM KİMLİĞİ `line_item_id`'DİR, iade satırının kendi `id`'si
     * DEĞİL. Kendi kimliği taşınsaydı `toAffectedLines()` onu sipariş
     * satırlarında BULAMAZ ve iade kalemi sessizce atlanırdı — stok geri
     * eklenmez, bakiye kalıcı eksik kalırdı.
     */
    #[Test]
    public function refund_lines_are_read_from_refund_line_items_with_the_order_line_id(): void
    {
        $event = $this->normalizeRefund();

        $line = $event?->payload['lines'][0] ?? [];

        $this->assertSame(2, $line['quantity'] ?? null, 'İade miktarı pozitif taşınmadı.');
        $this->assertSame(
            '555',
            $line['external_line_id'] ?? null,
            'İade satırının kendi kimliği taşındı — sipariş satırı bulunamaz ve stok geri eklenmez.',
        );
    }

    // ──────────────────────────────────────────────────────────────── toplam

    /**
     * ⚠️ TOPLAMLAR `current_*` ALANLARINDAN OKUNUR.
     *
     * Shopify kısmi iade veya sipariş düzenlemesinden SONRA `total_price`
     * alanını ORİJİNAL değerde bırakır ve güncel tutarı `current_total_price`
     * içinde verir. Orijinal okunsaydı kısmen iade edilmiş bir siparişin
     * paneldeki tutarı GERÇEKTEN tahsil edilenden yüksek görünürdü.
     */
    #[Test]
    public function totals_are_read_from_the_current_fields(): void
    {
        $event = $this->normalize('orders/updated', [
            'total_price' => '100.00',
            'current_total_price' => '60.00',
            'current_subtotal_price' => '50.00',
            'current_total_tax' => '10.00',
        ]);

        $payload = $event?->payload ?? [];

        $this->assertSame('60.00', $payload['grand_total'] ?? null);
        $this->assertSame('50.00', $payload['subtotal'] ?? null);
        $this->assertSame('10.00', $payload['tax_total'] ?? null);
    }

    /** `current_*` yoksa orijinal alana düşülür — alan kaybolmaz. */
    #[Test]
    public function totals_fall_back_to_the_original_fields(): void
    {
        $event = $this->normalize('orders/create', [
            'total_price' => '100.00',
            'current_total_price' => null,
        ]);

        $this->assertSame('100.00', $event?->payload['grand_total'] ?? null);
    }

    /**
     * ⚠️ KARGO TUTARI `_set.shop_money` ALTINDADIR.
     *
     * Shopify para alanlarını iki para biriminde döndürür (`shop_money`,
     * `presentment_money`). Satıcının muhasebesi MAĞAZA para birimidir;
     * `presentment_money` alıcının gördüğü kurdur ve okunsaydı yabancı
     * müşterili siparişte tutar yanlış para biriminde kaydedilirdi.
     */
    #[Test]
    public function the_shipping_total_is_read_from_shop_money(): void
    {
        $event = $this->normalize('orders/create', [
            'total_shipping_price_set' => [
                'shop_money' => ['amount' => '15.00', 'currency_code' => 'TRY'],
                'presentment_money' => ['amount' => '0.45', 'currency_code' => 'USD'],
            ],
        ]);

        $this->assertSame('15.00', $event?->payload['shipping_total'] ?? null);
    }

    // ──────────────────────────────────────────────────────────── kişisel veri

    /**
     * ⚠️ KİŞİSEL VERİ KANONİK YÜKE TAŞINMAZ — yalnızca referans.
     *
     * Woo normalizer'ındaki kuralın aynısı: `PayloadRedactor` e-postayı ve
     * adı zaten maskeler, ama kanonik yükte HİÇ TUTMAMAK daha güvenlidir.
     */
    #[Test]
    public function customer_personal_data_is_not_carried_into_the_payload(): void
    {
        $event = $this->normalize('orders/create', [
            'customer' => ['id' => 77, 'email' => 'alici@example.com', 'first_name' => 'Ayşe'],
            'email' => 'alici@example.com',
        ]);

        $encoded = json_encode($event?->payload ?? []);

        $this->assertStringNotContainsString('alici@example.com', (string) $encoded);
        $this->assertStringNotContainsString('Ayşe', (string) $encoded);
        $this->assertSame('77', $event?->payload['customer_ref']['external_customer_id'] ?? null);
    }

    // ────────────────────────────────────────────────────────────── yetenek

    /** Yetenek `instanceof` ile okunur ve adapter normalizer'a bağlanır. */
    #[Test]
    public function the_adapter_declares_the_orders_capability(): void
    {
        $adapter = $this->adapter();

        $this->assertInstanceOf(SupportsOrders::class, $adapter);
        $this->assertSame('created', $adapter->parseOrderEvent($this->message('orders/create'))?->type);
    }

    /**
     * ⚠️ SHOPIFY YOKLANMAZ — webhook gönderir (§19 · `supports_webhooks`).
     *
     * `fetchOrders` çağrılırsa bu bir PROGRAMLAMA HATASIDIR ve sessizce boş
     * sayfa dönmek onu gizlerdi: yoklama turu her seferinde "sipariş yok"
     * der ve kimse sebebini aramazdı. Yazılmamış yetenek SESSİZCE BAŞARILI
     * DÖNMEZ (v2.2 · adapter kuralı).
     */
    #[Test]
    public function polling_is_not_supported_and_says_so_loudly(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->adapter()->fetchOrders(now());
    }

    /**
     * Ayrı bir onay adımı YOKTUR — sipariş webhook ile gelir ve kabul
     * edilmiş sayılır. Sözleşme gereği başarı döner (Woo ile aynı).
     */
    #[Test]
    public function acknowledging_an_order_is_a_no_op_that_succeeds(): void
    {
        $order = new Order;

        $this->assertTrue($this->adapter()->acknowledgeOrder($order)->successful);
    }

    // ────────────────────────────────────────────────────────── yardımcılar

    /** @param array<string, mixed> $overrides */
    private function normalize(
        string $topic,
        array $overrides = [],
        ?string $externalEventId = 'evt-default',
    ): ?NormalizedOrderEvent {
        return ShopifyOrderNormalizer::normalize(
            $this->message($topic, [...$this->orderPayload(), ...$overrides], $externalEventId)
        );
    }

    private function normalizeRefund(): ?NormalizedOrderEvent
    {
        return ShopifyOrderNormalizer::normalize($this->message('refunds/create', [
            'id' => 4242,
            'order_id' => 9001,
            'refund_line_items' => [[
                'id' => 111,
                'quantity' => 2,
                'line_item_id' => 555,
                'subtotal' => '39.80',
                'line_item' => ['id' => 555, 'sku' => 'TSH-KIRMIZI-M', 'title' => 'Tişört', 'price' => '19.90'],
            ]],
        ]));
    }

    /** @return array<string, mixed> */
    private function orderPayload(): array
    {
        return [
            'id' => 9001,
            'order_number' => 1042,
            'currency' => 'TRY',
            'financial_status' => 'paid',
            'created_at' => '2026-08-25T10:00:00Z',
            'current_total_price' => '54.80',
            'current_subtotal_price' => '39.80',
            'current_total_tax' => '0.00',
            'total_shipping_price_set' => [
                'shop_money' => ['amount' => '15.00', 'currency_code' => 'TRY'],
            ],
            'line_items' => [[
                'id' => 555,
                'sku' => 'TSH-KIRMIZI-M',
                'title' => 'Tişört',
                'quantity' => 2,
                'price' => '19.90',
            ]],
        ];
    }

    /** @param array<string, mixed> $payload */
    private function message(
        string $topic,
        ?array $payload = null,
        ?string $externalEventId = 'evt-default',
    ): InboxMessage {
        $message = new InboxMessage;

        $message->event_type = $topic;
        $message->external_event_id = $externalEventId;
        $message->payload = $payload ?? $this->orderPayload();

        return $message;
    }

    private function adapter(): ShopifyAdapter
    {
        $tenant = $this->makeTenant();

        return $this->asTenant($tenant, function (): ShopifyAdapter {
            $connection = ChannelConnection::factory()->create([
                'channel_type_code' => 'shopify',
                'external_account_id' => 'magaza.myshopify.com',
                'settings' => ['location_gid' => 'gid://shopify/Location/12'],
            ]);

            app(CredentialVault::class)->store($connection, ['access_token' => 'shpat_test']);

            return new ShopifyAdapter(
                $connection,
                new ChannelHttpClient(
                    $connection,
                    app(CredentialVault::class),
                    app(PayloadRedactor::class),
                ),
            );
        });
    }

    private function makeTenant(): Tenant
    {
        $this->asSystem(fn (): ChannelType => ChannelType::query()->updateOrCreate(
            ['code' => 'shopify'],
            [
                'name' => 'Shopify',
                'kind' => 'storefront',
                'adapter_class' => ShopifyAdapter::class,
                'supports_webhooks' => true,
                'is_active' => false,
            ],
        ));

        return (new CreateTenant)->run(
            name: 'Shopify Sipariş '.uniqid(),
            owner: User::factory()->create(),
        );
    }
}
