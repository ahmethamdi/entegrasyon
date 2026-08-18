<?php

declare(strict_types=1);

namespace Tests\Feature\Channels;

use App\Domain\Channels\Adapters\Trendyol\TrendyolAdapter;
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
use App\Domain\Sync\Support\PricePushBatch;
use App\Support\Logging\PayloadRedactor;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Trendyol stok ve fiyat itme — §13 · Faz 2'nin beşinci maddesi.
 *
 * Mimari Karar Dokümanı v2.2 · §14 · Trendyol, §7 · SupportsInventory +
 * SupportsPricing, §1 · Karar 25 (mutlak değer).
 *
 * Bu maddenin kapsadığı şey ÇAPRAZ KANAL DÖNGÜSÜNÜN YARISIDIR: Woo'dan
 * gelen bir satış Trendyol'un stoğunu düşürür. Çekirdek tarafı zaten
 * hazır (`InventoryBatchBuilder` gruplar, `PushInventory` orkestra eder,
 * `SyncResultRecorder` sonucu yazar) ve Woo ile çalışıyor; eksik olan
 * yalnızca adapter gövdeleriydi.
 *
 * DEĞİŞMEZ KURAL — TEK UÇ NOKTA, İKİ YETENEK:
 *   Woo'da stok ve fiyat `products/batch` üzerinden ayrı alanlarla gider.
 *   Trendyol'da ikisi de `v2/products/price-and-inventory` uç noktasıdır ve
 *   kalem KISMİ güncellemeyi destekler: yalnızca `quantity` göndermek fiyata
 *   DOKUNMAZ, yalnızca fiyat göndermek stoğa dokunmaz. İki yeteneğin aynı
 *   uç noktayı paylaşması onları birleştirmez — `PushInventory` stok
 *   operasyonunu, fiyat yolu fiyat operasyonunu yazar ve biri diğerinin
 *   alanını EZMEMELİDİR.
 *
 * DEĞİŞMEZ KURAL — KİMLİK BARKODDUR:
 *   Trendyol ürünü barkodla tanır; sayısal bir ürün kimliği yoktur ve
 *   `external_id` barkodun kendisidir (`ListingMapper` de böyle yazar).
 *   Woo'daki `(int) $item['external_id']` dönüşümü buraya taşınsaydı
 *   harf içeren her barkod `0`'a düşer ve güncelleme YANLIŞ ÜRÜNE ya da
 *   hiçbir ürüne gitmezdi.
 */
final class TrendyolInventoryPricingTest extends TestCase
{
    use RefreshDatabase;

    // ─────────────────────────────────────────────────────────── stok

    /**
     * STOK MUTLAK DEĞER OLARAK, BARKOD ANAHTARIYLA GİDER.
     *
     * §1 · Karar 25: delta ASLA gönderilmez. Delta göndermek, kaybolan veya
     * iki kez işlenen bir isteğin kanaldaki bakiyeyi kalıcı olarak
     * kaydırması demektir ve fark geri kazanılamaz.
     */
    #[Test]
    public function inventory_is_pushed_as_absolute_quantity_keyed_by_barcode(): void
    {
        Http::fake(['*' => Http::response(['batchRequestId' => 'batch-1'], 200)]);

        $result = $this->adapter()->pushInventory(new InventoryPushBatch('c1', [
            new InventoryPushItem('l1', 'BARKOD-A', 'SKU-A', 7, 3),
            new InventoryPushItem('l2', 'BARKOD-B', 'SKU-B', 0, 4),
        ]));

        $this->assertTrue($result->successful);

        Http::assertSent(function (Request $request): bool {
            $items = $request->data()['items'] ?? [];

            return str_contains($request->url(), 'v2/products/price-and-inventory')
                && $request->method() === 'POST'
                && count($items) === 2
                && $items[0]['barcode'] === 'BARKOD-A'
                && $items[0]['quantity'] === 7
                && $items[1]['barcode'] === 'BARKOD-B'
                && $items[1]['quantity'] === 0;
        });
    }

