<?php

declare(strict_types=1);

namespace Tests\Feature\Channels;

use App\Domain\Channels\Actions\SyncTaxonomy;
use App\Domain\Channels\Adapters\Trendyol\TrendyolAdapter;
use App\Domain\Channels\Adapters\WooCommerce\WooCommerceAdapter;
use App\Domain\Channels\Models\ChannelCategory;
use App\Domain\Channels\Models\ChannelCategoryAttribute;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Models\ChannelType;
use App\Domain\Channels\Support\ChannelHttpClient;
use App\Domain\Channels\Support\CredentialVault;
use App\Domain\Channels\Support\SyncTaxonomyForChannels;
use App\Domain\Channels\Support\TaxonomySyncResult;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use App\Support\Logging\PayloadRedactor;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Taksonomi çekme, önbellekleme ve sürümleme.
 *
 * Mimari Karar Dokümanı v2.2 · §13 · Faz 2 ("Taksonomi çekme, önbellekleme,
 * sürümleme — 20 sa"), §4 · Mapping tabloları, §14 · Trendyol.
 *
 * DEĞİŞMEZ KURAL — TAKSONOMİ KİRACIYA AİT DEĞİLDİR:
 *   `channel_categories` KANALA aittir, satıcıya değil. Trendyol'un kategori
 *   ağacı tüm satıcılar için AYNIDIR; kiracı başına kopyalansaydı aynı ağaç
 *   yüzlerce kez saklanır, her kiracı ayrı ayrı çekmek zorunda kalır ve kota
 *   boşa giderdi. Tekillik `(channel_type_code, taxonomy_version,
 *   external_id)` üzerindedir — `tenant_id` KOLONU YOKTUR.
 *
 *   Kiracıya ait olan EŞLEŞTİRMEDİR (`category_mappings`), ağacın kendisi
 *   değil. O tablo bu maddenin değil, sonraki maddenin konusu.
 *
 * DEĞİŞMEZ KURAL — SÜRÜM ESKİYİ SİLMEZ:
 *   Yeni sürüm çekildiğinde eski satırlar KALIR. Eşleştirmeler eski sürüme
 *   bağlıdır ve silinseydi FK kopar, satıcının aylarca emek verdiği kategori
 *   eşleştirmeleri bir gecede yok olurdu. Sürüm bir AYIRAÇTIR, bir imha
 *   emri değil.
 *
 * DEĞİŞMEZ KURAL — TAKSONOMİ STOK AKIŞINA DOKUNMAZ (§14):
 *   Kategori ağacı çekmek hiçbir listing'in `lifecycle_status`'ünü
 *   değiştirmez ve hiçbir senkron operasyonu açmaz.
 */
final class TaxonomySyncTest extends TestCase
{
    use RefreshDatabase;

    // ────────────────────────────────────────────── adapter: çekme

    /**
     * Kategori ağacı Trendyol'dan çekilir ve sürüm damgası taşır.
     *
     * Sürüm ağacın KENDİSİNDEN türer: kanal bir sürüm numarası vermez, bu
     * yüzden içerikten deterministik bir parmak izi üretilir. İki farklı
     * ağaç iki farklı sürüm, aynı ağaç her zaman aynı sürüm demektir.
     */
    #[Test]
    public function category_tree_is_fetched_with_a_version(): void
    {
        Http::fake(['*' => Http::response($this->trendyolTree(), 200)]);

        $snapshot = $this->adapter()->fetchCategoryTree();

        $this->assertSame(3, $snapshot->count());
        $this->assertNotSame('', $snapshot->version);
        $this->assertNotNull($snapshot->fetchedAt);

        // Ağaç düzleştirilir: ebeveyn bağı `parent_external_id` ile taşınır.
        $byId = collect($snapshot->categories)->keyBy('external_id');

        $this->assertNull($byId['1']['parent_external_id']);
        $this->assertSame('1', $byId['11']['parent_external_id']);
        $this->assertFalse($byId['1']['is_leaf'], 'Alt kategorisi olan yaprak değildir.');
        $this->assertTrue($byId['11']['is_leaf']);
    }

    /**
     * SÜRÜM İÇERİKTEN TÜRER — aynı ağaç aynı sürümü verir.
     *
     * Zaman veya rastgelelik karışsaydı her çekim yeni sürüm üretir, hiç
     * değişmemiş ağaç için tüm eşleştirmeler "yeniden doğrula" damgası
     * yerdi ve sürüm alanı anlamını kaybederdi.
     */
    #[Test]
    public function taxonomy_version_is_stable_for_identical_trees(): void
    {
        Http::fake(['*' => Http::response($this->trendyolTree(), 200)]);

        // TEK adapter iki kez çeker: ikinci bir bağlantı açmak
        // `(type, account)` global tekilliğini ihlal ederdi — kısıt burada
        // doğru çalışıyor, test kurgusu ona uymalı.
        $adapter = $this->adapter();

        $first = $adapter->fetchCategoryTree()->version;
        $second = $adapter->fetchCategoryTree()->version;

        $this->assertSame($first, $second, 'Aynı ağaç aynı sürümü vermeli.');
    }

    /** Ağaç değişince sürüm de değişir — eşleştirmeler yeniden doğrulanmalı. */
    #[Test]
    public function taxonomy_version_changes_when_the_tree_changes(): void
    {
        $adapter = $this->adapter();

        $changed = $this->trendyolTree();
        $changed['categories'][0]['name'] = 'Giyim ve Aksesuar';

        // SIRALI yanıt: ikinci bir `Http::fake()` çağrısı ilkini DEĞİŞTİRMEZ,
        // bu yüzden iki farklı gövde tek sahtede sıraya konur.
        Http::fake(['*' => Http::sequence()
            ->push($this->trendyolTree(), 200)
            ->push($changed, 200)]);

        $before = $adapter->fetchCategoryTree()->version;
        $after = $adapter->fetchCategoryTree()->version;

        $this->assertNotSame($before, $after, 'Değişen ağaç yeni sürüm üretmeli.');
    }

    /**
     * KANALIN DÖNDÜRME SIRASI SÜRÜMÜ DEĞİŞTİRMEZ.
     *
     * Aynı kategoriler farklı sırada gelebilir — API sayfalama, önbellek
     * veya yük dengeleme yüzünden sıra garantili değildir. Parmak izi
     * sıraya duyarlı olsaydı, ağaçta hiçbir şey değişmemişken sürüm
     * değişir ve TÜM kategori eşleştirmeleri "yeniden doğrula" damgası
     * yerdi. Satıcı hiçbir şey yapmamışken eşleştirme ekranı kırmızıya
     * dönerdi.
     *
     * Bu yüzden parmak izi `sort()` ile sıralanır (mutasyonla bulundu).
     */
    #[Test]
    public function version_ignores_the_order_categories_arrive_in(): void
    {
        $adapter = $this->adapter();

        $reordered = $this->trendyolTree();
        // AYNI ağaç, alt kategoriler ters sırada.
        $reordered['categories'][0]['subCategories'] = array_reverse(
            $reordered['categories'][0]['subCategories'],
        );

        Http::fake(['*' => Http::sequence()
            ->push($this->trendyolTree(), 200)
            ->push($reordered, 200)]);

        $first = $adapter->fetchCategoryTree()->version;
        $second = $adapter->fetchCategoryTree()->version;

        $this->assertSame(
            $first,
            $second,
            'Sıra değişikliği sürümü DEĞİŞTİRMEMELİ — ağaç aynı.',
        );
    }

    /** Zorunlu öznitelikler kategori bazında çekilir. */
    #[Test]
    public function category_attributes_are_fetched_for_a_leaf(): void
    {
        Http::fake(['*' => Http::response($this->trendyolAttributes(), 200)]);

        $attributes = $this->adapter()->fetchCategoryAttributes('11');

        $this->assertCount(2, $attributes);

        $beden = collect($attributes)->firstWhere('external_attribute_id', '338');

        $this->assertSame('Beden', $beden['name']);
        $this->assertTrue($beden['is_required'], 'Zorunluluk kanaldan gelir.');
        $this->assertTrue($beden['is_variant_defining'], 'Varyant belirleyici bilgi taşınmalı.');
        $this->assertSame(
            ['S', 'M'],
            array_column($beden['allowed_values'], 'label'),
            'İzinli değerler eşleştirme ekranının girdisidir.',
        );
    }

    // ───────────────────────────────────────── önbellek: veritabanı

    /**
     * ÇEKİLEN AĞAÇ VERİTABANINA YAZILIR.
     *
     * Önbelleklenmeseydi ürün aktarımının her adımı kategori ağacını
     * yeniden çeker, Trendyol kotası tükenir ve panel her açılışta
     * saniyelerce beklerdi.
     */
    #[Test]
    public function fetched_tree_is_cached_in_the_database(): void
    {
        Http::fake(['*' => Http::response($this->trendyolTree(), 200)]);

        $result = $this->syncTaxonomy();

        $this->assertSame(3, $result->categoriesWritten);

        $rows = $this->asSystem(fn () => ChannelCategory::query()
            ->where('channel_type_code', 'trendyol')
            ->get());

        $this->assertCount(3, $rows);

        $leaf = $rows->firstWhere('external_id', '11');

        $this->assertSame('Kadın Elbise', $leaf->name);
        $this->assertSame('1', $leaf->parent_external_id);
        $this->assertTrue($leaf->is_leaf);
        $this->assertSame('Giyim > Kadın Elbise', $leaf->path, 'Yol okunabilir olmalı.');
    }

    /**
     * TAKSONOMİ KİRACIYA AİT DEĞİLDİR.
     *
     * Tablo `tenant_id` KOLONU TAŞIMAZ: Trendyol'un ağacı tüm satıcılar için
     * aynıdır. Kiracı başına kopyalansaydı aynı ağaç yüzlerce kez saklanır
     * ve her kiracı ayrı ayrı çekmek zorunda kalırdı.
     */
    #[Test]
    public function taxonomy_table_has_no_tenant_column(): void
    {
        $columns = Schema::getColumnListing('channel_categories');

        // ÖNCE tablonun VAR olduğu kanıtlanır: olmayan tablo için
        // `getColumnListing` boş dizi döner ve `assertNotContains` hiçbir şey
        // sınamadan yeşil kalırdı — tablo silinse bile test geçerdi.
        $this->assertContains('taxonomy_version', $columns, 'Tablo var olmalı.');

        $this->assertNotContains(
            'tenant_id',
            $columns,
            'Taksonomi KANALA aittir; kiracı kolonu olmamalı.',
        );

        $attributeColumns = Schema::getColumnListing('channel_category_attributes');

        $this->assertContains('is_required', $attributeColumns, 'Tablo var olmalı.');
        $this->assertNotContains('tenant_id', $attributeColumns);
    }

    /**
     * İKİNCİ ÇEKİM KOPYA SATIR AÇMAZ.
     *
     * Tekillik `(channel_type_code, taxonomy_version, external_id)`
     * üzerindedir; aynı sürüm yeniden çekildiğinde satırlar güncellenir.
     */
    #[Test]
    public function syncing_the_same_version_twice_creates_no_duplicates(): void
    {
        Http::fake(['*' => Http::response($this->trendyolTree(), 200)]);

        $this->syncTaxonomy();
        $this->syncTaxonomy();

        $count = $this->asSystem(fn (): int => ChannelCategory::query()
            ->where('channel_type_code', 'trendyol')
            ->count());

        $this->assertSame(3, $count, 'Aynı sürüm ikinci kez yazılmamalı.');
    }

    /**
     * YENİ SÜRÜM ESKİYİ SİLMEZ.
     *
     * Eşleştirmeler eski sürüme bağlıdır (`category_mappings.taxonomy_version`)
     * ve silinseydi satıcının aylarca emek verdiği eşleştirmeler bir gecede
     * yok olurdu. Sürüm bir AYIRAÇTIR, imha emri değil.
     */
    #[Test]
    public function a_new_version_never_deletes_the_previous_one(): void
    {
        $changed = $this->trendyolTree();
        $changed['categories'][0]['name'] = 'Giyim ve Aksesuar';

        // SIRALI yanıt: ikinci `Http::fake()` ilkini değiştirmez.
        Http::fake(['*' => Http::sequence()
            ->push($this->trendyolTree(), 200)
            ->push($changed, 200)]);

        $first = $this->syncTaxonomy();
        $second = $this->syncTaxonomy();

        $this->assertNotSame($first->version, $second->version);

        $versions = $this->asSystem(fn () => ChannelCategory::query()
            ->where('channel_type_code', 'trendyol')
            ->distinct()
            ->pluck('taxonomy_version'));

        $this->assertCount(2, $versions, 'Eski sürüm KORUNMALI.');
    }

    /** Yaprak kategorilerin öznitelikleri de önbelleğe yazılır. */
    #[Test]
    public function leaf_attributes_are_cached(): void
    {
        Http::fake([
            '*/product-categories' => Http::response($this->trendyolTree(), 200),
            '*' => Http::response($this->trendyolAttributes(), 200),
        ]);

        $this->syncTaxonomy(withAttributes: true);

        $leaf = $this->asSystem(fn () => ChannelCategory::query()
            ->where('external_id', '11')
            ->firstOrFail());

        $attributes = $this->asSystem(fn () => ChannelCategoryAttribute::query()
            ->where('channel_category_id', $leaf->id)
            ->get());

        $this->assertCount(2, $attributes);

        $beden = $attributes->firstWhere('external_attribute_id', '338');

        $this->assertTrue($beden->is_required);
        $this->assertTrue($beden->is_variant_defining);
        $this->assertNotNull($leaf->fresh()->attributes_fetched_at, 'Çekim anı damgalanmalı.');
    }

    /**
     * ÖZNİTELİK YALNIZCA YAPRAK İÇİN ÇEKİLİR.
     *
     * Ara kategoriye ürün açılamaz; öznitelik istemek boşuna istek ve boşuna
     * kotadır. 30 bin kategorili bir ağaçta bu fark saatler demektir.
     */
    #[Test]
    public function attributes_are_only_fetched_for_leaf_categories(): void
    {
        Http::fake([
            '*/product-categories' => Http::response($this->trendyolTree(), 200),
            '*' => Http::response($this->trendyolAttributes(), 200),
        ]);

        $this->syncTaxonomy(withAttributes: true);

        $attributeCalls = collect(Http::recorded())
            ->filter(fn (array $pair): bool => str_contains($pair[0]->url(), 'attributes'))
            ->map(fn (array $pair): string => $pair[0]->url());

        // Ağaçta iki yaprak var (11 ve 12); kök (1) yaprak değil.
        $this->assertCount(2, $attributeCalls, 'Yalnızca yapraklar için istek atılmalı.');

        $this->assertTrue(
            $attributeCalls->every(fn (string $url): bool => ! str_contains($url, '/1/attributes')),
            'Ara kategori için öznitelik istenmemeli.',
        );
    }

    /**
     * TAKSONOMİ STOK AKIŞINA DOKUNMAZ (§14).
     *
     * Kategori ağacı çekmek hiçbir senkron operasyonu açmaz ve hiçbir
     * outbox olayı yazmaz. §14'ün tasarım hedefi tam olarak budur:
     * pazaryeri karmaşıklığı çekirdeğe temas etmez.
     */
    #[Test]
    public function taxonomy_sync_touches_no_sync_state(): void
    {
        Http::fake(['*' => Http::response($this->trendyolTree(), 200)]);

        $before = $this->asSystem(fn (): array => [
            'operations' => DB::table('sync_operations')->count(),
            'outbox' => DB::table('outbox_events')->count(),
            'listings' => DB::table('listings')->count(),
        ]);

        $this->syncTaxonomy();

        $after = $this->asSystem(fn (): array => [
            'operations' => DB::table('sync_operations')->count(),
            'outbox' => DB::table('outbox_events')->count(),
            'listings' => DB::table('listings')->count(),
        ]);

        $this->assertSame($before, $after, 'Taksonomi stok akışına dokunmamalı.');
    }

    // ─────────────────────────────────────────────── yetenek kapısı

    /**
     * TAKSONOMİSİ OLMAYAN KANAL İÇİN ÇALIŞMAZ.
     *
     * WooCommerce `SupportsTaxonomy` uygulamaz; kategori serbesttir.
     * Yetenek `instanceof` ile okunur, `if ($code === 'trendyol')` YAZILMAZ.
     */
    #[Test]
    public function channels_without_taxonomy_are_skipped(): void
    {
        [$tenant] = $this->makeTenant();

        $connection = $this->asTenant($tenant, function (): ChannelConnection {
            $this->wooChannelType();

            return ChannelConnection::factory()->create([
                'channel_type_code' => 'woocommerce',
                'status' => 'active',
                'settings' => ['base_url' => 'https://magaza.example/wp-json/wc/v3'],
            ]);
        });

        Http::fake();

        $result = app(SyncTaxonomy::class)->run($connection);

        $this->assertFalse($result->supported, 'Taksonomisi olmayan kanal atlanmalı.');
        $this->assertSame(0, $result->categoriesWritten);
        Http::assertNothingSent();
    }

    /**
     * TEK BOZUK BAĞLANTI TÜM KANALI DURDURMAZ.
     *
     * Taksonomi kanal türü başına BİR kez çekilir ve çağrı bir bağlantı
     * üzerinden yapılır. Seçilen bağlantı bozuksa — ayarı eksik, kimlik
     * bilgisi ölmüş, satıcı hesabı kapanmış — ilk denemede pes edilseydi o
     * kanaldaki TÜM satıcılar taksonomisiz kalırdı. Üstelik sorun kendi
     * bağlantılarında değil bir BAŞKASININKİNDE olduğu için hiçbiri
     * düzeltemezdi.
     *
     * GERÇEK ÇALIŞTIRMADA BULUNDU: ayarı eksik eski bir bağlantı seçilmiş
     * ve `taxonomy:sync` "0 kanal türü" diyerek hiçbir şey çekmemişti.
     * Test paketi bunu göremiyordu çünkü tek bağlantıyla çalışıyordu.
     */
    #[Test]
    public function a_broken_connection_does_not_block_the_whole_channel(): void
    {
        [$tenant] = $this->makeTenant();

        $this->asTenant($tenant, function (): void {
            $this->trendyolChannelType();

            // ÖNCE yaratılan bağlantı BOZUK: satıcı kimliği yok ve adapter
            // istek kuramadan istisna fırlatır. Sıralama `created_at`'e
            // göre olduğu için bu önce denenecek.
            //
            // NOT: yalnızca `base_url`'ü boşaltmak YETMEZ — `Http::fake()`
            // her adrese cevap verdiği için istek sessizce "başarılı"
            // olurdu. Gerçek bozukluk için adapter'ın KENDİSİNİN
            // fırlatacağı bir eksiklik gerekir (gerçek çalıştırmada da
            // hata tam buradan çıkmıştı).
            ChannelConnection::factory()->create([
                'channel_type_code' => 'trendyol',
                'external_account_id' => 'BOZUK-1',
                'status' => 'active',
                'settings' => ['base_url' => 'https://api.trendyol.com/sapigw'],
                'created_at' => now()->subDay(),
            ]);

            // SONRA yaratılan bağlantı sağlam.
            $good = ChannelConnection::factory()->create([
                'channel_type_code' => 'trendyol',
                'external_account_id' => 'SAGLAM-1',
                'status' => 'active',
                'settings' => [
                    'base_url' => 'https://api.trendyol.com/sapigw',
                    'supplier_id' => 'SAGLAM-1',
                ],
                'created_at' => now(),
            ]);

            app(CredentialVault::class)->store($good, [
                'api_key' => 'anahtar',
                'api_secret' => 'sifre',
            ]);
        });

        // İlk bağlantı 500 alır, ikinci bağlantı ağacı getirir.
        Http::fake(['*' => Http::sequence()
            ->push(['errors' => [['message' => 'boom']]], 500)
            ->push($this->trendyolTree(), 200)]);

        $synced = app(SyncTaxonomyForChannels::class)->sweep();

        $this->assertSame(
            1,
            $synced,
            'Bozuk bağlantıdan sonra sağlam olan denenmeli.',
        );

        $count = $this->asSystem(fn (): int => ChannelCategory::query()
            ->where('channel_type_code', 'trendyol')
            ->count());

        $this->assertSame(3, $count, 'Ağaç yine de çekilmeli.');
    }

    /**
     * BAŞARISIZ YANIT SESSİZCE BOŞ AĞACA DÖNÜŞMEZ.
     *
     * `json()` bir 500 gövdesinde de dizi döndürür ve `categories` anahtarı
     * bulunmadığı için ağaç BOŞ çıkardı. O boş ağaç geçerli bir sürümle
     * veritabanına yazılır, panel "bu kanalda hiç kategori yok" der ve ürün
     * aktarımı ön koşul kapısında sonsuza kadar takılırdı — üstelik hata
     * hiçbir yere düşmeden.
     *
     * GERÇEK ÇALIŞTIRMA SIRASINDA BULUNDU.
     */
    #[Test]
    public function a_failed_response_never_becomes_an_empty_tree(): void
    {
        [$tenant] = $this->makeTenant();

        $connection = $this->asTenant(
            $tenant,
            fn (): ChannelConnection => $this->connection(),
        );

        Http::fake(['*' => Http::response(['errors' => [['message' => 'boom']]], 500)]);

        $threw = false;

        try {
            $this->asTenant($tenant, fn () => app(SyncTaxonomy::class)->run($connection));
        } catch (\Throwable) {
            $threw = true;
        }

        $this->assertTrue($threw, 'Başarısız yanıt istisna fırlatmalı.');

        $count = $this->asSystem(fn (): int => ChannelCategory::query()->count());

        $this->assertSame(0, $count, 'Boş ağaç YAZILMAMALI.');
    }

    // ─────────────────────────────────────────────── komut ve zamanlama

    /** Komut kayıtlı — kayıt olmadan zamanlayıcı onu bulamaz. */
    #[Test]
    public function taxonomy_command_is_registered(): void
    {
        $this->assertArrayHasKey(
            'taxonomy:sync',
            Artisan::all(),
            'Domain komutları OTOMATİK KEŞFEDİLMEZ; bootstrap/app.php içinde kaydedilir.',
        );
    }

    /**
     * Komut zamanlanmış — kayıt ve zamanlama İKİ AYRI koşuldur.
     *
     * Taksonomi günlüktür: kategori ağacı sık değişmez ve her saat çekmek
     * kotayı boşa harcar (§15 · maintenance kuyruğu).
     */
    #[Test]
    public function taxonomy_sync_is_scheduled_daily(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($event): bool => str_contains($event->command ?? '', 'taxonomy:sync'));

        $this->assertCount(1, $events, 'taxonomy:sync zamanlanmalı.');
        $this->assertSame('0 3 * * *', $events->first()->expression, 'Günlük olmalı.');
    }

    // ─────────────────────────────────────────────────── yardımcılar

    /** @return array{0: Tenant, 1: User} */
    private function makeTenant(string $name = 'Taksonomi'): array
    {
        $user = User::factory()->create();
        $tenant = (new CreateTenant)->run(name: $name.' '.uniqid(), owner: $user);

        return [$tenant, $user];
    }

    private function trendyolChannelType(): ChannelType
    {
        return $this->asSystem(fn (): ChannelType => ChannelType::query()->updateOrCreate(
            ['code' => 'trendyol'],
            [
                'name' => 'Trendyol',
                'kind' => 'marketplace',
                'adapter_class' => TrendyolAdapter::class,
                'capabilities' => ['taxonomy' => true],
                'rate_limit_profile' => ['requests_per_second' => 5, 'burst_capacity' => 10],
                'supports_webhooks' => false,
                'is_active' => true,
            ],
        ));
    }

    private function wooChannelType(): ChannelType
    {
        return $this->asSystem(fn (): ChannelType => ChannelType::query()->updateOrCreate(
            ['code' => 'woocommerce'],
            [
                'name' => 'WooCommerce',
                'kind' => 'storefront',
                'adapter_class' => WooCommerceAdapter::class,
                'capabilities' => ['taxonomy' => false],
                'rate_limit_profile' => ['requests_per_second' => 5, 'burst_capacity' => 10],
                'supports_webhooks' => true,
                'is_active' => true,
            ],
        ));
    }

    private function connection(): ChannelConnection
    {
        $this->trendyolChannelType();

        $connection = ChannelConnection::factory()->create([
            'channel_type_code' => 'trendyol',
            'external_account_id' => '123456',
            'status' => 'active',
            'settings' => [
                'base_url' => 'https://api.trendyol.com/sapigw',
                'supplier_id' => '123456',
            ],
        ]);

        app(CredentialVault::class)->store($connection, [
            'api_key' => 'anahtar',
            'api_secret' => 'sifre',
        ]);

        return $connection;
    }

    private function adapter(): TrendyolAdapter
    {
        [$tenant] = $this->makeTenant();

        return $this->asTenant($tenant, function (): TrendyolAdapter {
            $connection = $this->connection();

            return new TrendyolAdapter(
                $connection,
                new ChannelHttpClient(
                    $connection,
                    app(CredentialVault::class),
                    app(PayloadRedactor::class),
                ),
            );
        });
    }

    /**
     * Taksonomi turu — bağlantı BİR KEZ açılır ve tekrar kullanılır.
     *
     * Her çağrıda yeni bağlantı açmak `(type, account)` global tekilliğini
     * ihlal eder; kısıt doğru çalışıyor, test kurgusu ona uyar.
     */
    private function syncTaxonomy(bool $withAttributes = false): TaxonomySyncResult
    {
        if ($this->sharedTenant === null) {
            [$this->sharedTenant] = $this->makeTenant();
            $this->sharedConnection = $this->asTenant(
                $this->sharedTenant,
                fn (): ChannelConnection => $this->connection(),
            );
        }

        return $this->asTenant($this->sharedTenant, fn () => app(SyncTaxonomy::class)->run(
            $this->sharedConnection,
            withAttributes: $withAttributes,
        ));
    }

    private ?Tenant $sharedTenant = null;

    private ?ChannelConnection $sharedConnection = null;

    /**
     * Trendyol kategori ağacı — gerçek biçime yakın.
     *
     * Ağaç İÇ İÇEDİR: `subCategories` alanı özyinelemeli iner.
     *
     * @return array<string, mixed>
     */
    private function trendyolTree(): array
    {
        return [
            'categories' => [
                [
                    'id' => 1,
                    'name' => 'Giyim',
                    'subCategories' => [
                        ['id' => 11, 'name' => 'Kadın Elbise', 'subCategories' => []],
                        ['id' => 12, 'name' => 'Erkek Gömlek', 'subCategories' => []],
                    ],
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function trendyolAttributes(): array
    {
        return [
            'categoryAttributes' => [
                [
                    'attribute' => ['id' => 338, 'name' => 'Beden'],
                    'required' => true,
                    'varianter' => true,
                    'allowCustom' => false,
                    'attributeValues' => [
                        ['id' => 1, 'name' => 'S'],
                        ['id' => 2, 'name' => 'M'],
                    ],
                ],
                [
                    'attribute' => ['id' => 47, 'name' => 'Renk'],
                    'required' => false,
                    'varianter' => false,
                    'allowCustom' => true,
                    'attributeValues' => [],
                ],
            ],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        TenantContext::clear();
    }
}
