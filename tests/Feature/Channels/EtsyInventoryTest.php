<?php

declare(strict_types=1);

namespace Tests\Feature\Channels;

use App\Domain\Catalog\Models\Variant;
use App\Domain\Channels\Adapters\Etsy\EtsyAdapter;
use App\Domain\Channels\Adapters\Etsy\EtsyInventoryMerger;
use App\Domain\Channels\Contracts\SupportsInventory;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Models\ChannelType;
use App\Domain\Channels\Support\ChannelHttpClient;
use App\Domain\Channels\Support\CredentialVault;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use App\Domain\Sync\Models\Listing;
use App\Domain\Sync\Support\InventoryPushBatch;
use App\Domain\Sync\Support\InventoryPushItem;
use App\Support\Logging\PayloadRedactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Etsy stok — slice 3.5 · **PROJENİN EN TEHLİKELİ MADDESİ**.
 *
 * V3.0 · §11.3 · P0 ("envanter yazma kardeş varyantları KORUR").
 *
 * ═════════════════════════════════════════════════════════════════════
 * ⚠️ `PUT .../inventory` TÜM ENVANTERİ EZER
 * ═════════════════════════════════════════════════════════════════════
 * Etsy KISMİ GÜNCELLEME DESTEKLEMEZ: gövde neyi taşıyorsa ilanın
 * envanteri O OLUR. Gönderilmeyen her varyant KANALDAN SİLİNİR.
 *
 * Üç varyantlı bir üründe birinin stoğunu güncelleyen bir istek,
 * ötekilerin ikisini kaldırır. Kanal 200 döner (yani senkron BAŞARILI
 * görünür), işlem GERİ ALINAMAZ ve satıcı bunu ancak siparişler
 * kesilince fark eder.
 *
 * Bu dosyadaki testlerin çoğu tek bir soruyu sorar: KARDEŞ VARYANT
 * GÖVDEDE DURUYOR MU?
 */
final class EtsyInventoryTest extends TestCase
{
    use RefreshDatabase;

    // ══════════════════════════════ P0 · kardeş varyantlar korunur

    /**
     * ⚠️ EN ÖNEMLİ TEST: YAZILAN GÖVDE TÜM VARYANTLARI TAŞIR.
     *
     * Tek varyantın stoğu değişse bile gövde ÜÇÜNÜ birden taşımalıdır.
     * Taşımasaydı diğer ikisi kanaldan SİLİNİRDİ.
     */
    #[Test]
    public function the_written_body_carries_every_sibling_variant(): void
    {
        $body = $this->pushAndCaptureBody(['TSH-M' => 7]);

        $skus = array_column($body['products'], 'sku');

        sort($skus);

        $this->assertSame(
            ['TSH-L', 'TSH-M', 'TSH-S'],
            $skus,
            'Yazma gövdesi kardeş varyantları KAYBETTİ — o varyantlar '
            .'kanaldan SİLİNİR, işlem geri alınamaz ve satıcı bunu ancak '
            .'siparişler kesilince fark eder.',
        );
    }

    /** Bizim kalemimizin miktarı DEĞİŞİR — mutlak değer yazılır. */
    #[Test]
    public function our_own_quantity_is_updated(): void
    {
        $body = $this->pushAndCaptureBody(['TSH-M' => 7]);

        $this->assertSame(7, $this->offeringOf($body, 'TSH-M')['quantity']);
    }

    /**
     * ⚠️ KARDEŞ VARYANTIN MİKTARI KANALDAKİ DEĞERDE KALIR.
     *
     * Sıfırlansaydı ya da bizim değerimiz yazılsaydı, satıcının bizden
     * habersiz sattığı ürünlerin stoğu SESSİZCE bozulurdu.
     */
    #[Test]
    public function sibling_quantities_keep_their_channel_values(): void
    {
        $body = $this->pushAndCaptureBody(['TSH-M' => 7]);

        $this->assertSame(3, $this->offeringOf($body, 'TSH-S')['quantity']);
        $this->assertSame(11, $this->offeringOf($body, 'TSH-L')['quantity']);
    }