    /**
     * BARKOD SAYIYA ÇEVRİLMEZ.
     *
     * Woo'nun `pushInventory`'si `(int) $item['external_id']` yazar çünkü
     * orada kimlik sayısal ürün kimliğidir. Aynı satır buraya kopyalansaydı
     * harf içeren barkod (`TSH-201`) `0`'a düşer ve istek ya yanlış ürüne
     * giderdi ya da sessizce hiçbir şeyi güncellemezdi — kanal 200 döndüğü
     * için senkron BAŞARILI görünürdü.
     */
    #[Test]
    public function alphanumeric_barcode_is_not_cast_to_integer(): void
    {
        Http::fake(['*' => Http::response(['batchRequestId' => 'batch-1'], 200)]);

        $this->adapter()->pushInventory(new InventoryPushBatch('c1', [
            new InventoryPushItem('l1', 'TSH-201', 'TSH-201', 5, 1),
        ]));

        Http::assertSent(function (Request $request): bool {
            $items = $request->data()['items'] ?? [];

            return ($items[0]['barcode'] ?? null) === 'TSH-201';
        });
    }

    /**
     * STOK YÜKÜ FİYAT ALANI TAŞIMAZ.
     *
     * Uç nokta paylaşımlı ve KISMİ güncellemeyi destekliyor. Stok yükünde
     * fiyat da gönderilseydi, panelden yapılan bir fiyat değişikliği daha
     * kanala gitmeden önce eski fiyatla EZİLİRDİ: stok her satışta gider,
     * fiyat ise nadiren değişir — yani ezme sessiz ve sürekli olurdu.
     */
    #[Test]
    public function inventory_payload_carries_no_price_fields(): void
    {
        Http::fake(['*' => Http::response(['batchRequestId' => 'batch-1'], 200)]);

        $this->adapter()->pushInventory(new InventoryPushBatch('c1', [
            new InventoryPushItem('l1', 'BARKOD-A', 'SKU-A', 7, 3),
        ]));

        Http::assertSent(function (Request $request): bool {
            $item = $request->data()['items'][0] ?? [];

            return ! array_key_exists('salePrice', $item)
                && ! array_key_exists('listPrice', $item);
        });
    }

    /**
     * BOŞ YÜKTE ÇAĞRI YAPILMAZ.
     *
     * Kota boşa harcanmaz ve kanal boş `items` dizisini `VALIDATION`
     * hatasıyla reddederdi — o hata KALICIDIR ve listing "düzeltilemez"
     * damgasıyla ölürdü.
     */
    #[Test]
    public function empty_inventory_batch_makes_no_call(): void
    {
        Http::fake();

        $result = $this->adapter()->pushInventory(new InventoryPushBatch('c1', []));

        $this->assertTrue($result->successful);

        Http::assertNothingSent();
    }

    /**
     * BAŞARISIZLIK İSTİSNA OLARAK YÜKSELİR, `failure()` DÖNMEZ (§7).
     *
     * Sınıflandırma ve yeniden deneme kararı `PushInventory`'deki TEK
     * try/catch'te toplanır. Adapter'ın kendi kararını vermesi, aynı
     * politikanın iki yerde yaşaması demekti.
     */
    #[Test]
    public function failed_inventory_push_raises_instead_of_returning_failure(): void
    {
        Http::fake(['*' => Http::response(['errors' => [['message' => 'olmaz']]], 400)]);

        $this->expectException(RequestException::class);

        $this->adapter()->pushInventory(new InventoryPushBatch('c1', [
            new InventoryPushItem('l1', 'BARKOD-A', 'SKU-A', 7, 3),
        ]));
    }

