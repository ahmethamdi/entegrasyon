<?php

declare(strict_types=1);

namespace Tests\Feature\Channels;

use App\Domain\Channels\Contracts\HealthResult;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Models\ChannelType;
use App\Domain\Channels\Support\ChannelHealthSweep;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Channels\ProgrammableHealthAdapter;
use Tests\TestCase;

/**
 * Kanal sağlık taraması — 8 Eki 2026 canlı olayı.
 *
 * Etsy uygulama anahtarı kanal tarafında geçersiz oldu; 31 saat her çağrı
 * 403 aldı ama bağlantı panelde "sağlıklı" kaldı, çünkü sağlık yalnız
 * bağlanırken ölçülüyordu. Bu dosya taramanın üç sözünü sınar: kopan
 * bağlantı kırmızıya döner, anlık takılma bağlantıyı kapatmaz, düzelen
 * bağlantı kendiliğinden yeşile döner.
 */
final class ChannelHealthSweepTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        ProgrammableHealthAdapter::reset();
    }

    protected function tearDown(): void
    {
        ProgrammableHealthAdapter::reset();

        parent::tearDown();
    }

    /**
     * ⚠️ KOPAN BAĞLANTI KIRMIZIYA DÖNER ve akış durur.
     *
     * Canlı olayın kendisi: iki ölçüm de geçmezse bağlantı `unhealthy` +
     * `pending` olur ve neden panelde görünür.
     */
    #[Test]
    public function a_connection_that_keeps_failing_turns_unhealthy(): void
    {
        [$tenant, $connection] = $this->connection();
        ProgrammableHealthAdapter::results(HealthResult::unhealthy('HTTP 403: API key not found or not active'));

        $result = $this->sweep();

        $fresh = $this->fresh($tenant, $connection);
        $this->assertSame('unhealthy', $fresh->health_status, 'Kopan bağlantı panelde yeşil kaldı — canlı olayın aynısı.');
        $this->assertSame('pending', $fresh->status);
        $this->assertStringContainsString('API key not found', (string) $fresh->last_error);
        $this->assertSame(1, $result['unhealthy']);
        $this->assertSame(2, ProgrammableHealthAdapter::calls(), 'Hata ikinci ölçümle doğrulanmadı.');
    }

    /**
     * ⚠️ ANLIK TAKILMA BAĞLANTIYI KAPATMAZ.
     *
     * İlk ölçüm düşüp ikincisi geçerse bağlantı `active` kalır: tek bir ağ
     * hıçkırığı stok/fiyat akışını bir saat durdurmamalı.
     */
    #[Test]
    public function a_single_blip_does_not_take_the_connection_down(): void
    {
        [$tenant, $connection] = $this->connection();
        ProgrammableHealthAdapter::results(
            HealthResult::unhealthy('timeout'),
            HealthResult::healthy(latencyMs: 5),
        );

        $result = $this->sweep();

        $fresh = $this->fresh($tenant, $connection);
        $this->assertSame('healthy', $fresh->health_status);
        $this->assertSame('active', $fresh->status);
        $this->assertSame(1, $result['blips']);
    }

    /** Düzelen bağlantı kendiliğinden yeşile döner, eski hata silinir. */
    #[Test]
    public function a_recovered_connection_turns_healthy_again(): void
    {
        [$tenant, $connection] = $this->connection(health: 'unhealthy', status: 'pending', lastError: 'HTTP 403');
        ProgrammableHealthAdapter::results(HealthResult::healthy(latencyMs: 5));

        $result = $this->sweep();

        $fresh = $this->fresh($tenant, $connection);
        $this->assertSame('healthy', $fresh->health_status);
        $this->assertSame('active', $fresh->status);
        $this->assertNull($fresh->last_error);
        $this->assertSame(1, $result['recovered']);
    }

    /** Sağlıklı bağlantıda tek ölçüm yeter; yalnız son sağlıklı an ilerler. */
    #[Test]
    public function a_healthy_connection_is_measured_once(): void
    {
        [$tenant, $connection] = $this->connection();
        ProgrammableHealthAdapter::results(HealthResult::healthy(latencyMs: 5));

        $this->sweep();

        $this->assertSame(1, ProgrammableHealthAdapter::calls());
        $this->assertTrue($this->fresh($tenant, $connection)->last_healthy_at->isToday());
    }

    /**
     * Hiç bağlanmamış ya da iptal edilmiş bağlantıya dokunulmaz.
     *
     * Tarama yarım kalmış bir kurulumu kendiliğinden `active` yapmamalı;
     * iptal edilen (`inactive`) bağlantıyı hiç ölçmemeli.
     */
    #[Test]
    public function unconnected_and_revoked_connections_are_skipped(): void
    {
        [$tenant, $never] = $this->connection(status: 'pending', connectedAt: false);
        [, $revoked] = $this->connection(status: 'inactive', tenant: $tenant);
        ProgrammableHealthAdapter::results(HealthResult::healthy(latencyMs: 5));

        $this->sweep();

        $this->assertSame(0, ProgrammableHealthAdapter::calls());
        $this->assertSame('pending', $this->fresh($tenant, $never)->status);
        $this->assertSame('inactive', $this->fresh($tenant, $revoked)->status);
    }

    /** Komut saatlik zamanlanmıştır — sınıfın var olması çağrıldığı anlamına gelmez. */
    #[Test]
    public function the_sweep_is_scheduled_hourly(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($e): bool => str_contains((string) $e->command, 'channels:health'));

        $this->assertNotNull($event, 'channels:health zamanlanmamış.');
        $this->assertSame('0 * * * *', $event->expression);

        $this->artisan('channels:health', ['--confirm-delay' => 0])->assertSuccessful();
    }

    // ──────────────────────────────────────────────────────── yardımcılar

    /** @return array{healthy: int, unhealthy: int, recovered: int, blips: int} */
    private function sweep(): array
    {
        return app(ChannelHealthSweep::class)->run(confirmDelaySeconds: 0);
    }

    /** @return array{0: Tenant, 1: ChannelConnection} */
    private function connection(
        string $health = 'healthy',
        string $status = 'active',
        ?string $lastError = null,
        bool $connectedAt = true,
        ?Tenant $tenant = null,
    ): array {
        $this->asSystem(fn () => ChannelType::query()->updateOrCreate(['code' => 'saglik'], [
            'name' => 'Sağlık',
            'kind' => 'marketplace',
            'adapter_class' => ProgrammableHealthAdapter::class,
            'is_active' => true,
        ]));

        $tenant ??= (new CreateTenant)->run(name: 'Sağlık '.uniqid(), owner: User::factory()->create());

        $connection = $this->asTenant($tenant, fn () => ChannelConnection::factory()->create([
            'tenant_id' => $tenant->id,
            'channel_type_code' => 'saglik',
            'external_account_id' => 'saglik-'.uniqid(),
            'status' => $status,
            'health_status' => $health,
            'last_error' => $lastError,
            'connected_at' => $connectedAt ? now()->subDay() : null,
        ]));

        return [$tenant, $connection];
    }

    private function fresh(Tenant $tenant, ChannelConnection $connection): ChannelConnection
    {
        return $this->asTenant($tenant, fn () => ChannelConnection::query()->findOrFail($connection->id));
    }
}
