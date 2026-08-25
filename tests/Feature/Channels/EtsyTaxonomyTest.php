<?php

declare(strict_types=1);

namespace Tests\Feature\Channels;

use App\Domain\Channels\Actions\SyncTaxonomy;
use App\Domain\Channels\Adapters\Etsy\EtsyAdapter;
use App\Domain\Channels\Contracts\SupportsTaxonomy;
use App\Domain\Channels\Models\ChannelCategory;
use App\Domain\Channels\Models\ChannelCategoryAttribute;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Models\ChannelType;
use App\Domain\Channels\Support\ChannelHttpClient;
use App\Domain\Channels\Support\CredentialVault;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use App\Support\Logging\PayloadRedactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Etsy taksonomisi — slice 3.3.
 *
 * V3.0 · §11.5 · §21 · v2.2 §14 · taksonomi kuralları.
 *
 * ─────────────────────────────────────────────────────────────────────
 * TRENDYOL KALIBI BİREBİR GEÇERLİ, GÖVDE ŞEKLİ FARKLI
 * ─────────────────────────────────────────────────────────────────────
 * Ağaç kanalın GERÇEĞİDİR (kiracısız), eşleştirme satıcının KARARIDIR.
 * Sürüm içerikten türer ve SIRALANIR. Değişen tek şey alan adlarıdır:
 * `results` / `children` / `property_id`.
 */
final class EtsyTaxonomyTest extends TestCase
{
    use RefreshDatabase;

    // ──────────────────────────────────────────────────────── ağaç çekme

    /**
     * ⚠️ AĞAÇ DÜZLEŞTİRİLİR ve YAPRAK TÜRETİLİR.
     *
     * `children` boş olan düğüm yapraktır. Etsy her düğümde bir `level`
     * döndürür ama o DERİNLİKTİR — yaprak olup olmadığını SÖYLEMEZ ve
     * farklı dallar farklı derinlikte biter. `level` okunsaydı derin bir
     * dalın ARA düğümü yaprak sanılır, ürün ara kategoriye açılmaya
     * çalışılır ve kanal `VALIDATION` dönerdi — o hata KALICIDIR.
     */
    #[Test]
    public function the_tree_is_flattened_and_leaves_are_derived(): void
    {
        $snapshot = $this->adapter()->fetchCategoryTree();

        $this->assertCount(4, $snapshot->categories);

        $byId = collect($snapshot->categories)->keyBy('external_id');

        // Kök — çocuğu var, YAPRAK DEĞİL.
        $this->assertFalse($byId['1']['is_leaf']);
        $this->assertNull($byId['1']['parent_external_id']);

        // Ara düğüm — `level` 2 ama çocuğu VAR, yaprak DEĞİL.
        $this->assertFalse($byId['2']['is_leaf']);
        $this->assertSame('1', $byId['2']['parent_external_id']);

        // Gerçek yaprak.
        $this->assertTrue($byId['3']['is_leaf']);
        $this->assertSame('2', $byId['3']['parent_external_id']);
    }

    /**
     * Okunabilir yol kurulur — eşleştirme ekranında kullanıcı kategoriyi
     * ancak bağlamıyla tanır ("Ring" tek başına yetmez).
     */
    #[Test]
    public function the_readable_path_is_built(): void
    {
        $snapshot = $this->adapter()->fetchCategoryTree();

        $leaf = collect($snapshot->categories)->firstWhere('external_id', '3');

        $this->assertSame('Jewelry > Rings > Statement Rings', $leaf['path']);
    }

    /**
     * ⚠️ BAŞARISIZ YANIT SESSİZCE BOŞ AĞACA DÖNÜŞMEZ.
     *
     * `json()` bir 500 gövdesinde de dizi döndürür ve `results` anahtarı
     * bulunmadığı için ağaç BOŞ çıkardı. O boş ağaç GEÇERLİ bir sürümle
     * veritabanına yazılır, panel "bu kanalda hiç kategori yok" der ve
     * ürün aktarımı ön koşul kapısında SONSUZA KADAR takılırdı — üstelik
     * hata hiçbir yere düşmeden.
     */
    #[Test]
    public function a_failed_response_never_becomes_an_empty_tree(): void
    {
        Http::fake(['*' => Http::response(['error' => 'sunucu'], 500)]);

        $this->expectException(\Throwable::class);

        $this->adapterWithoutFake()->fetchCategoryTree();
    }

