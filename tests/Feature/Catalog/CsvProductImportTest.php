<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\Actions\ImportProducts;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Variant;
use App\Domain\Catalog\Support\CsvProductParser;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Models\InventoryLevel;
use App\Domain\Inventory\Models\InventoryMovement;
use App\Domain\Inventory\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\AssertsLedgerIntegrity;
use Tests\TestCase;

/**
 * §13 · Faz 3 · TOPLU İÇE AKTARMA — Excel/CSV.
 *
 * Mimari Karar Dokümanı v2.2 · §13 · Faz 3 · "Toplu içe aktarma (Excel/CSV)
 * ve kanaldan ürün çekme", §17 · öncelik tablosu ("Toplu içe aktarma —
 * TEMEL").
 *
 * MADDENİN VARLIK SEBEBİ: satıcı 500 ürününü panelden tek tek giremez.
 * Ödeme mekanizması olsa bile ürünlerini sisteme sokamayan satıcı sistemi
 * kullanamaz; bu madde ürünü kullanılabilir kılan eşiktir.
 *
 * DEĞİŞMEZ KURAL — AÇILIŞ STOĞU LEDGER ÜZERİNDEN GİRER:
 *   İçe aktarma `inventory_levels` satırına DOKUNMAZ; `CreateProduct`
 *   action'ını çağırır ve o IMPORT hareketi açar. Doğrudan yazmak
 *   `on_hand = Σ on_hand_delta` eşitliğini bozar ve mutabakat o günden
 *   sonra SAHTE SÜRÜKLENME bulmaya başlar. 500 satırlık bir dosyada bu,
 *   500 bozuk bakiye demektir.
 *
 * DEĞİŞMEZ KURAL — TEK BOZUK SATIR DOSYAYI DÜŞÜRMEZ:
 *   Taksonomi turundaki "tek bozuk bağlantı turu durdurmaz" kuralının
 *   aynısı. 500 satırlık bir dosyanın 437. satırında eksik fiyat varsa
 *   önceki 436 ürün YAZILMIŞ olmalı ve kullanıcı yalnızca o satırı
 *   düzeltip yeniden yüklemeli — yoksa her denemede baştan başlar.
 *
 * DEĞİŞMEZ KURAL — VAR OLAN SKU GÜNCELLENİR, KOPYA AÇILMAZ:
 *   `UNIQUE(tenant_id, sku)` zaten kopyayı reddeder ama asıl sebep
 *   davranışsal: satıcının en sık işi toplu FİYAT GÜNCELLEMESİDİR.
 *   Güncelleme `UpdateProduct` yolundan geçer, `content_version` artar ve
 *   değişiklik kanala gider.
 *
 * DEĞİŞMEZ KURAL — GÜNCELLEMEDE STOK SATIRDAN YAZILMAZ:
 *   Stok yalnızca ledger yollarından değişir (§4). CSV'deki stok kolonu
 *   YENİ ürünün açılış stoğudur; var olan üründe uygulanırsa satıcının
 *   sattığı mallar bir dosya yüklemesiyle geri gelir ve bakiye bozulur.
 */
final class CsvProductImportTest extends TestCase
{
    use AssertsLedgerIntegrity;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    // ---------------------------------------------------------------- ayrıştırma

    /** Başlık satırı okunur ve kolonlar ADIYLA eşlenir. */
    #[Test]
    public function the_parser_maps_columns_by_header_name(): void
    {
        $rows = $this->parse(<<<'CSV'
        sku,baslik,fiyat,stok
        TSH-1,Tişört,199.90,5
        CSV);

        $this->assertCount(1, $rows->valid);

        $this->assertSame('TSH-1', $rows->valid[0]['sku']);
        $this->assertSame('Tişört', $rows->valid[0]['title']);
        $this->assertSame(199.90, $rows->valid[0]['price']);
        $this->assertSame(5, $rows->valid[0]['opening_stock']);
    }

