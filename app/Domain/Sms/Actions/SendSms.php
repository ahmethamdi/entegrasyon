<?php

declare(strict_types=1);

namespace App\Domain\Sms\Actions;

use App\Domain\Sms\Exceptions\SmsException;
use App\Domain\Sms\Models\SmsAccount;
use App\Domain\Sms\Models\SmsMessage;
use App\Domain\Sms\Providers\NetgsmClient;
use App\Domain\Sms\Support\SmsPhone;
use App\Domain\Sync\Enums\ErrorClass;
use Illuminate\Support\Facades\DB;

/**
 * Tek SMS gönderir ve kaydeder — TEKRAR GÖNDERİM YOK.
 *
 * SIRA ÖNEMLİ: olay anahtarları (`sms_event_keys`) gönderimden ÖNCE
 * yazılır. Eşzamanlı iki tur aynı olayı görürse anahtarı yalnız biri
 * yazabilir; öteki boş küme alır ve göndermez. Gönderim başarısız olursa
 * anahtar SİLİNMEZ: Netgsm'in tekilleştirmesi yok ve 503/zaman aşımında
 * mesajın gidip gitmediği bilinmez — ikinci SMS satıcının kredisinden
 * düşer. Başarısız gönderim kayıtta görünür.
 *
 * Kimlik reddi hesabı `revoked` yapar: sonraki turlar boşuna denemez,
 * ekranda "bağlantı koptu" görünür.
 */
final class SendSms
{
    public function __construct(private readonly NetgsmClient $client) {}

    /**
     * Olay anahtarlarından henüz gönderilmemiş olanları ayırır ve yazar.
     *
     * @param  list<string>  $keys
     * @return list<string> bu turun sahiplendiği anahtarlar
     */
    public function claim(SmsAccount $account, array $keys): array
    {
        $claimed = [];

        foreach (array_values(array_unique($keys)) as $key) {
            $inserted = DB::table('sms_event_keys')->insertOrIgnore([
                'tenant_id' => $account->tenant_id,
                'event_key' => mb_substr($key, 0, 120),
                'created_at' => now(),
            ]);

            if ($inserted === 1) {
                $claimed[] = $key;
            }
        }

        return $claimed;
    }

    /**
     * Sahiplenilmiş anahtarlar için tek mesaj gönderir.
     *
     * @param  list<string>  $claimedKeys
     */
    public function send(SmsAccount $account, string $kind, string $phone, string $text, array $claimedKeys = []): SmsMessage
    {
        $normalized = SmsPhone::normalize($phone);

        if ($normalized === null) {
            return $this->record($account, $kind, '•••', $text, SmsMessage::STATUS_SKIPPED, null, 'Numara Türkiye cep telefonu değil.', $claimedKeys);
        }

        $masked = SmsPhone::mask($normalized);

        try {
            $sent = $this->client->send($account, [['recipient' => $normalized, 'text' => $text]], $claimedKeys[0] ?? $kind);
        } catch (SmsException $e) {
            if ($e->class === ErrorClass::AUTHENTICATION) {
                $account->forceFill(['status' => SmsAccount::STATUS_REVOKED, 'last_error' => $e->getMessage()])->save();
            }

            return $this->record($account, $kind, $masked, $text, SmsMessage::STATUS_FAILED, null, $e->getMessage(), $claimedKeys);
        }

        if ($sent->accepted < 1) {
            return $this->record($account, $kind, $masked, $text, SmsMessage::STATUS_FAILED, $sent->jobId,
                'Netgsm numarayı reddetti ('.implode(', ', $sent->rejectedReasons ?: ['sebep yok']).').', $claimedKeys);
        }

        return $this->record($account, $kind, $masked, $text, SmsMessage::STATUS_SENT, $sent->jobId, null, $claimedKeys);
    }

    /** @param  list<string>  $keys */
    private function record(SmsAccount $account, string $kind, string $masked, string $text, string $status, ?string $jobId, ?string $error, array $keys): SmsMessage
    {
        $message = SmsMessage::query()->create([
            'tenant_id' => $account->tenant_id,
            'kind' => $kind,
            'recipient_masked' => $masked,
            'body' => $text,
            'status' => $status,
            'provider_job_id' => $jobId,
            'error' => $error,
            'event_count' => max(1, count($keys)),
        ]);

        if ($keys !== []) {
            DB::table('sms_event_keys')
                ->where('tenant_id', $account->tenant_id)
                ->whereIn('event_key', $keys)
                ->update(['sms_message_id' => $message->id]);
        }

        return $message;
    }
}
