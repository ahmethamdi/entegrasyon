<?php

declare(strict_types=1);

namespace Tests\Feature\Channels;

use App\Domain\Channels\Actions\CheckChannelHealth;
use App\Domain\Channels\Adapters\Ebay\EbayAdapter;
use App\Domain\Channels\Adapters\Trendyol\TrendyolAdapter;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Models\ChannelType;
use App\Domain\Channels\Support\BetaChannelNotifier;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Beta kanal bildirimleri (kullanıcı kararı 21 Eki 2026).
 *
 * Beta kanala bağlantı kurulunca ve o bağlantıda ciddi hata olunca 34Devs
 * ntfy + posta ile haberdar olur. Hata bildirimi bağlantı başına saatte bir.
 */
final class BetaChannelNotifierTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $mailSubjects = [];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'entegrasyon.beta.ntfy_topic' => 'test-34pazar-beta',
            'entegrasyon.beta.ntfy_base_url' => 'https://ntfy.example',
            'entegrasyon.beta.notify_email' => 'info@34devs.com',
            'mail.default' => 'array',
        ]);

        Http::fake(['https://ntfy.example/*' => Http::response(['id' => 'x'], 200)]);

        Event::listen(MessageSending::class, function (MessageSending $event): void {
            $this->mailSubjects[] = (string) $event->message->getSubject();
        });
    }

    /** Beta kanalda bağlantı İLK KEZ kurulunca (connected_at dolunca) bildirim gider. */
    #[Test]
    public function a_new_beta_connection_is_announced(): void
    {
        [$tenant, $connection] = $this->connection('ebay', beta: true);

        $this->asTenant($tenant, fn () => $connection->forceFill(['connected_at' => now()])->save());

        Http::assertSent(fn (HttpRequest $r): bool => $r->url() === 'https://ntfy.example/'
            && $r['topic'] === 'test-34pazar-beta'
            && str_contains((string) $r['title'], 'Beta kanal bağlandı: eBay'));
        $this->assertCount(1, $this->mailSubjects);
        $this->assertStringContainsString('[34Pazar] Beta kanal bağlandı', $this->mailSubjects[0]);
    }

    /** Doğrulanmış (beta olmayan) kanal bildirim üretmez. */
    #[Test]
    public function a_verified_channel_is_not_announced(): void
    {
        [$tenant, $connection] = $this->connection('trendyol', beta: false);

        $this->asTenant($tenant, fn () => $connection->forceFill(['connected_at' => now()])->save());
        app(BetaChannelNotifier::class)->failed($connection, 'AUTHENTICATION', 'x');

        Http::assertNothingSent();
        $this->assertSame([], $this->mailSubjects);
    }

    /** Zaten kurulu bağlantının güncellenmesi yeniden bildirim üretmez. */
    #[Test]
    public function an_existing_connection_is_not_announced_again(): void
    {
        [$tenant, $connection] = $this->connection('ebay', beta: true, connected: true);

        $this->asTenant($tenant, fn () => $connection->forceFill(['connected_at' => now()->addMinute(), 'label' => 'Yeni ad'])->save());

        Http::assertNothingSent();
    }

    /** Hata bildirimi bağlantı başına saatte en çok bir kez. */
    #[Test]
    public function failures_are_throttled_per_connection_per_hour(): void
    {
        [, $connection] = $this->connection('ebay', beta: true, connected: true);
        [, $other] = $this->connection('ebay', beta: true, connected: true);

        $notifier = app(BetaChannelNotifier::class);
        $notifier->failed($connection, 'AUTHENTICATION', 'token geçersiz');
        $notifier->failed($connection, 'VALIDATION', 'ikinci hata');
        $notifier->failed($other, 'VALIDATION', 'başka bağlantı');

        Http::assertSentCount(2);
        $this->assertCount(2, $this->mailSubjects);

        $this->travel(61)->minutes();
        $notifier->failed($connection, 'VALIDATION', 'bir saat sonra');

        Http::assertSentCount(3);
    }

    /** ntfy düşerse asıl iş düşmez ve posta yine gider. */
    #[Test]
    public function a_broken_ntfy_does_not_break_the_caller(): void
    {
        Http::fake(['https://ntfy.example/*' => fn () => throw new ConnectionException('kapalı')]);

        [, $connection] = $this->connection('ebay', beta: true, connected: true);

        app(BetaChannelNotifier::class)->failed($connection, 'AUTHENTICATION', 'x');

        $this->assertCount(1, $this->mailSubjects);
    }

    /** Sağlık kontrolü düşen beta bağlantı bildirilir (uçtan uca kanca). */
    #[Test]
    public function a_failed_health_check_on_a_beta_channel_is_reported(): void
    {
        Http::fake([
            'https://ntfy.example/*' => Http::response(['id' => 'x'], 200),
            '*' => Http::response(['errors' => [['errorId' => 1001]]], 401),
        ]);

        [$tenant, $connection] = $this->connection('ebay', beta: true, connected: true);

        $this->asTenant($tenant, fn () => app(CheckChannelHealth::class)->run($connection));

        Http::assertSent(fn (HttpRequest $r): bool => $r->url() === 'https://ntfy.example/'
            && str_contains((string) $r['title'], 'Beta kanal hatası: eBay (sağlık kontrolü)'));
    }

    /** @return array{0: Tenant, 1: ChannelConnection} */
    private function connection(string $code, bool $beta, bool $connected = false): array
    {
        $this->asSystem(fn (): ChannelType => ChannelType::query()->updateOrCreate(
            ['code' => $code],
            [
                'name' => $code === 'ebay' ? 'eBay' : 'Trendyol',
                'kind' => 'marketplace',
                'adapter_class' => $code === 'ebay' ? EbayAdapter::class : TrendyolAdapter::class,
                'supports_webhooks' => false,
                'is_active' => true,
                'is_beta' => $beta,
            ],
        ));

        $tenant = (new CreateTenant)->run(name: 'Beta '.uniqid(), owner: User::factory()->create());

        $connection = $this->asTenant($tenant, fn (): ChannelConnection => ChannelConnection::factory()->create([
            'channel_type_code' => $code,
            'external_account_id' => $code.'-'.uniqid(),
            'status' => 'active',
            'connected_at' => $connected ? now()->subDay() : null,
            'settings' => $code === 'ebay' ? ['marketplace_id' => 'EBAY_DE'] : [],
        ]));

        return [$tenant, $connection];
    }
}