    /**
     * KOLON SIRASI DEĞİŞEBİLİR — eşleme ADLA yapılır, konumla değil.
     *
     * Satıcının Excel'inde kolonlar her zaman aynı sırada değildir.
     * Konumla eşlenseydi fiyat kolonu stok sanılır ve 500 ürün yanlış
     * fiyatla kanala giderdi — geri alınamaz bir hata.
     */
    #[Test]
    public function column_order_does_not_matter(): void
    {
        $rows = $this->parse(<<<'CSV'
        stok,fiyat,sku,baslik
        7,49.50,MUG-1,Kupa
        CSV);

        $this->assertSame('MUG-1', $rows->valid[0]['sku']);
        $this->assertSame(49.50, $rows->valid[0]['price']);
        $this->assertSame(7, $rows->valid[0]['opening_stock']);
    }

    /**
     * TÜRKÇE ONDALIK AYIRICI (virgül) KABUL EDİLİR.
     *
     * Türkçe Excel "199,90" yazar. `(float) "199,90"` PHP'de **199.0**
     * eder — kuruşlar SESSİZCE düşer ve satıcı bunu ancak kanalda yanlış
     * fiyat görünce fark eder. Para float'a çevrilirken bu tek satırlık
     * hata 500 üründe 500 yanlış fiyat demektir.
     */
    #[Test]
    public function turkish_decimal_comma_is_accepted(): void
    {
        $rows = $this->parse(<<<'CSV'
        sku,baslik,fiyat,stok
        TSH-2,Tişört,"1.299,90",3
        CSV);

        $this->assertSame(1299.90, $rows->valid[0]['price']);
    }

    /** Eksik zorunlu alan satırı GEÇERSİZ yapar ama dosyayı düşürmez. */
    #[Test]
    public function a_row_without_a_sku_is_rejected_without_killing_the_file(): void
    {
        $rows = $this->parse(<<<'CSV'
        sku,baslik,fiyat,stok
        ,Başlıksız,10.00,1
        OK-1,Geçerli,20.00,2
        CSV);

        $this->assertCount(1, $rows->valid);
        $this->assertCount(1, $rows->invalid);

        $this->assertSame('OK-1', $rows->valid[0]['sku']);
        $this->assertSame(2, $rows->invalid[0]['line'], 'Satır numarası KULLANICIYA gösterilir.');
    }

    /** Negatif stok reddedilir — açılış stoğu negatif olamaz. */
    #[Test]
    public function a_negative_opening_stock_is_rejected(): void
    {
        $rows = $this->parse(<<<'CSV'
        sku,baslik,fiyat,stok
        NEG-1,Negatif,10.00,-5
        CSV);

        $this->assertCount(0, $rows->valid);
        $this->assertCount(1, $rows->invalid);
    }

    /**
     * ZORUNLU KOLON EKSİKSE DOSYA HİÇ İŞLENMEZ.
     *
     * Satır bazlı hoşgörü, BAŞLIK hatası için geçerli DEĞİLDİR: `fiyat`
     * kolonu hiç yoksa her satır geçersizdir ve 500 hata satırı basmak
     * kullanıcıya "dosyan yanlış" demekten kötüdür.
     */
    #[Test]
    public function a_missing_required_column_rejects_the_whole_file(): void
    {
        $rows = $this->parse(<<<'CSV'
        sku,baslik,stok
        TSH-1,Tişört,5
        CSV);

        $this->assertFalse($rows->headerValid);
        $this->assertContains('fiyat', $rows->missingColumns);
    }

    /** BOM'lu dosya (Excel'in varsayılanı) ilk kolonu bozmaz. */
    #[Test]
    public function a_utf8_bom_does_not_break_the_first_column(): void
    {
        $rows = $this->parse("\u{FEFF}sku,baslik,fiyat,stok\nBOM-1,Ürün,10.00,1");

        $this->assertTrue($rows->headerValid, 'Excel BOM ekler; ilk kolon adı bozulmamalı.');
        $this->assertSame('BOM-1', $rows->valid[0]['sku']);
    }