    /**
     * ASENKRON KABUL "GÖNDERİLDİ" DEMEKTİR, "UYGULANDI" DEĞİL.
     *
     * Trendyol stok güncellemesini de asenkron yapar ve `batchRequestId`
     * döner. Kimlik sonuçta taşınır: kanalın işi gerçekten uygulayıp
     * uygulamadığı ancak o kimlikle sorulabilir ve mutabakat turu farkı
     * zaten yakalar.
     */
    #[Test]
    public function inventory_result_carries_the_batch_request_id(): void
    {
        Http::fake(['*' => Http::response(['batchRequestId' => 'batch-42'], 200)]);

        $result = $this->adapter()->pushInventory(new InventoryPushBatch('c1', [
            new InventoryPushItem('l1', 'BARKOD-A', 'SKU-A', 7, 3),
        ]));

        $this->assertSame('batch-42', $result->data['batch_request_id'] ?? null);
        $this->assertSame(1, $result->data['pushed'] ?? null);
    }

    // ────────────────────────────────────────────────────────── fiyat

    /**
     * FİYAT MUTLAK DEĞER OLARAK GİDER VE STOK ALANI TAŞIMAZ.
     *
     * Simetrik gerekçe: fiyat yükünde `quantity` de gönderilseydi, yükü
     * kuran taraf stoğu bilmediği için kanaldaki bakiyeyi bayat bir
     * değerle ezerdi — üstelik fazla satışı geri getirerek.
     */
    #[Test]
    public function prices_are_pushed_as_absolute_values_without_quantity(): void
    {
        Http::fake(['*' => Http::response(['batchRequestId' => 'batch-1'], 200)]);

        $result = $this->adapter()->pushPrices(new PricePushBatch('c1', [
            [
                'listing_id' => 'l1',
                'external_id' => 'BARKOD-A',
                'price' => '149.90',
                'compare_at_price' => '199.90',
                'version' => 2,
            ],
        ]));

        $this->assertTrue($result->successful);

        Http::assertSent(function (Request $request): bool {
            $item = $request->data()['items'][0] ?? [];

            return str_contains($request->url(), 'v2/products/price-and-inventory')
                && $item['barcode'] === 'BARKOD-A'
                && $item['salePrice'] === 149.90
                && $item['listPrice'] === 199.90
                && ! array_key_exists('quantity', $item);
        });
    }

    /**
     * ÜSTÜ ÇİZİLİ FİYAT YOKSA SATIŞ FİYATI KULLANILIR.
     *
     * Trendyol `listPrice`'ı zorunlu tutar. Alan atlanırsa kanal
     * `VALIDATION` döner ve o hata KALICIDIR: satır "düzeltilemez"
     * damgasıyla ölür, oysa eksik olan yalnızca satıcının hiç girmediği
     * bir kampanya fiyatıdır.
     */
    #[Test]
    public function missing_compare_at_price_falls_back_to_sale_price(): void
    {
        Http::fake(['*' => Http::response(['batchRequestId' => 'batch-1'], 200)]);

        $this->adapter()->pushPrices(new PricePushBatch('c1', [
            ['listing_id' => 'l1', 'external_id' => 'BARKOD-A', 'price' => '99.00', 'version' => 1],
        ]));

        Http::assertSent(function (Request $request): bool {
            $item = $request->data()['items'][0] ?? [];

            return $item['salePrice'] === 99.00 && $item['listPrice'] === 99.00;
        });
    }

    /** Boş fiyat yükünde de çağrı yapılmaz — aynı gerekçe. */
    #[Test]
    public function empty_price_batch_makes_no_call(): void
    {
        Http::fake();

        $result = $this->adapter()->pushPrices(new PricePushBatch('c1', []));

        $this->assertTrue($result->successful);

        Http::assertNothingSent();
    }

    /** Fiyat itmede de başarısızlık istisna olarak yükselir. */
    #[Test]
    public function failed_price_push_raises_instead_of_returning_failure(): void
    {
        Http::fake(['*' => Http::response(['errors' => [['message' => 'olmaz']]], 400)]);

        $this->expectException(RequestException::class);

        $this->adapter()->pushPrices(new PricePushBatch('c1', [
            ['listing_id' => 'l1', 'external_id' => 'BARKOD-A', 'price' => '99.00', 'version' => 1],
        ]));
    }

