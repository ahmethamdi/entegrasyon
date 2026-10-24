<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Domain\Channels\Exceptions\BlockedDestinationException;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Support\ChannelHttpClient;
use App\Domain\Channels\Support\CredentialVault;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Enums\TenantRole;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Support\DemoAccount;
use App\Support\Logging\PayloadRedactor;
use App\Support\Privacy\SealedJson;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\ChannelTypeSeeder;
use Database\Seeders\PlanSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * S12 · `/demo` — herkese açık, salt okunur, dışarı istek atmayan demo.
 *
 * Her kural AYRI sınanır: biri kaçarsa (ör. demo kullanıcısının rolü bir
 * gün `admin` olursa) diğeri yine tutmalı.
 */
final class DemoAccountTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ChannelTypeSeeder::class);
        (new PlanSeeder)->run();
        DemoAccount::forget();
    }

    #[Test]
    public function install_builds_a_read_only_tenant_with_sample_data_and_no_personal_data(): void
    {
        $this->artisan('demo:install')->assertSuccessful();

        $tenant = Tenant::query()->where('is_demo', true)->sole();
        $roles = DB::table('tenant_users')->where('tenant_id', $tenant->id)->pluck('role')->all();

        // Sahip yok: günlük uyarı postaları kimseye gitmez.
        $this->assertSame([TenantRole::VIEWER->value], $roles);

        $connections = DB::table('channel_connections')->where('tenant_id', $tenant->id)->get();
        $this->assertCount(3, $connections);
        foreach ($connections as $c) {
            $this->assertSame('inactive', $c->status);
            $this->assertFalse((bool) $c->stock_sync_enabled);
            $this->assertFalse((bool) $c->price_sync_enabled);
            $this->assertFalse((bool) $c->product_publish_enabled);
        }

        $this->assertSame(9, DB::table('orders')->where('tenant_id', $tenant->id)->count());
        // Alıcı bilgisi şifreli saklanır; açınca boş olmalı.
        foreach (DB::table('orders')->where('tenant_id', $tenant->id)->pluck('customer_ref') as $sealed) {
            $this->assertSame([], SealedJson::open($sealed) ?? []);
        }

        // Kanıt ekranı uydurma sayı göstermesin: hiç senkron işi yok.
        $this->assertSame(0, DB::table('sync_operations')->where('tenant_id', $tenant->id)->count());
    }

    #[Test]
    public function fresh_install_replaces_the_tenant_and_keeps_a_single_demo(): void
    {
        $this->artisan('demo:install')->assertSuccessful();
        $first = Tenant::query()->where('is_demo', true)->value('id');

        $this->artisan('demo:install')->expectsOutputToContain('Zaten var');
        $this->artisan('demo:install --fresh')->assertSuccessful();

        $this->assertSame(1, Tenant::query()->where('is_demo', true)->count());
        $this->assertNotSame($first, Tenant::query()->where('is_demo', true)->value('id'));
        $this->assertSame(1, User::query()->where('email', DemoAccount::EMAIL)->count());
    }

    #[Test]
    public function demo_link_logs_in_and_opens_the_panel_with_the_demo_banner(): void
    {
        $this->artisan('demo:install')->assertSuccessful();

        $this->get('/demo')->assertRedirect('/panel');
        $this->assertAuthenticatedAs(DemoAccount::user());

        $this->get('/panel')->assertOk()->assertInertia(fn ($page) => $page->where('demo', true));
    }

    /** Oturumu açık gerçek kullanıcı demoya atılmaz. */
    #[Test]
    public function a_signed_in_seller_is_not_switched_to_the_demo(): void
    {
        $this->artisan('demo:install')->assertSuccessful();
        $seller = User::factory()->create();
        (new CreateTenant)->run(name: 'Gerçek', owner: $seller);

        $this->actingAs($seller)->get('/demo')->assertRedirect('/panel');
        $this->assertAuthenticatedAs($seller);
    }

    #[Test]
    public function demo_link_is_404_when_not_installed_or_disabled(): void
    {
        $this->get('/demo')->assertNotFound();

        $this->artisan('demo:install')->assertSuccessful();
        config(['entegrasyon.demo.enabled' => false]);

        $this->get('/demo')->assertNotFound();
        $this->assertGuest();
    }

    /**
     * ⚠️ ROLDEN BAĞIMSIZ: demo üyesinin rolü bir gün sahip olsa bile demo
     * kiracısında yazma geçmez.
     */
    #[Test]
    public function nothing_can_be_written_in_the_demo_tenant_whatever_the_role(): void
    {
        $this->artisan('demo:install')->assertSuccessful();
        $tenant = Tenant::query()->where('is_demo', true)->sole();
        DB::table('tenant_users')->where('tenant_id', $tenant->id)->update(['role' => TenantRole::OWNER->value]);

        $this->actingAs(DemoAccount::user())
            ->post('/settings/team/invitations', ['email' => 'birisi@example.com', 'role' => TenantRole::VIEWER->value])
            ->assertForbidden();

        $this->assertSame(0, DB::table('tenant_invitations')->where('tenant_id', $tenant->id)->count());
    }

    /** Aynı istek gerçek bir kiracıda geçer — kapı yalnız demoyu tutar. */
    #[Test]
    public function the_same_write_passes_in_a_normal_tenant(): void
    {
        DB::table('plans')->delete();   // kullanıcı sınırı bu testin konusu değil
        $owner = User::factory()->create();
        $tenant = (new CreateTenant)->run(name: 'Gerçek', owner: $owner);
        Notification::fake();

        $this->actingAs($owner)
            ->post('/settings/team/invitations', ['email' => 'birisi@example.com', 'role' => TenantRole::VIEWER->value])
            ->assertRedirect();

        $this->assertSame(1, DB::table('tenant_invitations')->where('tenant_id', $tenant->id)->count());
    }

    #[Test]
    public function a_demo_connection_never_reaches_the_network(): void
    {
        $this->artisan('demo:install')->assertSuccessful();
        Http::fake();

        $tenant = Tenant::query()->where('is_demo', true)->sole();
        $connection = TenantContext::runFor($tenant->id, fn () => ChannelConnection::query()->firstOrFail());

        $client = new ChannelHttpClient(
            connection: $connection,
            vault: app(CredentialVault::class),
            redactor: app(PayloadRedactor::class),
        );

        try {
            $client->get('https://api.example.com/x');
            $this->fail('Demo bağlantısı dışarı istek attı.');
        } catch (BlockedDestinationException) {
            // beklenen
        }

        Http::assertNothingSent();
    }

    #[Test]
    public function the_demo_address_gets_no_mail(): void
    {
        $this->artisan('demo:install')->assertSuccessful();
        Notification::fake();

        $demo = DemoAccount::user();
        $demo->sendPasswordResetNotification('x');
        $demo->sendEmailVerificationNotification();

        Notification::assertNothingSent();
    }

    #[Test]
    public function the_demo_is_rebuilt_every_night(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($e) => str_contains((string) $e->command, 'demo:install --fresh'));

        $this->assertNotNull($event, '`demo:install --fresh` zamanlanmamış — demo verisi eskir.');
        $this->assertSame('30 4 * * *', $event->expression);
    }
}