    /**
     * ⚠️ KARDEŞ VARYANTIN FİYATI DA KORUNUR.
     *
     * Etsy'nin offering nesnesi fiyatı da taşır; gövdede eksik
     * bırakılırsa kanal onu SIFIRLAR. Yani bir STOK turu sessizce bir
     * FİYAT sıfırlaması yapardı — §9'un "sessizce ezmek EN SIK ŞİKAYET"
     * kuralının en ağır biçimi.
     */
    #[Test]
    public function sibling_prices_are_preserved(): void
    {
        $body = $this->pushAndCaptureBody(['TSH-M' => 7]);

        // Okuma NESNE verir (`{amount: 1990, divisor: 100}`), yazma DÜZ
        // SAYI bekler: 19.90. Ham `amount` gönderilseydi fiyat 1990 TL
        // olurdu.
        $this->assertSame(19.90, $this->offeringOf($body, 'TSH-S')['price']);
        $this->assertSame(24.50, $this->offeringOf($body, 'TSH-L')['price']);
    }

    /**
     * ⚠️ VARYANT ÖZELLİKLERİ (beden/renk) KORUNUR.
     *
     * Atılsaydı çok varyantlı ürün TEK varyanta çöker ve ötekiler
     * silinirdi — miktar doğru yazılmış olsa bile.
     */
    #[Test]
    public function variant_properties_survive_the_merge(): void
    {
        $body = $this->pushAndCaptureBody(['TSH-M' => 7]);

        $product = $this->productOf($body, 'TSH-L');

        $this->assertSame(
            [['property_id' => 100, 'value_ids' => [3], 'values' => ['L']]],
            $product['property_values'],
        );
    }

    /**
     * ⚠️ OKUMA-ÖZEL KİMLİKLER GÖVDEYE KONMAZ.
     *
     * `product_id` ve `offering_id` kanalın ürettiği kimliklerdir;
     * yazma gövdesinde geri gönderilirlerse Etsy `VALIDATION` döner ve
     * o hata KALICIDIR — listing "düzeltilemez" damgasıyla ölürdü.
     */
    #[Test]
    public function read_only_identifiers_are_stripped(): void
    {
        $body = $this->pushAndCaptureBody(['TSH-M' => 7]);

        foreach ($body['products'] as $product) {
            $this->assertArrayNotHasKey('product_id', $product);

            foreach ($product['offerings'] as $offering) {
                $this->assertArrayNotHasKey('offering_id', $offering);
            }
        }
    }

    /**
     * ⚠️ OKUMA BAŞARISIZSA YAZMA HAKKI DA YOKTUR.
     *
     * Boş envanter okunduysa gövde yalnızca bizim varyantımızı taşır ve
     * kanaldaki DİĞER TÜM varyantlar silinirdi — tam olarak bu akışın
     * önlemek için var olduğu felaket. İstisna fırlatılır ve HİÇBİR
     * yazma yapılmaz.
     */
    #[Test]
    public function an_empty_read_never_leads_to_a_write(): void
    {
        Http::fake(['*' => Http::response(['products' => []], 200)]);

        $this->expectException(\Throwable::class);

        try {
            $this->push(['TSH-M' => 7]);
        } finally {
            Http::assertNotSent(fn ($request): bool => $request->method() === 'PUT');
        }
    }

    // ══════════════════════════════════════════════ akış ve gruplama

    /** Akış OKU-BİRLEŞTİR-YAZ'dır: önce GET, sonra PUT. */
    #[Test]
    public function the_flow_reads_before_it_writes(): void
    {
        $this->pushAndCaptureBody(['TSH-M' => 7]);

        $methods = [];

        Http::recorded(function ($request) use (&$methods): bool {
            $methods[] = $request->method();

            return true;
        });

        $this->assertSame(['GET', 'PUT'], $methods);
    }

    /**
     * ⚠️ AYNI İLANIN VARYANTLARI TEK ÇAĞRIDA GİDER.
     *
     * Gruplanmasaydı iki varyantlı bir ürünün ikinci çağrısı birincinin
     * yazdığını OKUMADAN ezerdi: ilk yazma kanala ulaşır, ikinci çağrı
     * ESKİ envanteri okur ve ilk değişikliği geri alır.
     */
    #[Test]
    public function variants_of_the_same_listing_go_in_one_call(): void
    {
        $this->fakeInventory();

        $this->push(['TSH-M' => 7, 'TSH-L' => 9]);

        // TEK GET + TEK PUT — kalem başına ayrı tur DEĞİL.
        Http::assertSentCount(2);
    }