    /** Noktalı virgül ayırıcı da kabul edilir — Türkçe Excel varsayılanı. */
    #[Test]
    public function semicolon_separated_files_are_accepted(): void
    {
        $rows = $this->parse(<<<'CSV'
        sku;baslik;fiyat;stok
        SC-1;Ürün;10,50;4
        CSV);

        $this->assertTrue($rows->headerValid);
        $this->assertSame('SC-1', $rows->valid[0]['sku']);
        $this->assertSame(10.50, $rows->valid[0]['price']);
    }

    // ---------------------------------------------------------------- yazma

    /**
     * AÇILIŞ STOĞU LEDGER ÜZERİNDEN GİRER.
     *
     * `inventory_levels` doğrudan yazılsaydı `on_hand = Σ on_hand_delta`
     * eşitliği ürün yaratılırken bozulur ve mutabakat sahte sürüklenme
     * bulmaya başlardı.
     */
    #[Test]
    public function imported_products_get_their_opening_stock_through_the_ledger(): void
    {
        [$tenant, $warehouseId] = $this->makeTenant();

        $result = $this->import($tenant, $warehouseId, <<<'CSV'
        sku,baslik,fiyat,stok
        LED-1,Ledger,99.90,8
        CSV);

        $this->assertSame(1, $result->created);

        $variant = $this->asTenant($tenant, fn () => Variant::query()->where('sku', 'LED-1')->firstOrFail());

        $level = $this->asTenant($tenant, fn () => InventoryLevel::query()
            ->where('variant_id', $variant->id)
            ->firstOrFail());

        $this->assertSame(8, $level->on_hand);

        $this->assertLedgerMatchesProjection($tenant->id, $warehouseId, $variant->id);
    }

    /**
     * VAR OLAN SKU GÜNCELLENİR — ikinci ürün AÇILMAZ.
     *
     * Satıcının en sık işi toplu FİYAT GÜNCELLEMESİDİR.
     */
    #[Test]
    public function an_existing_sku_is_updated_instead_of_duplicated(): void
    {
        [$tenant, $warehouseId] = $this->makeTenant();

        $this->import($tenant, $warehouseId, <<<'CSV'
        sku,baslik,fiyat,stok
        UPD-1,Eski başlık,10.00,5
        CSV);

        $result = $this->import($tenant, $warehouseId, <<<'CSV'
        sku,baslik,fiyat,stok
        UPD-1,Yeni başlık,25.00,5
        CSV);

        $this->assertSame(0, $result->created);
        $this->assertSame(1, $result->updated);

        $this->assertSame(
            1,
            $this->asTenant($tenant, fn (): int => Product::query()->where('sku', 'UPD-1')->count()),
            'İkinci ürün AÇILMAMALI.',
        );

        $product = $this->asTenant($tenant, fn () => Product::query()->where('sku', 'UPD-1')->firstOrFail());

        $this->assertSame('Yeni başlık', $product->title);
    }

