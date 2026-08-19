<?php

declare(strict_types=1);

namespace Tests\Feature\Observability;

use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Models\ChannelType;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use App\Support\Observability\Metric;
use App\Support\Observability\MetricScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Metrik ekranı — §11'in ölçümlerinin kullanıcıya görünen yeri.
 *
 * Mimari Karar Dokümanı v2.2 · §11 · Ölçülecek metrikler,
 * §13 · Faz 3 · madde 2 ("panel grafikleri"), §17 · P0.
 *
 * EKRANIN VARLIK SEBEBİ: `CaptureMetrics` saatlik ölçüyor ama gösteren
 * hiçbir şey yoksa ölçüm bir veritabanı tablosunda ölür. §17 maddeyi
 * "ölçülmeyen güvenilirlik iddia edilemez" diye gerekçelendiriyor;
 * ölçülüp GÖSTERİLMEYEN de aynı kapıya çıkar.
 *
 * DEĞİŞMEZ KURAL — KİRACI KENDİ KAPSAMINI GÖRÜR, BAŞKASININKİNİ GÖRMEZ.
 *   `metric_snapshots` KİRACIYA AİT DEĞİLDİR (§4: `tenant_id` kolonu
 *   yok) ve `BelongsToTenant` global scope'u bu tabloda ÇALIŞMAZ.
 *   Filtre `scope` kolonu üzerinden ELLE yazılmak zorundadır; yazılmazsa
 *   rakip satıcının fazla satış miktarı ve ölü iş sayısı bu ekranda
 *   görünür. Bu projede aynı boşluk `DB::table()` üzerinde beş turda
 *   bulundu.
 *
 * DEĞİŞMEZ KURAL — SİSTEM METRİKLERİ HERKESE GÖSTERİLİR.
 *   Outbox birikmesi ve inbox gecikmesi altyapının sağlığıdır ve hiçbir
 *   kiracının verisini ifşa etmez; gizlenselerdi satıcı senkronun neden
 *   yavaşladığını hiçbir yerde göremezdi.
 *
 * DEĞİŞMEZ KURAL — GEÇMİŞ GÖSTERİLİR, YALNIZCA SON DEĞER DEĞİL.
 *   Ekranın tüm amacı "artıyor mu" sorusudur; tek sayı o soruyu ASLA
 *   cevaplayamaz ve zaten canlı sorguyla da alınabilirdi.
 *
 * DEĞİŞMEZ KURAL — EŞİK AŞIMI ROZETLE GÖSTERİLİR.
 *   Ham sayı tek başına "iyi mi kötü mü" sorusunu cevaplamaz: satıcı
 *   `1247 ms`in normal mi olduğunu bilemez. Eşik `Metric::threshold()`
 *   içinde TEK KAYNAKTIR (§11 tablosu birebir).
 */
final class MetricsScreenTest extends TestCase
{
    use RefreshDatabase;

    // ---------------------------------------------------------------- erişim

    /** Misafir metrik ekranını göremez. */
    #[Test]
    public function guest_cannot_reach_the_metrics_screen(): void
    {
        $this->get('/metrics')->assertRedirect('/login');
    }

    // -------------------------------------------------------------- izolasyon

    /**
     * BAŞKA KİRACININ KAPSAMLI METRİĞİ GÖRÜNMEZ.
     *
     * Fazla satış miktarı ve ölü iş sayısı rakip satıcının satış hacmini
     * ve operasyonel sorunlarını ifşa eder. `metric_snapshots` global
     * scope'a TABİ DEĞİLDİR; filtre `scope` üzerinden elle yazılmalıdır.
     */
    #[Test]
    public function tenant_scoped_metrics_never_leak_across_tenants(): void
    {
        [$tenantA, $userA] = $this->makeUser();
        [$tenantB] = $this->makeUser();

        $this->snapshot(Metric::OVERSOLD_UNITS, 3, MetricScope::tenant($tenantA->id));
        $this->snapshot(Metric::OVERSOLD_UNITS, 99, MetricScope::tenant($tenantB->id));

        $cards = $this->cards($this->actingAs($userA)->get('/metrics'));

        $oversold = $this->cardFor($cards, Metric::OVERSOLD_UNITS);

        $this->assertSame(3.0, $oversold['value'], 'Başka kiracının fazla satışı görünüyor.');
    }