    // ──────────────────────────────────────────────── uzak durum okuma

    /**
     * UZAK STOK TOPLU OKUNUR — mutabakatın karşılaştırma girdisi (§10).
     *
     * Listing başına ayrı istek, 500 ürünlü katalogda 500 istek demektir ve
     * ölçek hesabını yüz katına çıkarırdı.
     */
    #[Test]
    public function remote_inventory_is_read_in_one_batched_call(): void
    {
        Http::fake(['*' => Http::response([
            'content' => [
                ['barcode' => 'BARKOD-A', 'quantity' => 12],
                ['barcode' => 'BARKOD-B', 'quantity' => 0],
            ],
        ], 200)]);

        $snapshot = $this->adapter()->fetchInventory([
            $this->listing('BARKOD-A'),
            $this->listing('BARKOD-B'),
        ]);

        $this->assertSame(12, $snapshot->quantityFor('BARKOD-A'));
        $this->assertSame(0, $snapshot->quantityFor('BARKOD-B'));

        // Okuma anı taşınır: gecikmeli okuma sürüklenme sanılmamalı (§10).
        $this->assertNotNull($snapshot->observedAt);

        Http::assertSentCount(1);
    }

    /**
     * KİMLİĞİ OLMAYAN LISTING SORULMAZ VE FİLTRESİZ İSTEK ATILMAZ.
     *
     * `external_id` NULL ise ürün kanala hiç gitmemiştir. Hiç kimlik
     * kalmazsa çağrı da YAPILMAZ: boş bir filtreyle istek atmak kanalın
     * TÜM kataloğunu geri getirirdi (onay durumunda birebir aynı kural).
     */
    #[Test]
    public function fetch_inventory_makes_no_call_without_external_ids(): void
    {
        Http::fake();

        $snapshot = $this->adapter()->fetchInventory([$this->listing(null)]);

        $this->assertTrue($snapshot->isEmpty());

        Http::assertNothingSent();
    }

    /**
     * BAŞARISIZ YANIT SESSİZCE BOŞ SNAPSHOT'A DÖNÜŞMEZ.
     *
     * `json()` bir 500 gövdesinde de dizi döndürür. Boş snapshot
     * mutabakatta "kanalda hiç ürün yok" diye okunur ve `REMOTE_MISSING`
     * üretirdi — oysa olan yalnızca geçici bir sunucu hatasıdır.
     * Taksonomide birebir aynı hata yaşandı.
     */
    #[Test]
    public function failed_fetch_inventory_raises_instead_of_returning_empty(): void
    {
        Http::fake(['*' => Http::response(['errors' => []], 500)]);

        $this->expectException(RequestException::class);

        $this->adapter()->fetchInventory([$this->listing('BARKOD-A')]);
    }

    /** Uzak fiyat da toplu okunur ve string taşınır (kuruş kayması olmasın). */
    #[Test]
    public function remote_prices_are_read_in_one_batched_call(): void
    {
        Http::fake(['*' => Http::response([
            'content' => [['barcode' => 'BARKOD-A', 'salePrice' => 149.9]],
        ], 200)]);

        $snapshot = $this->adapter()->fetchPrices([$this->listing('BARKOD-A')]);

        $this->assertSame('149.9', $snapshot->priceFor('BARKOD-A'));

        Http::assertSentCount(1);
    }

    /** Fiyat okumada da başarısız yanıt yükseltilir. */
    #[Test]
    public function failed_fetch_prices_raises_instead_of_returning_empty(): void
    {
        Http::fake(['*' => Http::response(['errors' => []], 500)]);

        $this->expectException(RequestException::class);

        $this->adapter()->fetchPrices([$this->listing('BARKOD-A')]);
    }

    // ────────────────────────────────────────────────────── bağlam dışı

