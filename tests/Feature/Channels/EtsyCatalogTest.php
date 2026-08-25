<?php

declare(strict_types=1);

namespace Tests\Feature\Channels;

use App\Domain\Catalog\Models\Variant;
use App\Domain\Channels\Adapters\Etsy\EtsyAdapter;
use App\Domain\Channels\Adapters\Etsy\EtsyProductMapper;
use App\Domain\Channels\Contracts\SupportsCatalog;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Models\ChannelType;
use App\Domain\Channels\Support\ChannelHttpClient;
use App\Domain\Channels\Support\CredentialVault;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use App\Domain\Sync\Models\Listing;
use App\Domain\Sync\Support\ListingPayload;
use App\Support\Logging\PayloadRedactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Etsy katalog — slice 3.4.
 *
 * V3.0 · §11.1 · §11.3 · v2.2 §7 · ürün aktarımı kuralları.
 *
 * ─────────────────────────────────────────────────────────────────────
 * ⚠️ ÜÇ SEVİYE, ADLAR TERS — BU SLICE'IN TAMAMI BU EŞLEMEDİR
 * ─────────────────────────────────────────────────────────────────────
 *   Etsy Listing (listing_id)   → BİZİM ÜRÜNÜMÜZ  → external_parent_id
 *   Etsy Product (product_id)   → BİZİM VARYANTIMIZ → external_id
 *   Etsy Offering (offering_id) → fiyat/stok hedefi → channel_metadata
 */
final class EtsyCatalogTest extends TestCase
{
    use RefreshDatabase;

    // ────────────────────────────────────────────── §11.1 · kimlik eşlemesi

    /**
     * ⚠️ `external_id` = `product_id`, `listing_id` DEĞİL.
     *
     * Bizde listing satırı VARYANT BAŞINADIR
     * (`UNIQUE(channel_connection_id, variant_id)`). `listing_id`
     * yazılsaydı üç varyantlı bir ürünün üç listing satırı AYNI
     * `external_id`'yi taşır ve `UNIQUE(channel_connection_id,
     * external_id)` kısıtı ikincisini REDDEDERDİ.
     */
    #[Test]
    public function the_external_id_is_the_product_not_the_listing(): void
    {
        $identity = EtsyProductMapper::toIdentityResult($this->listingBody(), 'TSH-M');

        $this->assertSame(
            '5001',
            $identity['external_id'],
            'external_id ilan kimliğini taşıyor — çok varyantlı üründe '
            .'tekillik kısıtı ikinci varyantı reddederdi.',
        );
        $this->assertSame('9001', $identity['external_parent_id']);
    }

    /**
     * ⚠️ `offering_id` DE OKUNUR — fiyat/stok yazma hedefidir.
     *
     * Okunmasaydı her stok ve fiyat itmesi önce envanteri okumak için EK
     * BİR İSTEK gerektirirdi ve Etsy'de kota GERÇEK bir tavandır
     * (§21: 10.000 istek/gün, hesap başına).
     */
    #[Test]
    public function the_offering_id_is_captured_for_inventory_writes(): void
    {
        $identity = EtsyProductMapper::toIdentityResult($this->listingBody(), 'TSH-M');

        $this->assertSame(
            ['offering_id' => '7001'],
            $identity['channel_metadata'],
        );
    }

    /**
     * ⚠️ VARYANT SKU İLE BULUNUR, KONUMLA DEĞİL.
     *
     * İlk eleman alınsaydı çok varyantlı bir üründe BAŞKA varyantın
     * kimliği yazılır ve o listing satırı SONSUZA KADAR yanlış varyantı
     * güncellerdi — sessiz ve satıcının fark etmesi imkânsız.
     */
    #[Test]
    public function the_variant_is_matched_by_sku(): void
    {
        $identity = EtsyProductMapper::toIdentityResult($this->listingBody(), 'TSH-L');

        $this->assertSame('5002', $identity['external_id']);
        $this->assertSame(['offering_id' => '7002'], $identity['channel_metadata']);
    }