    // ────────────────────────────────────────────────────────────── sürüm

    /**
     * ⚠️ SÜRÜM İÇERİKTEN TÜRER — aynı ağaç DAİMA aynı sürümü verir.
     *
     * Zaman veya rastgelelik karışsaydı her çekim yeni sürüm üretir, hiç
     * değişmemiş ağaç için TÜM eşleştirmeler "yeniden doğrula" damgası
     * yer ve alan anlamını kaybederdi.
     */
    #[Test]
    public function the_version_is_stable_for_the_same_tree(): void
    {
        $first = $this->adapter()->fetchCategoryTree()->version;
        $second = $this->adapter()->fetchCategoryTree()->version;

        $this->assertSame($first, $second);
        $this->assertNotSame('', $first);
    }

    /**
     * ⚠️ SIRALAMA ZORUNLUDUR — kanalın döndürme sırası sürümü DEĞİŞTİRMEZ.
     *
     * Sıralanmasaydı ağaç AYNIYKEN sürüm değişir ve satıcının aylarca
     * emek verdiği tüm eşleştirmeler bir gecede "bayat" işaretlenirdi.
     *
     * ⚠️ İKİ AĞAÇ AYRI TESTLERDE ÇEKİLEMEZ ve aynı testte `Http::fake()`
     * İKİ KEZ ÇAĞRILAMAZ: ikinci çağrı birincinin yerine geçmez ve ilk
     * sahte yanıt kullanılmaya devam eder — iki ağaç da AYNI gövdeden
     * okunur, iddia sahte yeşile döner. Bu yüzden sürüm HESAPLAMASI
     * doğrudan iki AYRI gövde üzerinden karşılaştırılır.
     */
    #[Test]
    public function the_version_ignores_channel_ordering(): void
    {
        // AYNI ağaç, kardeş düğümler TERS sırada.
        $reversed = $this->treeBody();
        $reversed['results'][0]['children'] = array_reverse(
            $reversed['results'][0]['children']
        );

        [$first, $second] = $this->versionsOf($this->treeBody(), $reversed);

        $this->assertSame(
            $first,
            $second,
            'Kanalın döndürme sırası sürümü değiştirdi — ağaç aynıyken tüm '
            .'eşleştirmeler bayat işaretlenirdi.',
        );
    }

    /** Gerçekten değişen ağaç YENİ sürüm üretir — ayıraç işini yapar. */
    #[Test]
    public function a_changed_tree_yields_a_new_version(): void
    {
        $changed = $this->treeBody();
        $changed['results'][0]['children'][0]['name'] = 'Bands';

        [$before, $after] = $this->versionsOf($this->treeBody(), $changed);

        $this->assertNotSame(
            $before,
            $after,
            'Ağaç gerçekten değiştiği hâlde sürüm AYNI kaldı — ayıraç işini '
            .'yapmıyor ve eşleştirmeler eski ağaca bağlı kalırdı.',
        );
    }

    // ─────────────────────────────────────────────────────── öznitelikler

    /**
     * ⚠️ ETSY'DE ÖZNİTELİK ZORUNLU DEĞİLDİR — `is_required` DAİMA `false`.
     *
     * Uydurma bir zorunluluk yazmak, ön koşul kapısının ürünü HİÇ
     * geçirmemesi demektir: satıcı kanalın istemediği bir alanı doldurana
     * kadar listing `blocked` kalır ve o alan panelde hiçbir zaman
     * dolmaz. §11.5'in "onay süreci yoktur" kararıyla aynı aile —
     * kanalda olmayan bir kısıt UYDURULMAZ.
     */
    #[Test]
    public function etsy_attributes_are_never_required(): void
    {
        Http::fake(['*' => Http::response($this->propertiesBody(), 200)]);

        $definitions = $this->adapterWithoutFake()->fetchCategoryAttributes('3');

        $this->assertNotSame([], $definitions);

        foreach ($definitions as $definition) {
            $this->assertFalse(
                $definition['is_required'],
                'Etsy özniteliği ZORUNLU işaretlendi — ön koşul kapısı ürünü '
                .'hiç geçirmez ve satıcı kanalın istemediği bir alanı '
                .'doldurmaya çalışırdı.',
            );
        }
    }