    /** Ölü iş sayısı da kiracıya kapsanır — AYRI metrik, AYRI boşluk. */
    #[Test]
    public function dead_operation_counts_never_leak(): void
    {
        [$tenantA, $userA] = $this->makeUser();
        [$tenantB] = $this->makeUser();

        $this->snapshot(Metric::DEAD_OPERATIONS, 88, MetricScope::tenant($tenantB->id));

        $cards = $this->cards($this->actingAs($userA)->get('/metrics'));

        $this->assertNull(
            $this->cardFor($cards, Metric::DEAD_OPERATIONS),
            'Başka kiracının ölü iş sayısı görünüyor.',
        );
    }

    /**
     * KENDİ KANALININ GECİKMESİ GÖRÜNÜR, BAŞKASININKİ GÖRÜNMEZ.
     *
     * Kanal kapsamı `connection:{uuid}` taşır ve o bağlantı bir kiracıya
     * aittir; kapsam metninden kimliği çözüp kiracıya ait olup olmadığı
     * DOĞRULANMALIDIR. Doğrulanmazsa rakibin kanal performansı sızar.
     */
    #[Test]
    public function connection_scoped_metrics_are_filtered_to_owned_connections(): void
    {
        [$tenantA, $userA] = $this->makeUser();
        [$tenantB] = $this->makeUser();

        $mine = $this->connectionFor($tenantA);
        $theirs = $this->connectionFor($tenantB);

        $this->snapshot(Metric::API_LATENCY_P95, 1_200, MetricScope::connection($mine));
        $this->snapshot(Metric::API_LATENCY_P95, 9_000, MetricScope::connection($theirs));

        $cards = $this->cards($this->actingAs($userA)->get('/metrics'));

        $latency = $this->cardFor($cards, Metric::API_LATENCY_P95);

        $this->assertSame(1_200.0, $latency['value'], 'Başka kiracının kanal gecikmesi görünüyor.');
    }

    /**
     * SİSTEM METRİKLERİ HERKESE GÖSTERİLİR.
     *
     * Outbox birikmesi altyapının sağlığıdır ve hiçbir kiracının
     * verisini ifşa etmez. Gizlenseydi satıcı senkronun neden
     * yavaşladığını hiçbir yerde göremez ve destek yükü artardı.
     */
    #[Test]
    public function system_metrics_are_visible_to_every_tenant(): void
    {
        [, $user] = $this->makeUser();

        $this->snapshot(Metric::OUTBOX_PUBLISH_LAG, 42);

        $cards = $this->cards($this->actingAs($user)->get('/metrics'));

        $this->assertSame(42.0, $this->cardFor($cards, Metric::OUTBOX_PUBLISH_LAG)['value']);
    }

    // ----------------------------------------------------------------- sunum

    /**
     * EN SON ÖLÇÜM GÖSTERİLİR, İLKİ DEĞİL.
     *
     * Sıralama `id` üzerindendir, `captured_at` üzerinden DEĞİL:
     * `captured_at` SANİYE hassasiyetlidir ve arka arkaya koşan turlar
     * aynı damgayı taşıyabilir — sıra belirsiz kalır ve panel bazen eski
     * değeri gösterir. Bu projenin tekrar eden tuzağı.
     */
    #[Test]
    public function the_latest_snapshot_wins(): void
    {
        [, $user] = $this->makeUser();

        $this->snapshot(Metric::SYNC_ERROR_RATE, 1.5);
        $this->snapshot(Metric::SYNC_ERROR_RATE, 7.25);

        $cards = $this->cards($this->actingAs($user)->get('/metrics'));

        $this->assertSame(7.25, $this->cardFor($cards, Metric::SYNC_ERROR_RATE)['value']);
    }

