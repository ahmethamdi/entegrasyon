<?php

declare(strict_types=1);

namespace Tests\Feature\Channels;

use App\Domain\Channels\Adapters\Ticimax\TicimaxAdapter;
use App\Domain\Channels\Adapters\Ticimax\TicimaxSoapFault;
use App\Domain\Channels\Contracts\SupportsCatalog;
use App\Domain\Channels\Contracts\SupportsCatalogImport;
use App\Domain\Channels\Contracts\SupportsInventory;
use App\Domain\Channels\Contracts\SupportsOrders;
use App\Domain\Channels\Contracts\SupportsPricing;
use App\Domain\Channels\Contracts\SupportsTokenRefresh;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Models\ChannelType;
use App\Domain\Channels\Support\ChannelHttpClient;
use App\Domain\Channels\Support\CredentialVault;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use App\Domain\Sync\Enums\ErrorClass;
use App\Domain\Sync\Models\Listing;
use App\Domain\Sync\Support\InventoryPushBatch;
use App\Domain\Sync\Support\InventoryPushItem;
use App\Domain\Sync\Support\PricePushBatch;
use App\Support\Logging\PayloadRedactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TicimaxAdapter — mağazanın kendi alan adındaki WCF/SOAP servisleri.
 *
 * Yanıtlar canlı WSDL'deki öğe adları ve WCF'in gerçek ad alanı
 * önekleriyle (`a:`, `i:nil`) kurulur.
 *
 * DEĞİŞMEZ KURAL — ALAN SIRASI: DataContract alanları ordinal alfabetik
 * gitmezse WCF onları sessizce varsayılana düşürür.
 * DEĞİŞMEZ KURAL — "HEPSİ" FİLTRESİ: durum alanları -1, kimlik alanları 0.
 */
