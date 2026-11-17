<?php

declare(strict_types=1);

namespace App\Domain\Sms\Providers;

use App\Domain\Sms\Exceptions\SmsException;
use App\Domain\Sms\Models\SmsAccount;
use App\Domain\Sms\Support\SentSms;
use App\Domain\Sync\Enums\ErrorClass;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Netgsm SMS v3 istemcisi — satıcının kendi hesabıyla.
 *
 * Sözleşme (resmî belge api-docs.netgsm.com.tr: auth-v3, sms-v3; resmî SDK
 * github.com/netgsm/netgsm-sms-js). Ayrıntı docs/NETGSM-SMS-NOTLARI.md.
 *
 *   POST /auth/v3/token   {username, password, scope:"sms"} → access_token (24 sa)
 *   GET  /sms/v3/senders  → {senders:[{name, usable, …}]}
 *   GET  /sms/v3/balance  → {balance:[{amount, balance_name}]}
 *   POST /sms/v3/send     {sender, messages:[{text, recipient}], encoding, iysFilter, referenceId}
 *                         → 202 {jobId, status, accepted, rejected, rejectedItems}
 *
 * JETON ÖNBELLEKTE ŞİFRELİ durur (hesap + kimlik parmak izi anahtarıyla):
 * giriş uç noktası IP başına dakikada 30 istekle sınırlı ve tüm kiracılar
 * aynı sunucu IP'sinden çıkar — her gönderimde yeniden giriş yapılsaydı
 * yoğun dakikada giriş reddedilirdi.
 *
 * TEKİLLEŞTİRME YOK (Netgsm belgesi): 503 ya da zaman aşımında aynı mesaj
 * körlemesine yeniden GÖNDERİLMEZ — ikinci SMS satıcının kredisinden
 * düşer ve alıcıyı rahatsız eder. Tekrarı engelleyen çıpa çağıranın işi
 * (`sms_event_keys`).
 *
 * İstek gövdesi ve numara LOG'A YAZILMAZ.
 */
final class NetgsmClient
{
    /** Bilgilendirme iletisi: İYS filtresi uygulanmaz (ticari ileti GÖNDERİLMEZ). */
    public const IYS_FILTER = 'NONE';

    public const APP_NAME = '34Pazar';

    /**
     * Kimliği dener: jeton alır, onaylı başlıkları okur.
     *
     * @return list<string> kullanılabilir gönderici başlıkları
     *
     * @throws SmsException
     */
    public function verify(SmsAccount $account): array
    {
        $this->forgetToken($account);

        return $this->senders($account);
    }