    /**
     * ⚠️ BOŞ İZİNLİ LİSTE "SERBEST METİN" DEMEKTİR, "HİÇBİRİ" DEĞİL.
     *
     * Aksi yorumla satıcı o özniteliği ASLA eşleştiremezdi (eşleştirme
     * kuralları). İzinli listesi olan öznitelik `enum`, olmayan `string`.
     */
    #[Test]
    public function an_empty_value_list_means_free_text(): void
    {
        Http::fake(['*' => Http::response($this->propertiesBody(), 200)]);

        $byId = collect($this->adapterWithoutFake()->fetchCategoryAttributes('3'))
            ->keyBy('external_attribute_id');

        $this->assertSame('enum', $byId['200']['data_type']);
        $this->assertSame('string', $byId['201']['data_type']);
        $this->assertSame([], $byId['201']['allowed_values']);
    }

    /** Değer listesi kimlik + etiket olarak taşınır. */
    #[Test]
    public function allowed_values_carry_id_and_label(): void
    {
        Http::fake(['*' => Http::response($this->propertiesBody(), 200)]);

        $byId = collect($this->adapterWithoutFake()->fetchCategoryAttributes('3'))
            ->keyBy('external_attribute_id');

        $this->assertSame(
            [['id' => '10', 'label' => 'Gold'], ['id' => '11', 'label' => 'Silver']],
            $byId['200']['allowed_values'],
        );
    }

    /** Başarısız öznitelik yanıtı da sessizce boş dönmez. */
    #[Test]
    public function a_failed_attribute_response_throws(): void
    {
        Http::fake(['*' => Http::response(['error' => 'x'], 500)]);

        $this->expectException(\Throwable::class);

        $this->adapterWithoutFake()->fetchCategoryAttributes('3');
    }

    // ──────────────────────────────────────────── çekirdek entegrasyonu

    /**
     * ⚠️ `SyncTaxonomy` ETSY'Yİ GERÇEKTEN İŞLER ve YALNIZCA YAPRAĞA
     * öznitelik sorar.
     *
     * Ara kategoriye öznitelik istemek boşuna istek ve boşuna KOTADIR;
     * Etsy'de kota GERÇEK bir tavandır (§21: 10.000 istek/gün).
     *
     * Bu test ZİNCİRİ sınar: adapter → `SyncTaxonomy` → `channel_categories`
     * + `channel_category_attributes`. Gövde testleri kusursuz olup
     * entegrasyon hiç kurulmasa yine yeşil kalırlardı.
     */
    #[Test]
    public function the_core_sync_writes_the_tree_and_only_leaf_attributes(): void
    {
        Http::fake([
            '*/seller-taxonomy/nodes/*/properties' => Http::response($this->propertiesBody(), 200),
            '*/seller-taxonomy/nodes' => Http::response($this->treeBody(), 200),
        ]);

        [$tenant, $connection] = $this->connected();

        // ⚠️ `withAttributes` VARSAYILAN OLARAK `false`'TUR ve bu bilinçli:
        // öznitelik çekmek yaprak başına AYRI istek demektir ve 30 bin
        // yapraklı bir ağaçta 30 bin çağrı olur. Komut bunu
        // `--with-attributes` ile açar. Açıkça geçilmezse bu test
        // `leavesFetched = 0` görür ve "yaprak filtresi çalışıyor" diye
        // YANLIŞ bir sonuç çıkarırdı — hiçbir şey ölçmeyen bir iddia.
        $result = $this->asTenant(
            $tenant,
            fn () => app(SyncTaxonomy::class)->run($connection, withAttributes: true)
        );

        $this->assertSame(4, $result->categoriesWritten);

        // ⚠️ YALNIZCA YAPRAK ÇEKİLİR — dört kategoriden İKİSİ yaprak
        // (`Statement Rings` ve `Necklaces`); kök ve ara düğüm sorulmaz.
        $this->assertSame(
            2,
            $result->leavesFetched,
            'Ara kategoriye de öznitelik soruldu — boşuna istek ve boşuna '
            .'kota (Etsy günlük kotası GERÇEK bir tavandır).',
        );

        $categories = $this->asSystem(
            fn () => ChannelCategory::query()->where('channel_type_code', 'etsy')->count()
        );

        $this->assertSame(4, $categories);

        // Öznitelikler YAPRAĞA bağlanır.
        $leafId = $this->asSystem(
            fn () => ChannelCategory::query()
                ->where('channel_type_code', 'etsy')
                ->where('external_id', '3')
                ->value('id')
        );

        $this->assertSame(
            2,
            $this->asSystem(
                fn (): int => ChannelCategoryAttribute::query()
                    ->where('channel_category_id', $leafId)
                    ->count()
            ),
        );
    }

