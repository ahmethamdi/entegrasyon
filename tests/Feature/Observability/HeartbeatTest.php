<?php

declare(strict_types=1);

namespace Tests\Feature\Observability;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** S13 · zamanlayıcı nabzı (healthchecks.io). */
final class HeartbeatTest extends TestCase
{
    #[Test]
    public function heartbeat_pings_the_configured_url(): void
    {
        config(['entegrasyon.heartbeat.url' => 'https://hc-ping.example/uuid-1']);
        Http::fake(['https://hc-ping.example/*' => Http::response('OK')]);

        $this->artisan('ops:heartbeat')->expectsOutput('ping: ok')->assertExitCode(0);

        Http::assertSent(fn (Request $r) => $r->url() === 'https://hc-ping.example/uuid-1' && $r->method() === 'GET');
    }

    #[Test]
    public function unreachable_healthchecks_does_not_crash_the_scheduler(): void
    {
        config(['entegrasyon.heartbeat.url' => 'https://hc-ping.example/uuid-1']);
        Http::fake(['https://hc-ping.example/*' => Http::response('down', 503)]);

        $this->artisan('ops:heartbeat')->expectsOutput('ping: ulaşılamadı')->assertExitCode(0);
    }

    #[Test]
    public function missing_url_is_a_no_op_with_one_warning_a_day(): void
    {
        config(['entegrasyon.heartbeat.url' => '']);
        Http::fake();
        Log::spy();

        $this->artisan('ops:heartbeat')->assertExitCode(0);
        $this->artisan('ops:heartbeat')->assertExitCode(0);

        Http::assertNothingSent();
        Log::shouldHaveReceived('warning')->once()->with('ops.heartbeat.not_configured', ['env' => 'HEALTHCHECKS_HEARTBEAT_URL']);
    }
}
