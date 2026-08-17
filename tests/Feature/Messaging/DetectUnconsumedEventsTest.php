<?php

declare(strict_types=1);

namespace Tests\Feature\Messaging;

use App\Domain\Catalog\Models\Variant;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use App\Domain\Messaging\Models\OutboxEvent;
use App\Domain\Messaging\Support\DetectUnconsumedEvents;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\UuidV7;
use Tests\TestCase;

/**
 * T5 · Seviye 1 bütünlük taraması — tüketici hiç çalışmadı.
 *
 * Mimari Karar Dokümanı v2.2 · §6 · İki bütünlük taraması, §18 · T5.
 *
 * TARAMANIN VAROLUŞ NEDENİ: relay olayı kuyruğa verip published_at damgalar.
 * O andan sonra olayın kaderi Redis'in elindedir. Redis işi düşürürse
 * (maxmemory-policy allkeys-lru, flush, çökme) olay "yayınlanmış" görünür ama
 * fan-out hiç çalışmaz: hiçbir sync_operation yaratılmaz. Seviye 2 taraması
 * da onu bulamaz — bulacağı operasyon hiç var olmadı. Bu taramanın
 * görmediğini HİÇBİR mekanizma görmez.
 *
 * YENİDEN YAYIN GÜVENLİDİR: published_at = NULL yazmak olayı relay'in
 * kapsamına geri sokar. Tüketici idempotenttir ve OpenSyncOperation hem
 * sürüm kapısı hem tekillik kısıtıyla korunur; olay ikinci kez tüketilse
 * bile yeni operasyon yaratılmaz.
 *
 * consumed_at DOLUYSA OLAY YENİDEN YAYINLANMAZ. Kalıcı kanal hatası
 * (AUTHENTICATION → dead) bu alanı boş bırakmaz; o hata sync_operations
 * seviyesinde yaşar ve panelde çözülür. Yeniden yayınlamak, çözülmesi
 * kullanıcı müdahalesi gerektiren bir hatayı sonsuz döngüye çevirirdi.
 */
