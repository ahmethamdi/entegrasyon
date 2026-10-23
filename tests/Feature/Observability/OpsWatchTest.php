<?php

declare(strict_types=1);

namespace Tests\Feature\Observability;

use App\Domain\Channels\Adapters\Trendyol\TrendyolAdapter;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Models\ChannelType;
use App\Domain\Channels\Support\CircuitBreaker;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use App\Support\Observability\OpsWatch;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Horizon\WaitTimeCalculator;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * S7 · `ops:watch` — dört sinyal, eşik, saatlik tekrar koruması, zamanlama.
 *
 * Eşik ALTI da sınanır: yalnız "alarm gider" sınansaydı eşiği sıfırlayan
 * (her şeye öten) bir hata yeşil kalırdı.
 */
final class OpsWatchTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private ChannelConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'entegrasyon.beta.ntfy_topic' => 'test-ops',
            'entegrasyon.beta.ntfy_base_url' => 'https://ntfy.example',
            'entegrasyon.beta.notify_email' => 'info@34devs.com',
            'mail.default' => 'array',
            'entegrasyon.ops.queue_wait_seconds' => 120,
            'entegrasyon.ops.rate_limit_hits_15m' => 3,
            'entegrasyon.ops.dead_operations_1h' => 2,
        ]);

        Http::fake(['https://ntfy.example/*' => Http::response(['id' => 'x'])]);

        $this->quietQueues();

        $this->asSystem(fn () => ChannelType::query()->updateOrCreate(['code' => 'trendyol'], [
            'name' => 'Trendyol',
            'kind' => 'marketplace',
            'adapter_class' => TrendyolAdapter::class,
            'supports_webhooks' => false,
            'is_active' => true,
            'is_beta' => false,
        ]));

        $this->tenant = (new CreateTenant)->run('Kavlak', User::factory()->create());
        $this->connection = $this->asTenant($this->tenant, fn () => ChannelConnection::factory()->create([
            'channel_type_code' => 'trendyol',
            'status' => 'active',
            'label' => 'Kavlak Trendyol',
        ]));
    }

    #[Test]
    public function a_quiet_system_raises_nothing(): void
    {
        $this->rateLimited(3);          // eşik = 3, aşmıyor
        $this->dead(2);                 // eşik = 2, aşmıyor

        $this->assertSame([], app(OpsWatch::class)->findings());
        $this->artisan('ops:watch')->assertSuccessful();
        Http::assertNothingSent();
    }

    #[Test]
    public function each_signal_raises_its_own_alert(): void
    {
        $this->rateLimited(4);
        $this->rateLimited(1, ageMinutes: 30);   // pencere dışı, sayılmaz
        $this->dead(3);
        app(CircuitBreaker::class)->openFor($this->connection->id, 300);

        $calc = Mockery::mock(WaitTimeCalculator::class);
        $calc->shouldReceive('calculate')->andReturn(['redis:orders:high' => 300, 'redis:default' => 10]);
        $this->app->instance(WaitTimeCalculator::class, $calc);

        $keys = array_column(app(OpsWatch::class)->findings(), 'key');
        sort($keys);

        $this->assertSame([
            "circuit:{$this->connection->id}",
            'dead:system',
            'queue:redis:orders:high',
            "rate_limit:{$this->connection->id}",
        ], $keys);

        app(CircuitBreaker::class)->reset($this->connection->id);
    }

    #[Test]
    public function the_same_alert_goes_out_at_most_once_an_hour(): void
    {
        $this->dead(5);

        $this->artisan('ops:watch')->expectsOutputToContain('gönderildi: 1')->assertSuccessful();
        $this->artisan('ops:watch')->expectsOutputToContain('gönderildi: 0')->assertSuccessful();

        Http::assertSentCount(1);
        Http::assertSent(fn (HttpRequest $r): bool => $r['topic'] === 'test-ops'
            && str_contains((string) $r['title'], 'Ölü senkron'));

        $this->travel(61)->minutes();
        $this->dead(5);
        $this->artisan('ops:watch')->expectsOutputToContain('gönderildi: 1');
    }

    #[Test]
    public function a_broken_probe_does_not_silence_the_others(): void
    {
        $calc = Mockery::mock(WaitTimeCalculator::class);
        $calc->shouldReceive('calculate')->andThrow(new \RuntimeException('redis yok'));
        $this->app->instance(WaitTimeCalculator::class, $calc);

        $this->dead(3);

        $this->assertSame(['dead:system'], array_column(app(OpsWatch::class)->findings(), 'key'));
    }

    #[Test]
    public function the_watch_is_scheduled_every_five_minutes(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($e) => str_contains((string) $e->command, 'ops:watch'));

        $this->assertNotNull($event, '`ops:watch` zamanlanmamış — tur hiç koşmaz.');
        $this->assertSame('*/5 * * * *', $event->expression);
    }

    private function quietQueues(): void
    {
        $calc = Mockery::mock(WaitTimeCalculator::class);
        $calc->shouldReceive('calculate')->andReturn([]);
        $this->app->instance(WaitTimeCalculator::class, $calc);
    }

    private function rateLimited(int $count, int $ageMinutes = 1): void
    {
        for ($i = 0; $i < $count; $i++) {
            DB::table('api_calls')->insert([
                'tenant_id' => $this->tenant->id,
                'channel_connection_id' => $this->connection->id,
                'method' => 'POST',
                'endpoint' => '/x',
                'status_code' => 429,
                'duration_ms' => 10,
                'called_at' => now()->subMinutes($ageMinutes),
                'expires_at' => now()->addDays(7),
            ]);
        }
    }

    private function dead(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            DB::table('sync_operations')->insert([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $this->tenant->id,
                'channel_connection_id' => $this->connection->id,
                'operation_type' => 'INVENTORY_PUSH',
                'intent' => 'NORMAL_SYNC',
                'entity_type' => 'listing',
                'entity_id' => (string) Str::uuid7(),
                'entity_version' => 1,
                'idempotency_key' => 'ops:'.uniqid('', true),
                'priority' => 0,
                'status' => 'dead',
                'attempt_count' => 5,
                'completed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