    /**
     * GÜNCELLEMEDE STOK SATIRDAN YAZILMAZ.
     *
     * Stok yalnızca ledger yollarından değişir. CSV'deki stok kolonu YENİ
     * ürünün açılış stoğudur; var olan üründe uygulanırsa satıcının
     * SATTIĞI mallar bir dosya yüklemesiyle geri gelir ve bakiye kalıcı
     * olarak bozulur. Bu, maddenin en tehlikeli hatasıdır: sessizdir ve
     * fazla satışa yol açar.
     */
    #[Test]
    public function an_update_never_writes_stock_from_the_file(): void
    {
        [$tenant, $warehouseId] = $this->makeTenant();

        $this->import($tenant, $warehouseId, <<<'CSV'
        sku,baslik,fiyat,stok
        STK-1,Ürün,10.00,3
        CSV);

        $variant = $this->asTenant($tenant, fn () => Variant::query()->where('sku', 'STK-1')->firstOrFail());

        $movementsBefore = $this->movementCount($tenant, $variant->id);

        // Aynı SKU, ÇOK DAHA YÜKSEK stok — uygulanmamalı.
        $this->import($tenant, $warehouseId, <<<'CSV'
        sku,baslik,fiyat,stok
        STK-1,Ürün,10.00,999
        CSV);

        $level = $this->asTenant($tenant, fn () => InventoryLevel::query()
            ->where('variant_id', $variant->id)
            ->firstOrFail());

        $this->assertSame(3, $level->on_hand, 'Güncelleme stoğa DOKUNMAMALI.');

        $this->assertSame(
            $movementsBefore,
            $this->movementCount($tenant, $variant->id),
            'Güncelleme yeni hareket AÇMAMALI.',
        );

        $this->assertLedgerMatchesProjection($tenant->id, $warehouseId, $variant->id);
    }

    /**
     * TEK BOZUK SATIR DOSYAYI DÜŞÜRMEZ.
     *
     * 500 satırlık dosyanın 437. satırında hata varsa önceki 436 ürün
     * YAZILMIŞ olmalı; yoksa kullanıcı her denemede baştan başlar.
     */
    #[Test]
    public function one_bad_row_does_not_discard_the_good_ones(): void
    {
        [$tenant, $warehouseId] = $this->makeTenant();

        $result = $this->import($tenant, $warehouseId, <<<'CSV'
        sku,baslik,fiyat,stok
        GOOD-1,İlk,10.00,1
        ,Bozuk satır,20.00,2
        GOOD-2,İkinci,30.00,3
        CSV);

        $this->assertSame(2, $result->created);
        $this->assertCount(1, $result->errors);

        $this->assertSame(
            2,
            $this->asTenant($tenant, fn (): int => Product::query()->count()),
        );
    }

    /**
     * YAZMA SIRASINDA PATLAYAN SATIR DA DOSYAYI DÜŞÜRMEZ.
     *
     * BU TEST BİR MUTASYONUN ARDINDAN EKLENDİ: `ImportProducts`'taki
     * `catch (Throwable)` daraltıldığında hiçbir test kırılmıyordu, çünkü
     * mevcut "bozuk satır" testlerinin hepsi AYRIŞTIRMADA eleniyordu ve
     * yazma yoluna hiç ulaşmıyordu. Yani maddenin en kritik kuralı —
     * "tek bozuk satır dosyayı düşürmez" — yazma tarafında hiç sınanmamıştı.
     *
     * Burada satır ayrıştırmayı GEÇER (SKU, başlık, fiyat ve stok
     * geçerlidir) ama veritabanına yazılırken patlar: `products.title`
     * 255 karakterle sınırlıdır. Gerçek dünyada aynı biçim çok görülür —
     * kanal açıklamasını başlığa yapıştırmış bir Excel satırı.
     */
    #[Test]
    public function a_row_that_fails_while_writing_does_not_discard_the_others(): void
    {
        [$tenant, $warehouseId] = $this->makeTenant();

        $tooLongTitle = str_repeat('A', 300);

        $result = $this->import($tenant, $warehouseId, <<<CSV
        sku,baslik,fiyat,stok
        WOK-1,İlk ürün,10.00,1
        WOK-2,{$tooLongTitle},20.00,2
        WOK-3,Üçüncü ürün,30.00,3
        CSV);

        $this->assertSame(2, $result->created, 'Sağlam satırlar YAZILMIŞ olmalı.');
        $this->assertCount(1, $result->errors, 'Patlayan satır rapora girmeli.');

        $this->assertSame(
            3,
            $result->errors[0]['line'],
            'Hata SATIR NUMARASIYLA raporlanmalı — kullanıcı hangisini düzelteceğini bilmeli.',
        );

        $this->assertSame(
            2,
            $this->asTenant($tenant, fn (): int => Product::query()->count()),
        );
    }