    /**
     * ⚠️ SKU EŞLEŞMEZSE İLK ELEMANA DÜŞÜLMEZ.
     *
     * Düşülseydi yanlış varyantın kimliği yazılır ve hata sessizce
     * KALICILAŞIRDI. Kimlik yoksa `PushListing` bunu görür ve satır
     * "senkron" damgası YEMEZ.
     */
    #[Test]
    public function an_unmatched_sku_yields_no_variant_identity(): void
    {
        $identity = EtsyProductMapper::toIdentityResult($this->listingBody(), 'YOK-OLAN');

        $this->assertArrayNotHasKey('external_id', $identity);
        $this->assertArrayNotHasKey('channel_metadata', $identity);

        // İlan kimliği yine de taşınır: ilan GERÇEKTEN yaratılmıştır ve
        // sonraki tur UPDATE yoluna girmelidir — kopya ilan AÇILMAZ.
        $this->assertSame('9001', $identity['external_parent_id']);
    }

    // ──────────────────────────────────────────────────────── §11.3 · para

    /**
     * ⚠️ ETSY FİYATI NESNEDİR ve `divisor`'A BÖLÜNÜR.
     *
     * Ham `amount` okunsaydı 19.90 TL kanalda 1990 TL görünür ve
     * mutabakat her turda SAHTE bir fiyat çakışması raporlardı — satıcı
     * hiç var olmayan bir kampanyayı sonsuza kadar onaylardı.
     */
    #[Test]
    public function money_is_divided_by_the_divisor(): void
    {
        $this->assertSame('19.90', EtsyProductMapper::money([
            'amount' => 1990, 'divisor' => 100, 'currency_code' => 'TRY',
        ]));
    }

    /** Bozuk para nesnesi `null` döner — uydurma bir fiyat yazılmaz. */
    #[Test]
    public function a_malformed_money_object_is_null(): void
    {
        $this->assertNull(EtsyProductMapper::money(['amount' => 1990, 'divisor' => 0]));
        $this->assertNull(EtsyProductMapper::money([]));
    }

    // ─────────────────────────────────────────────────────── ilan gövdesi

    /**
     * ⚠️ İLAN GÖVDESİ FİYAT VE STOK TAŞIMAZ (§11.3).
     *
     * Etsy'de ikisi de ENVANTER uç noktasında yaşar. Buraya konsaydı
     * ilan yaratma anında bir fiyat yazılır, ardından envanter çağrısı
     * onu EZER ve iki gerçek kaynağı doğardı.
     */
    #[Test]
    public function the_listing_body_carries_no_price_or_stock(): void
    {
        $body = EtsyProductMapper::toListingBody($this->payload());

        $this->assertArrayNotHasKey('price', $body);
        $this->assertArrayNotHasKey('quantity', $body);
        $this->assertSame('Tişört', $body['title']);
    }

    /**
     * ⚠️ YENİ İLAN TASLAK DOĞAR.
     *
     * `active` gönderilseydi ilan STOK YAZILMADAN yayına girer ve satıcı
     * stoksuz ürün satardı. Canlı işaretini `PushListing` kanal onayından
     * SONRA yazar (ürün aktarımı kuralı).
     */
    #[Test]
    public function a_new_listing_is_born_as_a_draft(): void
    {
        $this->assertSame('draft', EtsyProductMapper::toListingBody($this->payload())['state']);
    }

    /**
     * ⚠️ ZORUNLU BEYAN ALANLARI UYDURULMAZ.
     *
     * `who_made` ve `when_made` satıcı adına YASAL bir beyandır.
     * Varsayılan yazmak ("i_did") satıcının adına yanlış beyanda
     * bulunmak olurdu; alan yoksa gövdeye HİÇ konmaz ve kanal eksikliği
     * kendi doğrulamasıyla bildirir.
     */
    #[Test]
    public function legal_declarations_are_never_invented(): void
    {
        $body = EtsyProductMapper::toListingBody($this->payload());

        $this->assertArrayNotHasKey('who_made', $body);
        $this->assertArrayNotHasKey('when_made', $body);
    }

    // ───────────────────────────────────────────────────────── create/update

