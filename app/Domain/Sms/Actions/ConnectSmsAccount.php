<?php

declare(strict_types=1);

namespace App\Domain\Sms\Actions;

use App\Domain\Sms\Exceptions\SmsException;
use App\Domain\Sms\Models\SmsAccount;
use App\Domain\Sms\Providers\NetgsmClient;
use App\Domain\Sync\Enums\ErrorClass;
use App\Support\Tenancy\TenantContext;

/**
 * Satıcının Netgsm hesabını bağlar: önce Netgsm'de DENER, sonra kaydeder.
 *
 * Deneme zararsızdır (giriş + başlık listesi; SMS gitmez, kredi düşmez).
 * Kimliği reddedilen hesap kaydedilmez. Onaylı başlığı olmayan hesap da
 * kaydedilmez: başlıksız hesap hiçbir SMS gönderemez, "bağlı" görünmesi
 * yanıltırdı.
 *
 * Bildirim seçimleri (numara, türler) yeniden bağlanınca KORUNUR.
 */
final class ConnectSmsAccount
{
    public function __construct(private readonly NetgsmClient $client) {}

    /** @throws SmsException */
    public function run(string $environment, string $username, string $password): SmsAccount
    {
        $candidate = new SmsAccount([
            'provider' => SmsAccount::PROVIDER_NETGSM,
            'environment' => $environment,
            'credentials' => ['username' => $username, 'password' => $password],
            'status' => SmsAccount::STATUS_CONNECTED,
        ]);

        $senders = $this->client->verify($candidate);

        if ($senders === []) {
            throw new SmsException(ErrorClass::VALIDATION, 'Netgsm hesabında onaylı gönderici başlığı yok. Önce Netgsm panelinden başlık onaylatın.');
        }

        $existing = SmsAccount::query()->first();
        $sender = $existing !== null && in_array($existing->sender, $senders, true) ? $existing->sender : $senders[0];

        $account = SmsAccount::query()->updateOrCreate(
            ['tenant_id' => TenantContext::idOrFail()],
            [
                'provider' => SmsAccount::PROVIDER_NETGSM,
                'environment' => $environment,
                'credentials' => ['username' => $username, 'password' => $password],
                'sender' => $sender,
                'senders' => $senders,
                'status' => SmsAccount::STATUS_CONNECTED,
                'last_error' => null,
                'verified_at' => now(),
                // İlk bağlantıda geçmiş olaylar SMS'e dönüşmez.
                'notify_since' => $existing?->notify_since ?? now(),
            ],
        );

        // Yeni kimlikle eski jeton kullanılmasın.
        $this->client->forgetToken($account);

        return $account;
    }
}