    /** İki varyant birden güncellenirse İKİSİ de gövdede değişir. */
    #[Test]
    public function two_updated_variants_both_change(): void
    {
        $body = $this->pushAndCaptureBody(['TSH-M' => 7, 'TSH-L' => 9]);

        $this->assertSame(7, $this->offeringOf($body, 'TSH-M')['quantity']);
        $this->assertSame(9, $this->offeringOf($body, 'TSH-L')['quantity']);

        // Dokunulmayan kardeş yine korunur.
        $this->assertSame(3, $this->offeringOf($body, 'TSH-S')['quantity']);
    }

    /** Boş yükte çağrı YAPILMAZ — kota boşa harcanmaz. */
    #[Test]
    public function an_empty_batch_never_calls_the_channel(): void
    {
        Http::fake();

        $result = $this->adapter()->pushInventory(
            new InventoryPushBatch(channelConnectionId: 'x', items: [])
        );

        $this->assertTrue($result->successful);
        Http::assertNothingSent();
    }

    /**
     * ⚠️ İLAN KİMLİĞİ ÇÖZÜLEMEYEN YÜK SESSİZCE BAŞARILI DÖNMEZ.
     *
     * Dönseydi operasyon tamamlandı sanılır, `synced_version` ilerler ve
     * satır kanalda hiçbir şey değişmemişken "senkron" görünürdü
     * (v2.2 · §7).
     */
    #[Test]
    public function a_batch_without_parent_ids_fails_loudly(): void
    {
        Http::fake();

        [$tenant, $connection] = $this->connected();

        // `external_parent_id` YAZILMAMIŞ listing.
        $listing = $this->asTenant($tenant, fn (): Listing => Listing::factory()->create([
            'channel_connection_id' => $connection->id,
            'external_id' => '5001',
            'external_parent_id' => null,
        ]));

        $result = $this->asTenant($tenant, fn () => $this->adapterFor($connection)->pushInventory(
            new InventoryPushBatch(
                channelConnectionId: $connection->id,
                items: [new InventoryPushItem(
                    listingId: $listing->id,
                    externalId: '5001',
                    sku: 'TSH-M',
                    quantity: 7,
                    version: 1,
                )],
            )
        ));

        $this->assertTrue($result->failed());
        Http::assertNothingSent();
    }

    // ═══════════════════════════════════════════ mutabakat okuması

    /**
     * Uzak stok okunur — mutabakatın girdisi.
     *
     * ⚠️ İLAN BAŞINA TEK ÇAĞRI: aynı ilanın üç varyantı tek okumadan
     * çözülür. Varyant başına istek atılsaydı ölçek üç katına çıkar ve
     * Etsy'nin GÜNLÜK kotası mutabakat turlarıyla dolardı (§21).
     */
    #[Test]
    public function remote_stock_is_read_once_per_listing(): void
    {
        $this->fakeInventory();

        [$tenant, $connection] = $this->connected();

        // ⚠️ SKU KİRACI İÇİNDE TEKİLDİR — iki listing AYNI varsayılan
        // SKU ile kurulamaz. İki AYRI varyant kurulur ve ikisi de AYNI
        // Etsy ilanına bağlanır; testin sorusu tam olarak budur.
        $listings = $this->asTenant($tenant, fn (): array => [
            $this->listingFor($connection, '5001', 'TSH-M'),
            $this->listingFor($connection, '5000', 'TSH-S'),
        ]);

        $snapshot = $this->asTenant(
            $tenant,
            fn () => $this->adapterFor($connection)->fetchInventory($listings)
        );

        $this->assertSame(5, $snapshot->quantityFor('5001'));
        $this->assertSame(3, $snapshot->quantityFor('5000'));

        Http::assertSentCount(1);
    }

