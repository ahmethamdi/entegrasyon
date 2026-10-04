<?php

declare(strict_types=1);

namespace Tests\Feature\Channels;

use App\Domain\Channels\Actions\CheckChannelHealth;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Models\ChannelType;
use App\Domain\Channels\Support\CircuitBreaker;
use App\Domain\Channels\Support\CredentialVault;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use App\Domain\Sync\Enums\ErrorClass;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Support\Channels\FakeAdapter;
use Tests\TestCase;

/**
 * AUTHENTICATION'IN SÜRESİZ AÇTIĞI DEVRE KAPANABİLİR Mİ?
 *
 * Mimari Karar Dokümanı v2.2 · §12 · devre kesici.
 *
 * Kural "kullanıcı kimlik bilgisini yenileyince reset() çağrılır" diyordu
 * ama uygulamada `reset()`'i çağıran TEK BİR YER YOKTU. Tek bir 401 —
 * Etsy token'ı yenileme turundan önce dolması, kasa okumasının bir kez
 * yutulması — bağlantıyı sonsuza kadar durdururdu: token yenilense de,
 * satıcı OAuth'u baştan yapsa da push işleri her beş dakikada ertelenirdi.
 *
 * İki kapı devreyi kapatır: kasaya YENİ kimlik yazılması ve sağlık
 * kontrolünün GEÇMESİ.
 */
final class CircuitBreakerResetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Redis::connection()->flushdb();
    }

    protected function tearDown(): void
    {
        Redis::connection()->flushdb();

        parent::tearDown();
    }

    #[Test]
    public function storing_new_credentials_closes_an_authentication_circuit(): void
    {
        [$tenant, $connection] = $this->makeConnection();
        $breaker = app(CircuitBreaker::class);

        $breaker->recordFailure($connection->id, ErrorClass::AUTHENTICATION);
        $this->assertFalse($breaker->allows($connection->id), 'Ön koşul: 401 devreyi açmalı.');

        $this->asTenant($tenant, fn () => app(CredentialVault::class)->store($connection, ['access_token' => 'yeni']));

        $this->assertTrue($breaker->allows($connection->id), 'Yeni kimlik yazılınca devre kapanmalı.');
    }

    /** Yazım geri alınırsa devre eski kimlikle AÇIK kalır. */
    #[Test]
    public function a_rolled_back_credential_write_keeps_the_circuit_open(): void
    {
        [$tenant, $connection] = $this->makeConnection();
        $breaker = app(CircuitBreaker::class);

        $breaker->recordFailure($connection->id, ErrorClass::AUTHENTICATION);

        try {
            $this->asTenant($tenant, fn () => DB::transaction(function () use ($connection): void {
                app(CredentialVault::class)->store($connection, ['access_token' => 'yeni']);

                throw new RuntimeException('yazımdan sonra patladı');
            }));
        } catch (RuntimeException) {
        }

        $this->assertFalse($breaker->allows($connection->id), 'Geri alınan yazım devreyi kapatmamalı.');
    }

    /**
     * Anahtar rotasyonu kimlik DEĞİŞİKLİĞİ DEĞİLDİR.
     *
     * `read()` eski anahtarla şifrelenmiş kaydı fırsatçı olarak yeniden
     * yazar; AYNI token'ı yeni anahtarla şifrelemek açık devreyi
     * kapatsaydı geçersiz token'la istekler yeniden başlardı.
     */
    #[Test]
    public function key_rotation_on_read_does_not_close_the_circuit(): void
    {
        [$tenant, $connection] = $this->makeConnection();
        $breaker = app(CircuitBreaker::class);

        $this->asTenant($tenant, fn () => app(CredentialVault::class)->store($connection, ['access_token' => 'eski']));

        $breaker->recordFailure($connection->id, ErrorClass::AUTHENTICATION);

        config(['entegrasyon.credentials.key_version' => 2]);

        $this->asTenant($tenant, fn () => app(CredentialVault::class)->read($connection));

        $this->assertSame(
            2,
            (int) $this->asSystem(fn () => DB::table('channel_credentials')->where('channel_connection_id', $connection->id)->value('key_version')),
            'Ön koşul: rotasyon gerçekten yazmalı — yoksa test hiçbir şey sınamıyor.',
        );
        $this->assertFalse($breaker->allows($connection->id));
    }

    #[Test]
    public function a_passing_health_check_closes_an_authentication_circuit(): void
    {
        [$tenant, $connection] = $this->makeConnection();
        $breaker = app(CircuitBreaker::class);

        $breaker->recordFailure($connection->id, ErrorClass::AUTHENTICATION);

        $this->asTenant($tenant, fn () => app(CheckChannelHealth::class)->run($connection));

        $this->assertTrue($breaker->allows($connection->id), 'Sağlık kontrolü geçtiyse kimlik çalışıyordur.');
    }

    /** @return array{0: Tenant, 1: ChannelConnection} */
    private function makeConnection(): array
    {
        $tenant = (new CreateTenant)->run(
            name: 'Devre '.uniqid(),
            owner: User::factory()->create(),
        );

        $this->asSystem(fn () => ChannelType::query()->updateOrCreate(
            ['code' => 'fake'],
            [
                'name' => 'Sahte',
                'kind' => 'marketplace',
                'adapter_class' => FakeAdapter::class,
                'is_active' => true,
            ],
        ));

        $connection = $this->asTenant($tenant, fn () => ChannelConnection::factory()
            ->create(['channel_type_code' => 'fake']));

        return [$tenant, $connection];
    }
}
