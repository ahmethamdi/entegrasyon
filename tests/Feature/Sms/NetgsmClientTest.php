<?php

declare(strict_types=1);

namespace Tests\Feature\Sms;

use App\Domain\Sms\Exceptions\SmsException;
use App\Domain\Sms\Models\SmsAccount;
use App\Domain\Sms\Providers\NetgsmClient;
use App\Domain\Sms\Support\SmsPhone;
use App\Domain\Sync\Enums\ErrorClass;
use Illuminate\Cache\ArrayStore;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Netgsm SMS v3 istemcisi — test ortamından alınmış gerçek cevaplarla.
 *
 * Sözleşme: jeton önbellekte şifreli ve tekrar kullanılır; süresi dolan
 * jetonda (401) bir kez yeniden giriş; gönderim TEK denemedir; hata
 * sınıfıyla ve satıcıya gösterilebilir metinle döner.
 */
final class NetgsmClientTest extends TestCase
{
    use FakesNetgsm;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    #[Test]
    public function verify_logs_in_and_returns_only_usable_senders(): void
    {
        $this->fakeNetgsm();

        $senders = $this->client()->verify($this->account());

        $this->assertSame(['TESTHEADER'], $senders, 'Kullanılamayan abone numarası başlığı listeye girmez.');
        $this->assertSame('https://ext-sandbox.netgsm.com.tr/auth/v3/token', $this->netgsmCalls[0]['url'], 'Test hesabı test ortamına gider.');
        $this->assertSame(['username' => '8500000000', 'password' => 'password001', 'scope' => 'sms'], $this->netgsmCalls[0]['body']);
        $this->assertSame('Bearer sandbox-erisim-jetonu', $this->netgsmCalls[1]['auth']);
    }

    #[Test]
    public function the_token_is_cached_encrypted_and_reused(): void
    {
        $this->fakeNetgsm();
        $account = $this->account();

        $this->client()->senders($account);
        $this->client()->balance($account);

        $logins = array_filter($this->netgsmCalls, fn (array $c): bool => $c['path'] === '/auth/v3/token');
        $this->assertCount(1, $logins, 'Giriş IP başına dakikada 30 ile sınırlı: her istekte yeniden giriş yapılmaz.');

        $stored = (new \ReflectionProperty(ArrayStore::class, 'storage'))->getValue(Cache::getStore());
        $tokens = array_filter($stored, fn (string $key): bool => str_starts_with($key, 'sms:netgsm:token:'), ARRAY_FILTER_USE_KEY);
        $this->assertCount(1, $tokens);
        $this->assertStringNotContainsString('sandbox-erisim-jetonu', (string) json_encode($tokens), 'Jeton önbellekte düz metin durmaz.');
    }

    #[Test]
    public function an_expired_token_is_renewed_once_and_the_request_repeated(): void
    {
        $this->fakeNetgsm([
            '/sms/v3/senders' => [['token-invalid', 401], ['senders', 200]],
        ]);

        $this->assertSame(['TESTHEADER'], $this->client()->senders($this->account()));
        $this->assertCount(2, array_filter($this->netgsmCalls, fn (array $c): bool => $c['path'] === '/auth/v3/token'));
    }

    #[Test]
    public function send_uses_the_v3_body_with_turkish_encoding_only_when_needed(): void
    {
        $this->fakeNetgsm();
        $client = $this->client();

        $sent = $client->send($this->account(), [['recipient' => '905321112233', 'text' => 'Siparişiniz kargoya verildi.']], 'ship:abc');
        $client->send($this->account(), [['recipient' => '905321112233', 'text' => 'Order shipped.']]);

        $this->assertSame('17949071472739000017964541', $sent->jobId);
        $this->assertSame(1, $sent->accepted);

        [$first, $second] = $this->netgsmSends();
        $this->assertSame('TESTHEADER', $first['body']['sender']);
        $this->assertSame([['text' => 'Siparişiniz kargoya verildi.', 'recipient' => '905321112233']], $first['body']['messages']);
        $this->assertSame('TURKISH', $first['body']['encoding']);
        $this->assertSame('NONE', $first['body']['iysFilter'], 'Bilgilendirme iletisi; ticari ileti gönderilmez.');
        $this->assertSame('ship:abc', $first['body']['referenceId']);
        $this->assertSame('DEFAULT', $second['body']['encoding']);
    }

