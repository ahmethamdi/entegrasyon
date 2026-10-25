<?php

declare(strict_types=1);

namespace Tests\Feature\Mail;

use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Actions\SendWelcomeMail;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Notifications\WelcomeNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Hoş geldin postası: doğrulama anında, yalnız hesap sahibine, EN FAZLA bir kez.
 */
final class WelcomeMailTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function verifying_the_email_sends_the_welcome_mail_once(): void
    {
        Notification::fake();
        $owner = User::factory()->unverified()->create();
        (new CreateTenant)->run('Atölye Nur', $owner);

        $url = URL::temporarySignedRoute('verification.verify', now()->addHour(), [
            'id' => $owner->getKey(),
            'hash' => sha1($owner->getEmailForVerification()),
        ]);
        $this->actingAs($owner)->get($url)->assertRedirect('/panel');
        // İkinci tık (eski sekme): zaten doğrulanmış, ikinci posta yok.
        $this->actingAs($owner)->get($url);
        app(SendWelcomeMail::class)($owner->refresh());

        Notification::assertSentToTimes($owner, WelcomeNotification::class, 1);
        $this->assertNotNull($owner->refresh()->getAttribute('welcome_sent_at'));
    }

    #[Test]
    public function an_unverified_owner_gets_no_welcome_mail(): void
    {
        Notification::fake();
        $owner = User::factory()->unverified()->create();
        (new CreateTenant)->run('Atölye Nur', $owner);

        app(SendWelcomeMail::class)($owner);

        Notification::assertNothingSent();
    }

    #[Test]
    public function an_invited_team_member_gets_no_welcome_mail(): void
    {
        Notification::fake();
        $owner = User::factory()->create();
        $tenant = (new CreateTenant)->run('Atölye Nur', $owner);
        $member = User::factory()->create();
        $tenant->users()->attach($member->getKey(), ['id' => (string) Str::uuid(), 'role' => 'warehouse', 'accepted_at' => now()]);

        app(SendWelcomeMail::class)($member);

        Notification::assertNotSentTo($member, WelcomeNotification::class);
    }

    #[Test]
    public function an_already_welcomed_owner_is_not_mailed_again(): void
    {
        Notification::fake();
        $owner = User::factory()->create();
        (new CreateTenant)->run('Atölye Nur', $owner);
        DB::table('users')->where('id', $owner->getKey())->update(['welcome_sent_at' => now()->subDay()]);

        app(SendWelcomeMail::class)($owner);

        Notification::assertNothingSent();
    }

    #[Test]
    public function a_failed_send_releases_the_claim_so_it_can_be_retried(): void
    {
        $owner = User::factory()->create();
        (new CreateTenant)->run('Atölye Nur', $owner);
        Notification::shouldReceive('send')->andThrow(new RuntimeException('smtp kapalı'));

        app(SendWelcomeMail::class)($owner);

        $this->assertNull($owner->refresh()->getAttribute('welcome_sent_at'), 'gönderilemeyen posta "gönderildi" sayılmamalı');
    }
}