    /**
     * @return list<string>
     *
     * @throws SmsException
     */
    public function senders(SmsAccount $account): array
    {
        $data = $this->authorized($account, 'GET', '/sms/v3/senders');
        $names = [];

        foreach ((array) ($data['senders'] ?? []) as $sender) {
            if (is_array($sender) && ($sender['usable'] ?? true) !== false && is_string($sender['name'] ?? null) && $sender['name'] !== '') {
                $names[] = $sender['name'];
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * Kalan bakiye satırları — ekranda bilgi; okunamazsa boş dizi.
     *
     * @return list<array{name: string, amount: string}>
     */
    public function balance(SmsAccount $account): array
    {
        try {
            $data = $this->authorized($account, 'GET', '/sms/v3/balance?stip=3');
        } catch (Throwable) {
            return [];
        }

        $rows = [];

        foreach ((array) ($data['balance'] ?? []) as $row) {
            if (is_array($row) && isset($row['amount'], $row['balance_name'])) {
                $rows[] = ['name' => (string) $row['balance_name'], 'amount' => (string) $row['amount']];
            }
        }

        return $rows;
    }

    /**
     * @param  list<array{recipient: string, text: string}>  $messages  numara `90XXXXXXXXXX`
     *
     * @throws SmsException
     */
    public function send(SmsAccount $account, array $messages, ?string $referenceId = null): SentSms
    {
        $turkish = false;

        foreach ($messages as $message) {
            $turkish = $turkish || preg_match('/[çğıöşüÇĞİÖŞÜ]/u', $message['text']) === 1;
        }

        $body = array_filter([
            'sender' => (string) $account->sender,
            'messages' => array_map(fn (array $m): array => ['text' => $m['text'], 'recipient' => $m['recipient']], $messages),
            'encoding' => $turkish ? 'TURKISH' : 'DEFAULT',
            'iysFilter' => self::IYS_FILTER,
            'referenceId' => $referenceId !== null ? mb_substr($referenceId, 0, 50) : null,
            'appName' => self::APP_NAME,
        ], fn ($v): bool => $v !== null);

        $data = $this->authorized($account, 'POST', '/sms/v3/send', $body);
        $jobId = $data['jobId'] ?? null;

        if (! is_string($jobId) || $jobId === '') {
            throw new SmsException(ErrorClass::SERVER_ERROR, 'Netgsm gönderim kimliği döndürmedi.');
        }

        $reasons = [];

        foreach ((array) ($data['rejectedItems'] ?? []) as $item) {
            if (is_array($item) && is_string($item['reason'] ?? null)) {
                $reasons[] = $item['reason'];
            }
        }

        return new SentSms(
            jobId: $jobId,
            accepted: (int) ($data['accepted'] ?? count($messages)),
            rejected: (int) ($data['rejected'] ?? 0),
            rejectedReasons: array_values(array_unique($reasons)),
        );
    }

    public function forgetToken(SmsAccount $account): void
    {
        Cache::forget($this->tokenKey($account));
    }

    // ─────────────────────────────────────────────────────────────── iç

    /**
     * Jetonlu istek. Jeton süresi dolmuşsa (401) bir kez yeniden giriş:
     * 401 isteği kabul etmez, mesaj kuyruğa GİRMEMİŞTİR — tekrar güvenli.
     *
     * @param  array<string, mixed>|null  $json
     * @return array<string, mixed>
     */
    private function authorized(SmsAccount $account, string $method, string $path, ?array $json = null): array
    {
        $response = $this->request($account, $method, $path, $this->token($account), $json);

        if ($response->status() === 401) {
            $this->forgetToken($account);
            $response = $this->request($account, $method, $path, $this->token($account), $json);
        }

        if ($response->successful()) {
            $data = json_decode((string) $response->body(), true);

            return is_array($data) ? $data : [];
        }

        throw $this->error($response);
    }

    private function token(SmsAccount $account): string
    {
        $key = $this->tokenKey($account);
        $cached = Cache::get($key);

        if (is_string($cached)) {
            try {
                return Crypt::decryptString($cached);
            } catch (Throwable) {
                Cache::forget($key);
            }
        }

        $response = $this->call('POST', $this->baseUrl($account).'/auth/v3/token', [], [
            'username' => $account->username(),
            'password' => $account->password(),
            'scope' => 'sms',
        ]);

        if (! $response->successful()) {
            throw $this->error($response);
        }

        $token = $response->json('access_token');

        if (! is_string($token) || $token === '') {
            throw new SmsException(ErrorClass::SERVER_ERROR, 'Netgsm giriş yanıtı jeton taşımıyor.');
        }

        // Süre dolmadan 5 dk önce yenilenir; belgede 24 saat.
        $ttl = max(60, (int) ($response->json('expires_in') ?? 86400) - 300);
        Cache::put($key, Crypt::encryptString($token), $ttl);

        return $token;
    }

    /** @param  array<string, mixed>|null  $json */
    private function request(SmsAccount $account, string $method, string $path, string $token, ?array $json): Response
    {
        return $this->call($method, $this->baseUrl($account).$path, ['Authorization' => 'Bearer '.$token], $json);
    }

    /**
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>|null  $json
     */
    private function call(string $method, string $url, array $headers, ?array $json): Response
    {
        try {
            $request = Http::timeout((int) config('sms.timeout', 20))->acceptJson()->withHeaders($headers);

            return $method === 'GET' ? $request->get($url) : $request->asJson()->post($url, $json ?? []);
        } catch (ConnectionException $e) {
            $timedOut = str_contains(strtolower($e->getMessage()), 'timed out') || str_contains($e->getMessage(), 'cURL error 28');

            throw new SmsException($timedOut ? ErrorClass::TIMEOUT : ErrorClass::NETWORK, 'Netgsm\'e bağlanılamadı.');
        }
    }

    private function error(Response $response): SmsException
    {
        $data = json_decode((string) $response->body(), true);
        $data = is_array($data) ? $data : [];
        $code = is_string($data['code'] ?? null) ? $data['code'] : null;
        $message = is_string($data['message'] ?? null) ? $data['message'] : (is_string($data['detail'] ?? null) ? $data['detail'] : '');

        $class = match (true) {
            $response->status() === 401, $response->status() === 403, $code === 'invalid_credentials' => ErrorClass::AUTHENTICATION,
            $response->status() === 429 => ErrorClass::RATE_LIMITED,
            $response->status() >= 500 => ErrorClass::SERVER_ERROR,
            default => ErrorClass::VALIDATION,
        };

        $text = match (true) {
            $class === ErrorClass::AUTHENTICATION => 'Netgsm kullanıcı adı/şifreyi reddetti ya da hesapta SMS API yetkisi yok.',
            $code === 'REJECTED_HEADER' => 'Gönderici başlığı Netgsm\'de onaylı değil: '.$message,
            $code === 'REJECTED_IYS' => 'Netgsm İYS kuralı nedeniyle reddetti: '.$message,
            $code === 'NO_DELIVERABLE_MESSAGES' => 'Netgsm numarayı geçersiz saydı; mesaj gönderilmedi.',
            $class === ErrorClass::RATE_LIMITED => 'Netgsm gönderim sınırına takıldı ya da hesapta gönderim durduruldu.',
            $class === ErrorClass::SERVER_ERROR => 'Netgsm şu an yanıt vermiyor.',
            default => 'Netgsm isteği reddetti'.($message !== '' ? ': '.$message : '.'),
        };

        return new SmsException($class, $text, $code);
    }

    private function baseUrl(SmsAccount $account): string
    {
        $env = $account->environment === SmsAccount::ENV_TEST ? 'test' : 'live';

        return rtrim((string) config("sms.netgsm.{$env}"), '/');
    }

    private function tokenKey(SmsAccount $account): string
    {
        $fingerprint = hash('sha256', $account->environment."\0".$account->username()."\0".$account->password());

        return 'sms:netgsm:token:'.($account->id ?? 'yeni').':'.substr($fingerprint, 0, 16);
    }
}