    #[Test]
    public function rejected_credentials_are_an_authentication_error(): void
    {
        $this->fakeNetgsm(['/auth/v3/token' => ['token-invalid', 401]]);

        $e = $this->error(fn () => $this->client()->verify($this->account()));

        $this->assertSame(ErrorClass::AUTHENTICATION, $e->class);
    }

    #[Test]
    public function an_unregistered_sender_is_a_validation_error_with_the_netgsm_reason(): void
    {
        $this->fakeNetgsm(['/sms/v3/send' => ['send-rejected-header', 422]]);

        $e = $this->error(fn () => $this->client()->send($this->account(), [['recipient' => '905321112233', 'text' => 'x']]));

        $this->assertSame(ErrorClass::VALIDATION, $e->class);
        $this->assertSame('REJECTED_HEADER', $e->providerCode);
        $this->assertStringContainsString('NOT_REGISTERED', $e->getMessage());
    }

    #[Test]
    public function an_invalid_number_is_reported_and_not_retried(): void
    {
        $this->fakeNetgsm(['/sms/v3/send' => ['send-no-deliverable', 422]]);

        $e = $this->error(fn () => $this->client()->send($this->account(), [['recipient' => '9012', 'text' => 'x']]));

        $this->assertSame('NO_DELIVERABLE_MESSAGES', $e->providerCode);
        $this->assertCount(1, $this->netgsmSends(), 'Gönderim tek denemedir (Netgsm tekilleştirmez).');
    }

    #[Test]
    public function balance_rows_are_read_and_failures_are_silent(): void
    {
        $this->fakeNetgsm();
        $this->assertSame(
            [['name' => 'Adet SMS', 'amount' => '1500'], ['name' => 'Adet OTP Sms', 'amount' => '300'], ['name' => 'Kredi Bakiye', 'amount' => '125,500']],
            $this->client()->balance($this->account()),
        );

        Cache::flush();
        $this->fakeNetgsm(['/auth/v3/token' => ['token-invalid', 401]]);
        $this->assertSame([], $this->client()->balance($this->account()), 'Bakiye bilgi amaçlı; okunamazsa sayfa bozulmaz.');
    }

    #[Test]
    public function phone_numbers_are_normalized_to_the_v3_format(): void
    {
        $this->assertSame('905321112233', SmsPhone::normalize('0532 111 22 33'));
        $this->assertSame('905321112233', SmsPhone::normalize('+90 (532) 111-22-33'));
        $this->assertSame('905321112233', SmsPhone::normalize('5321112233'));
        $this->assertSame('905321112233', SmsPhone::normalize('00905321112233'));
        $this->assertNull(SmsPhone::normalize('0212 111 22 33'), 'Sabit hat: kargo SMS\'i gitmez.');
        $this->assertNull(SmsPhone::normalize('+49 151 1234 5678'));
        $this->assertNull(SmsPhone::normalize(''));
        $this->assertSame('90532•••2233', SmsPhone::mask('905321112233'));
    }

    private function client(): NetgsmClient
    {
        return new NetgsmClient;
    }

    private function account(): SmsAccount
    {
        $account = new SmsAccount([
            'provider' => SmsAccount::PROVIDER_NETGSM,
            'environment' => SmsAccount::ENV_TEST,
            'credentials' => ['username' => '8500000000', 'password' => 'password001'],
            'sender' => 'TESTHEADER',
            'status' => SmsAccount::STATUS_CONNECTED,
        ]);
        $account->id = '01900000-0000-7000-8000-000000000001';

        return $account;
    }

    private function error(\Closure $fn): SmsException
    {
        try {
            $fn();
        } catch (SmsException $e) {
            return $e;
        }

        $this->fail('SmsException beklendi.');
    }
}