    /** Yetenek `instanceof` ile okunur — panelde `if type === '...'` yazılmaz. */
    #[Test]
    public function the_adapter_declares_the_taxonomy_capability(): void
    {
        $this->assertInstanceOf(SupportsTaxonomy::class, $this->adapter());
    }

    // ────────────────────────────────────────────────────────── yardımcılar

    private function adapter(): EtsyAdapter
    {
        Http::fake(['*' => Http::response($this->treeBody(), 200)]);

        return $this->adapterWithoutFake();
    }

    /** @param array<string, mixed> $body */
    private function adapterFor(array $body): EtsyAdapter
    {
        Http::fake(['*' => Http::response($body, 200)]);

        return $this->adapterWithoutFake();
    }

    /**
     * İki ağacın sürümleri — TEK `Http::fake()` çağrısı, SIRALI yanıt.
     *
     * ⚠️ AYNI TESTTE `Http::fake()` İKİ KEZ ÇAĞRILAMAZ. İkinci çağrı
     * birincinin YERİNE GEÇMEZ ve ilk sahte yanıt kullanılmaya devam
     * eder — iki ağaç da AYNI gövdeden okunur ve "sürüm değişti mi"
     * iddiası SAHTE YEŞİLE döner (bu turda yaşandı: değişmiş ağaç testi
     * geçiyordu çünkü ikinci gövde hiç sunulmuyordu).
     *
     * `Http::sequence()` tek stub içinde sıralı yanıt verir ve tuzağı
     * tamamen kapatır.
     *
     * @param  array<string, mixed>  $first
     * @param  array<string, mixed>  $second
     * @return array{0: string, 1: string}
     */
    private function versionsOf(array $first, array $second): array
    {
        Http::fake([
            '*' => Http::sequence()
                ->push($first, 200)
                ->push($second, 200),
        ]);

        return [
            $this->adapterWithoutFake()->fetchCategoryTree()->version,
            $this->adapterWithoutFake()->fetchCategoryTree()->version,
        ];
    }

    private function adapterWithoutFake(): EtsyAdapter
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

    /**
     * İki seviyeli gerçekçi ağaç.
     *
     * ⚠️ `level` ALANI BİLEREK VAR ve ara düğümde 2. Yaprak kararı ondan
     * OKUNMAMALIDIR — `children` boşluğundan türetilir.
     *
     * @return array<string, mixed>
     */
    private function treeBody(): array
    {
        return [
            'count' => 1,
            'results' => [
                [
                    'id' => 1,
                    'level' => 1,
                    'name' => 'Jewelry',
                    'children' => [
                        [
                            'id' => 2,
                            'level' => 2,
                            'name' => 'Rings',
                            'children' => [
                                [
                                    'id' => 3,
                                    'level' => 3,
                                    'name' => 'Statement Rings',
                                    'children' => [],
                                ],
                            ],
                        ],
                        // İKİNCİ KARDEŞ — sıralama testinin anlamlı olması
                        // için gerekli. Tek kardeşle "ters sıra" diye bir
                        // şey yoktur ve test hiçbir şey ölçmezdi.
                        [
                            'id' => 4,
                            'level' => 2,
                            'name' => 'Necklaces',
                            'children' => [],
                        ],
                    ],
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function propertiesBody(): array
    {
        return [
            'count' => 2,
            'results' => [
                [
                    'property_id' => 200,
                    'name' => 'primary_color',
                    'display_name' => 'Primary color',
                    'is_multivalued' => false,
                    'possible_values' => [
                        ['value_id' => 10, 'name' => 'Gold'],
                        ['value_id' => 11, 'name' => 'Silver'],
                    ],
                ],
                [
                    'property_id' => 201,
                    'name' => 'occasion',
                    'display_name' => 'Occasion',
                    'is_multivalued' => false,
                    // İZİNLİ LİSTE YOK — SERBEST METİN.
                    'possible_values' => [],
                ],
            ],
        ];
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
            name: 'Etsy Taksonomi '.uniqid(),
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