    /** Silinmiş ilan (404) turu ÇÖKERTMEZ — boş okunur. */
    #[Test]
    public function a_deleted_listing_does_not_crash_the_scan(): void
    {
        Http::fake(['*' => Http::response(['error' => 'not found'], 404)]);

        [$tenant, $connection] = $this->connected();

        $listings = $this->asTenant($tenant, fn (): array => [
            $this->listingFor($connection, '5001'),
        ]);

        $snapshot = $this->asTenant(
            $tenant,
            fn () => $this->adapterFor($connection)->fetchInventory($listings)
        );

        $this->assertTrue($snapshot->isEmpty());
    }

    // ═══════════════════════════════════════════════ saf birleştirici

    /**
     * ⚠️ BİRLEŞTİRİCİ HER ZAMAN GİRDİYLE AYNI SAYIDA ÜRÜN DÖNER.
     *
     * Eksik bir eleman, o varyantın kanaldan silinmesi demektir.
     */
    #[Test]
    public function the_merger_never_drops_a_product(): void
    {
        $merged = EtsyInventoryMerger::merge($this->products(), ['TSH-M' => 7]);

        $this->assertCount(3, $merged);
    }

    /**
     * ⚠️ YÜKTE OLMAYAN SKU'YA DOKUNULMAZ, ATILMAZ.
     *
     * Satıcının bizden habersiz eklediği varyant kanalda ne ise O KALIR.
     */
    #[Test]
    public function an_unknown_sku_is_left_untouched(): void
    {
        $merged = EtsyInventoryMerger::merge($this->products(), ['HIC-YOK' => 99]);

        $quantities = array_map(
            static fn (array $p): int => $p['offerings'][0]['quantity'],
            $merged,
        );

        $this->assertSame([3, 5, 11], $quantities);
    }

    /** Yetenek `instanceof` ile okunur. */
    #[Test]
    public function the_adapter_declares_the_inventory_capability(): void
    {
        $this->assertInstanceOf(SupportsInventory::class, $this->adapter());
    }

    /** Parti boyutu ilan başına 1 (§11.3). */
    #[Test]
    public function the_batch_size_is_one_per_listing(): void
    {
        $this->assertSame(1, $this->adapter()->maxInventoryBatchSize());
    }

    // ────────────────────────────────────────────────────────── yardımcılar

    /**
     * Yükü gönderir ve YAZILAN gövdeyi döner.
     *
     * @param  array<string, int>  $quantityBySku
     * @return array<string, mixed>
     */
    private function pushAndCaptureBody(array $quantityBySku): array
    {
        $this->fakeInventory();

        $this->push($quantityBySku);

        $written = null;

        Http::recorded(function ($request) use (&$written): bool {
            if ($request->method() === 'PUT') {
                $written = $request->data();
            }

            return true;
        });

        $this->assertIsArray($written, 'PUT isteği HİÇ atılmadı.');

        return $written;
    }

    /** @param array<string, int> $quantityBySku */
    private function push(array $quantityBySku): void
    {
        [$tenant, $connection] = $this->connected();

        $this->asTenant($tenant, function () use ($connection, $quantityBySku): void {
            $items = [];

            foreach ($quantityBySku as $sku => $quantity) {
                $listing = $this->listingFor($connection, self::PRODUCT_IDS[$sku], $sku);

                $items[] = new InventoryPushItem(
                    listingId: $listing->id,
                    externalId: self::PRODUCT_IDS[$sku],
                    sku: $sku,
                    quantity: $quantity,
                    version: 1,
                );
            }

            $this->adapterFor($connection)->pushInventory(new InventoryPushBatch(
                channelConnectionId: $connection->id,
                items: $items,
            ));
        });
    }

    /** SKU → Etsy product_id. */
    private const PRODUCT_IDS = ['TSH-S' => '5000', 'TSH-M' => '5001', 'TSH-L' => '5002'];

    private function fakeInventory(): void
    {
        Http::fake([
            '*' => Http::sequence()
                ->push(['products' => $this->products()], 200)
                ->push(['products' => $this->products()], 200)
                ->push(['products' => $this->products()], 200),
        ]);
    }