final class DetectUnconsumedEventsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Yayınlanmış ama tüketilmemiş eski olay bulunur ve yeniden yayına alınır.
     */
    #[Test]
    public function unconsumed_event_is_detected_and_republished(): void
    {
        [$tenant, $variant] = $this->makeContext();

        $event = $this->publishedEvent($tenant, $variant, publishedMinutesAgo: 10);

        $found = $this->scan();

        $this->assertTrue(
            $found->contains($event->id),
            'Yayınlanmış ama tüketilmemiş olay taramada görünmeli.',
        );

        $fresh = $event->fresh();

        $this->assertNull(
            $fresh->published_at,
            'Olay yeniden yayına alınmalı — published_at NULL yazılır ki relay onu tekrar alsın.',
        );
        $this->assertSame(
            2,
            $fresh->publish_attempts,
            'Yeniden yayına alınan olayın deneme sayacı artmalı.',
        );
        $this->assertNull(
            $fresh->consumed_at,
            'Tarama consumed_at yazmaz — planlamayı tüketici yapar.',
        );
    }

    /**
     * Kalıcı hataya düşmüş operasyonlar olayı YENİDEN YAYINLATMAZ.
     *
     * Bağlantının token'ı geçersiz: fan-out çalıştı, operasyonlar açıldı ve
     * hepsi AUTHENTICATION ile dead oldu. Olay consumed'dır: planlama bitti.
     * Downstream başarısızlığı consumed_at'i geri almaz.
     */
    #[Test]
    public function permanently_failed_operations_do_not_republish_event(): void
    {
        [$tenant, $variant] = $this->makeContext();

        $event = $this->publishedEvent($tenant, $variant, publishedMinutesAgo: 30);

        // Tüketici çalıştı: planlama bitti, damga atıldı.
        $this->asTenant($tenant, fn () => $event->markConsumed(operationsPlanned: 3));

        $found = $this->scan();

        $this->assertFalse(
            $found->contains($event->id),
            'consumed_at dolu olan olay yeniden yayınlanmaz — kalıcı kanal '.
            'hatası operasyon seviyesinde yaşar, olay seviyesinde değil.',
        );

        $fresh = $event->fresh();
        $this->assertNotNull($fresh->consumed_at);
        $this->assertNotNull($fresh->published_at, 'Damga korunmalı.');
    }

    /**
     * Henüz yayınlanmamış olay bu taramanın konusu DEĞİLDİR.
     *
     * O olay relay'in normal kapsamındadır ve bir sonraki turda alınacaktır.
     * Tarama ona dokunursa publish_attempts sayacını boşuna şişirir ve
     * "beşten fazla deneme → kritik alarm" eşiğini anlamsız kılar.
     */
    #[Test]
    public function never_published_event_is_not_flagged(): void
    {
        [$tenant, $variant] = $this->makeContext();

        $event = $this->asTenant($tenant, fn () => OutboxEvent::record(
            aggregateType: 'inventory_level',
            aggregateId: (string) new UuidV7,
            eventType: 'InventoryLevelChanged',
            payload: ['variant_id' => $variant->id, 'version' => 1],
            tenantId: $tenant->id,
        ));

        $this->assertFalse($this->scan()->contains($event->id));
        $this->assertSame(0, $event->fresh()->publish_attempts);
    }

    /**
     * Taze olay bekleme süresi dolmadan alınmaz.
     *
     * Tüketici asenkrondur: yayınla-tüket arası saniyeler sürer. Eşik
     * olmasaydı tarama her normal olayı "kayıp" sanıp yeniden yayınlar ve
     * her olay iki kez fan-out edilirdi.
     */
    #[Test]
    public function recently_published_event_is_within_grace_period(): void
    {
        [$tenant, $variant] = $this->makeContext();

        $event = $this->publishedEvent($tenant, $variant, publishedMinutesAgo: 1);

        $this->assertFalse(
            $this->scan()->contains($event->id),
            'Bir dakika önce yayınlanmış olay henüz kayıp sayılmaz.',
        );
        $this->assertNotNull($event->fresh()->published_at);
    }

    /**
     * publish_attempts eşiği aşılınca KRİTİK alarm yazılır.
     *
     * Beş turdur yeniden yayınlanıp hâlâ tüketilmeyen olay artık Redis
     * kaybı değildir: tüketicide sistematik bir hata vardır ve sessiz
     * yeniden yayın onu sonsuza kadar gizler.
     */
    #[Test]
    public function exhausted_event_raises_critical_alarm(): void
    {
        [$tenant, $variant] = $this->makeContext();

        $event = $this->publishedEvent($tenant, $variant, publishedMinutesAgo: 10);

        $this->asSystem(fn () => DB::table('outbox_events')
            ->where('id', $event->id)
            ->update(['publish_attempts' => 5]));

        Log::shouldReceive('warning')->zeroOrMoreTimes();
        Log::shouldReceive('critical')
            ->once()
            ->withArgs(fn (string $message, array $context): bool => $message === 'outbox.consumer_never_ran_exhausted'
                && $context['event'] === $event->id);

        $found = $this->scan();

        $this->assertTrue($found->contains($event->id));
    }

    /** Bulunan her olay için UYARI günlüğü yazılır. */
    #[Test]
    public function detected_event_is_logged_as_warning(): void
    {
        [$tenant, $variant] = $this->makeContext();

        $event = $this->publishedEvent($tenant, $variant, publishedMinutesAgo: 10);

        Log::shouldReceive('critical')->zeroOrMoreTimes();
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(fn (string $message, array $context): bool => $message === 'outbox.consumer_never_ran'
                && $context['event'] === $event->id
                && $context['tenant'] === $tenant->id);

        $this->scan();
    }

    /**
     * Tarama TÜM kiracıları görür ve bağlam KURULMADAN çalışır.
     *
     * Kiracı bağlamıyla çalışsaydı yalnızca tek kiracının kayıp olayları
     * kurtarılır, diğerleri sessizce kaybolurdu. Erişim bilinçli ve açıktır
     * (runAsSystem).
     */
    #[Test]
    public function scan_spans_all_tenants_without_context(): void
    {
        [$tenantA, $variantA] = $this->makeContext();
        [$tenantB, $variantB] = $this->makeContext();

        $eventA = $this->publishedEvent($tenantA, $variantA, publishedMinutesAgo: 10);
        $eventB = $this->publishedEvent($tenantB, $variantB, publishedMinutesAgo: 10);

        $this->assertFalse(TenantContext::hasTenant());

        $found = $this->scan();

        $this->assertTrue($found->contains($eventA->id));
        $this->assertTrue($found->contains($eventB->id));
        $this->assertFalse(
            TenantContext::hasTenant(),
            'Tarama bağlam sızdırmamalı.',
        );
    }

    /**
     * Sorgu clock_timestamp() kullanır, now() DEĞİL.
     *
     * Tarama bir transaction içinde çalışır ve "şu ana kadar eskimiş"
     * olanları arar. now() transaction'ın başlama anında donar; eşik de o
     * ana göre hesaplanır. Zaman damgaları SANİYE hassasiyetli olduğu için
     * bu donma tam sınırdaki bir olayı eleyebilir.
     *
     * Test pencereyi saniye granülerliğinde kurar: published_at donmuş
     * now()'a göre eşiğin İÇİNDE (yani "taze" görünür) ama duvar saatine
     * göre eşiğin DIŞINDA (gerçekten eskimiş).
     */
    #[Test]
    public function events_stale_only_by_wall_clock_are_still_detected(): void
    {
        [$tenant, $variant] = $this->makeContext();

        $event = $this->publishedEvent($tenant, $variant, publishedMinutesAgo: 0);

        // Duvar saatini bir saniyeden fazla ilerlet: artık
        // clock_timestamp() >= now() + 1.1s.
        $this->asSystem(fn () => DB::select('SELECT pg_sleep(1.1)'));

        // Eşik bir saniye. published_at donmuş now()'a EŞİT yazılır:
        //   published_at < now() - 1s              → YANLIŞ (taze görünür)
        //   published_at < clock_timestamp() - 1s  → DOĞRU  (gerçekten eskimiş)
        // Pencere, pg_sleep'in açtığı 1.1 saniyelik farkın içinde yaşar.
        $this->asSystem(fn () => DB::statement(
            "UPDATE outbox_events
                SET published_at = date_trunc('second', now())
              WHERE id = ?",
            [$event->id],
        ));

        // Ön koşul: pencere gerçekten kuruldu mu? Kurulmadıysa test yanlış
        // yeşile dönerdi.
        $window = $this->asSystem(fn () => DB::selectOne(
            "SELECT (published_at >= now() - interval '1 second') AS fresh_to_frozen,
                    (published_at < clock_timestamp() - interval '1 second') AS stale_to_wall_clock
               FROM outbox_events WHERE id = ?",
            [$event->id],
        ));

        $this->assertTrue(
            (bool) $window->fresh_to_frozen,
            'Pencere kurulamadı: olay donmuş now()\'a göre de eskimiş.',
        );
        $this->assertTrue(
            (bool) $window->stale_to_wall_clock,
            'Pencere kurulamadı: olay duvar saatine göre eskimemiş.',
        );

        $found = $this->scan(staleAfterSeconds: 1);

        $this->assertTrue(
            $found->contains($event->id),
            'Eşiği duvar saatine göre geçmiş olay elenmemeli — sorgu now() '.
            'değil clock_timestamp() kullanmalı.',
        );
    }

    /** Parti sınırı aşılmaz. */
    #[Test]
    public function limit_bounds_a_single_pass(): void
    {
        [$tenant, $variant] = $this->makeContext();

        foreach (range(1, 3) as $version) {
            $this->publishedEvent($tenant, $variant, publishedMinutesAgo: 10, version: $version);
        }

        $this->assertCount(2, $this->scan(limit: 2));
        $this->assertCount(1, $this->scan(limit: 2));
        $this->assertCount(0, $this->scan(limit: 2));
    }

    // ---------------------------------------------------------------- yardımcılar

    /** @return Collection<int, string> */
    private function scan(int $staleAfterSeconds = 300, int $limit = 500): Collection
    {
        return app(DetectUnconsumedEvents::class)->run(
            staleAfterSeconds: $staleAfterSeconds,
            limit: $limit,
        );
    }

    /** @return array{0: Tenant, 1: Variant} */
    private function makeContext(): array
    {
        $tenant = (new CreateTenant)->run(
            name: 'Tarama '.uniqid(),
            owner: User::factory()->create(),
        );

        $variant = $this->asTenant($tenant, fn () => Variant::factory()->create());

        return [$tenant, $variant];
    }

    /**
     * Yayınlanmış (ama tüketilmemiş) olay kurar.
     *
     * publish_attempts = 1 yazılır: relay damgalarken sayacı artırır ve
     * tarama bu gerçek başlangıç durumunu görmelidir.
     */
    private function publishedEvent(
        Tenant $tenant,
        Variant $variant,
        int $publishedMinutesAgo,
        int $version = 1,
    ): OutboxEvent {
        $event = $this->asTenant($tenant, fn () => OutboxEvent::record(
            aggregateType: 'inventory_level',
            aggregateId: (string) new UuidV7,
            eventType: 'InventoryLevelChanged',
            payload: ['variant_id' => $variant->id, 'version' => $version],
            tenantId: $tenant->id,
        ));

        $this->asSystem(fn () => DB::table('outbox_events')
            ->where('id', $event->id)
            ->update([
                'published_at' => now()->subMinutes($publishedMinutesAgo),
                'publish_attempts' => 1,
            ]));

        return $event->fresh();
    }
}