final class TicimaxAdapterTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_declares_only_the_capabilities_it_implements(): void
    {
        $adapter = $this->adapter();

        $this->assertInstanceOf(SupportsCatalogImport::class, $adapter);
        $this->assertInstanceOf(SupportsInventory::class, $adapter);
        $this->assertInstanceOf(SupportsPricing::class, $adapter);
        $this->assertInstanceOf(SupportsOrders::class, $adapter);
        $this->assertNotInstanceOf(SupportsCatalog::class, $adapter);
        $this->assertNotInstanceOf(SupportsTokenRefresh::class, $adapter);
    }

    /**
     * Zarf: HTTPS mağaza adresi, SOAPAction, `UyeKodu` İLK parametre,
     * süzgeç "hepsi" değerleriyle ve alfabetik.
     */
    #[Test]
    public function the_envelope_carries_the_auth_code_and_all_filters(): void
    {
        Http::fake(['*' => Http::response($this->soap('SelectUrunCount', '42'))]);

        $this->assertTrue($this->adapter()->healthCheck()->healthy);

        Http::assertSent(function (Request $r): bool {
            $xml = $r->body();

            return $r->url() === 'https://www.magazam.com/Servis/UrunServis.svc'
                && $r->hasHeader('SOAPAction', '"http://tempuri.org/IUrunServis/SelectUrunCount"')
                && str_contains($r->header('Content-Type')[0] ?? '', 'text/xml')
                && preg_match('#<SelectUrunCount[^>]*><UyeKodu>YETKI-KODU-123</UyeKodu><f>#', $xml) === 1
                && str_contains($xml, '<d:Aktif>-1</d:Aktif>')
                && str_contains($xml, '<d:UrunKartiID>0</d:UrunKartiID>')
                && strpos($xml, '<d:Aktif>') < strpos($xml, '<d:Firsat>')
                && strpos($xml, '<d:KategoriID>') < strpos($xml, '<d:Vitrin>');
        });
    }

    /** Yetki kodu yoksa istek ATILMAZ. */
    #[Test]
    public function without_an_auth_code_nothing_is_sent(): void
    {
        Http::fake();

        $health = $this->adapter(secrets: [])->healthCheck();

        $this->assertFalse($health->healthy);
        $this->assertStringContainsString('UyeKodu', (string) $health->message);
        Http::assertNothingSent();
    }

    /** HTTP 500 içindeki Fault'un mesajı kaybolmaz; yetki reddi AUTHENTICATION. */
    #[Test]
    public function a_fault_keeps_its_message_and_auth_faults_are_classified(): void
    {
        Http::fake(['*' => Http::response(
            '<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/"><s:Body><s:Fault>'
            .'<faultcode>s:Client</faultcode><faultstring xml:lang="tr-TR">Yetkisiz erişim. UyeKodu hatalı.</faultstring>'
            .'</s:Fault></s:Body></s:Envelope>',
            500,
        )]);

        $adapter = $this->adapter();

        try {
            $adapter->fetchProductPage();
            $this->fail('İstisna bekleniyordu.');
        } catch (TicimaxSoapFault $e) {
            $this->assertStringContainsString('UyeKodu hatalı', $e->getMessage());
            $this->assertSame(ErrorClass::AUTHENTICATION, $adapter->classifyError($e));
        }

        $this->assertSame(ErrorClass::SERVER_ERROR, $adapter->classifyError(new TicimaxSoapFault('Object reference not set', 's:Server')));
    }

    /**
     * Her varyasyon ayrı ürün; tek varyasyonlu kart da liste olur; KDV
     * hariç fiyat BRÜTE çevrilir; indirim etkin fiyattır; SKU'suz
     * varyasyonun SKU'su çekirdeğe bırakılır.
     */
    #[Test]
    public function variations_are_imported_with_gross_prices(): void
    {
        $cards = $this->card(10, 'Tişört', '<a:Varyasyon>'.$this->variation(101, 'TS-M', 'false', 100, 0, 5, '<a:Ozellikler><a:VaryasyonOzellik><a:Deger>M</a:Deger><a:Tanim>Beden</a:Tanim></a:VaryasyonOzellik></a:Ozellikler>').'</a:Varyasyon>'
            .'<a:Varyasyon>'.$this->variation(102, '', 'true', 300, 249.9, 0).'</a:Varyasyon>')
            .$this->card(11, 'Kupa', '<a:Varyasyon>'.$this->variation(111, 'KUPA', 'true', 50, 0, 3).'</a:Varyasyon>');

        Http::fake(['*' => Http::response($this->soap('SelectUrun', $cards, 'UrunKarti'))]);

        $page = $this->adapter()->fetchProductPage();

        $this->assertCount(3, $page->products);
        $this->assertFalse($page->hasMore);

        [$m, $noSku, $kupa] = $page->products;

        $this->assertSame('101', $m->externalId);
        $this->assertSame('Tişört — M', $m->title);
        // 100 NET + %20 KDV.
        $this->assertSame('120.00', $m->price);
        $this->assertSame(5, $m->quantity);
        $this->assertSame([
            'external_id' => '101',
            'external_parent_id' => '10',
            'channel_metadata' => ['kdv_dahil' => false, 'kdv_orani' => 20.0, 'para_birimi_id' => 1, 'para_birimi' => 'TRY'],
        ], $m->listingIdentity);

        $this->assertNull($noSku->sku);
        $this->assertTrue($noSku->isImportable());
        $this->assertSame('249.90', $noSku->price);

        $this->assertSame('111', $kupa->externalId);
    }

    /** Stok toplu `StokAdediGuncelle`: yalnız ID + StokAdedi, Varyasyon listesi. */
    #[Test]
    public function stock_is_written_in_bulk_by_variation_id(): void
    {
        Http::fake(['*' => Http::response($this->soap('StokAdediGuncelle', '2'))]);

        $result = $this->adapter()->pushInventory(new InventoryPushBatch(
            channelConnectionId: 'c1',
            items: [
                new InventoryPushItem(listingId: 'l1', externalId: '101', sku: 'A', quantity: 7, version: 1),
                new InventoryPushItem(listingId: 'l2', externalId: '102', sku: 'B', quantity: 0, version: 1),
            ],
        ));

        $this->assertTrue($result->successful);
        $this->assertSame(2, $result->data['updated']);

        Http::assertSent(static fn (Request $r): bool => str_contains($r->body(), '<urunler><d:Varyasyon><d:ID>101</d:ID><d:StokAdedi>7</d:StokAdedi></d:Varyasyon><d:Varyasyon><d:ID>102</d:ID><d:StokAdedi>0</d:StokAdedi></d:Varyasyon></urunler>'));
    }

    /**
     * Fiyat `VaryasyonGuncelle`: KDV hariç varyasyona NET yazılır,
     * karşılaştırma fiyatı satış fiyatı olur, yalnız iki fiyat bayrağı açık,
     * alanlar alfabetik.
     */
    #[Test]
    public function price_is_written_net_with_only_price_flags(): void
    {
        Http::fake(['*' => Http::response($this->soap('VaryasyonGuncelle', '1'))]);

        [$tenant, $connection] = $this->connection();
        $listing = $this->listing($tenant, $connection, '101', ['kdv_dahil' => false, 'kdv_orani' => 20, 'para_birimi_id' => 1, 'para_birimi' => 'TRY']);

        $result = $this->asTenant($tenant, fn () => $this->adapterFor($connection)->pushPrices(new PricePushBatch(
            channelConnectionId: $connection->id,
            items: [['listing_id' => $listing, 'external_id' => '101', 'price' => '120.00', 'compare_at_price' => '144.00', 'version' => 1]],
        )));

        $this->assertTrue($result->successful);

        Http::assertSent(static fn (Request $r): bool => str_contains($r->body(),
            '<urun><d:ID>101</d:ID><d:IndirimliFiyati>100</d:IndirimliFiyati><d:ParaBirimiID>1</d:ParaBirimiID><d:SatisFiyati>120</d:SatisFiyati></urun>')
            && str_contains($r->body(), '<d:SatisFiyatiGuncelle>true</d:SatisFiyatiGuncelle>')
            && str_contains($r->body(), '<d:StokAdediGuncelle>false</d:StokAdediGuncelle>'));
    }

    /** Varyasyon başka para biriminde ise fiyat YAZILMAZ. */
    #[Test]
    public function a_foreign_currency_variation_is_not_priced(): void
    {
        Http::fake();

        [$tenant, $connection] = $this->connection();
        $listing = $this->listing($tenant, $connection, '101', ['kdv_dahil' => true, 'kdv_orani' => 20, 'para_birimi_id' => 2, 'para_birimi' => 'EUR']);

        $result = $this->asTenant($tenant, fn () => $this->adapterFor($connection)->pushPrices(new PricePushBatch(
            channelConnectionId: $connection->id,
            items: [['listing_id' => $listing, 'external_id' => '101', 'price' => '10.00', 'version' => 1]],
        )));

        $this->assertTrue($result->failed());
        $this->assertSame(ErrorClass::VALIDATION, $result->errorClass);
        Http::assertNothingSent();
    }

    /** `IsError=true` yanıtı başarı sayılmaz. */
    #[Test]
    public function a_web_service_error_response_is_a_validation_failure(): void
    {
        Http::fake(['*' => Http::response($this->soap('SelectUrunCount', '<a:ErrorCode>3</a:ErrorCode><a:ErrorMessage>Geçersiz istek</a:ErrorMessage><a:IsError>true</a:IsError>'))]);

        $adapter = $this->adapter();

        $this->assertFalse($adapter->healthCheck()->healthy);
        $this->assertSame(ErrorClass::VALIDATION, $adapter->classifyError(new TicimaxSoapFault('x', 'IsError')));
    }

    // ─────────────────────────────────────────────────── yardımcılar

    private function soap(string $operation, string $inner, ?string $_ = null): string
    {
        return '<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/"><s:Body>'
            .'<'.$operation.'Response xmlns="http://tempuri.org/"><'.$operation.'Result xmlns:a="http://schemas.datacontract.org/2004/07/" xmlns:i="http://www.w3.org/2001/XMLSchema-instance">'
            .$inner
            .'</'.$operation.'Result></'.$operation.'Response></s:Body></s:Envelope>';
    }

    private function card(int $id, string $name, string $variations): string
    {
        return '<a:UrunKarti><a:Aciklama i:nil="true"/><a:Aktif>true</a:Aktif><a:ID>'.$id.'</a:ID><a:Marka>Marka</a:Marka>'
            .'<a:Resimler xmlns:b="http://schemas.microsoft.com/2003/10/Serialization/Arrays"><b:string>https://cdn.magazam.com/'.$id.'.jpg</b:string></a:Resimler>'
            .'<a:UrunAdi>'.$name.'</a:UrunAdi><a:Varyasyonlar>'.$variations.'</a:Varyasyonlar></a:UrunKarti>';
    }

    private function variation(int $id, string $sku, string $vatIncluded, float $sell, float $discount, int $stock, string $extra = ''): string
    {
        return '<a:Aktif>true</a:Aktif><a:Barkod>869'.$id.'</a:Barkod><a:ID>'.$id.'</a:ID><a:IndirimliFiyati>'.$discount.'</a:IndirimliFiyati>'
            .'<a:KdvDahil>'.$vatIncluded.'</a:KdvDahil><a:KdvOrani>20</a:KdvOrani>'.$extra
            .'<a:ParaBirimiID>1</a:ParaBirimiID><a:ParaBirimiKodu>TRY</a:ParaBirimiKodu><a:SatisFiyati>'.$sell.'</a:SatisFiyati>'
            .'<a:StokAdedi>'.$stock.'</a:StokAdedi><a:StokKodu>'.$sku.'</a:StokKodu><a:UrunKartiID>0</a:UrunKartiID>';
    }

    /** @param array<string, mixed> $metadata */
    private function listing(Tenant $tenant, ChannelConnection $connection, string $externalId, array $metadata): string
    {
        return $this->asTenant($tenant, fn (): string => Listing::factory()->create([
            'tenant_id' => $tenant->id,
            'channel_connection_id' => $connection->id,
            'external_id' => $externalId,
            'external_parent_id' => '10',
            'channel_metadata' => $metadata,
        ])->id);
    }

    /** @param array<string, string> $secrets */
    private function adapter(array $secrets = ['uye_kodu' => 'YETKI-KODU-123']): TicimaxAdapter
    {
        [$tenant, $connection] = $this->connection($secrets);

        return $this->asTenant($tenant, fn () => $this->adapterFor($connection));
    }

    /**
     * @param  array<string, string>  $secrets
     * @return array{0: Tenant, 1: ChannelConnection}
     */
    private function connection(array $secrets = ['uye_kodu' => 'YETKI-KODU-123']): array
    {
        $this->asSystem(fn () => ChannelType::query()->updateOrCreate(
            ['code' => 'ticimax'],
            [
                'name' => 'Ticimax', 'kind' => 'storefront', 'adapter_class' => TicimaxAdapter::class,
                'capabilities' => [], 'rate_limit_profile' => [], 'supports_webhooks' => false, 'is_active' => false,
            ],
        ));

        $tenant = (new CreateTenant)->run(name: 'Ticimax '.uniqid(), owner: User::factory()->create());

        $connection = $this->asTenant($tenant, function () use ($secrets): ChannelConnection {
            $connection = ChannelConnection::factory()->create([
                'channel_type_code' => 'ticimax',
                'external_account_id' => 'www.magazam.com',
                'settings' => [],
            ]);

            if ($secrets !== []) {
                app(CredentialVault::class)->store($connection, $secrets);
            }

            return $connection;
        });

        return [$tenant, $connection];
    }

    private function adapterFor(ChannelConnection $connection): TicimaxAdapter
    {
        return new TicimaxAdapter(
            $connection,
            new ChannelHttpClient($connection, app(CredentialVault::class), app(PayloadRedactor::class)),
        );
    }

    /** api_calls günlüğüne yetki kodu düz yazılmaz. */
    #[Test]
    public function the_auth_code_is_masked_in_the_call_log(): void
    {
        Http::fake(['*' => Http::response($this->soap('SelectUrunCount', '1'))]);

        $this->adapter()->healthCheck();

        $logged = (string) DB::table('api_calls')->latest('id')->value('request_body');

        $this->assertNotSame('', $logged);
        $this->assertStringNotContainsString('YETKI-KODU-123', $logged);
    }
}
