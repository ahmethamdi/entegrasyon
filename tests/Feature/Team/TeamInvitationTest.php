<?php

declare(strict_types=1);

namespace Tests\Feature\Team;

use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Enums\AuditAction;
use App\Domain\Identity\Models\AuditLog;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\TenantInvitation;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Notifications\TeamInvitationNotification;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * S7 · ekip daveti uçtan uca: davet → posta → bağlantı → kayıt/giriş →
 * üyelik. Posta sahte; bağlantı GERÇEK bildirimden okunur (elle
 * kurulsaydı imza ve rota adı hatası görünmezdi).
 */
final class TeamInvitationTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $this->owner = User::factory()->create();
        $this->tenant = (new CreateTenant)->run('Kavlak', $this->owner);
    }

    #[Test]
    public function an_invitee_without_an_account_registers_straight_into_the_team(): void
    {
        $url = $this->invite('depo@ornek.com', 'warehouse');

        $this->post('/logout');

        $this->get($url)->assertOk()->assertInertia(fn ($page) => $page
            ->component('Auth/Invitation')
            ->where('state', 'guest')
            ->where('hasAccount', false));

        $this->get('/register')->assertInertia(fn ($page) => $page
            ->where('invitation.email', 'depo@ornek.com'));

        // Göreli sayım: başka test sınıfları kendi transaction'ı dışında
        // kiracı bırakabiliyor (tam pakette ölçüldü).
        $tenantsBefore = Tenant::query()->count();

        $this->post('/register', [
            'name' => 'Depo Ali',
            'email' => 'depo@ornek.com',
            'password' => 'Gizli-Parola-123',
            'password_confirmation' => 'Gizli-Parola-123',
        ])->assertRedirect();

        $user = User::query()->where('email', 'depo@ornek.com')->firstOrFail();

        $this->assertSame($tenantsBefore, Tenant::query()->count(), 'Davetle kayıtta YENİ firma açılmamalı.');
        $this->assertSame('warehouse', $this->roleOf($user));
        $this->assertTrue($user->fresh()->hasVerifiedEmail(), 'Davet adresi doğrulanmış sayılır.');

        $this->get('/panel')->assertOk()->assertInertia(fn ($page) => $page
            ->where('tenant.id', $this->tenant->id)
            ->where('tenantRole.value', 'warehouse'));
    }

    #[Test]
    public function an_existing_user_accepts_and_lands_in_the_inviting_tenant(): void
    {
        $member = User::factory()->create(['email' => 'muhasebe@ornek.com']);
        $own = (new CreateTenant)->run('Kendi firması', $member);

        $url = $this->invite('Muhasebe@Ornek.com', 'viewer');

        $this->actingAs($member)->get($url)->assertRedirect(route('dashboard'));

        $this->assertSame('viewer', $this->roleOf($member));
        $this->actingAs($member)->get('/panel')->assertInertia(fn ($page) => $page
            ->where('tenant.id', $this->tenant->id)
            ->has('memberships', 2));

        // Firma değiştirme yalnız üye olunan firmaya.
        $this->actingAs($member)->post('/tenants/switch', ['tenant_id' => $own->id])->assertRedirect(route('dashboard'));
        $this->actingAs($member)->get('/panel')->assertInertia(fn ($page) => $page->where('tenant.id', $own->id));

        $stranger = (new CreateTenant)->run('Yabancı', User::factory()->create());
        $this->actingAs($member)->post('/tenants/switch', ['tenant_id' => $stranger->id])->assertNotFound();
    }

    #[Test]
    public function the_link_works_only_for_the_invited_address(): void
    {
        $url = $this->invite('kisi@ornek.com', 'admin');
        $other = User::factory()->create(['email' => 'baskasi@ornek.com']);
        (new CreateTenant)->run('Başka', $other);

        $this->actingAs($other)->get($url)->assertOk()->assertInertia(fn ($page) => $page->where('state', 'mismatch'));

        $this->assertNull($this->roleOf($other), 'Başka hesap davetle ekibe GİREMEZ.');
    }

    #[Test]
    public function a_tampered_or_expired_link_is_refused(): void
    {
        $url = $this->invite('kisi@ornek.com', 'admin');

        $this->post('/logout');
        $this->get(str_replace('/invitations/', '/invitations/x', $url))->assertForbidden();

        $this->asSystem(fn () => TenantInvitation::query()->update(['expires_at' => now()->subMinute()]));
        $this->get($url)->assertOk()->assertInertia(fn ($page) => $page->where('state', 'invalid'));
    }

    #[Test]
    public function the_token_is_stored_only_as_a_hash(): void
    {
        $url = $this->invite('kisi@ornek.com', 'admin');
        $token = basename((string) parse_url($url, PHP_URL_PATH));

        $row = $this->asSystem(fn () => TenantInvitation::query()->firstOrFail());

        $this->assertNotSame($token, $row->token_hash);
        $this->assertSame(hash('sha256', $token), $row->token_hash);
    }

    #[Test]
    public function reinviting_kills_the_old_link(): void
    {
        $first = $this->invite('kisi@ornek.com', 'admin');
        $second = $this->invite('kisi@ornek.com', 'viewer');

        $this->assertSame(1, $this->asSystem(fn () => TenantInvitation::query()->count()));

        $this->post('/logout');
        $this->get($first)->assertInertia(fn ($page) => $page->where('state', 'invalid'));
        $this->get($second)->assertInertia(fn ($page) => $page->where('state', 'guest'));
    }

    #[Test]
    public function a_member_cannot_be_invited_twice_and_owner_role_is_not_invitable(): void
    {
        $this->actingAs($this->owner)
            ->post('/settings/team/invitations', ['email' => $this->owner->email, 'role' => 'admin'])
            ->assertSessionHasErrors('email');

        $this->actingAs($this->owner)
            ->post('/settings/team/invitations', ['email' => 'yeni@ornek.com', 'role' => 'owner'])
            ->assertSessionHasErrors('role');

        Notification::assertNothingSent();
    }

    #[Test]
    public function the_plan_limits_the_number_of_users_including_pending_invitations(): void
    {
        (new PlanSeeder)->run();   // free = 1 kullanıcı (sahip)

        $this->actingAs($this->owner)
            ->post('/settings/team/invitations', ['email' => 'yeni@ornek.com', 'role' => 'admin'])
            ->assertSessionHasErrors('email');

        Notification::assertNothingSent();
        $this->assertSame(0, $this->asSystem(fn () => TenantInvitation::query()->count()));
    }

    #[Test]
    public function a_pending_invitation_holds_a_seat(): void
    {
        (new PlanSeeder)->run();
        // Ücretsiz planı 2 kullanıcıya aç: sahip + 1 koltuk.
        DB::table('plans')->where('code', 'free')->update([
            'limits' => json_encode(['products' => 25, 'channels' => 1, 'invoices_monthly' => 20, 'users' => 2]),
        ]);

        $this->invite('bir@ornek.com', 'viewer');

        $this->actingAs($this->owner)
            ->post('/settings/team/invitations', ['email' => 'iki@ornek.com', 'role' => 'viewer'])
            ->assertSessionHasErrors('email');

        // Aynı kişiye yeniden davet koltuk İSTEMEZ (zaten tutuyor).
        $this->invite('bir@ornek.com', 'admin');
    }

    #[Test]
    public function invitations_are_audited(): void
    {
        $this->invite('kisi@ornek.com', 'warehouse');

        $this->actingAs($this->owner)->delete('/settings/team/invitations/'.$this->asSystem(fn () => TenantInvitation::query()->value('id')));

        $actions = $this->asSystem(fn () => AuditLog::query()->orderBy('occurred_at')->pluck('action')->all());

        $this->assertContains(AuditAction::MEMBER_INVITED->value, $actions);
        $this->assertContains(AuditAction::INVITATION_CANCELLED->value, $actions);
        $this->assertSame(0, $this->asSystem(fn () => TenantInvitation::query()->count()));
    }

    private function invite(string $email, string $role): string
    {
        $this->actingAs($this->owner)
            ->post('/settings/team/invitations', ['email' => $email, 'role' => $role])
            ->assertSessionHasNoErrors();

        $url = null;

        // Yeniden davette SON gönderilen bağlantı kazanır (sıra korunur).
        Notification::assertSentOnDemand(
            TeamInvitationNotification::class,
            function (TeamInvitationNotification $n, array $channels, AnonymousNotifiable $notifiable) use ($email, &$url): bool {
                if (strcasecmp((string) $notifiable->routes['mail'], $email) !== 0) {
                    return false;
                }

                $url = $n->acceptUrl();

                return true;
            },
        );

        return (string) $url;
    }

    private function roleOf(User $user): ?string
    {
        $role = DB::table('tenant_users')
            ->where('tenant_id', $this->tenant->id)
            ->where('user_id', $user->id)
            ->value('role');

        return $role === null ? null : (string) $role;
    }
}