    /**
     * GEÇMİŞ GÖNDERİLİR — grafik ancak onunla çizilir.
     *
     * Tek değer gönderilseydi ekran "artıyor mu" sorusunu ASLA
     * cevaplayamaz ve zaten canlı sorguyla da alınabilirdi; anlık
     * görüntü tablosunun tüm gerekçesi geçmiştir.
     */
    #[Test]
    public function each_card_carries_its_history(): void
    {
        [, $user] = $this->makeUser();

        $this->snapshot(Metric::SYNC_ERROR_RATE, 1);
        $this->snapshot(Metric::SYNC_ERROR_RATE, 2);
        $this->snapshot(Metric::SYNC_ERROR_RATE, 3);

        $card = $this->cardFor($this->cards($this->actingAs($user)->get('/metrics')), Metric::SYNC_ERROR_RATE);

        $this->assertCount(3, $card['history']);
        $this->assertSame(
            [1.0, 2.0, 3.0],
            array_column($card['history'], 'value'),
            'Geçmiş ESKİDEN YENİYE sıralanmalı — grafik soldan sağa okunur.',
        );
    }

    /**
     * EŞİK AŞIMI İŞARETLENİR VE EŞİK TEK KAYNAKTIR.
     *
     * Ham sayı tek başına "iyi mi kötü mü" sorusunu cevaplamaz: satıcı
     * `1247 ms`in normal mi olduğunu bilemez. Eşik panelde yeniden
     * tanımlansaydı iki gerçek kaynağı doğar ve biri değiştiğinde rozet
     * sessizce yanlış renk gösterirdi.
     */
    #[Test]
    public function a_breaching_metric_is_flagged(): void
    {
        [, $user] = $this->makeUser();

        // §11 · senkron hata oranı eşiği %5.
        $this->snapshot(Metric::SYNC_ERROR_RATE, 7.5);
        // §11 · outbox yayın birikmesi eşiği 60 sn.
        $this->snapshot(Metric::OUTBOX_PUBLISH_LAG, 12);

        $cards = $this->cards($this->actingAs($user)->get('/metrics'));

        $this->assertTrue($this->cardFor($cards, Metric::SYNC_ERROR_RATE)['breaching']);
        $this->assertFalse($this->cardFor($cards, Metric::OUTBOX_PUBLISH_LAG)['breaching']);
    }

    /** Tam eşik değeri AŞIM DEĞİLDİR — §11 "büyüktür" diyor. */
    #[Test]
    public function a_value_exactly_at_the_threshold_is_not_a_breach(): void
    {
        [, $user] = $this->makeUser();

        $this->snapshot(Metric::SYNC_ERROR_RATE, 5.0);

        $cards = $this->cards($this->actingAs($user)->get('/metrics'));

        $this->assertFalse($this->cardFor($cards, Metric::SYNC_ERROR_RATE)['breaching']);
    }

    /**
     * EŞİĞE DAYANMIŞ AMA AŞMAMIŞ DEĞER AYRI İŞARETLENİR.
     *
     * "5 / eşik 5" aşım DEĞİLDİR (§11 "büyüktür" der) ve kırmızı
     * gösterilmesi yanlış olurdu — ama sessizce sıradan göstermek de
     * satıcıyı bir adım ötede olduğundan habersiz bırakır. GERÇEK
     * TARAYICI ÇALIŞTIRMASINDA görüldü: fazla satış eşiğe tam dayanmıştı
     * ve kart hiçbir şey söylemiyordu.
     */
    #[Test]
    public function a_value_at_the_threshold_is_marked_as_near(): void
    {
        [, $user] = $this->makeUser();

        // §11 · fazla satış eşiği 5 adet.
        $this->snapshot(Metric::OVERSOLD_UNITS, 5, MetricScope::tenant($this->tenantOf($user)));

        $card = $this->cardFor($this->cards($this->actingAs($user)->get('/metrics')), Metric::OVERSOLD_UNITS);

        $this->assertFalse($card['breaching'], 'Tam eşik aşım sayıldı.');
        $this->assertTrue($card['nearThreshold'], 'Eşiğe dayanan değer işaretlenmedi.');
    }

