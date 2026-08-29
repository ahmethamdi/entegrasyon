<?php

declare(strict_types=1);

namespace Tests\Feature\Channels;

use App\Domain\Channels\Actions\SyncTaxonomy;
use App\Domain\Channels\Adapters\Ebay\EbayAdapter;
use App\Domain\Channels\Models\ChannelCategory;
use App\Domain\Channels\Models\ChannelCategoryAttribute;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Models\ChannelType;
use App\Domain\Channels\Support\ChannelHttpClient;
use App\Domain\Channels\Support\CredentialVault;
use App\Domain\Channels\Support\SyncTaxonomyForChannels;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\User;
use App\Support\Logging\PayloadRedactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * eBay taksonomi — slice 4.5.
 *
 * V3.0 · §13.5 · v2.2 §14.
 *
 * ⚠️ `Http::fake()` AYNI TESTTE İKİ KEZ ÇAĞRILMAZ — her senaryo TEK
 * `fake()` kurar (bu tuzak 4.4'te ÜÇÜNCÜ kez ısırdı).
 */
final class EbayTaxonomyTest extends TestCase
{
    use RefreshDatabase;

    // ─────────────────────────────────────────────────── ağaç (§13.5)

    /**
     * ⚠️ AĞAÇ KİMLİĞİ ÖNCE SORULUR — sabit YAZILAMAZ.
     *
     * eBay'de `EBAY_US` ağacı `0`, `EBAY_DE` `77`'dir ve liste zamanla
     * DEĞİŞİR; kodda sabitlenseydi tüm satıcılar ABD ağacını görür ve
     * o ağaçtan seçilen kategori `VALIDATION` alırdı (KALICI).
     */
    #[Test]
    public function the_tree_id_is_resolved_from_the_marketplace_first(): void
    {
        $adapter = $this->adapter();

        Http::fake([
            '*get_default_category_tree_id*' => Http::response(['categoryTreeId' => '77'], 200),
            '*category_tree/77*' => Http::response($this->treeBody(), 200),
        ]);

        $adapter->fetchCategoryTree();

        Http::assertSent(static fn (Request $r): bool => str_contains($r->url(), 'get_default_category_tree_id')
            && str_contains($r->url(), 'marketplace_id=EBAY_DE'));

        Http::assertSent(static fn (Request $r): bool => str_contains(
            $r->url(),
            '/category_tree/77',
        ));
    }

    /**
     * Ağaç düzleştirilir: `parent_external_id` ve okunabilir `path`.
     *
     * İç içe yapı saklansaydı "şu kategorinin tüm çocukları" sorgusu
     * özyinelemeli CTE gerektirirdi ve eşleştirme ekranı her tuşta
     * ağacı yeniden yürürdü.
     */
    #[Test]
    public function the_tree_is_flattened_with_parents_and_paths(): void
    {
        $adapter = $this->adapter();

        Http::fake([
            '*get_default_category_tree_id*' => Http::response(['categoryTreeId' => '77'], 200),
            '*category_tree*' => Http::response($this->treeBody(), 200),
        ]);

        $snapshot = $adapter->fetchCategoryTree();

        $byId = [];

        foreach ($snapshot->categories as $category) {
            $byId[$category['external_id']] = $category;
        }

        // ⚠️ `array_keys()` İLE KARŞILAŞTIRILMAZ — PHP sayısal görünen
        // dize anahtarları INT'e çevirir ve `'11450'` anahtarı `11450`
        // olur. Kimliğin STRING kaldığı KOLONDAN doğrulanır: `(int)`
        // dönüşümü Trendyol'da harf içeren barkodu `0`'a düşürmüştü ve
        // aynı tuzağın taksonomi karşılığı burada yaşıyor.
        $this->assertSame(
            ['11450', '15724', '57990'],
            array_column($snapshot->categories, 'external_id'),
        );

        $this->assertNull($byId['11450']['parent_external_id']);
        $this->assertSame('11450', $byId['15724']['parent_external_id']);
        $this->assertSame('15724', $byId['57990']['parent_external_id']);

        $this->assertSame('Giyim > Kadın > Elbise', $byId['57990']['path']);
    }

    /**
     * ⚠️ YAPRAK BİLGİSİ ÇOCUK LİSTESİNDEN TÜRETİLİR — bayrak TEK BAŞINA
     * okunmaz.
     *
     * Bayrak ile çocuk listesi ÇELİŞEBİLİR: eBay bir ara düğümü yaprak
     * işaretleyip yine de çocuk döndürebilir. Çocuğu OLAN bir düğüm
     * yaprak sayılsaydı ürün ARA kategoriye açılmaya çalışılır, kanal
     * `VALIDATION` döner ve o hata KALICIDIR.
     *
     * Gövdede `11450` TAM OLARAK bu tuzağı taşıyor: bayrağı `true` ama
     * çocuğu VAR.
     */
    #[Test]
    public function a_node_with_children_is_never_a_leaf_even_if_flagged(): void
    {
        $adapter = $this->adapter();

        Http::fake([
            '*get_default_category_tree_id*' => Http::response(['categoryTreeId' => '77'], 200),
            '*category_tree*' => Http::response($this->treeBody(), 200),
        ]);

        $byId = [];

        foreach ($adapter->fetchCategoryTree()->categories as $category) {
            $byId[$category['external_id']] = $category;
        }

        $this->assertFalse(
            $byId['11450']['is_leaf'],
            'Çocuğu olan düğüm YAPRAK sayıldı — ürün ara kategoriye '
            .'açılmaya çalışılır ve `VALIDATION` KALICI hata verirdi.',
        );

        $this->assertTrue($byId['57990']['is_leaf']);
    }

    /**
     * ⚠️ BAYRAĞI `false` OLAN ÇOCUKSUZ DÜĞÜM DE YAPRAK DEĞİLDİR.
     *
     * İki kaynak da "yaprak" demelidir; çelişkide GÜVENLİ taraf "yaprak
     * DEĞİL"dir. Ara kategori eşleştirilemez (görünür eksiklik), yanlış
     * yaprak ürünü ÖLDÜRÜR (KALICI hata).
     */
    #[Test]
    public function a_childless_node_flagged_not_leaf_is_not_a_leaf(): void
    {
        $adapter = $this->adapter();

        Http::fake([
            '*get_default_category_tree_id*' => Http::response(['categoryTreeId' => '77'], 200),
            '*category_tree*' => Http::response([
                'categoryTreeVersion' => '9',
                'rootCategoryNode' => [
                    'childCategoryTreeNodes' => [[
                        'category' => ['categoryId' => '1', 'categoryName' => 'Belirsiz'],
                        'leafCategoryTreeNode' => false,
                    ]],
                ],
            ], 200),
        ]);

        $categories = $adapter->fetchCategoryTree()->categories;

        $this->assertFalse($categories[0]['is_leaf']);
    }

    /**
     * ⚠️ SÜRÜM MARKETPLACE KİMLİĞİNİ İÇERMEK ZORUNDADIR (§13.5).
     *
     * `EBAY_US` ve `EBAY_DE` FARKLI ağaçlar taşır ama sürüm numaraları
     * AYNI olabilir. Tekillik `(channel_type_code, taxonomy_version,
     * external_id)` olduğu için iki pazarın aynı kimlikli kategorileri
     * BİRBİRİNİ EZERDİ ve satıcı ABD kategorisini Almanya'ya gönderip
     * `VALIDATION` alırdı.
     */
    #[Test]
    public function the_version_carries_the_marketplace_id(): void
    {
        Http::fake([
            '*get_default_category_tree_id*' => Http::response(['categoryTreeId' => '77'], 200),
            '*category_tree*' => Http::response($this->treeBody(), 200),
        ]);

        $version = $this->adapter()->fetchCategoryTree()->version;

        $this->assertStringStartsWith('EBAY_DE:', $version);
        $this->assertStringContainsString('134', $version, 'Kanalın sürümü kayboldu.');
    }

    /**
     * ⚠️ İKİ PAZAR AYNI SÜRÜM NUMARASINI VERSE BİLE AYRIŞIR.
     *
     * Ayırt edici işaret budur: yalnızca "sürüm dolu" iddia edilseydi
     * marketplace ön ekini düşüren mutasyon HAYATTA KALIRDI.
     */
    #[Test]
    public function two_marketplaces_with_the_same_channel_version_do_not_collide(): void
    {
        Http::fake([
            '*get_default_category_tree_id*' => Http::response(['categoryTreeId' => '77'], 200),
            '*category_tree*' => Http::response($this->treeBody(), 200),
        ]);

        $de = $this->adapter(marketplace: 'EBAY_DE')->fetchCategoryTree()->version;
        $us = $this->adapter(marketplace: 'EBAY_US')->fetchCategoryTree()->version;

        $this->assertNotSame(
            $de,
            $us,
            'İki pazarın ağaçları AYNI sürüme yazıldı — kategoriler '
            .'birbirini EZER ve yanlış ağaçtan seçim `VALIDATION` alırdı.',
        );
    }

    /**
     * ⚠️ KANAL SÜRÜM VERMEZSE AĞACIN ŞEKLİNDEN TÜRETİLİR, SABİT
     * YAZILMAZ.
     *
     * Sabit bir dize yazılsaydı ağaç değiştiğinde sürüm AYNI kalır, yeni
     * satırlar eskilerin üzerine yazılır ve eşleştirmeler sessizce başka
     * bir kategoriyi gösterirdi.
     */
    #[Test]
    public function a_missing_channel_version_falls_back_to_the_tree_shape(): void
    {
        // ⚠️ DESEN `*/category_tree/77*` — `*category_tree*` YAZILSAYDI
        // `get_default_category_tree_id` ADRESİ DE EŞLEŞİRDİ (o yol da
        // "category_tree" dizgisini içerir) ve dizinin ilk yanıtı ağaç
        // kimliği isteğine gider, ağaç isteği tükenmiş diziye çarpardı.
        Http::fake([
            '*get_default_category_tree_id*' => Http::response(['categoryTreeId' => '77'], 200),
            '*/category_tree/77*' => Http::sequence()
                ->push($this->treeBody(version: null), 200)
                ->push($this->treeBody(version: null, extraLeaf: true), 200),
        ]);

        $adapter = $this->adapter();

        $first = $adapter->fetchCategoryTree()->version;
        $second = $adapter->fetchCategoryTree()->version;

        $this->assertNotSame(
            $first,
            $second,
            'Ağaç DEĞİŞTİ ama sürüm aynı kaldı — yeni satırlar eskilerin '
            .'üzerine yazılır ve eşleştirmeler başka kategoriyi gösterirdi.',
        );
    }

    /**
     * ⚠️ KANALIN DÖNDÜRME SIRASI DEĞİŞİNCE SÜRÜM DEĞİŞMEZ.
     *
     * Parmak izi SIRALANIR: sıralanmasaydı ağaç AYNIYKEN kanal düğümleri
     * başka sırada döndürdüğü an sürüm değişir, TÜM eşleştirmeler
     * "yeniden doğrula" damgası yer ve alan anlamını kaybederdi. Satıcı
     * hiçbir şey değişmemişken yüzlerce eşleştirmeyi elden geçirirdi.
     *
     * ⚠️ MUTASYONLA BULUNDU: `sort()`'u kaldıran mutasyon HAYATTA
     * KALMIŞTI çünkü hiçbir test kanalın SIRASINI değiştirmiyordu —
     * "aynı gövde aynı sürümü verir" iddiası sıralamayı HİÇ ölçmez.
     */
    #[Test]
    public function a_reordered_tree_with_the_same_shape_keeps_its_version(): void
    {
        Http::fake([
            '*get_default_category_tree_id*' => Http::response(['categoryTreeId' => '77'], 200),
            '*/category_tree/77*' => Http::sequence()
                ->push($this->siblingTreeBody(reversed: false), 200)
                ->push($this->siblingTreeBody(reversed: true), 200),
        ]);

        $adapter = $this->adapter();

        $first = $adapter->fetchCategoryTree();
        $second = $adapter->fetchCategoryTree();

        // Ön koşul: gerçekten SIRA değişti, içerik değil.
        $this->assertNotSame(
            array_column($first->categories, 'external_id'),
            array_column($second->categories, 'external_id'),
            'Test kurulumu hatalı — kanal sırası DEĞİŞMEDİ ve iddia '
            .'hiçbir şey ölçmüyor.',
        );

        $this->assertSame(
            $first->version,
            $second->version,
            'Kanal sırası değişince sürüm değişti — ağaç AYNIYKEN tüm '
            .'eşleştirmeler bayat işaretlenir ve satıcı hiçbir şey '
            .'değişmemişken hepsini yeniden doğrulardı.',
        );
    }

    /**
     * ⚠️ BAŞARISIZ YANIT SESSİZCE BOŞ AĞACA DÖNÜŞMEZ.
     *
     * `json()` bir 500 gövdesinde de dizi döndürür ve ağaç BOŞ çıkardı.
     * O boş ağaç GEÇERLİ bir sürümle yazılır, panel "bu kanalda hiç
     * kategori yok" der ve aktarım ön koşul kapısında SONSUZA KADAR
     * takılırdı — üstelik hata hiçbir yere düşmeden.
     */
    #[Test]
    public function a_failed_tree_response_throws_instead_of_writing_an_empty_tree(): void
    {
        Http::fake([
            '*get_default_category_tree_id*' => Http::response(['categoryTreeId' => '77'], 200),
            '*category_tree*' => Http::response(['errors' => []], 500),
        ]);

        $this->expectException(RequestException::class);

        $this->adapter()->fetchCategoryTree();
    }

    /**
     * ⚠️ AĞAÇ KİMLİĞİ GELMEZSE İSTİSNA — boş kimlikle devam EDİLMEZ.
     *
     * Boş dizeyle devam edilseydi istek `/category_tree/` adresine
     * gider, 404 alınır ve sebebi hiçbir yerde görünmezdi.
     */
    #[Test]
    public function a_missing_tree_id_throws_before_fetching_the_tree(): void
    {
        Http::fake([
            '*get_default_category_tree_id*' => Http::response(['baska' => 'alan'], 200),
            '*category_tree*' => Http::response($this->treeBody(), 200),
        ]);

        try {
            $this->adapter()->fetchCategoryTree();
            $this->fail('Ağaç kimliği yokken istisna beklenirdi.');
        } catch (RuntimeException) {
            // beklenen
        }

        Http::assertNotSent(static fn (Request $r): bool => str_contains($r->url(), '/category_tree/'));
    }

    /**
     * ⚠️ MARKETPLACE TANIMSIZSA İSTEK HİÇ ATILMAZ.
     *
     * Varsayılana düşülseydi (`EBAY_US`) satıcının Almanya mağazası için
     * ABD ağacı çekilir, eşleştirmeler o ağaca bağlanır ve gönderilen
     * HER ürün `VALIDATION` alırdı — sebebi "yanlış ağaç" olarak hiçbir
     * yerde görünmezdi.
     */
    #[Test]
    public function a_missing_marketplace_sends_no_request_at_all(): void
    {
        Http::fake(['*' => Http::response(['categoryTreeId' => '0'], 200)]);

        try {
            $this->adapter(marketplace: null)->fetchCategoryTree();
            $this->fail('Marketplace tanımsızken istisna beklenirdi.');
        } catch (RuntimeException) {
            // beklenen
        }

        Http::assertNothingSent();
    }

    // ───────────────────────────────────────────── aspect'ler (§13.5)

    /**
     * ⚠️ KATEGORİ KİMLİĞİ SORGU PARAMETRESİDİR, YOLDA DEĞİL.
     *
     * Etsy'de yolda taşınıyordu; buraya kopyalansaydı istek
     * `category_id` olmadan gider ve eBay onu reddederdi.
     */
    #[Test]
    public function aspects_are_requested_with_the_category_id_as_a_query_parameter(): void
    {
        Http::fake([
            '*get_default_category_tree_id*' => Http::response(['categoryTreeId' => '77'], 200),
            '*get_item_aspects_for_category*' => Http::response($this->aspectsBody(), 200),
        ]);

        $this->adapter()->fetchCategoryAttributes('57990');

        Http::assertSent(static fn (Request $r): bool => str_contains($r->url(), 'get_item_aspects_for_category')
            && str_contains($r->url(), 'category_id=57990'));
    }

    /**
     * ⚠️ ZORUNLULUK eBay'DE GERÇEKTİR — ETSY'NİN TERSİ.
     *
     * Etsy'de `is_required` DAİMA `false` yazılıyordu çünkü o kanalda
     * kavram YOKTU. eBay'de VAR ve eksik zorunlu aspect offer yaratmada
     * `VALIDATION` üretir (KALICI). `false` yazılsaydı ön koşul kapısı
     * ürünü geçirir ve HER ürün kanalda ölürdü.
     */
    #[Test]
    public function a_required_aspect_is_marked_required(): void
    {
        $definitions = $this->fetchAspects();

        $this->assertTrue(
            $this->definition($definitions, 'Marka')['is_required'],
            'Zorunlu aspect isteğe bağlı sayıldı — ön koşul kapısı ürünü '
            .'geçirir ve kanal onu KALICI hatayla reddederdi.',
        );

        $this->assertFalse($this->definition($definitions, 'Desen')['is_required']);
    }

    /**
     * ⚠️ SERBEST METİN KABUL EDEN ASPECT `enum` DEĞİLDİR.
     *
     * `FREE_TEXT` modunda satıcı kendi değerini yazabilir; `enum`
     * denseydi izinli liste kapısı MEŞRU bir değeri REDDEDERDİ.
     */
    #[Test]
    public function a_free_text_aspect_is_not_an_enum_even_with_suggested_values(): void
    {
        $definitions = $this->fetchAspects();

        $this->assertSame('enum', $this->definition($definitions, 'Renk')['data_type']);

        $free = $this->definition($definitions, 'Desen');

        $this->assertSame(
            'string',
            $free['data_type'],
            'Serbest metin aspect `enum` sayıldı — izinli liste kapısı '
            .'satıcının MEŞRU değerini reddederdi.',
        );

        // Öneri listesi KORUNUR: panel onu yine de gösterir, yalnızca
        // BAĞLAYICI değildir.
        $this->assertNotSame([], $free['allowed_values']);
    }

    /**
     * ⚠️ DEĞER KİMLİĞİ METNİN KENDİSİDİR — eBay ayrı bir id VERMEZ.
     *
     * Etsy'de `value_id` vardı; oradan kopyalanan bir `id` alanı BOŞ
     * kalırdı ve boş kimlik iki farklı değeri BİRBİRİNE eşlerdi.
     */
    #[Test]
    public function allowed_values_use_the_label_as_their_id(): void
    {
        $renk = $this->definition($this->fetchAspects(), 'Renk');

        $this->assertSame(
            [
                ['id' => 'Kırmızı', 'label' => 'Kırmızı'],
                ['id' => 'Mavi', 'label' => 'Mavi'],
            ],
            $renk['allowed_values'],
        );
    }

    /** Varyant belirleyici bayrağı eBay'den AÇIKÇA okunur, türetilmez. */
    #[Test]
    public function the_variation_flag_is_read_from_the_channel(): void
    {
        $definitions = $this->fetchAspects();

        $this->assertTrue($this->definition($definitions, 'Renk')['is_variant_defining']);
        $this->assertFalse($this->definition($definitions, 'Marka')['is_variant_defining']);
    }

    /**
     * ⚠️ ADSIZ ASPECT YAZILMAZ.
     *
     * `external_attribute_id` `updateOrCreate` anahtarıdır ve boş dize
     * iki farklı aspect'i BİRBİRİNE eşlerdi.
     */
    #[Test]
    public function a_nameless_aspect_is_skipped(): void
    {
        $names = array_column($this->fetchAspects(), 'name');

        $this->assertNotContains('', $names);
        $this->assertCount(3, $names);
    }

    /**
     * ⚠️ BAŞARISIZ ASPECT YANITI "ZORUNLU ÖZNİTELİK YOK" DEMEK DEĞİLDİR.
     *
     * Sessizce boş dönseydi ön koşul kapısı ürünü geçirir ve kanal onu
     * `VALIDATION` ile reddederdi — KALICI hata.
     */
    #[Test]
    public function a_failed_aspect_response_throws_instead_of_returning_empty(): void
    {
        Http::fake([
            '*get_default_category_tree_id*' => Http::response(['categoryTreeId' => '77'], 200),
            '*get_item_aspects_for_category*' => Http::response(['errors' => []], 500),
        ]);

        $this->expectException(RequestException::class);

        $this->adapter()->fetchCategoryAttributes('57990');
    }

    // ──────────────────────────────── GERÇEK AKIŞ · `SyncTaxonomy` turu

    /**
     * ⚠️ "YAZILDI" ≠ "ÇAĞRILIYOR" — tur GERÇEK `SyncTaxonomy` ile
     * sürülür.
     *
     * Yukarıdaki testler adapter'ı DOĞRUDAN çağırır ve gövdelerin doğru
     * olduğunu kanıtlar; AKIŞTAN çağrıldıklarını KANITLAMAZ. Etsy'de de
     * aynı kural: iki yön de sürülür.
     *
     * ⚠️ `withAttributes` AÇIKÇA GEÇİLİR — varsayılanı `false` ve
     * geçilmezse `leavesFetched = 0` çıkar; "yaprak filtresi çalışıyor"
     * diye YANLIŞ sonuç çıkarılırdı (CLAUDE.md'de yazılı tuzak).
     */
    #[Test]
    public function the_real_sync_writes_categories_and_only_leaf_aspects(): void
    {
        $connection = $this->connection();

        Http::fake([
            '*get_default_category_tree_id*' => Http::response(['categoryTreeId' => '77'], 200),
            '*get_item_aspects_for_category*' => Http::response($this->aspectsBody(), 200),
            '*category_tree*' => Http::response($this->treeBody(), 200),
        ]);

        $result = $this->asSystem(fn () => app(SyncTaxonomy::class)->run($connection, withAttributes: true));

        $this->assertSame(3, $result->categoriesWritten);

        // ⚠️ YALNIZCA YAPRAK İÇİN ASPECT ÇEKİLİR — ara kategoriye ürün
        // açılamaz ve öznitelik istemek boşuna KOTADIR (§21).
        $this->assertSame(
            1,
            $result->leavesFetched,
            'Ara kategoriler için de aspect çekildi — boşuna istek ve '
            .'boşuna kota (eBay tavanı ~5.000/gün/uç nokta).',
        );

        $leaf = $this->asSystem(fn (): ?ChannelCategory => ChannelCategory::query()
            ->where('channel_type_code', 'ebay')
            ->where('external_id', '57990')
            ->first());

        $this->assertNotNull($leaf);
        $this->assertTrue((bool) $leaf->is_leaf);

        $written = $this->asSystem(fn (): int => ChannelCategoryAttribute::query()
            ->where('channel_category_id', $leaf->id)
            ->count());

        $this->assertSame(3, $written);
    }

    /**
     * ⚠️ AĞAÇ KİMLİĞİ TUR BOYUNCA BİR KEZ SORULUR.
     *
     * Önbelleklenmeseydi HER YAPRAK iki istek ederdi ve eBay ağacı ON
     * BİNLERCE yaprak taşıyor; tur kotayı (~5.000/gün/uç nokta) İKİ
     * KATINA çıkarır ve gün ortasında 429'a çarpardı.
     */
    #[Test]
    public function the_tree_id_is_resolved_once_per_sync_run(): void
    {
        $connection = $this->connection();

        Http::fake([
            '*get_default_category_tree_id*' => Http::response(['categoryTreeId' => '77'], 200),
            '*get_item_aspects_for_category*' => Http::response($this->aspectsBody(), 200),
            '*category_tree*' => Http::response($this->treeBody(), 200),
        ]);

        $this->asSystem(fn () => app(SyncTaxonomy::class)->run($connection, withAttributes: true));

        $resolves = Http::recorded(
            static fn (Request $r): bool => str_contains($r->url(), 'get_default_category_tree_id'),
        );

        $this->assertCount(
            1,
            $resolves,
            'Ağaç kimliği birden çok kez soruldu — her yaprak iki istek '
            .'eder ve tur günlük kotayı iki katına çıkarırdı.',
        );
    }

    /**
     * ⚠️ ZAMANLANMIŞ TUR eBay'İ KENDİLİĞİNDEN SEÇER — yeni kod GEREKMEZ.
     *
     * `SyncTaxonomyForChannels` yalnızca `status = 'active'` süzer ve
     * yeteneği `instanceof` ile okur; kanal adı SORULMAZ (§22'nin
     * mutabakat taramasıyla aynı tasarım). Ama "kanal-agnostik yazıldı"
     * demek "bu kanal için ÇAĞRILIYOR" demek DEĞİLDİR: entegrasyonun
     * kurulduğu GERÇEK sweep'le doğrulanır, yoksa üç gövde hiç
     * çağrılmadan öylece durur.
     *
     * Bu, slice 1.9'un "mutabakat akışı kanal bilmez ve BU SINANIR"
     * kuralının taksonomi karşılığıdır.
     */
    #[Test]
    public function the_scheduled_sweep_picks_up_ebay_without_channel_specific_code(): void
    {
        $this->connection();

        Http::fake([
            '*get_default_category_tree_id*' => Http::response(['categoryTreeId' => '77'], 200),
            '*get_item_aspects_for_category*' => Http::response($this->aspectsBody(), 200),
            '*category_tree*' => Http::response($this->treeBody(), 200),
        ]);

        $synced = app(SyncTaxonomyForChannels::class)->sweep();

        $this->assertSame(1, $synced, 'Tur eBay bağlantısını HİÇ seçmedi.');

        // Ayırt edici işaret: kanala GERÇEKTEN istek gitti ve satır yazıldı.
        Http::assertSent(static fn (Request $r): bool => str_contains($r->url(), '/category_tree/77'));

        $this->assertSame(
            3,
            $this->asSystem(fn (): int => ChannelCategory::query()
                ->where('channel_type_code', 'ebay')
                ->count()),
        );
    }

    // ──────────────────────────────────────────────────────── yardımcılar

    /** @return list<array<string, mixed>> */
    private function fetchAspects(): array
    {
        Http::fake([
            '*get_default_category_tree_id*' => Http::response(['categoryTreeId' => '77'], 200),
            '*get_item_aspects_for_category*' => Http::response($this->aspectsBody(), 200),
        ]);

        return $this->adapter()->fetchCategoryAttributes('57990');
    }

    /**
     * @param  list<array<string, mixed>>  $definitions
     * @return array<string, mixed>
     */
    private function definition(array $definitions, string $name): array
    {
        foreach ($definitions as $definition) {
            if ($definition['name'] === $name) {
                return $definition;
            }
        }

        $this->fail("`{$name}` aspect'i tanımlar arasında yok.");
    }

    /**
     * Üç düğümlü ağaç.
     *
     * ⚠️ `11450` BİLEREK ÇELİŞKİLİ: `leafCategoryTreeNode` `true` ama
     * çocuğu VAR — yaprak türetmesinin sınandığı düğüm.
     *
     * @return array<string, mixed>
     */
    private function treeBody(?string $version = '134', bool $extraLeaf = false): array
    {
        $kadinChildren = [[
            'category' => ['categoryId' => '57990', 'categoryName' => 'Elbise'],
            'leafCategoryTreeNode' => true,
        ]];

        if ($extraLeaf) {
            $kadinChildren[] = [
                'category' => ['categoryId' => '63861', 'categoryName' => 'Etek'],
                'leafCategoryTreeNode' => true,
            ];
        }

        $body = [
            'rootCategoryNode' => [
                'childCategoryTreeNodes' => [[
                    'category' => ['categoryId' => '11450', 'categoryName' => 'Giyim'],
                    // ⚠️ ÇELİŞKİ: bayrak yaprak diyor ama çocuğu var.
                    'leafCategoryTreeNode' => true,
                    'childCategoryTreeNodes' => [[
                        'category' => ['categoryId' => '15724', 'categoryName' => 'Kadın'],
                        'leafCategoryTreeNode' => false,
                        'childCategoryTreeNodes' => $kadinChildren,
                    ]],
                ]],
            ],
        ];

        if ($version !== null) {
            $body['categoryTreeVersion'] = $version;
        }

        return $body;
    }

    /**
     * AYNI ağaç, kardeşlerin SIRASI ters.
     *
     * ⚠️ SÜRÜM YOKTUR (`categoryTreeVersion` gönderilmez) — parmak izi
     * yoluna DÜŞÜLMESİ gerekiyor; kanal sürüm verseydi test sıralamayı
     * HİÇ ölçmezdi (sürüm gövdeden okunur ve sıradan bağımsızdır).
     *
     * @return array<string, mixed>
     */
    private function siblingTreeBody(bool $reversed): array
    {
        $siblings = [
            [
                'category' => ['categoryId' => '1', 'categoryName' => 'Ayakkabı'],
                'leafCategoryTreeNode' => true,
            ],
            [
                'category' => ['categoryId' => '2', 'categoryName' => 'Çanta'],
                'leafCategoryTreeNode' => true,
            ],
        ];

        return [
            'rootCategoryNode' => [
                'childCategoryTreeNodes' => $reversed ? array_reverse($siblings) : $siblings,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function aspectsBody(): array
    {
        return [
            'aspects' => [
                [
                    'localizedAspectName' => 'Marka',
                    'aspectConstraint' => [
                        'aspectRequired' => true,
                        'aspectMode' => 'FREE_TEXT',
                        'aspectEnabledForVariations' => false,
                    ],
                ],
                [
                    'localizedAspectName' => 'Renk',
                    'aspectConstraint' => [
                        'aspectRequired' => false,
                        'aspectMode' => 'SELECTION_ONLY',
                        'aspectEnabledForVariations' => true,
                    ],
                    'aspectValues' => [
                        ['localizedValue' => 'Kırmızı'],
                        ['localizedValue' => 'Mavi'],
                    ],
                ],
                [
                    // Serbest metin AMA öneri listesi VAR — `enum`
                    // sayılsaydı satıcının kendi değeri reddedilirdi.
                    'localizedAspectName' => 'Desen',
                    'aspectConstraint' => [
                        'aspectRequired' => false,
                        'aspectMode' => 'FREE_TEXT',
                        'aspectEnabledForVariations' => false,
                    ],
                    'aspectValues' => [['localizedValue' => 'Çizgili']],
                ],
                // ⚠️ ADSIZ — yazılmamalı.
                ['aspectConstraint' => ['aspectRequired' => true]],
            ],
        ];
    }

    private function adapter(?string $marketplace = 'EBAY_DE'): EbayAdapter
    {
        $connection = $this->connection($marketplace);

        return new EbayAdapter($connection, new ChannelHttpClient(
            $connection,
            app(CredentialVault::class),
            app(PayloadRedactor::class),
        ));
    }

    private function connection(?string $marketplace = 'EBAY_DE'): ChannelConnection
    {
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

        return $this->asTenant($tenant, function () use ($marketplace): ChannelConnection {
            $connection = ChannelConnection::factory()->create([
                'channel_type_code' => 'ebay',
                'external_account_id' => 'ebay-seller-'.uniqid(),
                'status' => 'active',
                'settings' => array_filter([
                    'merchant_location_key' => 'WAREHOUSE-1',
                    'marketplace_id' => $marketplace,
                    'fulfillment_policy_id' => 'FP-1',
                    'payment_policy_id' => 'PP-1',
                    'return_policy_id' => 'RP-1',
                ], static fn (mixed $v): bool => $v !== null),
            ]);

            app(CredentialVault::class)->store($connection, [
                'client_id' => 'app-id',
                'client_secret' => 'cert-id',
                'access_token' => 'gecerli-access',
                'refresh_token' => 'gecerli-refresh',
            ]);

            return $connection;
        });
    }
}
