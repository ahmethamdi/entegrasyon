<?php

declare(strict_types=1);

namespace Tests\Feature\Messaging;

use App\Domain\Catalog\Models\Variant;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use App\Domain\Messaging\Jobs\ConsumeOutboxEvent;
use App\Domain\Messaging\Models\OutboxEvent;
use App\Domain\Messaging\Support\OutboxRelay;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\UuidV7;
use Tests\TestCase;

/**
 * Outbox relay — yayınlama döngüsü.
 *
 * Mimari Karar Dokümanı v2.2 · §6 · Outbox relay.
 *
 * Relay yalnızca YAYINLAR: olayı kuyruğa verir ve published_at damgalar.
 * Fan-out tüketicinin işidir, consumed_at'i relay YAZMAZ.
 *
 * FOR UPDATE SKIP LOCKED: birden fazla relay çalıştığında ikinci süreç
 * birincinin aldığı satırları atlar, aynı olay iki kez yayınlanmaz.
 *
 * Olay SİLİNMEZ, işaretlenir. Çökme senaryolarında en fazla iki kez
 * yayınlanır; tüketici idempotenttir ve sürüm kapısı ikinciyi eler.
 */
final class OutboxRelayTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function unpublished_events_are_dispatched_and_stamped(): void
    {
        Queue::fake();

        [$tenant, $variant] = $this->makeContext();

        $event = $this->pendingEvent($tenant, $variant);

        $published = $this->relay()->run();

        $this->assertSame(1, $published);

        $fresh = $event->fresh();
        $this->assertNotNull($fresh->published_at, 'Yayınlanan olay damgalanmalı.');
        $this->assertSame(1, $fresh->publish_attempts);

        // Fan-out relay'in işi DEĞİL — tüketici yapar.
        $this->assertNull($fresh->consumed_at, 'Relay consumed_at yazmaz.');

        Queue::assertPushed(ConsumeOutboxEvent::class, 1);
    }

    /** Zaten yayınlanmış olay ikinci kez alınmaz. */
    #[Test]
    public function already_published_events_are_not_republished(): void
    {
        Queue::fake();

        [$tenant, $variant] = $this->makeContext();

        $event = $this->pendingEvent($tenant, $variant);

        $this->asSystem(fn () => $event->forceFill([
            'published_at' => now()->subMinute(),
            'publish_attempts' => 1,
        ])->save());

        $this->assertSame(0, $this->relay()->run());

        Queue::assertNothingPushed();
        $this->assertSame(1, $event->fresh()->publish_attempts);
    }

    /** Geleceğe planlanmış olay henüz alınmaz. */
    #[Test]
    public function events_scheduled_in_the_future_are_not_published(): void
    {
        Queue::fake();

        [$tenant, $variant] = $this->makeContext();

        $this->asTenant($tenant, fn () => OutboxEvent::record(
            aggregateType: 'inventory_level',
            aggregateId: (string) new UuidV7,
            eventType: 'InventoryLevelChanged',
            payload: ['variant_id' => $variant->id, 'version' => 1],
            tenantId: $tenant->id,
            availableAt: now()->addHour(),
        ));

        $this->assertSame(0, $this->relay()->run());

        Queue::assertNothingPushed();
    }

    /**
     * Sorgu clock_timestamp() kullanır, now() DEĞİL.
     *
     * İKİ AYRI OLGU BU HATAYI ÜRETİYOR:
     *
     * (1) PostgreSQL'de now() transaction'ın BAŞLAMA anını döndürür ve
     *     transaction boyunca — iç savepoint'ler dahil — donmuş kalır.
     *     Relay sorgusu bir transaction içinde çalışır; uzun ömürlü bir
     *     turda transaction başladıktan sonra hazırlanan olaylar "geleceğe
     *     planlanmış" sayılıp elenir.
     *
     * (2) outbox_events zaman damgaları SANİYE hassasiyetindedir
     *     (datetime_precision = 0). 19:56:25.7'de yazılan bir olayın
     *     available_at değeri 19:56:26 olarak YUVARLANIR — yani yazıldığı
     *     andan bir saniyeye kadar İLERİDE olabilir.
     *
     * İkisi birleşince taze bir olay, donmuş now()'a göre gelecekte görünür
     * ve o turda hiç alınmaz. clock_timestamp() (1)'i tamamen, (2)'yi de
     * pratikte çözer: duvar saati ilerledikçe olay bir sonraki turda görünür.
     *
     * Test bu pencereyi saniye granülerliğinde kurar.
     */
    #[Test]
    public function events_available_after_transaction_start_are_still_published(): void
    {
        Queue::fake();

        [$tenant, $variant] = $this->makeContext();

        $event = $this->pendingEvent($tenant, $variant);

        // Pencere: available_at donmuş now()'ın İLERİSİNDE ama duvar
        // saatinin GERİSİNDE. Kolon saniye hassasiyetli olduğu için
        // aralık saniye biriminde kurulur.
        //
        // Duvar saatini bir saniye ileri taşı; ardından available_at'i
        // donmuş now()'ın bir saniye ilerisine koy. Böylece:
        //   available_at > now()            → hatalı sorgu eler
        //   available_at <= clock_timestamp() → doğru sorgu alır
        $this->asSystem(fn () => DB::select('SELECT pg_sleep(1.1)'));

        $this->asSystem(fn () => DB::statement(
            "UPDATE outbox_events
                SET available_at = date_trunc('second', now()) + interval '1 second'
              WHERE id = ?",
            [$event->id],
        ));

        // Ön koşul: pencere gerçekten kuruldu mu? Kurulmadıysa test yanlış
        // yeşile dönerdi — bu iki iddia onu engeller.
        $window = $this->asSystem(fn () => DB::selectOne(
            'SELECT (available_at > now()) AS ahead_of_frozen,
                    (available_at <= clock_timestamp()) AS really_ready
               FROM outbox_events WHERE id = ?',
            [$event->id],
        ));

        $this->assertTrue((bool) $window->ahead_of_frozen, 'Pencere kurulamadı: donmuş now() geçilmiş.');
        $this->assertTrue((bool) $window->really_ready, 'Pencere kurulamadı: olay gerçekten hazır değil.');

        $published = $this->relay()->run();

        $this->assertSame(
            1,
            $published,
            'available_at transaction başlangıcından sonra ama duvar saatinden '.
            'önce olan olay elenmemeli — sorgu now() değil clock_timestamp() kullanmalı.',
        );
        $this->assertNotNull($event->fresh()->published_at);
    }

    /** Olaylar yaratılma sırasına göre yayınlanır — olay sırası korunur. */
    #[Test]
    public function events_are_published_in_creation_order(): void
    {
        Queue::fake();

        [$tenant, $variant] = $this->makeContext();

        $ids = [];
        foreach (range(1, 3) as $version) {
            $ids[] = $this->pendingEvent($tenant, $variant, $version)->id;
        }

        $this->relay()->run();

        $dispatched = [];
        Queue::assertPushed(ConsumeOutboxEvent::class, function (ConsumeOutboxEvent $job) use (&$dispatched): bool {
            $dispatched[] = $job->outboxEventId;

            return true;
        });

        $this->assertSame($ids, $dispatched, 'Olay sırası korunmalı (ORDER BY created_at).');
    }

    /** Parti sınırı aşılmaz — döngü kontrollü ilerler. */
    #[Test]
    public function batch_size_limits_a_single_pass(): void
    {
        Queue::fake();

        [$tenant, $variant] = $this->makeContext();

        foreach (range(1, 5) as $version) {
            $this->pendingEvent($tenant, $variant, $version);
        }

        $this->assertSame(2, $this->relay()->run(batchSize: 2));

        Queue::assertPushed(ConsumeOutboxEvent::class, 2);

        // Kalanlar bir sonraki turda alınır.
        $this->assertSame(2, $this->relay()->run(batchSize: 2));
        $this->assertSame(1, $this->relay()->run(batchSize: 2));
        $this->assertSame(0, $this->relay()->run(batchSize: 2));
    }

    /**
     * Relay SİSTEM bağlamında çalışır ve TÜM kiracıların olaylarını görür.
     *
     * Kiracı bağlamıyla çalışsaydı yalnızca tek kiracının olayları
     * yayınlanır, diğerleri sessizce beklerdi.
     */
    #[Test]
    public function relay_publishes_events_across_all_tenants(): void
    {
        Queue::fake();

        [$tenantA, $variantA] = $this->makeContext();
        [$tenantB, $variantB] = $this->makeContext();

        $this->pendingEvent($tenantA, $variantA);
        $this->pendingEvent($tenantB, $variantB);

        // Bağlam KURULMADAN çalıştırılır.
        $this->assertFalse(TenantContext::hasTenant());

        $this->assertSame(2, $this->relay()->run());

        Queue::assertPushed(ConsumeOutboxEvent::class, 2);
    }

    /** İş, olayın kiracısını taşır — worker bağlamı ondan kurar. */
    #[Test]
    public function dispatched_job_carries_the_tenant_id(): void
    {
        Queue::fake();

        [$tenant, $variant] = $this->makeContext();

        $event = $this->pendingEvent($tenant, $variant);

        $this->relay()->run();

        Queue::assertPushed(
            ConsumeOutboxEvent::class,
            fn (ConsumeOutboxEvent $job): bool => $job->tenantId === $tenant->id
                && $job->outboxEventId === $event->id,
        );
    }

    /**
     * Dispatch patlarsa olay YAYINLANMAMIŞ kalır.
     *
     * Bir sonraki tur onu yeniden alır. Damga atılıp dispatch başarısız
     * olsaydı olay sonsuza kadar kaybolurdu.
     */
    #[Test]
    public function failed_dispatch_leaves_event_unpublished(): void
    {
        [$tenant, $variant] = $this->makeContext();

        $event = $this->pendingEvent($tenant, $variant);

        $relay = new OutboxRelay(
            dispatcher: function (): void {
                throw new \RuntimeException('Redis erişilemez');
            },
        );

        try {
            $relay->run();
        } catch (\RuntimeException) {
            // beklenen
        }

        $this->assertNull(
            $event->fresh()->published_at,
            'Dispatch başarısızsa olay yayınlanmamış kalmalı — sonraki tur alır.',
        );
    }

    /**
     * DAMGA DISPATCH'TEN SONRA ATILIR — sıra kritiktir.
     *
     * Damga önce atılsaydı ve dispatch sonra patlasaydı, olay "yayınlanmış"
     * görünür ama kuyruğa hiç girmemiş olurdu: seviye 1 taraması da onu
     * bulamaz (published_at dolu), olay sonsuza kadar kaybolurdu.
     *
     * Testi anlamlı kılan ayrıntı: dispatch İKİNCİ olayda patlar. Böylece
     * "hepsi ya damgalandı ya damgalanmadı" tuzağına düşmeden, damganın
     * gerçekten dispatch'ten SONRA yazıldığı gözlenir — mutasyon sınamasında
     * damgayı öne almak bu testi kırar.
     */
    #[Test]
    public function events_are_not_stamped_before_dispatch_succeeds(): void
    {
        [$tenant, $variant] = $this->makeContext();

        $first = $this->pendingEvent($tenant, $variant, version: 1);
        $second = $this->pendingEvent($tenant, $variant, version: 2);

        $seen = [];

        $relay = new OutboxRelay(
            dispatcher: function (string $tenantId, string $eventId) use (&$seen): void {
                // İlk olay başarıyla "gönderilir"; damga bu anda HENÜZ
                // atılmamış olmalıdır.
                $this->assertNull(
                    $this->publishedAt($eventId),
                    'Damga dispatch tamamlanmadan atılmış — dispatch patlarsa olay kaybolur.',
                );

                $seen[] = $eventId;

                if (count($seen) === 2) {
                    throw new \RuntimeException('Redis ikinci olayda düştü');
                }
            },
        );

        try {
            $relay->run();
        } catch (\RuntimeException) {
            // beklenen
        }

        $this->assertSame([$first->id, $second->id], $seen);

        // Tur geri alındı: ikisi de yayınlanmamış kaldı, sonraki tur alır.
        $this->assertNull($first->fresh()->published_at);
        $this->assertNull($second->fresh()->published_at);
    }

    private function publishedAt(string $eventId): ?string
    {
        return $this->asSystem(fn () => DB::table('outbox_events')
            ->where('id', $eventId)
            ->value('published_at'));
    }

    // ---------------------------------------------------------------- yardımcılar

    private function relay(): OutboxRelay
    {
        return app(OutboxRelay::class);
    }

    /** @return array{0: Tenant, 1: Variant} */
    private function makeContext(): array
    {
        $tenant = (new CreateTenant)->run(
            name: 'Relay '.uniqid(),
            owner: User::factory()->create(),
        );

        $variant = $this->asTenant($tenant, fn () => Variant::factory()->create());

        return [$tenant, $variant];
    }

    private function pendingEvent(Tenant $tenant, Variant $variant, int $version = 1): OutboxEvent
    {
        return $this->asTenant($tenant, fn () => OutboxEvent::record(
            aggregateType: 'inventory_level',
            aggregateId: (string) new UuidV7,
            eventType: 'InventoryLevelChanged',
            payload: ['variant_id' => $variant->id, 'version' => $version],
            tenantId: $tenant->id,
        ));
    }
}
