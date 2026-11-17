<?php

declare(strict_types=1);

namespace Tests\Feature\Sms;

use App\Domain\Identity\Enums\TenantRole;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use App\Domain\Sms\Models\SmsAccount;
use App\Domain\Sms\Models\SmsMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Invoicing\InvoicingFixtures;
use Tests\TestCase;

/**
 * Ayarlar › SMS bildirimleri — hesabı bağlama, bildirim seçimi, deneme SMS'i.
 *
 * Şifre ve numara ekrana/diske düz gitmez; kimliği reddedilen ya da onaylı
 * başlığı olmayan hesap kaydedilmez; salt okuyucu değiştiremez.
 */
final class SmsSettingsTest extends TestCase
{
    use FakesNetgsm;
    use InvoicingFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->fakeNetgsm();
    }

    #[Test]
    public function connecting_tests_the_account_first_and_never_shows_the_password(): void
    {
        [$tenant, $user] = $this->trendyolOrder();

        $this->actingAs($user)->post('/settings/sms', [
            'environment' => 'test', 'username' => '8500000000', 'password' => 'password001',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $account = $this->asTenant($tenant, fn () => SmsAccount::query()->sole());
        $this->assertSame(SmsAccount::STATUS_CONNECTED, $account->status);
        $this->assertSame('TESTHEADER', $account->sender);
        $this->assertSame(['TESTHEADER'], $account->senders);
        $this->assertNotNull($account->notify_since, 'Bağlanmadan önceki olaylar SMS\'e dönüşmez.');
        $this->assertStringNotContainsString('password001', (string) DB::table('sms_accounts')->value('credentials'), 'Kimlik diskte şifreli.');
        $this->assertSame([], $this->netgsmSends(), 'Bağlanırken SMS gitmez.');

        $response = $this->actingAs($user)->get('/settings/sms')->assertOk();
        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Settings/Sms')
            ->where('account.status', 'connected')
            ->where('account.username', '•••000')
            ->where('account.senders', ['TESTHEADER'])
            ->has('events', 4));
        $this->assertStringNotContainsString('password001', (string) $response->getContent());
        $this->assertStringNotContainsString('8500000000', (string) $response->getContent());
    }

    #[Test]
    public function rejected_credentials_are_not_saved(): void
    {
        [$tenant, $user] = $this->trendyolOrder();
        $this->fakeNetgsm(['/auth/v3/token' => ['token-invalid', 401]]);

        $this->actingAs($user)->post('/settings/sms', [
            'environment' => 'live', 'username' => '8500000000', 'password' => 'yanlis',
        ])->assertSessionHasErrors('sms');

        $this->assertSame(0, $this->asTenant($tenant, fn () => SmsAccount::query()->count()));
    }

    #[Test]
    public function seller_notifications_need_a_mobile_number(): void
    {
        [$tenant, $user] = $this->connected();

        $this->actingAs($user)->put('/settings/sms', [
            'sender' => 'TESTHEADER', 'notify_phone' => '', 'events' => ['new_order'], 'quiet_hours' => true,
        ])->assertSessionHasErrors('notify_phone');

        $this->actingAs($user)->put('/settings/sms', [
            'sender' => 'TESTHEADER', 'notify_phone' => '0212 111 22 33', 'events' => ['new_order'], 'quiet_hours' => true,
        ])->assertSessionHasErrors('notify_phone');

        $this->actingAs($user)->put('/settings/sms', [
            'sender' => 'BASKA', 'notify_phone' => '0555 999 88 77', 'events' => ['new_order'], 'quiet_hours' => true,
        ])->assertSessionHasErrors('sender');

        $this->actingAs($user)->put('/settings/sms', [
            'sender' => 'TESTHEADER', 'notify_phone' => '0555 999 88 77', 'events' => ['new_order', 'buyer_shipped'], 'quiet_hours' => false,
        ])->assertSessionHasNoErrors();

        $account = $this->asTenant($tenant, fn () => SmsAccount::query()->sole());
        $this->assertSame('905559998877', $account->notify_phone);
        $this->assertSame(['new_order', 'buyer_shipped'], $account->events);
        $this->assertFalse($account->quiet_hours);
        $this->assertStringNotContainsString('5559998877', (string) DB::table('sms_accounts')->value('notify_phone'), 'Numara diskte şifreli.');

        $this->actingAs($user)->get('/settings/sms')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('account.notifyPhone', '90555•••8877'));
    }

    #[Test]
    public function the_test_message_goes_to_the_seller_and_is_logged(): void
    {
        [$tenant, $user] = $this->connected(['notify_phone' => '905559998877']);

        $this->actingAs($user)->post('/settings/sms/test')->assertSessionHasNoErrors();

        $this->assertCount(1, $this->netgsmSends());
        $this->assertSame('34Pazar: SMS bildirimleri çalışıyor.', $this->netgsmSends()[0]['body']['messages'][0]['text']);
        $this->assertSame(SmsMessage::STATUS_SENT, $this->asTenant($tenant, fn () => SmsMessage::query()->sole()->status));
    }

    #[Test]
    public function a_viewer_cannot_change_sms_settings(): void
    {
        [$tenant, $user] = $this->connected();
        DB::table('tenant_users')->where('tenant_id', $tenant->id)->update(['role' => TenantRole::VIEWER->value]);

        $this->actingAs($user)->get('/settings/sms')->assertOk();
        $this->actingAs($user)->post('/settings/sms/test')->assertForbidden();
        $this->actingAs($user)->delete('/settings/sms')->assertForbidden();
    }

    #[Test]
    public function removing_the_account_stops_sending(): void
    {
        [$tenant, $user] = $this->connected();

        $this->actingAs($user)->delete('/settings/sms')->assertRedirect();

        $this->assertSame(0, $this->asTenant($tenant, fn () => SmsAccount::query()->count()));
    }

    #[Test]
    public function the_kill_switch_hides_the_screen(): void
    {
        [, $user] = $this->trendyolOrder();
        config(['sms.enabled' => false]);

        $this->actingAs($user)->get('/settings/sms')->assertNotFound();
    }

    /** @return array{0: Tenant, 1: User} */
    private function connected(array $overrides = []): array
    {
        [$tenant, $user] = $this->trendyolOrder();

        $this->asTenant($tenant, fn () => SmsAccount::query()->create([
            'tenant_id' => $tenant->id,
            'provider' => SmsAccount::PROVIDER_NETGSM,
            'environment' => SmsAccount::ENV_TEST,
            'credentials' => ['username' => '8500000000', 'password' => 'password001'],
            'sender' => 'TESTHEADER',
            'senders' => ['TESTHEADER'],
            'status' => SmsAccount::STATUS_CONNECTED,
            'verified_at' => now(),
            'events' => [],
            'quiet_hours' => true,
            'notify_since' => now(),
            ...$overrides,
        ]));

        return [$tenant, $user];
    }
}