    /** Rahat bir değer ne aşım ne yakınlık taşır. */
    #[Test]
    public function a_comfortable_value_is_neither_breaching_nor_near(): void
    {
        [, $user] = $this->makeUser();

        $this->snapshot(Metric::SYNC_ERROR_RATE, 0.5);

        $card = $this->cardFor($this->cards($this->actingAs($user)->get('/metrics')), Metric::SYNC_ERROR_RATE);

        $this->assertFalse($card['breaching']);
        $this->assertFalse($card['nearThreshold']);
    }

    /**
     * AŞAN DEĞER "YAKIN" DA SAYILMAZ — iki rozet aynı anda gösterilmez.
     *
     * Gösterilseydi kart hem kırmızı hem sarı olur ve satıcı hangisinin
     * geçerli olduğunu bilemezdi.
     */
    #[Test]
    public function a_breaching_value_is_not_also_marked_near(): void
    {
        [, $user] = $this->makeUser();

        $this->snapshot(Metric::SYNC_ERROR_RATE, 40);

        $card = $this->cardFor($this->cards($this->actingAs($user)->get('/metrics')), Metric::SYNC_ERROR_RATE);

        $this->assertTrue($card['breaching']);
        $this->assertFalse($card['nearThreshold']);
    }

    /**
     * SIFIR EŞİKLİ METRİKTE "YAKIN" YOKTUR.
     *
     * Eşiği sıfır olanlarda (`outbox_consume_gap`, `sync_delivery_gap`)
     * sıfırın altı yoktur ve "yaklaşıyor" demek HER SAĞLIKLI ölçümü
     * sarıya boyardı — uyarı anlamını yitirirdi.
     */
    #[Test]
    public function a_zero_threshold_metric_is_never_near(): void
    {
        [, $user] = $this->makeUser();

        $this->snapshot(Metric::OUTBOX_CONSUME_GAP, 0);

        $card = $this->cardFor($this->cards($this->actingAs($user)->get('/metrics')), Metric::OUTBOX_CONSUME_GAP);

        $this->assertFalse($card['breaching']);
        $this->assertFalse($card['nearThreshold'], 'Sağlıklı sıfır "eşiğe yakın" gösterildi.');
    }

    /** Eşik ve birim ekrana gönderilir — kullanıcı sayıyı yorumlayabilsin. */
    #[Test]
    public function each_card_carries_its_threshold_and_unit(): void
    {
        [, $user] = $this->makeUser();

        $this->snapshot(Metric::INVENTORY_SYNC_LATENCY_P95, 1_200);

        $card = $this->cardFor(
            $this->cards($this->actingAs($user)->get('/metrics')),
            Metric::INVENTORY_SYNC_LATENCY_P95,
        );

        $this->assertSame(60_000.0, $card['threshold']);
        $this->assertSame('ms', $card['unit']);
        $this->assertSame('Stok senkron gecikmesi (p95)', $card['label']);
    }

    /**
     * ÜST ÖZET AŞAN METRİK SAYISINI VERİR.
     *
     * On üç kart arasında tek bir kırmızı gözden kaçar; sayı satıcıyı
     * doğrudan soruna yönlendirir.
     */
    #[Test]
    public function the_summary_counts_breaching_metrics(): void
    {
        [, $user] = $this->makeUser();

        $this->snapshot(Metric::SYNC_ERROR_RATE, 9);
        $this->snapshot(Metric::OUTBOX_CONSUME_GAP, 4);
        $this->snapshot(Metric::OUTBOX_PUBLISH_LAG, 1);

        $response = $this->actingAs($user)->get('/metrics');

        $this->assertSame(2, $response->viewData('page')['props']['summary']['breaching']);
    }

