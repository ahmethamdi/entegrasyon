<?php

declare(strict_types=1);

namespace Tests\Feature\Channels;

use App\Domain\Catalog\Models\Variant;
use App\Domain\Channels\Adapters\Ebay\EbayAdapter;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Models\ChannelType;
use App\Domain\Channels\Registry\AdapterRegistry;
use App\Domain\Channels\Support\ChannelHttpClient;
use App\Domain\Channels\Support\CircuitBreaker;
use App\Domain\Channels\Support\CredentialVault;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use App\Domain\Sync\Actions\OpenSyncOperation;
use App\Domain\Sync\Enums\SyncDomain;
use App\Domain\Sync\Enums\SyncIntent;
use App\Domain\Sync\Jobs\PushOfferListing;
use App\Domain\Sync\Models\Listing;
use App\Domain\Sync\Models\SyncOperation;
use App\Domain\Sync\Support\ListingPayload;
use App\Domain\Sync\Support\ListingPayloadBuilder;
use App\Domain\Sync\Support\SyncResultRecorder;
use App\Support\Logging\PayloadRedactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * eBay üç adımlı yayın zinciri — slice 4.4 (GERÇEK GÖVDELER).
 *
 * V3.0 · §13.1 · §13.2 · §17 · §03 · Delta 1.
 *
 * ⚠️ ÇEKİRDEK TARAFI 4.3'TE SÜRÜLDÜ (`PushOfferListingTest`, sahte
 * adapter'la). BURADA SÜRÜLEN ŞEY GERÇEK `EbayAdapter` GÖVDELERİDİR:
 * hangi adrese, hangi metotla, hangi gövdeyle gidiliyor ve yanıttan
 * hangi kimlik çıkarılıyor. İkisi FARKLI soruları cevaplar ve biri
 * ötekinin yerine geçmez ("yazıldı" ≠ "çağrılıyor" kuralının aynısı).
 *
 * ⚠️ `Http::fake()` AYNI TESTTE İKİ KEZ ÇAĞRILMAZ — ikinci çağrı
 * birincinin YERİNE GEÇMEZ ve iki farklı gövde bekleyen test SAHTE YEŞİL
 * olur. Bu dosyada her senaryo TEK `fake()` kurar; sıralı yanıt gerekiyorsa
 * `Http::sequence()` kullanılır (slice 4.1'de bu tuzak YİNE ısırdı).
 */
final class EbayOfferLifecycleTest extends TestCase
{
    use RefreshDatabase;

    // ─────────────────────────────────────── ① envanter kalemi (§13.1)

    /**
     * Envanter kalemi SKU ile adreslenir ve `PUT` ile yazılır.
     *
     * ⚠️ ADRES `offer_id` DEĞİL SKU TAŞIR. Karıştırılsaydı istek var
     * olmayan bir kaynağa gider ve 404 alınırdı (§13.1: "ikisi FARKLI
     * kimliklerdir").
     */
    #[Test]
    public function the_inventory_item_is_written_with_put_addressed_by_sku(): void
    {
        [$adapter, $listing] = $this->scenario(sku: 'TSH-KIRMIZI-M');

        Http::fake([
            // Kalem henüz YOK — zincirin ilk turunda bu NORMALDİR.
            '*/inventory_item/*' => Http::sequence()
                ->push(['errors' => [['errorId' => 25710]]], 404)
                ->push([], 204),
        ]);

        $adapter->upsertInventoryItem($listing, $this->payload($listing));

        Http::assertSent(static fn (Request $r): bool => $r->method() === 'PUT'
            && str_contains($r->url(), '/sell/inventory/v1/inventory_item/TSH-KIRMIZI-M'));
    }

    /**
     * ⚠️ ENVANTER KALEMİ UZAK KİMLİK DÖNDÜRMEZ — kimlik SKU'nun
     * KENDİSİDİR (§13.1).
     *
     * Boş olmayan bir sonuç dönseydi `PushOfferListing::persist()` satırı
     * her turda gereksizce UPDATE ederdi; daha kötüsü, uydurma bir
     * `external_id` yazılsaydı satır yayınlanmamışken "canlı" görünürdü.
     */
    #[Test]
    public function the_inventory_item_step_returns_no_remote_identity(): void
    {
        [$adapter, $listing] = $this->scenario();

        Http::fake(['*' => Http::response([], 204)]);

        $result = $adapter->upsertInventoryItem($listing, $this->payload($listing));

        $this->assertTrue($result->successful);
        $this->assertSame([], $result->data);
    }

    /**
     * ⚠️ MEVCUT MİKTAR OKUNUR VE GÖVDEYE GERİ YAZILIR — Etsy'nin
     * "oku-birleştir-yaz" kuralının eBay karşılığı.
     *
     * eBay'in `PUT /inventory_item` TAM DEĞİŞTİRME yapar: `availability`
     * bloğu gönderilmezse kanaldaki miktar SIFIRLANIR ve ürün SATIŞA
     * KAPANIR. Bir İÇERİK turu sessizce bir STOK sıfırlaması yapardı ve
     * kanal 200 döndüğü için senkron BAŞARILI görünürdü.
     */
    #[Test]
    public function the_existing_quantity_is_read_and_written_back(): void
    {
        [$adapter, $listing] = $this->scenario();

        Http::fake([
            '*/inventory_item/*' => Http::sequence()
                ->push(['availability' => ['shipToLocationAvailability' => ['quantity' => 40]]], 200)
                ->push([], 204),
        ]);

        $adapter->upsertInventoryItem($listing, $this->payload($listing));

        Http::assertSent(static function (Request $r): bool {
            if ($r->method() !== 'PUT') {
                return false;
            }

            return ($r->data()['availability']['shipToLocationAvailability']['quantity'] ?? null) === 40;
        });
    }

    /**
     * ⚠️ KALEM YOKSA (404) MİKTAR BLOĞU HİÇ YAZILMAZ — 0 DA YAZILMAZ.
     *
     * 0 yazılsaydı ürün daha yaratılırken satışa KAPALI doğardı ve
     * satıcı sebebini stok ekranında arardı — oysa sorun içerik turunda.
     */
    #[Test]
    public function a_missing_item_writes_no_quantity_block_at_all(): void
    {
        [$adapter, $listing] = $this->scenario();

        Http::fake([
            '*/inventory_item/*' => Http::sequence()
                ->push(['errors' => [['errorId' => 25710]]], 404)
                ->push([], 204),
        ]);

        $adapter->upsertInventoryItem($listing, $this->payload($listing));

        Http::assertSent(static fn (Request $r): bool => $r->method() === 'PUT'
            && ! array_key_exists('availability', $r->data()));
    }

    /**
     * ⚠️ OKUMA BAŞARISIZSA YAZMA HAKKI DA YOKTUR (Etsy kuralının aynısı).
     *
     * 500 alınmışken miktar "bilinmiyor" sayılıp blok atılsaydı, kanalda
     * 40 adet duran ürün bir içerik turuyla SIFIRA düşerdi. Ayırt edici
     * işaret istisnadır — ve `PUT` HİÇ ATILMAMALIDIR.
     */
    #[Test]
    public function a_failed_read_aborts_the_write(): void
    {
        [$adapter, $listing] = $this->scenario();

        Http::fake(['*/inventory_item/*' => Http::response(['errors' => []], 500)]);

        try {
            $adapter->upsertInventoryItem($listing, $this->payload($listing));
            $this->fail('Okuma 500 verdiğinde istisna beklenirdi.');
        } catch (RequestException) {
            // beklenen
        }

        Http::assertNotSent(static fn (Request $r): bool => $r->method() === 'PUT');
    }

    // ──────────────────────────────────────────────── ② offer (§13.2)

    /**
     * ⚠️ `offer_id` YOKSA `POST` ATILIR ve DÖNEN KİMLİK KURTARMA
     * ÇIPASIDIR (§13.2).
     *
     * Yazılmazsa sonraki tur `POST /offer`'ı İKİNCİ KEZ çağırır, eBay
     * `25002` (duplicate offer) döner ve o hata KALICIDIR — listing
     * "düzeltilemez" damgasıyla ölür.
     */
    #[Test]
    public function creating_an_offer_returns_the_offer_id_as_metadata(): void
    {
        [$adapter, $listing] = $this->scenario();

        Http::fake(['*' => Http::response(['offerId' => '8912345'], 201)]);

        $result = $adapter->upsertOffer($listing, $this->payload($listing));

        $this->assertSame(
            ['offer_id' => '8912345'],
            $result->data['channel_metadata'] ?? null,
            'Kurtarma çıpası yazılmadı — sonraki tur `25002` duplicate alırdı.',
        );

        Http::assertSent(static fn (Request $r): bool => $r->method() === 'POST'
            && str_ends_with($r->url(), '/sell/inventory/v1/offer'));
    }

    /**
     * ⚠️ `offer_id` VARSA `PUT` ATILIR — İKİNCİ OFFER YARATILMAZ.
     *
     * Bu, zincirin "kaldığı yerden devam" kuralının ikinci adımdaki
     * yüzüdür. `POST` atılsaydı eBay `25002` döner ve hata KALICI olurdu.
     */
    #[Test]
    public function an_existing_offer_is_updated_with_put_not_recreated(): void
    {
        [$adapter, $listing] = $this->scenario(metadata: ['offer_id' => '8912345']);

        Http::fake(['*' => Http::response([], 204)]);

        $adapter->upsertOffer($listing, $this->payload($listing));

        Http::assertSent(static fn (Request $r): bool => $r->method() === 'PUT'
            && str_ends_with($r->url(), '/sell/inventory/v1/offer/8912345'));

        Http::assertNotSent(static fn (Request $r): bool => $r->method() === 'POST');
    }

    /**
     * ⚠️ OFFER ADRESİ `channel_metadata`'DAN OKUNUR, `external_id`'DEN
     * DEĞİL.
     *
     * `external_id` = `listing_id`'dir ve offer'ı ADRESLEMEZ; onunla
     * `PUT /offer/{id}` çağrılsaydı istek var olmayan bir kaynağa gider
     * ve 404 alınırdı (§13.1). İki kimlik AYRI değerlerle kurulur ki
     * yanlış olanı okuyan mutasyon YAKALANSIN.
     */
    #[Test]
    public function the_offer_is_addressed_by_offer_id_not_by_external_id(): void
    {
        [$adapter, $listing] = $this->scenario(
            metadata: ['offer_id' => '8912345'],
            externalId: '110566778899',
        );

        Http::fake(['*' => Http::response([], 204)]);

        $adapter->upsertOffer($listing, $this->payload($listing));

        Http::assertSent(static fn (Request $r): bool => str_ends_with($r->url(), '/offer/8912345'));
        Http::assertNotSent(static fn (Request $r): bool => str_contains($r->url(), '110566778899'));
    }

    /**
     * ⚠️ BEŞ `settings` ALANI GÖVDEYE GİRER (§17) — hepsi ZORUNLUDUR.
     *
     * Eksik politika offer yaratmada `VALIDATION` üretir ve o KALICIDIR.
     * Alan alan iddia edilir: tek bir "gövde dolu" iddiası, üç politikadan
     * birini düşüren mutasyonu KAÇIRIRDI.
     */
    #[Test]
    public function the_offer_body_carries_the_location_marketplace_and_policy_triple(): void
    {
        [$adapter, $listing] = $this->scenario();

        Http::fake(['*' => Http::response(['offerId' => '1'], 201)]);

        $adapter->upsertOffer($listing, $this->payload($listing));

        Http::assertSent(static function (Request $r): bool {
            $body = $r->data();

            return ($body['merchantLocationKey'] ?? null) === 'WAREHOUSE-1'
                && ($body['marketplaceId'] ?? null) === 'EBAY_DE'
                && ($body['listingPolicies']['fulfillmentPolicyId'] ?? null) === 'FP-1'
                && ($body['listingPolicies']['paymentPolicyId'] ?? null) === 'PP-1'
                && ($body['listingPolicies']['returnPolicyId'] ?? null) === 'RP-1';
        });
    }

    /**
     * ⚠️ PARA BİRİMİ MARKETPLACE'TEN GELİR, `variants.currency`'DEN
     * DEĞİL.
     *
     * Kanonik kolonun varsayılanı `TRY`'dir ve `EBAY_DE`'ye TRY fiyat
     * gönderilseydi `VALIDATION` alınır, o hata KALICI olurdu. Varyant
     * bilinçli olarak TRY taşır ki kanonik değeri okuyan mutasyon
     * YAKALANSIN.
     */
    #[Test]
    public function the_price_currency_comes_from_the_marketplace_not_the_variant(): void
    {
        [$adapter, $listing] = $this->scenario();

        Http::fake(['*' => Http::response(['offerId' => '1'], 201)]);

        $adapter->upsertOffer($listing, $this->payload($listing));

        Http::assertSent(static function (Request $r): bool {
            $price = $r->data()['pricingSummary']['price'] ?? [];

            return ($price['currency'] ?? null) === 'EUR'
                && ($price['value'] ?? null) === '199.90';
        });
    }

    /**
     * ⚠️ İÇERİK TURU STOĞA DOKUNMAZ — offer gövdesi MİKTAR TAŞIMAZ.
     *
     * Offer'ın `availableQuantity` alanı vardır ve doldurulsaydı HER
     * içerik turu kanaldaki stoğu ezerdi (v2.2 · katalog kuralı). Miktar
     * slice 4.6'nın işidir ve MUTLAK değerle gider.
     */
    #[Test]
    public function the_offer_body_never_carries_a_quantity(): void
    {
        [$adapter, $listing] = $this->scenario();

        Http::fake(['*' => Http::response(['offerId' => '1'], 201)]);

        $adapter->upsertOffer($listing, $this->payload($listing));

        Http::assertSent(static fn (Request $r): bool => ! array_key_exists('availableQuantity', $r->data()));
    }

    /**
     * ⚠️ `offerId` GELMEZSE İSTİSNA FIRLATILIR, sessizce boş DÖNÜLMEZ.
     *
     * Sessiz dönüş `PushOfferListing`'i üçüncü adıma geçirir,
     * `publishOffer` kimliksiz kalır ve satır "yayınlandı" görünürken
     * kanalda hiçbir şey olmazdı.
     */
    #[Test]
    public function a_create_response_without_an_offer_id_throws(): void
    {
        [$adapter, $listing] = $this->scenario();

        Http::fake(['*' => Http::response(['mesaj' => 'tamam'], 201)]);

        $this->expectException(RuntimeException::class);

        $adapter->upsertOffer($listing, $this->payload($listing));
    }

    // ──────────────────────────────────────────────── ③ yayın (§13.1)

    /**
     * Yayın `listing_id` döner ve o `external_id` OLUR — `offer_id`
     * DEĞİL (§13.1).
     *
     * `external_id` satıcının kanalda GÖRDÜĞÜ ilandır; panel onu link
     * olarak gösterir ve mutabakat onunla sorgular.
     */
    #[Test]
    public function publishing_returns_the_listing_id_as_external_id(): void
    {
        [$adapter, $listing] = $this->scenario(metadata: ['offer_id' => '8912345']);

        Http::fake(['*' => Http::response(['listingId' => '110566778899'], 200)]);

        $result = $adapter->publishOffer($listing);

        $this->assertSame('110566778899', $result->data['external_id'] ?? null);

        Http::assertSent(static fn (Request $r): bool => $r->method() === 'POST'
            && str_ends_with($r->url(), '/offer/8912345/publish'));
    }

    /**
     * ⚠️ `offer_id` YOKSA İSTEK HİÇ ATILMAZ.
     *
     * Zincirin ikinci adımı hiç koşmamış demektir; kimliksiz bir yayın
     * isteği literal yer tutucu taşıyan bir adrese gider ve 404'ün sebebi
     * hiçbir yerde görünmezdi.
     */
    #[Test]
    public function publishing_without_an_offer_id_sends_nothing(): void
    {
        [$adapter, $listing] = $this->scenario();

        Http::fake(['*' => Http::response(['listingId' => '1'], 200)]);

        try {
            $adapter->publishOffer($listing);
            $this->fail('`offer_id` yokken istisna beklenirdi.');
        } catch (RuntimeException) {
            // beklenen
        }

        Http::assertNothingSent();
    }

    /**
     * ⚠️ `listingId` GELMEZSE İSTİSNA — satır kimliksiz "canlı"
     * yazılmaz.
     *
     * Sessizce başarı dönseydi `lifecycle_status = live` yazılır ama
     * `external_id` boş kalırdı; mutabakat o satırı sorgulayamaz ve
     * fan-out ona sonsuza kadar iş atardı.
     */
    #[Test]
    public function a_publish_response_without_a_listing_id_throws(): void
    {
        [$adapter, $listing] = $this->scenario(metadata: ['offer_id' => '8912345']);

        Http::fake(['*' => Http::response(['warnings' => []], 200)]);

        $this->expectException(RuntimeException::class);

        $adapter->publishOffer($listing);
    }

    // ────────────────────────────────────────────────── withdraw (§13.1)

    /**
     * ⚠️ `delist` SİLMEZ — `withdraw` çağrılır, `DELETE` ASLA.
     *
     * Silme geri alınamaz ve `offer_id`'yi de götürür; o kimlik
     * kaybedilirse listing'e bir daha stok gönderilemez ve yeniden
     * yaratmak `25002` verir.
     */
    #[Test]
    public function withdrawing_uses_the_withdraw_endpoint_and_never_deletes(): void
    {
        [$adapter, $listing] = $this->scenario(metadata: ['offer_id' => '8912345']);

        Http::fake(['*' => Http::response([], 200)]);

        $adapter->withdrawOffer($listing);

        Http::assertSent(static fn (Request $r): bool => $r->method() === 'POST'
            && str_ends_with($r->url(), '/offer/8912345/withdraw'));

        Http::assertNotSent(static fn (Request $r): bool => $r->method() === 'DELETE');
    }

    /**
     * ⚠️ HİÇ YAYINLANMAMIŞ SATIRI KALDIRMAK NO-OP'TUR, HATA DEĞİL.
     *
     * İstisna fırlatılsaydı taslak bir listing'i delist etmek kalıcı
     * hataya düşerdi — oysa yapacak bir şey yok ve bu eksiklik değil
     * satırın hâlidir (`acknowledgeOrder` kararının aynısı).
     */
    #[Test]
    public function withdrawing_an_unpublished_listing_is_a_no_op(): void
    {
        [$adapter, $listing] = $this->scenario();

        Http::fake(['*' => Http::response([], 200)]);

        $this->assertTrue($adapter->withdrawOffer($listing)->successful);

        Http::assertNothingSent();
    }

    // ────────────────────────────────────────────── ortak · sandbox/dil

    /**
     * ⚠️ `Content-Language` BAŞLIĞI ZORUNLUDUR ve MARKETPLACE'TEN
     * TÜRETİLİR.
     *
     * Eksikse eBay `VALIDATION` döner ve o hata KALICIDIR — gövde ve
     * kimlik DOĞRUYKEN istek reddedilir ve sebep hiçbir yerde görünmez
     * (Hepsiburada'nın `User-Agent` kuralının eBay karşılığı). `en-US`
     * sabitlenseydi `EBAY_DE` ilanları yanlış dil etiketiyle giderdi.
     */
    #[Test]
    public function write_calls_carry_the_marketplace_content_language(): void
    {
        [$adapter, $listing] = $this->scenario();

        Http::fake(['*' => Http::response(['offerId' => '1'], 201)]);

        $adapter->upsertOffer($listing, $this->payload($listing));

        Http::assertSent(static fn (Request $r): bool => $r->header('Content-Language') === ['de-DE']);
    }

    /**
     * ⚠️ VARSAYILAN ÜRETİMDİR — zincirin her adımı için de geçerli.
     *
     * Varsayılan sandbox olsaydı satıcının gerçek mağazası yerine boş bir
     * test hesabına yazılır ve "senkron başarılı" görünürken hiçbir şey
     * değişmezdi.
     */
    #[Test]
    public function the_chain_goes_to_production_unless_the_sandbox_flag_is_set(): void
    {
        [$adapter, $listing] = $this->scenario();

        Http::fake(['*' => Http::response(['offerId' => '1'], 201)]);

        $adapter->upsertOffer($listing, $this->payload($listing));

        Http::assertSent(static fn (Request $r): bool => str_starts_with($r->url(), 'https://api.ebay.com/'));
    }

    // ─────────────────────── GERÇEK İŞ · zincir uçtan uca (§13.2 · P0)

    /**
     * ⚠️ "YAZILDI" ≠ "ÇAĞRILIYOR" — zincir GERÇEK `PushOfferListing`
     * işiyle sürülür.
     *
     * Yukarıdaki testler adapter gövdelerini DOĞRUDAN çağırır ve onların
     * doğru çalıştığını kanıtlar; AKIŞTAN çağrıldıklarını KANITLAMAZ.
     * Bu test üç adımın sırayla gittiğini ve kimliklerin SATIRA
     * yazıldığını gerçek işle doğrular (Etsy'de aynı kural: "iki yön de
     * sürülür").
     */
    #[Test]
    public function the_real_job_drives_the_whole_chain_and_persists_both_identities(): void
    {
        [, $listing, $tenant] = $this->scenario();

        Http::fake([
            // ① okuma (kalem yok) → ① yazma → ② offer → ③ publish
            '*/inventory_item/*' => Http::sequence()
                ->push(['errors' => [['errorId' => 25710]]], 404)
                ->push([], 204),
            '*/offer/*/publish' => Http::response(['listingId' => '110566778899'], 200),
            '*/offer' => Http::response(['offerId' => '8912345'], 201),
        ]);

        $this->runChainJob($tenant, $listing);

        $fresh = $this->asTenant($tenant, fn (): ?Listing => $listing->fresh());

        $this->assertSame(
            '110566778899',
            $fresh?->external_id,
            'Yayın kimliği satıra yazılmadı — mutabakat bu satırı sorgulayamazdı.',
        );

        $this->assertSame(
            '8912345',
            $fresh?->channel_metadata['offer_id'] ?? null,
            'KURTARMA ÇIPASI yazılmadı — sonraki tur `25002` duplicate alırdı.',
        );

        $this->assertSame('live', $fresh?->lifecycle_status);
    }

    /**
     * ⚠️ ARA BAŞARISIZLIK KALDIĞI YERDEN DEVAM EDER — Delta 1'İN VARLIK
     * SEBEBİ (§13.2).
     *
     * Birinci turda offer yaratılır ve publish 429 alır; `offer_id`
     * SATIRDA KALIR. İkinci tur `POST /offer`'ı BİR DAHA çağırmaz —
     * çağırsaydı eBay `25002` (duplicate offer) döner, hata KALICI olur
     * ve listing "düzeltilemez" damgasıyla ölürdü.
     *
     * ⚠️ AYIRT EDİCİ İŞARET İKİNCİ TURDA `POST /offer`'IN HİÇ
     * ATILMAMASIDIR. Yalnızca "sonunda yayınlandı" iddia edilseydi,
     * zinciri baştan çalıştıran bir mutasyon da yeşil kalırdı: sahte
     * kanal `25002` döndürmez ve ikinci offer sorunsuz yaratılırdı.
     */
    #[Test]
    public function an_interrupted_chain_resumes_from_the_stored_offer_id(): void
    {
        [, $listing, $tenant] = $this->scenario();

        // ⚠️ TEK `Http::fake()` — İKİ TURU BİRDEN KURAR.
        //
        // İki ayrı `fake()` yazılsaydı İKİNCİSİ BİRİNCİNİN YERİNE
        // GEÇMEZDİ: ilk turun `*/inventory_item/*` dizisi TÜKENMİŞ
        // hâlde kalır ve ikinci tur "response sequence is empty" ile
        // NETWORK hatası alırdı — test "zincir devam etmedi" derken
        // aslında sahte kanalın kurulumunu ölçmüş olurdu. Bu tuzak
        // CLAUDE.md'de YAZILI ve bu turda YİNE ısırdı (ölçüldü).
        //
        // Dizi İKİ TURU birden taşır:
        //   tur 1 → kalem YOK (404) · yazma 204
        //   tur 2 → kalem VAR, miktar 7 · yazma 204
        Http::fake([
            '*/inventory_item/*' => Http::sequence()
                ->push(['errors' => [['errorId' => 25710]]], 404)
                ->push([], 204)
                ->push(['availability' => ['shipToLocationAvailability' => ['quantity' => 7]]], 200)
                ->push([], 204),
            // Publish TUR 1'de 429, TUR 2'de başarılı.
            '*/offer/*/publish' => Http::sequence()
                ->push(['errors' => []], 429)
                ->push(['listingId' => '110566778899'], 200),
            // ⚠️ TUR 2'DE BURAYA `PUT` GELİR (offer GÜNCELLEME).
            '*/offer/8912345' => Http::response([], 204),
            // Offer YARATMA. Tur 2'de buraya İKİNCİ KEZ gelinmemelidir
            // ve bunu `assertNotSent` kanıtlar: gerçek kanal o çağrıya
            // `25002` (duplicate offer) döner ve hata KALICI olurdu.
            '*/offer' => Http::response(['offerId' => '8912345'], 201),
        ]);

        // ─── TUR 1: offer yaratıldı, publish 429 aldı.
        $this->runChainJob($tenant, $listing);

        $afterCrash = $this->asTenant($tenant, fn (): ?Listing => $listing->fresh());

        $this->assertSame(
            '8912345',
            $afterCrash?->channel_metadata['offer_id'] ?? null,
            'Ara başarısızlıkta `offer_id` KAYBOLDU — Delta 1 devre dışı.',
        );
        $this->assertNull($afterCrash?->external_id, 'Yayın başarısızken kimlik yazılmamalı.');

        // ⚠️ DEVRE KESİCİ SIFIRLANIR — 429 onu AÇTI ve açık devrede iş
        // kanala HİÇ gitmez (`release`, deneme AÇILMAZ). Gerçekte
        // sonraki tur `PAUSE_SECONDS` sonra koşar; testte beklemek
        // yerine devre sıfırlanır. Sıfırlanmasaydı bu test "zincir
        // devam etmedi" derken aslında HİÇBİR ŞEY sürmemiş olurdu —
        // ikinci turda tek bir istek bile atılmıyordu (ölçüldü).
        $this->asTenant($tenant, fn () => app(CircuitBreaker::class)
            ->reset($listing->fresh()->channel_connection_id));

        // ─── TUR 2: yeni bir içerik olayı zinciri yeniden açar; yalnızca
        // KALAN adım gitmeli.
        $this->runChainJob($tenant, $listing, version: 2);

        // ⚠️ AYIRT EDİCİ İŞARET: `POST /offer` TOPLAMDA BİR KEZ ATILDI.
        //
        // `assertNotSent` KULLANILAMAZ — tur 1 o çağrıyı MEŞRU olarak
        // yapar ve iddia her hâlükârda kırmızı olurdu. Sayıya bakmak
        // zorunludur: zinciri baştan çalıştıran bir mutasyon ikinci bir
        // POST atar ve gerçek kanalda `25002` (duplicate offer) alırdı —
        // KALICI hata.
        $offerCreates = Http::recorded(static fn (Request $r): bool => $r->method() === 'POST'
            && str_ends_with($r->url(), '/sell/inventory/v1/offer'));

        $this->assertCount(
            1,
            $offerCreates,
            '`POST /offer` birden çok kez atıldı — zincir kaldığı yerden '
            .'devam etmedi ve gerçek kanalda `25002` duplicate alınırdı.',
        );

        $final = $this->asTenant($tenant, fn (): ?Listing => $listing->fresh());

        $this->assertSame('110566778899', $final?->external_id);
        $this->assertSame(
            '8912345',
            $final?->channel_metadata['offer_id'] ?? null,
            'İkinci tur YENİ bir offer yarattı — `25002` duplicate kaçınılmazdı.',
        );
    }

    // ──────────────────────────────────────────────────────── yardımcılar

    /**
     * Gerçek `PushOfferListing` işini gerçek adapter'la yürütür.
     *
     * ⚠️ İKİNCİ TUR YENİ BİR SÜRÜMLE AÇILIR. Aynı sürümle çağrılsaydı
     * `OpenSyncOperation`'ın sürüm kapısı operasyonu ELERDİ
     * (`desired_version > eventVersion` → ele) ve iş HİÇ koşmazdı;
     * test "zincir devam etmedi" derken aslında hiçbir şey sürmemiş
     * olurdu. Gerçekte ikinci turu ya yeni bir içerik olayı ya da
     * mutabakat REPAIR'i açar.
     */
    private function runChainJob(Tenant $tenant, Listing $listing, int $version = 1): void
    {
        $operation = $this->asTenant($tenant, fn (): SyncOperation => app(OpenSyncOperation::class)->run(
            listing: $listing,
            domain: SyncDomain::CONTENT,
            eventVersion: $version,
            intent: SyncIntent::NORMAL_SYNC,
        ));

        (new PushOfferListing($operation->id, $tenant->id))->handle(
            app(ListingPayloadBuilder::class),
            app(SyncResultRecorder::class),
            app(AdapterRegistry::class),
        );
    }

    private function payload(Listing $listing): ListingPayload
    {
        return new ListingPayload(
            listing: $listing,
            title: 'Kırmızı Tişört',
            description: 'Pamuklu',
            version: 1,
        );
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     * @return array{0: EbayAdapter, 1: Listing, 2: Tenant}
     */
    private function scenario(
        string $sku = 'TSH-1',
        ?array $metadata = null,
        ?string $externalId = null,
    ): array {
        $this->asSystem(fn (): ChannelType => ChannelType::query()->updateOrCreate(
            ['code' => 'ebay'],
            [
                'name' => 'eBay',
                'kind' => 'marketplace',
                'adapter_class' => EbayAdapter::class,
                'supports_webhooks' => false,
                'is_active' => false,
            ],
        ));

        $tenant = (new CreateTenant)->run(
            name: 'eBay '.uniqid(),
            owner: User::factory()->create(),
        );

        return $this->asTenant($tenant, function () use ($sku, $metadata, $externalId, $tenant): array {
            $connection = ChannelConnection::factory()->create([
                'channel_type_code' => 'ebay',
                'external_account_id' => 'ebay-seller-'.uniqid(),
                'status' => 'active',
                'settings' => [
                    'merchant_location_key' => 'WAREHOUSE-1',
                    'marketplace_id' => 'EBAY_DE',
                    'fulfillment_policy_id' => 'FP-1',
                    'payment_policy_id' => 'PP-1',
                    'return_policy_id' => 'RP-1',
                ],
            ]);

            app(CredentialVault::class)->store($connection, [
                'client_id' => 'app-id',
                'client_secret' => 'cert-id',
                'access_token' => 'gecerli-access',
                'refresh_token' => 'gecerli-refresh',
            ]);

            $variant = Variant::factory()->create([
                'sku' => $sku,
                'price' => '199.90',
                // ⚠️ KANONİK PARA BİRİMİ TRY — marketplace EUR ister.
                // Ayrışmaları BİLİNÇLİDİR: bu kolonu okuyan bir mutasyon
                // ancak böyle yakalanır.
                'currency' => 'TRY',
            ]);

            $listing = Listing::factory()->create([
                'channel_connection_id' => $connection->id,
                'variant_id' => $variant->id,
                'external_id' => $externalId,
                'lifecycle_status' => $externalId === null ? 'draft' : 'live',
                'channel_metadata' => $metadata,
            ]);

            return [
                new EbayAdapter($connection, new ChannelHttpClient(
                    $connection,
                    app(CredentialVault::class),
                    app(PayloadRedactor::class),
                )),
                $listing,
                $tenant,
            ];
        });
    }
}