    /**
     * STOK İTME KİRACI BAĞLAMI OLMADAN DA KİMLİKLİ GİDER.
     *
     * `PushInventory` fan-out'tan VE seviye 2 taramasından (`runAsSystem`,
     * bağlam YOK) atılır. Kimlik bilgisi kapsanmış sorgudan okunamazsa
     * istek SESSİZCE KİMLİKSİZ gider, kanal 401 döner, `AUTHENTICATION`
     * KALICI sayılır ve listing "anahtarın yanlış" diyerek ölür — oysa
     * anahtar doğrudur ve hiç gönderilmemiştir. Bu hata üretimde Woo'yu da
     * vurdu; adapter yazınca bağlam DIŞINDA çağırmak şart.
     */
    #[Test]
    public function inventory_push_carries_credentials_without_tenant_context(): void
    {
        [$tenant] = $this->makeTenant();

        $connection = $this->asTenant(
            $tenant,
            fn (): ChannelConnection => $this->connection(),
        );

        Http::fake(['*' => Http::response(['batchRequestId' => 'batch-1'], 200)]);

        $this->assertFalse(
            TenantContext::hasTenant(),
            'Test kurgusu gereği bağlam bırakılmış olmalı.',
        );

        $this->adapterFor($connection)->pushInventory(new InventoryPushBatch('c1', [
            new InventoryPushItem('l1', 'BARKOD-A', 'SKU-A', 7, 3),
        ]));

        Http::assertSent(function (Request $request): bool {
            return ($request->header('Authorization')[0] ?? '')
                === 'Basic '.base64_encode('anahtar:sifre');
        });
    }

    // ──────────────────────────────────────────────────────── yardımcı

    /** @return array{0: Tenant, 1: User} */
    private function makeTenant(string $name = 'Trendyol'): array
    {
        $user = User::factory()->create();
        $tenant = (new CreateTenant)->run(name: $name.' '.uniqid(), owner: $user);

        return [$tenant, $user];
    }

    /**
     * Kalıcılığı olmayan listing — yalnızca `external_id` taşır.
     *
     * Okuma yolları yalnızca kimliği kullanır; veritabanına yazmak testi
     * sınadığı şeyden uzaklaştırırdı.
     */
    private function listing(?string $externalId): Listing
    {
        $listing = new Listing;
        $listing->external_id = $externalId;

        return $listing;
    }

    private function channelType(): ChannelType
    {
        return $this->asSystem(fn (): ChannelType => ChannelType::query()->updateOrCreate(
            ['code' => 'trendyol'],
            [
                'name' => 'Trendyol',
                'kind' => 'marketplace',
                'adapter_class' => TrendyolAdapter::class,
                'capabilities' => [
                    'catalog' => true, 'inventory' => true, 'pricing' => true,
                    'orders' => true, 'taxonomy' => true, 'approval' => true,
                    'fulfillment' => false,
                ],
                'rate_limit_profile' => [
                    'requests_per_second' => 5,
                    'burst_capacity' => 10,
                ],
                'supports_webhooks' => false,
                'is_active' => true,
            ],
        ));
    }

    private function connection(string $supplierId = '123456'): ChannelConnection
    {
        $this->channelType();

        $connection = ChannelConnection::factory()->create([
            'channel_type_code' => 'trendyol',
            'external_account_id' => $supplierId,
            'settings' => [
                'base_url' => 'https://api.trendyol.com/sapigw',
                'supplier_id' => $supplierId,
            ],
        ]);

        app(CredentialVault::class)->store($connection, [
            'api_key' => 'anahtar',
            'api_secret' => 'sifre',
        ]);

        return $connection;
    }

    private function adapterFor(ChannelConnection $connection): TrendyolAdapter
    {
        return new TrendyolAdapter(
            $connection,
            new ChannelHttpClient(
                $connection,
                app(CredentialVault::class),
                app(PayloadRedactor::class),
            ),
        );
    }

    private function adapter(string $supplierId = '123456'): TrendyolAdapter
    {
        [$tenant] = $this->makeTenant();

        return $this->asTenant(
            $tenant,
            fn (): TrendyolAdapter => $this->adapterFor($this->connection($supplierId)),
        );
    }
}