    /** Özet sayısı da kiracıya kapsanır — AYRI sorgu, AYRI boşluk. */
    #[Test]
    public function the_summary_never_counts_another_tenants_breach(): void
    {
        [, $userA] = $this->makeUser();
        [$tenantB] = $this->makeUser();

        $this->snapshot(Metric::OVERSOLD_UNITS, 500, MetricScope::tenant($tenantB->id));

        $response = $this->actingAs($userA)->get('/metrics');

        $this->assertSame(0, $response->viewData('page')['props']['summary']['breaching']);
    }

    /**
     * HİÇ ÖLÇÜLMEMİŞ METRİK KART ÜRETMEZ.
     *
     * Sıfır gösterilseydi satıcı "her şey mükemmel" sanır; oysa ölçüm
     * YAPILMADI. `CaptureMetrics`'in "sıfır yazma" kuralının panel
     * karşılığıdır.
     */
    #[Test]
    public function a_metric_never_captured_produces_no_card(): void
    {
        [, $user] = $this->makeUser();

        $cards = $this->cards($this->actingAs($user)->get('/metrics'));

        $this->assertSame([], $cards);
    }

    /** Hiç ölçüm yokken ekran patlamaz ve boş durumu anlatır. */
    #[Test]
    public function an_empty_screen_renders(): void
    {
        [, $user] = $this->makeUser();

        $this->actingAs($user)->get('/metrics')->assertOk();
    }

    /**
     * GEÇMİŞ PENCEREYLE SINIRLIDIR.
     *
     * Sınırsız geçmiş gönderilseydi bir yıllık saatlik ölçüm 8760 nokta
     * demektir: Inertia yükü şişer ve grafik okunamaz hâle gelir.
     */
    #[Test]
    public function history_is_bounded(): void
    {
        [, $user] = $this->makeUser();

        for ($i = 0; $i < 60; $i++) {
            $this->snapshot(Metric::SYNC_ERROR_RATE, $i);
        }

        $card = $this->cardFor($this->cards($this->actingAs($user)->get('/metrics')), Metric::SYNC_ERROR_RATE);

        $this->assertLessThanOrEqual(48, count($card['history']));

        // SON değerler tutulur, ilkler değil: eski uç kesilirse grafik
        // "şu an ne oluyor" sorusunu hâlâ cevaplar, tersi cevaplamaz.
        $this->assertSame(59.0, end($card['history'])['value']);
    }

    // --------------------------------------------------------------- yardım

    /** @return array{0: Tenant, 1: User} */
    private function makeUser(): array
    {
        $tenant = (new CreateTenant)->run(
            name: 'Metrik ekranı '.uniqid(),
            owner: $user = User::factory()->create(),
        );

        return [$tenant, $user];
    }

    /** Kullanıcının kiracı kimliği — `makeUser()` ikisini birlikte yaratır. */
    private function tenantOf(User $user): string
    {
        return (string) DB::table('tenant_users')->where('user_id', $user->id)->value('tenant_id');
    }

    private function connectionFor(Tenant $tenant): string
    {
        return $this->asTenant($tenant, function () {
            $this->asSystem(fn () => ChannelType::query()->firstOrCreate(
                ['code' => 'woocommerce'],
                [
                    'name' => 'WooCommerce',
                    'kind' => 'storefront',
                    'adapter_class' => 'App\\Domain\\Channels\\Adapters\\WooCommerceAdapter',
                    'is_active' => true,
                ],
            ));

            return ChannelConnection::factory()->create()->id;
        });
    }

    private function snapshot(Metric $metric, float $value, ?string $scope = null): void
    {
        DB::table('metric_snapshots')->insert([
            'metric' => $metric->value,
            'scope' => $scope ?? MetricScope::SYSTEM,
            'value' => $value,
            'captured_at' => now(),
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function cards(TestResponse $response): array
    {
        return $response->viewData('page')['props']['cards'];
    }

    /**
     * @param  list<array<string, mixed>>  $cards
     * @return array<string, mixed>|null
     */
    private function cardFor(array $cards, Metric $metric): ?array
    {
        foreach ($cards as $card) {
            if ($card['metric'] === $metric->value) {
                return $card;
            }
        }

        return null;
    }
}