    /** Hata raporu SATIR NUMARASI ve SEBEP taşır — kullanıcı düzeltebilmeli. */
    #[Test]
    public function the_error_report_names_the_line_and_the_reason(): void
    {
        [$tenant, $warehouseId] = $this->makeTenant();

        $result = $this->import($tenant, $warehouseId, <<<'CSV'
        sku,baslik,fiyat,stok
        OK-1,Geçerli,10.00,1
        ,Sku yok,20.00,2
        CSV);

        $this->assertCount(1, $result->errors);

        $this->assertSame(3, $result->errors[0]['line'], 'Başlık satırı 1 sayılır; hata 3. satırda.');
        $this->assertNotSame('', $result->errors[0]['message']);
    }

    /** İçe aktarma BAŞKA KİRACIYA sızmaz. */
    #[Test]
    public function importing_never_crosses_tenants(): void
    {
        [$tenantA, $warehouseA] = $this->makeTenant('A');
        [$tenantB] = $this->makeTenant('B');

        $this->import($tenantA, $warehouseA, <<<'CSV'
        sku,baslik,fiyat,stok
        ISO-1,Ürün,10.00,1
        CSV);

        $this->assertSame(
            0,
            $this->asTenant($tenantB, fn (): int => Product::query()->count()),
            'Başka kiracıda ürün oluşmamalı.',
        );
    }

    /**
     * AYNI DOSYADA AYNI SKU İKİ KEZ GEÇERSE İKİNCİSİ GÜNCELLEMEDİR.
     *
     * Satıcının dosyasında kopya satır olabilir. İkisi de "yeni ürün"
     * sayılsaydı ikincisi `UNIQUE(tenant_id, sku)` ihlaliyle hata satırı
     * olurdu ve kullanıcı kendi dosyasındaki zararsız bir tekrarı hata
     * sanırdı.
     */
    #[Test]
    public function a_duplicate_sku_inside_one_file_is_treated_as_an_update(): void
    {
        [$tenant, $warehouseId] = $this->makeTenant();

        $result = $this->import($tenant, $warehouseId, <<<'CSV'
        sku,baslik,fiyat,stok
        DUP-1,İlk hali,10.00,5
        DUP-1,Son hali,15.00,5
        CSV);

        $this->assertSame(1, $result->created);
        $this->assertSame(1, $result->updated);
        $this->assertCount(0, $result->errors);

        $product = $this->asTenant($tenant, fn () => Product::query()->where('sku', 'DUP-1')->firstOrFail());

        $this->assertSame('Son hali', $product->title, 'Son satır kazanır.');
    }

    // ---------------------------------------------------------------- yardımcılar

    private function parse(string $csv): object
    {
        return (new CsvProductParser)->parse($csv);
    }

    /** @return array{0: Tenant, 1: string} */
    private function makeTenant(string $name = 'İçe aktarma'): array
    {
        $tenant = (new CreateTenant)->run(
            name: $name.' '.uniqid(),
            owner: User::factory()->create(),
        );

        $warehouseId = $this->asTenant($tenant, fn (): string => Warehouse::query()
            ->where('is_default', true)
            ->firstOrFail()->id);

        return [$tenant, $warehouseId];
    }

    private function import(Tenant $tenant, string $warehouseId, string $csv): object
    {
        return $this->asTenant($tenant, fn () => app(ImportProducts::class)->run(
            csv: $csv,
            warehouseId: $warehouseId,
        ));
    }

    private function movementCount(Tenant $tenant, string $variantId): int
    {
        return $this->asTenant($tenant, fn (): int => InventoryMovement::query()
            ->where('variant_id', $variantId)
            ->count());
    }
}
