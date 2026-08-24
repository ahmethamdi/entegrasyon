<?php

declare(strict_types=1);

namespace Tests\Feature\Sync;

use App\Domain\Catalog\Actions\UpdateProduct;
use App\Domain\Catalog\Models\PriceOverride;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Variant;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Models\ChannelType;
use App\Domain\Channels\Registry\AdapterRegistry;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use App\Domain\Messaging\Consumers\VariantPriceChangedConsumer;
use App\Domain\Messaging\Jobs\ConsumeOutboxEvent;
use App\Domain\Messaging\Models\OutboxEvent;
use App\Domain\Sync\Actions\OpenSyncOperation;
use App\Domain\Sync\Enums\SyncDomain;
use App\Domain\Sync\Enums\SyncOperationStatus;
use App\Domain\Sync\Jobs\PushPrices;
use App\Domain\Sync\Models\Listing;
use App\Domain\Sync\Models\SyncOperation;
use App\Domain\Sync\Support\DetectStuckSyncOperations;
use App\Domain\Sync\Support\PriceBatchBuilder;
use App\Domain\Sync\Support\SyncResultRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * §13 · Faz 3 · FİYAT SENKRON YOLU.
 *
 * Mimari Karar Dokümanı v2.2 · §7 · SupportsPricing, §8 · fan-out ve
 * gruplama, §12 · iş tarafı.
 *
 * KAPATILAN BOŞLUK: `pushPrices` gövdeleri (Woo VE Trendyol) ilk günden beri
 * hazırdı ama ÇEKİRDEKTE ÇAĞIRANI YOKTU. `SyncDomain::PRICE` ve `PRICE_PUSH`
 * şemada ve enum'da vardı; fiyat operasyonu açan ya da dispatch eden hiçbir
 * kod yoktu, yani iki adapter'ın da fiyat gövdesi ULAŞILAMAZDI. Panelden
 * fiyat düzeltmek kanala HİÇ yansımıyordu.
 *
 * TETİKLEYİCİ DE BU MADDENİN PARÇASIDIR (kullanıcı kararı): stokta tetik
 * `ApplyMovement`'ın ledger transaction'ında yaşar, fiyatın ledger'ı yoktur
 * ve `UpdateProduct` düz kolon güncelliyordu. Artık aynı transaction içinde
 * `VariantPriceChanged` outbox olayı yazılıyor ve yol stokun BİREBİR aynısı:
 * relay → tüketici → fan-out → PRICE_PUSH → PushPrices.
 */