    /** İlan POST ile açılır ve kimlik üçlüsü döner. */
    #[Test]
    public function creating_a_listing_returns_the_identity_triple(): void
    {
        Http::fake(['*' => Http::response($this->listingBody(), 201)]);

        $result = $this->adapter()->createListing($this->payload());

        $this->assertTrue($result->successful);
        $this->assertSame('5001', $result->data['external_id']);
        $this->assertSame('9001', $result->data['external_parent_id']);
        $this->assertSame(['offering_id' => '7001'], $result->data['channel_metadata']);
    }

    /**
     * ⚠️ İLAN KİMLİĞİ YOKSA BAŞARI DÖNÜLMEZ.
     *
     * Yanıt 200 ama `listing_id` yoksa sözleşme ihlali vardır. Başarı
     * dönülseydi `synced_version` ilerler ve satır kanalda karşılığı
     * OLMADAN "senkron" görünürdü.
     */
    #[Test]
    public function a_response_without_a_listing_id_fails(): void
    {
        Http::fake(['*' => Http::response(['baska' => 'alan'], 200)]);

        $this->assertTrue($this->adapter()->createListing($this->payload())->failed());
    }

    /**
     * ⚠️ GÜNCELLEME HEDEFİ `listing_id`'DİR, `product_id` DEĞİL.
     *
     * İçerik İLAN seviyesindedir; `external_id` (product_id) tek başına
     * ilan uç noktasına verilemez — istek var olmayan bir ilana gider.
     */
    #[Test]
    public function updating_targets_the_listing_id(): void
    {
        Http::fake(['*' => Http::response($this->listingBody(), 200)]);

        $payload = $this->payload(externalId: '5001', externalParentId: '9001');

        $this->assertTrue($this->adapter()->updateListing($payload)->successful);

        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/listings/9001')
            && ! str_contains($request->url(), '/listings/5001')
            && $request->method() === 'PATCH');
    }

    /** Ebeveyn kimliği yoksa güncelleme YAPILMAZ ve çağrı ATILMAZ. */
    #[Test]
    public function updating_without_a_parent_id_fails_without_calling(): void
    {
        Http::fake();

        $result = $this->adapter()->updateListing($this->payload(externalId: '5001'));

        $this->assertTrue($result->failed());
        Http::assertNothingSent();
    }

    // ──────────────────────────────────────────────────────────── delist

    /**
     * ⚠️ `delist` SİLMEZ — `inactive` YAPAR.
     *
     * Silme GERİ ALINAMAZ ve kanaldaki yorumları, FAVORİLERİ, arama
     * sıralamasını ve SEO geçmişini de götürür. Etsy'de bu özellikle
     * ağırdır: favori sayısı satıcının en değerli sinyalidir.
     */
    #[Test]
    public function delist_deactivates_and_never_deletes(): void
    {
        Http::fake(['*' => Http::response(['listing_id' => 9001], 200)]);

        $listing = new Listing;
        $listing->external_parent_id = '9001';

        $this->assertTrue($this->adapter()->delist($listing)->successful);

        Http::assertSent(fn ($request): bool => $request->method() === 'PATCH'
            && ($request->data()['state'] ?? null) === 'inactive');

        Http::assertNotSent(fn ($request): bool => $request->method() === 'DELETE');
    }

    // ──────────────────────────────────────────────── findExistingListing

    /**
     * ⚠️ KANALDA VAR OLAN İLAN BULUNUR — kopya ilan AÇILMAZ.
     *
     * Bu adım atlanırsa satıcının Etsy panelinden açtığı ürün ikinci kez
     * yaratılır; yorumlar, FAVORİLER ve arama sıralaması ilk ilanda kalır
     * ve bu GERİ ALINAMAZ.
     */
    #[Test]
    public function an_existing_listing_is_found_by_sku(): void
    {
        Http::fake(['*' => Http::response(['results' => [$this->listingBody()]], 200)]);

        $variant = new Variant;
        $variant->sku = 'TSH-L';

        $remote = $this->adapter()->findExistingListing($variant);

        $this->assertNotNull($remote);
        $this->assertSame('5002', $remote->externalId);
        $this->assertSame('19.90', $remote->price);
    }

    /** Eşleşme yoksa `null` döner — yanlış ilana bağlanmaz. */
    #[Test]
    public function a_missing_sku_yields_null(): void
    {
        Http::fake(['*' => Http::response(['results' => [$this->listingBody()]], 200)]);

        $variant = new Variant;
        $variant->sku = 'HIC-YOK';

        $this->assertNull($this->adapter()->findExistingListing($variant));
    }

    /** SKU'suz varyant için çağrı HİÇ atılmaz — boşuna kota harcanmaz. */
    #[Test]
    public function a_variant_without_a_sku_never_calls_the_channel(): void
    {
        Http::fake();

        $variant = new Variant;
        $variant->sku = '';

        $this->assertNull($this->adapter()->findExistingListing($variant));
        Http::assertNothingSent();
    }

    // ────────────────────────────────────────────────────── fetchListing

    /**
     * ⚠️ 404 İSTİSNA DEĞİLDİR — "ilan silinmiş" demektir.
     *
     * Mutabakat bunu `REMOTE_MISSING` olarak görmeli ve tur ÇÖKMEMELİDİR.
     * İstisna fırlatılsaydı tek silinmiş ilan tüm mutabakat turunu
     * düşürürdü.
     */
    #[Test]
    public function a_deleted_listing_reads_as_null_not_an_exception(): void
    {
        Http::fake(['*' => Http::response(['error' => 'not found'], 404)]);

        $listing = new Listing;
        $listing->external_parent_id = '9001';

        $this->assertNull($this->adapter()->fetchListing($listing));
    }

    /** Uzak durum okunur — mutabakatın girdisi. */
    #[Test]
    public function the_remote_state_is_read(): void
    {
        Http::fake(['*' => Http::response($this->listingBody(), 200)]);

        $listing = new Listing;
        $listing->external_parent_id = '9001';

        $remote = $this->adapter()->fetchListing($listing);

        $this->assertNotNull($remote);
        $this->assertSame('Tişört', $remote->title);
        $this->assertSame('active', $remote->status);
        $this->assertSame('19.90', $remote->price);
    }

    /** Yetenek `instanceof` ile okunur. */
    #[Test]
    public function the_adapter_declares_the_catalog_capability(): void
    {
        $this->assertInstanceOf(SupportsCatalog::class, $this->adapter());
    }

    // ────────────────────────────────────────────────────────── yardımcılar

    /**
     * İki varyantlı gerçekçi Etsy ilanı.
     *
     * @return array<string, mixed>
     */
    private function listingBody(): array
    {
        return [
            'listing_id' => 9001,
            'title' => 'Tişört',
            'state' => 'active',
            'quantity' => 5,
            'url' => 'https://www.etsy.com/listing/9001',
            'price' => ['amount' => 1990, 'divisor' => 100, 'currency_code' => 'TRY'],
            'inventory' => [
                'products' => [
                    [
                        'product_id' => 5001,
                        'sku' => 'TSH-M',
                        'offerings' => [['offering_id' => 7001, 'quantity' => 3]],
                    ],
                    [
                        'product_id' => 5002,
                        'sku' => 'TSH-L',
                        'offerings' => [['offering_id' => 7002, 'quantity' => 2]],
                    ],
                ],
            ],
        ];
    }

    private function payload(
        ?string $externalId = null,
        ?string $externalParentId = null,
    ): ListingPayload {
        $listing = new Listing;
        $listing->external_id = $externalId;
        $listing->external_parent_id = $externalParentId;

        $variant = new Variant;
        $variant->sku = 'TSH-M';
        $listing->setRelation('variant', $variant);

        return new ListingPayload(
            listing: $listing,
            title: 'Tişört',
            description: 'Pamuklu tişört',
            categoryId: '3',
        );
    }

    private function adapter(): EtsyAdapter
    {
        [$tenant, $connection] = $this->connected();

        return $this->asTenant($tenant, fn (): EtsyAdapter => new EtsyAdapter(
            $connection,
            new ChannelHttpClient(
                $connection,
                app(CredentialVault::class),
                app(PayloadRedactor::class),
            ),
        ));
    }

    /** @return array{0: Tenant, 1: ChannelConnection} */
    private function connected(): array
    {
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
            name: 'Etsy Katalog '.uniqid(),
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

        return [$tenant, $connection];
    }
}
