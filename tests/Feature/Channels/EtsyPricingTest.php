<?php

declare(strict_types=1);

namespace Tests\Feature\Channels;

use App\Domain\Catalog\Models\Variant;
use App\Domain\Channels\Adapters\Etsy\EtsyAdapter;
use App\Domain\Channels\Adapters\Etsy\EtsyInventoryMerger;
use App\Domain\Channels\Contracts\SupportsPricing;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Models\ChannelType;
use App\Domain\Channels\Registry\AdapterRegistry;
use App\Domain\Channels\Support\ChannelHttpClient;
use App\Domain\Channels\Support\CredentialVault;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use App\Domain\Sync\Actions\OpenSyncOperation;
use App\Domain\Sync\Enums\SyncDomain;
use App\Domain\Sync\Enums\SyncOperationStatus;
use App\Domain\Sync\Jobs\PushPrices;
use App\Domain\Sync\Models\Listing;
use App\Domain\Sync\Models\SyncOperation;
use App\Domain\Sync\Support\PriceBatchBuilder;
use App\Domain\Sync\Support\PricePushBatch;
use App\Domain\Sync\Support\SyncResultRecorder;
use App\Support\Logging\PayloadRedactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Etsy fiyat — slice 3.6.
 *
 * V3.0 · §11.3 ("stok ve fiyat — TEK uç nokta, TÜM envanter").
 *
 * ═════════════════════════════════════════════════════════════════════
 * ⚠️ FİYAT DE ENVANTER UÇ NOKTASINDA YAŞAR — YENİ UÇ NOKTA YOKTUR
 * ═════════════════════════════════════════════════════════════════════
 * Etsy'de offering nesnesi HEM miktarı HEM fiyatı taşır ve `PUT
 * .../inventory` TÜM envanteri ezer. Yani fiyat turu, stok turunun AYNA
 * GÖRÜNTÜSÜDÜR ve aynı oku-birleştir-yaz akışını kullanır.
 *
 * Slice 3.5'te tehlike "stok turu sessizce fiyatı sıfırlar"dı. Burada
 * TERSİ: **fiyat turu sessizce stoğu sıfırlayabilir** — ve bu daha ağır
 * bir hatadır, çünkü sıfır stok satışı DURDURUR.
 *
 * Bu dosyadaki testlerin çoğu tek bir soruyu sorar: KARDEŞ VARYANT VE
 * ONUN MİKTARI GÖVDEDE DURUYOR MU?
 */
final class EtsyPricingTest extends TestCase
{
    use RefreshDatabase;

    // ══════════════════════════════ P0 · fiyat turu stoğu BOZMAZ

    /**
     * ⚠️ EN ÖNEMLİ TEST: FİYAT TURU MİKTARLARA DOKUNMAZ.
     *
     * Offering nesnesi ikisini birden taşır; miktar gövdede eksik
     * bırakılsaydı ya da sıfırlansaydı kanal stoğu SIFIRLAR ve ürün
     * satışa kapanır. Sessizdir (kanal 200 döner) ve satıcı bunu ancak
     * siparişler kesilince fark eder.
     */
    #[Test]
    public function a_price_push_never_touches_quantities(): void
    {
        $body = $this->pushAndCaptureBody(['5001' => '29.90']);

        $this->assertSame(3, $this->offeringOf($body, 'TSH-S')['quantity']);
        $this->assertSame(5, $this->offeringOf($body, 'TSH-M')['quantity']);
        $this->assertSame(11, $this->offeringOf($body, 'TSH-L')['quantity']);
    }

    /**
     * ⚠️ YAZILAN GÖVDE TÜM VARYANTLARI TAŞIR — stoktakiyle aynı kural.
     *
     * Taşımasaydı gönderilmeyen varyantlar kanaldan SİLİNİRDİ.
     */
    #[Test]
    public function the_written_body_carries_every_sibling_variant(): void
    {
        $body = $this->pushAndCaptureBody(['5001' => '29.90']);

        $skus = array_column($body['products'], 'sku');

        sort($skus);

        $this->assertSame(['TSH-L', 'TSH-M', 'TSH-S'], $skus);
    }

    /** Bizim kalemimizin fiyatı DEĞİŞİR — mutlak değer yazılır. */
    #[Test]
    public function our_own_price_is_updated(): void
    {
        $body = $this->pushAndCaptureBody(['5001' => '29.90']);

        $this->assertSame(29.90, $this->offeringOf($body, 'TSH-M')['price']);
    }

    /**
     * ⚠️ KARDEŞ VARYANTIN FİYATI KANALDAKİ DEĞERDE KALIR.
     *
     * Bizim değerimiz ötekilere de yazılsaydı satıcının farklı fiyatlı
     * varyantları (küçük beden ucuz, büyük beden pahalı) TEK fiyata
     * çöker ve bu geri alınamazdı.
     */
    #[Test]
    public function sibling_prices_keep_their_channel_values(): void
    {
        $body = $this->pushAndCaptureBody(['5001' => '29.90']);

        $this->assertSame(19.90, $this->offeringOf($body, 'TSH-S')['price']);
        $this->assertSame(24.50, $this->offeringOf($body, 'TSH-L')['price']);
    }

    /**
     * ⚠️ FİYAT YAZMADA DÜZ SAYIDIR, OKUMADA NESNE.
     *
     * Okuma `{amount: 1990, divisor: 100}` verir. Ham `amount`
     * gönderilseydi 19.90 TL kanalda **1990 TL** olurdu; nesne geri
     * gönderilseydi Etsy `VALIDATION` döner ve o hata KALICIDIR.
     */
    #[Test]
    public function the_written_price_is_a_plain_number_not_an_object(): void
    {
        $body = $this->pushAndCaptureBody(['5001' => '29.90']);

        foreach ($body['products'] as $product) {
            foreach ($product['offerings'] as $offering) {
                $this->assertIsNumeric(
                    $offering['price'],
                    'Fiyat NESNE olarak gönderildi — Etsy VALIDATION döner '
                    .'ve o hata KALICIDIR.',
                );
                $this->assertLessThan(
                    1000,
                    $offering['price'],
                    'Ham `amount` gönderilmiş: 19.90 TL kanalda 1990 TL olur.',
                );
            }
        }
    }

    /**
     * ⚠️ VARYANT ÖZELLİKLERİ (beden/renk) KORUNUR.
     *
     * Atılsaydı çok varyantlı ürün TEK varyanta çöker — fiyat doğru
     * yazılmış olsa bile.
     */
    #[Test]
    public function variant_properties_survive_the_price_merge(): void
    {
        $body = $this->pushAndCaptureBody(['5001' => '29.90']);

        $this->assertSame(
            [['property_id' => 100, 'value_ids' => [3], 'values' => ['L']]],
            $this->productOf($body, 'TSH-L')['property_values'],
        );
    }

    /**
     * ⚠️ OKUMA-ÖZEL KİMLİKLER GÖVDEYE KONMAZ — stoktakiyle aynı kural.
     */
    #[Test]
    public function read_only_identifiers_are_stripped(): void
    {
        $body = $this->pushAndCaptureBody(['5001' => '29.90']);

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
     * Boş envanterle yazmak kanaldaki TÜM varyantları silerdi.
     */
    #[Test]
    public function an_empty_read_never_leads_to_a_write(): void
    {
        Http::fake(['*' => Http::response(['products' => []], 200)]);

        $this->expectException(\Throwable::class);

        try {
            $this->push(['5001' => '29.90']);
        } finally {
            Http::assertNotSent(fn ($request): bool => $request->method() === 'PUT');
        }
    }

    // ══════════════════════════════════════════════ akış ve gruplama

    /** Akış OKU-BİRLEŞTİR-YAZ'dır: önce GET, sonra PUT. */
    #[Test]
    public function the_flow_reads_before_it_writes(): void
    {
        $this->pushAndCaptureBody(['5001' => '29.90']);

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
     * Gruplanmasaydı ikinci çağrı birincinin yazdığını OKUMADAN ezerdi.
     */
    #[Test]
    public function variants_of_the_same_listing_go_in_one_call(): void
    {
        $this->fakeInventory();

        $this->push(['5001' => '29.90', '5002' => '34.00']);

        Http::assertSentCount(2);
    }

    /** İki varyant birden güncellenirse İKİSİ de gövdede değişir. */
    #[Test]
    public function two_updated_variants_both_change(): void
    {
        $body = $this->pushAndCaptureBody(['5001' => '29.90', '5002' => '34.00']);

        $this->assertSame(29.90, $this->offeringOf($body, 'TSH-M')['price']);
        $this->assertSame(34.00, $this->offeringOf($body, 'TSH-L')['price']);

        // Dokunulmayan kardeş yine korunur.
        $this->assertSame(19.90, $this->offeringOf($body, 'TSH-S')['price']);
    }

    /** Boş yükte çağrı YAPILMAZ — kota boşa harcanmaz. */
    #[Test]
    public function an_empty_batch_never_calls_the_channel(): void
    {
        Http::fake();

        $result = $this->adapter()->pushPrices(
            new PricePushBatch(channelConnectionId: 'x', items: [])
        );

        $this->assertTrue($result->successful);
        Http::assertNothingSent();
    }

    /**
     * ⚠️ İLAN KİMLİĞİ ÇÖZÜLEMEYEN YÜK SESSİZCE BAŞARILI DÖNMEZ.
     *
     * Dönseydi `synced_version` ilerler ve satır kanalda hiçbir şey
     * değişmemişken "senkron" görünürdü (v2.2 · §7).
     */
    #[Test]
    public function a_batch_without_parent_ids_fails_loudly(): void
    {
        Http::fake();

        [$tenant, $connection] = $this->connected();

        $listing = $this->asTenant($tenant, function () use ($connection): Listing {
            $variant = Variant::factory()->create(['sku' => 'TSH-M']);

            return Listing::factory()->create([
                'channel_connection_id' => $connection->id,
                'variant_id' => $variant->id,
                'external_id' => '5001',
                'external_parent_id' => null,
            ]);
        });

        $result = $this->asTenant($tenant, fn () => $this->adapterFor($connection)->pushPrices(
            new PricePushBatch(
                channelConnectionId: $connection->id,
                items: [[
                    'listing_id' => $listing->id,
                    'external_id' => '5001',
                    'price' => '29.90',
                    'version' => 1,
                ]],
            )
        ));

        $this->assertTrue($result->failed());
        Http::assertNothingSent();
    }

    // ═══════════════════════════════════════════ mutabakat okuması

    /**
     * Uzak fiyat okunur — mutabakatın ve §9 çakışma tespitinin girdisi.
     *
     * ⚠️ İLAN BAŞINA TEK ÇAĞRI — stoktakiyle aynı gerekçe (§21 kotası).
     */
    #[Test]
    public function remote_prices_are_read_once_per_listing(): void
    {
        $this->fakeInventory();

        [$tenant, $connection] = $this->connected();

        $listings = $this->asTenant($tenant, fn (): array => [
            $this->listingFor($connection, '5001', 'TSH-M'),
            $this->listingFor($connection, '5000', 'TSH-S'),
        ]);

        $snapshot = $this->asTenant(
            $tenant,
            fn () => $this->adapterFor($connection)->fetchPrices($listings)
        );

        // ⚠️ FİYAT STRING TAŞINIR — float karşılaştırması kuruş kayması
        // üretir (§7) ve `"19.9"` ile `"19.90"` metin olarak FARKLIDIR;
        // biçim `number_format(..., 2)` ile SABİTTİR.
        $this->assertSame('19.90', $snapshot->priceFor('5001'));
        $this->assertSame('19.90', $snapshot->priceFor('5000'));

        Http::assertSentCount(1);
    }

    /**
     * ⚠️ FARKLI FİYATLI VARYANTLAR AYRI OKUNUR.
     *
     * Hepsine ilk offering'in fiyatı yazılsaydı mutabakat, fiyatı
     * gerçekten farklı olan varyantlarda her tur SAHTE çakışma
     * raporlardı ve satıcı aynı kararı sonsuza kadar verirdi (§9).
     */
    #[Test]
    public function each_variant_reports_its_own_price(): void
    {
        $this->fakeInventory();

        [$tenant, $connection] = $this->connected();

        $listings = $this->asTenant($tenant, fn (): array => [
            $this->listingFor($connection, '5002', 'TSH-L'),
        ]);

        $snapshot = $this->asTenant(
            $tenant,
            fn () => $this->adapterFor($connection)->fetchPrices($listings)
        );

        $this->assertSame('24.50', $snapshot->priceFor('5002'));
    }

    /**
     * ⚠️ FİYATI OLMAYAN VARYANT SIFIR OKUNMAZ, ATLANIR.
     *
     * `"0"` yazılsaydı mutabakat "kanalda 0 TL" sanır ve `PRICE_CONFLICT`
     * açardı — satıcı VAR OLMAYAN bir fiyat için karar vermeye
     * zorlanırdı. Doğru sınıflandırma `REMOTE_MISSING`'dir (§10).
     */
    #[Test]
    public function a_variant_without_a_price_is_skipped_not_zeroed(): void
    {
        Http::fake(['*' => Http::response(['products' => [[
            'product_id' => 5001,
            'sku' => 'TSH-M',
            'offerings' => [['offering_id' => 7001, 'quantity' => 5, 'is_enabled' => true]],
        ]]], 200)]);

        [$tenant, $connection] = $this->connected();

        $listings = $this->asTenant($tenant, fn (): array => [
            $this->listingFor($connection, '5001', 'TSH-M'),
        ]);

        $snapshot = $this->asTenant(
            $tenant,
            fn () => $this->adapterFor($connection)->fetchPrices($listings)
        );

        $this->assertNull($snapshot->priceFor('5001'));
    }

    /** Silinmiş ilan (404) turu ÇÖKERTMEZ — boş okunur. */
    #[Test]
    public function a_deleted_listing_does_not_crash_the_price_scan(): void
    {
        Http::fake(['*' => Http::response(['error' => 'not found'], 404)]);

        [$tenant, $connection] = $this->connected();

        $listings = $this->asTenant($tenant, fn (): array => [
            $this->listingFor($connection, '5001', 'TSH-M'),
        ]);

        $snapshot = $this->asTenant(
            $tenant,
            fn () => $this->adapterFor($connection)->fetchPrices($listings)
        );

        $this->assertSame([], $snapshot->pricesByExternalId);
    }

    // ═══════════════════════════════════════════════ saf birleştirici

    /**
     * ⚠️ BİRLEŞTİRİCİ FİYAT TURUNDA DA HER ÜRÜNÜ DÖNER.
     */
    #[Test]
    public function the_merger_never_drops_a_product_on_a_price_round(): void
    {
        $merged = EtsyInventoryMerger::merge(
            $this->products(),
            quantityBySku: [],
            priceByProductId: ['5001' => '29.90'],
        );

        $this->assertCount(3, $merged);
    }

    /**
     * ⚠️ EŞLEŞMEYEN KİMLİĞE DOKUNULMAZ.
     *
     * Satıcının bizden habersiz eklediği varyant kanalda ne ise O KALIR.
     */
    #[Test]
    public function an_unknown_product_id_leaves_prices_untouched(): void
    {
        $merged = EtsyInventoryMerger::merge(
            $this->products(),
            quantityBySku: [],
            priceByProductId: ['9999' => '99.00'],
        );

        $prices = array_map(
            static fn (array $p): float => $p['offerings'][0]['price'],
            $merged,
        );

        $this->assertSame([19.90, 19.90, 24.50], $prices);
    }

    /**
     * ⚠️ FİYAT KİMLİKLE EŞLENİR, SKU İLE DEĞİL — ve bu BİLİNÇLİDİR.
     *
     * `PricePushBatch` kalemi `sku` TAŞIMAZ, `external_id` taşır
     * (= Etsy `product_id`). SKU ile eşlenseydi kalemin taşımadığı bir
     * alan uydurulmak zorunda kalınırdı; üstelik SKU kanal tarafında
     * BOŞ olabilir ve o zaman boş dize iki varyantı birden eşlerdi.
     *
     * Stok tarafı SKU ile eşlenir çünkü `InventoryPushItem` sku TAŞIR ve
     * o yol `product_id` bilmez. İki anahtarın farklı olması taşınan
     * veriden gelir, keyfi değildir.
     */
    #[Test]
    public function prices_match_on_product_id_even_when_skus_collide(): void
    {
        $products = $this->products();

        // İki varyant AYNI (boş) SKU'yu taşıyor — kanalda mümkündür.
        $products[0]['sku'] = '';
        $products[1]['sku'] = '';

        $merged = EtsyInventoryMerger::merge(
            $products,
            quantityBySku: [],
            priceByProductId: ['5001' => '29.90'],
        );

        // Yalnızca 5001 değişti; 5000 kanaldaki fiyatını korudu.
        $this->assertSame(19.90, $merged[0]['offerings'][0]['price']);
        $this->assertSame(29.90, $merged[1]['offerings'][0]['price']);
    }

    /**
     * ⚠️ STOK VE FİYAT AYNI TURDA GÖNDERİLMEZ — ama birleştirici
     * ikisini de kabul eder ve KARIŞTIRMAZ.
     */
    #[Test]
    public function quantity_and_price_updates_do_not_interfere(): void
    {
        $merged = EtsyInventoryMerger::merge(
            $this->products(),
            quantityBySku: ['TSH-S' => 42],
            priceByProductId: ['5002' => '34.00'],
        );

        $this->assertSame(42, $merged[0]['offerings'][0]['quantity']);
        $this->assertSame(19.90, $merged[0]['offerings'][0]['price']);

        $this->assertSame(11, $merged[2]['offerings'][0]['quantity']);
        $this->assertSame(34.00, $merged[2]['offerings'][0]['price']);
    }

    // ══════════════════════════ "YAZILDI" mı "ÇAĞRILIYOR" mu

    /**
     * ⚠️ GERÇEK KUYRUK İŞİ ETSY'YE FİYAT GÖNDERİR.
     *
     * Yukarıdaki testler `pushPrices()` gövdesini DOĞRUDAN çağırır ve
     * doğru çalıştığını kanıtlar. Bu test BAŞKA bir soru sorar: o gövde
     * ÇEKİRDEĞİN akışından gerçekten çağrılıyor mu?
     *
     * `PushPrices` yeteneği `instanceof SupportsPricing` ile okur — yani
     * yeni kanal için tek satır çekirdek kodu yazılmaz (§22). Ama
     * "yazıldı" ile "çağrılıyor" farkı ancak GERÇEK işin sürülmesiyle
     * kapanır: arayüz ilan edilmemiş olsaydı iş `recordSkipped` yazar,
     * operasyon SESSİZCE atlanır ve kanala hiçbir istek gitmezdi.
     *
     * İddia "operasyon tamamlandı" DEĞİL, **kanala PUT gitti**'dir:
     * ilki `AdapterResult` uydurulsa bile yeşil kalırdı.
     */
    #[Test]
    public function the_real_queue_job_pushes_a_price_to_etsy(): void
    {
        $this->fakeInventory();

        [$tenant, $connection] = $this->connected();

        $operationId = $this->asTenant($tenant, function () use ($connection): string {
            $variant = Variant::factory()->create(['sku' => 'TSH-M', 'price' => '29.90']);

            $listing = Listing::factory()->create([
                'channel_connection_id' => $connection->id,
                'variant_id' => $variant->id,
                'external_id' => '5001',
                'external_parent_id' => '9001',
                'lifecycle_status' => 'live',
            ]);

            return app(OpenSyncOperation::class)->run(
                listing: $listing,
                domain: SyncDomain::PRICE,
                eventVersion: 2,
            )->id;
        });

        (new PushPrices($operationId, $tenant->id))->handle(
            app(PriceBatchBuilder::class),
            app(SyncResultRecorder::class),
            app(AdapterRegistry::class),
        );

        // ⚠️ ASIL KANIT: gövde Etsy'nin ENVANTER uç noktasına GİTTİ ve
        // bizim fiyatımızı taşıyor.
        Http::assertSent(function ($request): bool {
            if ($request->method() !== 'PUT' || ! str_contains($request->url(), '/inventory')) {
                return false;
            }

            foreach ($request->data()['products'] as $product) {
                if (($product['sku'] ?? null) === 'TSH-M') {
                    return $product['offerings'][0]['price'] === 29.90;
                }
            }

            return false;
        });

        $operation = $this->asTenant(
            $tenant,
            fn () => SyncOperation::query()->findOrFail($operationId)
        );

        $this->assertSame(
            SyncOperationStatus::COMPLETED,
            $operation->status,
            'Operasyon tamamlanmadı — sonuç hiçbir operasyona yazılmıyor.',
        );
    }

    // ═══════════════════════════════════════════════════ yetenek

    /** Yetenek `instanceof` ile okunur. */
    #[Test]
    public function the_adapter_declares_the_pricing_capability(): void
    {
        $this->assertInstanceOf(SupportsPricing::class, $this->adapter());
    }

    /**
     * ⚠️ FİYAT PARTİSİ DE İLAN BAŞINA 1'DİR — stokla AYNI gerekçe.
     *
     * Uç nokta tek ilanı adresler ve o ilanın TÜM varyantlarını tek
     * gövdede ister; büyük bir sayı yalnızca tek turda daha çok ÇAĞRI
     * demektir ve Etsy'nin GÜNLÜK kotasını (§21) hızla yakardı.
     */
    #[Test]
    public function the_price_batch_is_one_per_listing(): void
    {
        $this->assertSame(1, $this->adapter()->maxPriceBatchSize());
    }

    // ────────────────────────────────────────────────────────── yardımcılar

    /**
     * Yükü gönderir ve YAZILAN gövdeyi döner.
     *
     * @param  array<string, string>  $priceByExternalId
     * @return array<string, mixed>
     */
    private function pushAndCaptureBody(array $priceByExternalId): array
    {
        $this->fakeInventory();

        $this->push($priceByExternalId);

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

    /** @param array<string, string> $priceByExternalId */
    private function push(array $priceByExternalId): void
    {
        [$tenant, $connection] = $this->connected();

        $this->asTenant($tenant, function () use ($connection, $priceByExternalId): void {
            $items = [];

            foreach ($priceByExternalId as $externalId => $price) {
                $listing = $this->listingFor(
                    $connection,
                    (string) $externalId,
                    self::SKUS[$externalId],
                );

                $items[] = [
                    'listing_id' => $listing->id,
                    'external_id' => (string) $externalId,
                    'price' => $price,
                    'compare_at_price' => null,
                    'version' => 1,
                ];
            }

            $this->adapterFor($connection)->pushPrices(new PricePushBatch(
                channelConnectionId: $connection->id,
                items: $items,
            ));
        });
    }

    /** Etsy product_id → SKU. */
    private const SKUS = ['5000' => 'TSH-S', '5001' => 'TSH-M', '5002' => 'TSH-L'];

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
            name: 'Etsy Fiyat '.uniqid(),
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