final class PriceSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Planlamayı sınıyoruz; gerçek worker'ı `sync` sürücü taklit etmez.
        Queue::fake();
    }

    // ------------------------------------------------------------ tetikleyici

    /**
     * FİYAT DEĞİŞİKLİĞİ OUTBOX OLAYI YAZAR.
     *
     * Bu olmadan panelden yapılan fiyat düzeltmesi kanala HİÇ gitmez ve
     * satırda "senkron" görünür — projedeki en pahalı sessiz hata biçimi.
     */
    #[Test]
    public function changing_the_price_writes_an_outbox_event(): void
    {
        [$tenant, $product] = $this->makeProduct(price: 100.00);

        $this->asTenant($tenant, fn () => app(UpdateProduct::class)->run(
            product: $product,
            title: $product->title,
            price: 149.90,
        ));

        $this->assertDatabaseHas('outbox_events', [
            'event_type' => 'VariantPriceChanged',
            'aggregate_type' => 'variant',
        ]);
    }

    /**
     * FİYAT DEĞİŞMEDİYSE OLAY YAZILMAZ.
     *
     * İçerik düzenlemesi (başlık, açıklama) fiyat olayı üretmemeli: her
     * kaydetme fiyat turu açsaydı kanal kotası boşa harcanır ve mutabakat
     * gerçek sürüklenmeyi gürültüde kaybederdi. `content_version` yine artar
     * — o CONTENT alanının işidir.
     */
    #[Test]
    public function editing_content_without_touching_the_price_writes_no_price_event(): void
    {
        [$tenant, $product] = $this->makeProduct(price: 100.00);

        $this->asTenant($tenant, fn () => app(UpdateProduct::class)->run(
            product: $product,
            title: 'Yeni başlık',
            price: 100.00,          // AYNI fiyat
        ));

        $this->assertDatabaseMissing('outbox_events', [
            'event_type' => 'VariantPriceChanged',
        ]);
    }

    /**
     * OLAY YÜKÜ FİYATI STRING TAŞIR ve varyant sürümünü bildirir.
     *
     * Para float taşınmaz: yuvarlama kuruş kayması üretir (§7).
     */
    #[Test]
    public function the_payload_carries_the_price_as_a_string_and_the_variant_version(): void
    {
        [$tenant, $product] = $this->makeProduct(price: 100.00);

        $this->asTenant($tenant, fn () => app(UpdateProduct::class)->run(
            product: $product,
            title: $product->title,
            price: 149.90,
        ));

        $event = $this->priceEvent($tenant);

        $this->assertSame('149.90', $event->payload['price']);
        $this->assertIsString($event->payload['price'], 'fiyat float taşınıyor — kuruş kayması riski.');
        $this->assertArrayHasKey('variant_id', $event->payload);
        $this->assertSame(2, $event->payload['version'], 'varyant content_version yükte taşınmıyor.');
    }

    // ------------------------------------------------------------ fan-out

    /**
     * BİR OLAY, VARYANTIN CANLI LISTING SAYISI KADAR PRICE_PUSH ÜRETİR.
     *
     * Fan-out tüketicide olur (§8): her listing kendi operasyonunu, kendi
     * durumunu ve kendi hatasını taşır. Tek operasyon modelinde bir kanalın
     * hatası diğerlerinin durumunu kirletirdi.
     */
    #[Test]
    public function the_consumer_fans_out_one_operation_per_live_listing(): void
    {
        [$tenant, $product, $variant] = $this->makeProduct(price: 100.00, withVariant: true);

        $this->asTenant($tenant, function () use ($variant): void {
            $this->listingFor($variant, externalId: '11');
            $this->listingFor($variant, externalId: '22');
        });

        $this->asTenant($tenant, fn () => app(UpdateProduct::class)->run(
            product: $product,
            title: $product->title,
            price: 149.90,
        ));

        $event = $this->priceEvent($tenant);
        $this->asTenant($tenant, fn () => app(VariantPriceChangedConsumer::class)->handle($event));

        $count = $this->asTenant($tenant, fn () => SyncOperation::query()
            ->where('operation_type', 'PRICE_PUSH')
            ->count());

        $this->assertSame(2, $count, 'fan-out listing başına operasyon açmadı.');
        $this->assertNotNull($event->fresh()->consumed_at);
    }

    /**
     * OLAY GERÇEK TESLİM YOLUNDAN GEÇER — `ConsumeOutboxEvent` YÖNLENDİRİR.
     *
     * Tüketiciyi doğrudan çağıran testler, tüketicinin `match` dalına hiç
     * bağlanmadığı bir dünyada da yeşil kalır: dal yoksa olay "tanınmayan
     * tür" sayılır, SESSİZCE consumed damgalanır ve fiyat hiç gitmez. Bu
     * boşluk resync turunda mutasyonla bulundu; aynı hatayı burada da
     * yapmamak için yol baştan sınanıyor.
     */
    #[Test]
    public function the_price_event_is_routed_by_the_real_outbox_consumer_job(): void
    {
        [$tenant, $product, $variant] = $this->makeProduct(price: 100.00, withVariant: true);

        $this->asTenant($tenant, fn () => $this->listingFor($variant, externalId: '11'));

        $this->asTenant($tenant, fn () => app(UpdateProduct::class)->run(
            product: $product,
            title: $product->title,
            price: 149.90,
        ));

        $event = $this->priceEvent($tenant);

        (new ConsumeOutboxEvent($tenant->id, $event->id))->handle();

        $count = $this->asTenant($tenant, fn () => SyncOperation::query()
            ->where('operation_type', 'PRICE_PUSH')
            ->count());

        $this->assertSame(
            1,
            $count,
            'ConsumeOutboxEvent fiyat olayını yönlendirmedi — olay sessizce yutuldu.',
        );
    }

    /**
     * CANLI OLMAYAN LISTING'E FİYAT GÖNDERİLMEZ.
     *
     * Kanalda karşılığı olmayan satıra gönderim her turda hata alır.
     */
    #[Test]
    public function draft_listings_are_not_price_targets(): void
    {
        [$tenant, $product, $variant] = $this->makeProduct(price: 100.00, withVariant: true);

        $this->asTenant($tenant, fn () => $this->listingFor($variant, externalId: '11', lifecycle: 'draft'));

        $this->asTenant($tenant, fn () => app(UpdateProduct::class)->run(
            product: $product,
            title: $product->title,
            price: 149.90,
        ));

        $event = $this->priceEvent($tenant);
        $this->asTenant($tenant, fn () => app(VariantPriceChangedConsumer::class)->handle($event));

        $count = $this->asTenant($tenant, fn () => SyncOperation::query()
            ->where('operation_type', 'PRICE_PUSH')
            ->count());

        $this->assertSame(0, $count);
        $this->assertNotNull(
            $event->fresh()->consumed_at,
            'olay damgalanmadı — seviye 1 taraması onu sonsuza kadar yeniden yayınlar.',
        );
    }

    // ------------------------------------------------------------ gruplama

    /**
     * BUILDER YALNIZCA GRUPLAMA YAPAR — FAN-OUT YAPMAZ.
     *
     * Aynı bağlantıda bekleyen fiyat operasyonları tek yükte birleşir ama
     * OPERASYON SAYISI DEĞİŞMEZ (§8).
     */
    #[Test]
    public function the_builder_groups_pending_operations_of_the_same_connection(): void
    {
        [$tenant, $product, $variant] = $this->makeProduct(price: 100.00, withVariant: true);

        $connectionId = null;

        $this->asTenant($tenant, function () use ($variant, &$connectionId): void {
            $connection = ChannelConnection::factory()->create();
            $connectionId = $connection->id;
            $this->listingFor($variant, externalId: '11', connectionId: $connection->id);

            $other = Variant::factory()->create(['product_id' => $variant->product_id]);
            $this->listingFor($other, externalId: '22', connectionId: $connection->id);
        });

        $this->asTenant($tenant, fn () => app(UpdateProduct::class)->run(
            product: $product,
            title: $product->title,
            price: 149.90,
        ));

        // İki varyantın da fiyat operasyonunu elle aç (fan-out yalnızca
        // değişen varyantı hedefler; burada gruplama sınanıyor).
        $this->asTenant($tenant, function () use ($tenant, $connectionId): void {
            foreach (Listing::query()->where('channel_connection_id', $connectionId)->get() as $listing) {
                $this->openPriceOperation($tenant, $listing);
            }

            $trigger = SyncOperation::query()->where('operation_type', 'PRICE_PUSH')->firstOrFail();

            $batch = DB::transaction(fn () => app(PriceBatchBuilder::class)->build($trigger));

            $this->assertSame(2, $batch->count(), 'aynı bağlantıdaki iki operasyon tek yükte birleşmedi.');
        });
    }

    /**
     * FİYAT YÜKÜ MUTLAK DEĞER TAŞIR ve `compare_at_price`'ı da bildirir.
     *
     * Yüzde indirim veya delta gönderilmez: kaybolan ya da iki kez işlenen
     * bir istek fiyatı KALICI olarak kaydırırdı (§7).
     */
    #[Test]
    public function the_payload_is_absolute_and_carries_the_compare_at_price(): void
    {
        [$tenant, $product, $variant] = $this->makeProduct(price: 100.00, withVariant: true);

        $this->asTenant($tenant, function () use ($variant): void {
            $variant->forceFill(['price' => 149.90, 'compare_at_price' => 199.90])->save();
            $this->listingFor($variant, externalId: '11');
        });

        $this->asTenant($tenant, function () use ($tenant): void {
            $listing = Listing::query()->firstOrFail();
            $operation = $this->openPriceOperation($tenant, $listing);

            $batch = DB::transaction(fn () => app(PriceBatchBuilder::class)->build($operation));

            $item = $batch->items[0];

            $this->assertSame('149.90', $item['price']);
            $this->assertSame('199.90', $item['compare_at_price']);
            $this->assertSame('11', $item['external_id']);
        });
    }

    /**
     * §9 · KABUL EDİLEN KANAL FİYATI BİR DAHA EZİLMEZ.
     *
     * BU TEST OLMADAN TÜM ÖZELLİK ANLAMSIZDIR: satıcı çakışmada
     * "kanalınki kalsın" der, `price_overrides` satırı yazılır — ve bir
     * sonraki fiyat turu kanonik fiyatı yine gönderirse kampanya sessizce
     * silinir. §9 bunu AÇIKÇA "en sık şikayet" diye adlandırıyor.
     *
     * Operasyon durumu DEĞİŞMEZ: atlanan operasyon `pending` kalır ve
     * `SyncResultRecorder` ona dokunmaz ("yükte olmayan operasyona
     * dokunulmaz"). Kapatılsaydı `PriceBatchBuilder` durum yazan İKİNCİ
     * yol olurdu.
     */
    #[Test]
    public function a_listing_with_an_accepted_price_override_is_excluded_from_the_payload(): void
    {
        [$tenant, $product, $variant] = $this->makeProduct(price: 149.90, withVariant: true);

        $this->asTenant($tenant, function () use ($tenant, $variant): void {
            $listing = $this->listingFor($variant, externalId: '11');

            PriceOverride::query()->create([
                'tenant_id' => $tenant->id,
                'listing_id' => $listing->id,
                'channel_price' => '119.90',
                // KARAR ANINDAKİ kanonik fiyat, varyantınkiyle AYNI:
                // farklı olsaydı override BAYAT sayılır ve eleme
                // yanlış sebeple çalışmazdı.
                'our_price' => '149.90',
                'accepted_at' => now(),
                'expires_at' => null,
            ]);

            $operation = $this->openPriceOperation($tenant, $listing);

            $batch = DB::transaction(fn () => app(PriceBatchBuilder::class)->build($operation));

            $this->assertTrue(
                $batch->isEmpty(),
                'Override\'lı listing yüke GİRDİ: kabul edilen kanal fiyatı bir '.
                'sonraki turda ezilirdi ve özellik anlamsızlaşırdı (§9).',
            );

            $this->assertSame(
                SyncOperationStatus::PENDING,
                $operation->fresh()->status,
                'Atlanan operasyonun DURUMU değişmemelidir — yükte olmayan '.
                'operasyona dokunulmaz.',
            );
        });
    }

    /**
     * BAYAT OVERRIDE ELEMEZ — kanonik fiyat değiştiyse gönderim SÜRER.
     *
     * Satıcı "119.90 kalsın" dedi (o an bizimki 149.90'dı), sonra panelden
     * fiyatı 199.90 yaptı. O karar ARTIK BAŞKA BİR SORUYA verilmiştir ve
     * elemeye devam etseydi panelden yapılan zam o kanala SESSİZCE hiç
     * gitmezdi — sürekli ve fark edilmesi zor gelir kaybı.
     */
    #[Test]
    public function a_stale_override_does_not_exclude_the_listing(): void
    {
        [$tenant, $product, $variant] = $this->makeProduct(price: 199.90, withVariant: true);

        $this->asTenant($tenant, function () use ($tenant, $variant): void {
            $listing = $this->listingFor($variant, externalId: '11');

            PriceOverride::query()->create([
                'tenant_id' => $tenant->id,
                'listing_id' => $listing->id,
                'channel_price' => '119.90',
                // Karar 149.90'a verilmişti; kanonik fiyat artık 199.90.
                'our_price' => '149.90',
                'accepted_at' => now(),
                'expires_at' => null,
            ]);

            $operation = $this->openPriceOperation($tenant, $listing);

            $batch = DB::transaction(fn () => app(PriceBatchBuilder::class)->build($operation));

            $this->assertSame(1, $batch->count(), 'Bayat override gönderimi engelledi.');
            $this->assertSame('199.90', $batch->items[0]['price']);
        });
    }

    /** Dış kimliği olmayan listing yüke girmez — kanal onu tanımaz. */
    #[Test]
    public function a_listing_without_an_external_id_is_excluded_from_the_payload(): void
    {
        [$tenant, $product, $variant] = $this->makeProduct(price: 100.00, withVariant: true);

        $this->asTenant($tenant, function () use ($tenant, $variant): void {
            $this->listingFor($variant, externalId: null);

            $listing = Listing::query()->firstOrFail();
            $operation = $this->openPriceOperation($tenant, $listing);

            $batch = DB::transaction(fn () => app(PriceBatchBuilder::class)->build($operation));

            $this->assertTrue($batch->isEmpty(), 'external_id olmayan listing yüke girdi.');
        });
    }

    // ------------------------------------------------------------ gönderim

    /** Boş yükte DENEME AÇILMAZ — seviye 2 taramasının anlamı korunur. */
    #[Test]
    public function an_empty_payload_does_not_open_an_attempt(): void
    {
        [$tenant, $product, $variant] = $this->makeProduct(price: 100.00, withVariant: true);

        $operationId = $this->asTenant($tenant, function () use ($tenant, $variant): string {
            $this->listingFor($variant, externalId: null);   // yüke girmez
            $listing = Listing::query()->firstOrFail();

            return $this->openPriceOperation($tenant, $listing)->id;
        });

        (new PushPrices($operationId, $tenant->id))->handle(
            app(PriceBatchBuilder::class),
            app(SyncResultRecorder::class),
            app(AdapterRegistry::class),
        );

        $operation = $this->asTenant($tenant, fn () => SyncOperation::query()->findOrFail($operationId));

        $this->assertSame(0, $operation->attempt_count, 'boş yükte deneme açıldı.');
    }

    // ------------------------------------------------------------ kurtarma

    /**
     * SEVİYE 2 TARAMASI FİYAT OPERASYONUNU DA KURTARIR.
     *
     * Dal eklenmezse tarama `PRICE_PUSH` için "iş yok" uyarısı yazar ve
     * kurtarılmış SAYMAZ: Redis işi düşürdüğünde fiyat kanala HİÇ gitmez ve
     * hiçbir mekanizma onu görmez.
     */
    #[Test]
    public function the_level_two_scan_redispatches_stuck_price_operations(): void
    {
        [$tenant, $product, $variant] = $this->makeProduct(price: 100.00, withVariant: true);

        $operationId = $this->asTenant($tenant, function () use ($tenant, $variant): string {
            $this->listingFor($variant, externalId: '11');
            $listing = Listing::query()->firstOrFail();

            return $this->openPriceOperation($tenant, $listing)->id;
        });

        // Taramanın imzası: pending + attempt_count = 0 + eski.
        DB::table('sync_operations')->where('id', $operationId)->update([
            'created_at' => now()->subMinutes(30),
        ]);

        $redispatched = app(DetectStuckSyncOperations::class)->run();

        $this->assertContains(
            $operationId,
            $redispatched->all(),
            'seviye 2 taraması PRICE_PUSH operasyonunu kurtarmadı — fiyat sonsuza kadar takılı kalır.',
        );

        Queue::assertPushed(PushPrices::class);
    }

    /**
     * BAŞARILI GÖNDERİM `synced_version`'I İLERLETİR — DİKEY DİLİM.
     *
     * BU TESTİN VARLIK NEDENİ: yük operasyon listesini taşımazsa
     * `SyncResultRecorder` HİÇBİR ŞEY yazamaz — çağrı kanala gider, başarılı
     * olur ve `synced_version` yerinde kalır. Satır sonsuza kadar "kirli"
     * görünür, her turda yeniden gönderilir ve kanal kotası boşa akar; hiçbir
     * hata da görünmez. Mutasyonla bulundu (yükten `operations` çıkarıldığında
     * hiçbir test kırılmıyordu).
     *
     * Çekirdek GERÇEK Woo adapter'ını sürüyor, sahte olan yalnızca HTTP:
     * adapter testi ile sahte-adapterlı çekirdek testi İKİSİ DE yeşilken
     * aradaki sözleşme yanlış olabilir ve bu projede tam bu biçimde iki
     * ölümcül hata bulundu. `pushPrices` gövdesi bugüne kadar HİÇ
     * çalıştırılmamıştı.
     */
    #[Test]
    public function a_successful_push_advances_the_synced_version(): void
    {
        [$tenant, $product, $variant] = $this->makeProduct(price: 100.00, withVariant: true);

        Http::fake([
            '*/products/batch*' => Http::response(['update' => [['id' => 11]]], 200),
            '*' => Http::response([], 200),
        ]);

        $operationId = $this->asTenant($tenant, function () use ($tenant, $variant): string {
            $this->listingFor($variant, externalId: '11');
            $listing = Listing::query()->firstOrFail();

            return $this->openPriceOperation($tenant, $listing)->id;
        });

        (new PushPrices($operationId, $tenant->id))->handle(
            app(PriceBatchBuilder::class),
            app(SyncResultRecorder::class),
            app(AdapterRegistry::class),
        );

        $operation = $this->asTenant($tenant, fn () => SyncOperation::query()->findOrFail($operationId));

        $this->assertSame(
            SyncOperationStatus::COMPLETED,
            $operation->status,
            'başarılı gönderim operasyonu tamamlamadı — sonuç hiçbir operasyona yazılmıyor.',
        );

        // Ham satır okunur: Eloquent kimlik haritası kalıcılık testinde
        // yanıltır ve aynı bellek nesnesini geri verir.
        $state = $this->asTenant($tenant, fn () => DB::table('listing_sync_states')
            ->where('domain', SyncDomain::PRICE->value)
            ->first());

        $this->assertSame(
            2,
            (int) $state->synced_version,
            'synced_version ilerlemedi — satır sonsuza kadar kirli görünür ve her turda yeniden gönderilir.',
        );
    }

    /**
     * FİYAT İŞİ HORIZON'UN DİNLEDİĞİ KUYRUĞA ATILIR.
     *
     * BU TESTİN VARLIK NEDENİ: kuyruk adı uydurulursa iş Redis'e yazılır ve
     * HİÇBİR worker onu almaz — üretimde fiyat sonsuza kadar beklemede kalır,
     * hiçbir hata görünmez ve tüm testler yeşil kalır. Bu tur `price:default`
     * yazıldı, oysa §15 ve `config/horizon.php` `price:high` diyor; elle
     * yakalandı ve test bu yüzden eklendi.
     */
    #[Test]
    public function price_jobs_are_dispatched_to_a_queue_horizon_actually_listens_to(): void
    {
        [$tenant, $product, $variant] = $this->makeProduct(price: 100.00, withVariant: true);

        $this->asTenant($tenant, fn () => $this->listingFor($variant, externalId: '11'));

        $this->asTenant($tenant, fn () => app(UpdateProduct::class)->run(
            product: $product,
            title: $product->title,
            price: 149.90,
        ));

        $event = $this->priceEvent($tenant);
        $this->asTenant($tenant, fn () => app(VariantPriceChangedConsumer::class)->handle($event));

        $configured = collect(config('horizon.defaults'))
            ->flatMap(fn (array $pool): array => $pool['queue'] ?? [])
            ->unique()
            ->all();

        Queue::assertPushed(PushPrices::class, function (PushPrices $job) use ($configured): bool {
            $this->assertContains(
                $job->queue,
                $configured,
                "PushPrices '{$job->queue}' kuyruğuna atıldı ama Horizon o kuyruğu dinlemiyor — ".
                'iş Redis\'te sonsuza kadar bekler ve hiçbir hata görünmez.',
            );

            return true;
        });
    }

    // ---------------------------------------------------------------- yardımcılar

    /** @return array{0: Tenant, 1: Product, 2: Variant} */
    private function makeProduct(float $price, bool $withVariant = false): array
    {
        $tenant = (new CreateTenant)->run(
            name: 'Fiyat '.uniqid(),
            owner: User::factory()->create(),
        );

        [$product, $variant] = $this->asTenant($tenant, function () use ($price): array {
            $this->asSystem(fn () => ChannelType::query()->firstOrCreate(
                ['code' => 'woocommerce'],
                [
                    'name' => 'WooCommerce',
                    'kind' => 'storefront',
                    'adapter_class' => 'App\\Domain\\Channels\\Adapters\\WooCommerce\\WooCommerceAdapter',
                    'is_active' => true,
                ],
            ));

            $product = Product::factory()->create(['content_version' => 1]);
            $variant = Variant::factory()->create([
                'product_id' => $product->id,
                'price' => $price,
                'content_version' => 1,
            ]);

            return [$product, $variant];
        });

        return [$tenant, $product, $variant];
    }

    private function listingFor(
        Variant $variant,
        ?string $externalId,
        string $lifecycle = 'live',
        ?string $connectionId = null,
    ): Listing {
        return Listing::factory()->create([
            'channel_connection_id' => $connectionId ?? ChannelConnection::factory()->create()->id,
            'variant_id' => $variant->id,
            'external_id' => $externalId,
            'lifecycle_status' => $lifecycle,
        ]);
    }

    private function openPriceOperation(Tenant $tenant, Listing $listing): SyncOperation
    {
        return app(OpenSyncOperation::class)->run(
            listing: $listing,
            domain: SyncDomain::PRICE,
            eventVersion: 2,
        );
    }

    private function priceEvent(Tenant $tenant): OutboxEvent
    {
        return $this->asTenant($tenant, fn () => OutboxEvent::query()
            ->where('event_type', 'VariantPriceChanged')
            ->firstOrFail());
    }
}