    /**
     * ÜÇ varyantlı gerçekçi envanter — ikisi bizim yükümüzde YOK.
     *
     * @return list<array<string, mixed>>
     */
    private function products(): array
    {
        return [
            [
                'product_id' => 5000,
                'sku' => 'TSH-S',
                'property_values' => [['property_id' => 100, 'value_ids' => [1], 'values' => ['S']]],
                'offerings' => [[
                    'offering_id' => 7000,
                    'quantity' => 3,
                    'is_enabled' => true,
                    'price' => ['amount' => 1990, 'divisor' => 100, 'currency_code' => 'TRY'],
                ]],
            ],
            [
                'product_id' => 5001,
                'sku' => 'TSH-M',
                'property_values' => [['property_id' => 100, 'value_ids' => [2], 'values' => ['M']]],
                'offerings' => [[
                    'offering_id' => 7001,
                    'quantity' => 5,
                    'is_enabled' => true,
                    'price' => ['amount' => 1990, 'divisor' => 100, 'currency_code' => 'TRY'],
                ]],
            ],
            [
                'product_id' => 5002,
                'sku' => 'TSH-L',
                'property_values' => [['property_id' => 100, 'value_ids' => [3], 'values' => ['L']]],
                'offerings' => [[
                    'offering_id' => 7002,
                    'quantity' => 11,
                    'is_enabled' => true,
                    'price' => ['amount' => 2450, 'divisor' => 100, 'currency_code' => 'TRY'],
                ]],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function productOf(array $body, string $sku): array
    {
        foreach ($body['products'] as $product) {
            if (($product['sku'] ?? null) === $sku) {
                return $product;
            }
        }

        $this->fail("Gövdede {$sku} YOK — o varyant kanaldan silinirdi.");
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function offeringOf(array $body, string $sku): array
    {
        return $this->productOf($body, $sku)['offerings'][0];
    }

    private function listingFor(
        ChannelConnection $connection,
        string $externalId,
        string $sku = 'TSH-M',
    ): Listing {
        $variant = Variant::factory()->create(['sku' => $sku]);

        return Listing::factory()->create([
            'channel_connection_id' => $connection->id,
            'variant_id' => $variant->id,
            'external_id' => $externalId,
            // TÜM varyantlar AYNI Etsy ilanına bağlıdır — gruplama
            // tam olarak bunu kullanır.
            'external_parent_id' => '9001',
        ]);
    }

    private function adapter(): EtsyAdapter
    {
        [$tenant, $connection] = $this->connected();

        return $this->asTenant($tenant, fn (): EtsyAdapter => $this->adapterFor($connection));
    }

    private function adapterFor(ChannelConnection $connection): EtsyAdapter
    {
        return new EtsyAdapter(
            $connection,
            new ChannelHttpClient(
                $connection,
                app(CredentialVault::class),
                app(PayloadRedactor::class),
            ),
        );
    }

    /** @return array{0: Tenant, 1: ChannelConnection} */
    private function connected(): array
    {
        if (isset($this->cached)) {
            return $this->cached;
        }

        $this->asSystem(fn (): ChannelType => ChannelType::query()->updateOrCreate(
            ['code' => 'etsy'],
            [
                'name' => 'Etsy',
                'kind' => 'marketplace',
                'adapter_class' => EtsyAdapter::class,
                'supports_webhooks' => false,
                'is_active' => false,
            ],
        ));

        $tenant = (new CreateTenant)->run(
            name: 'Etsy Stok '.uniqid(),
            owner: User::factory()->create(),
        );

        $connection = $this->asTenant($tenant, function (): ChannelConnection {
            $connection = ChannelConnection::factory()->create([
                'channel_type_code' => 'etsy',
                'external_account_id' => 'etsy-'.uniqid(),
                'status' => 'active',
                'settings' => [
                    EtsyAdapter::KEYSTRING_KEY => 'key-abc',
                    EtsyAdapter::SHOP_ID_KEY => '777',
                ],
            ]);

            app(CredentialVault::class)->store($connection, [
                'access_token' => '12345.token',
                'refresh_token' => '12345.refresh',
            ]);

            return $connection;
        });

        return $this->cached = [$tenant, $connection];
    }

    /** @var array{0: Tenant, 1: ChannelConnection}|null */
    private ?array $cached = null;
}
