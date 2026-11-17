<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Channels\Contracts\SupportsShippingRecipient;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Sms\Actions\ConnectSmsAccount;
use App\Domain\Sms\Actions\SendSms;
use App\Domain\Sms\Exceptions\SmsException;
use App\Domain\Sms\Models\SmsAccount;
use App\Domain\Sms\Models\SmsMessage;
use App\Domain\Sms\Providers\NetgsmClient;
use App\Domain\Sms\Support\SmsPhone;
use App\Domain\Sync\Enums\ErrorClass;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * SMS bildirimleri — satıcının kendi Netgsm hesabı.
 *
 * Şifre ekrana GERİ GÖNDERİLMEZ (yalnız kullanıcı adının son 3 hanesi);
 * bildirim numarası maskeli gösterilir. Bakiye sayfa açılışını
 * yavaşlatmasın diye ertelenmiş prop'tur.
 */
final class SmsSettingsController extends Controller
{
    public function index(NetgsmClient $client): InertiaResponse
    {
        $account = SmsAccount::query()->first();

        return Inertia::render('Settings/Sms', [
            'account' => $account === null ? null : [
                'status' => $account->status,
                'environment' => $account->environment,
                'username' => '•••'.mb_substr($account->username(), -3),
                'sender' => $account->sender,
                'senders' => array_values($account->senders ?? []),
                'notifyPhone' => $account->notify_phone !== null && SmsPhone::normalize($account->notify_phone) !== null
                    ? SmsPhone::mask((string) SmsPhone::normalize($account->notify_phone))
                    : null,
                'events' => array_values($account->events ?? []),
                'quietHours' => $account->quiet_hours,
                'lastError' => $account->last_error,
                'verifiedAt' => $account->verified_at?->toIso8601String(),
            ],
            'events' => SmsAccount::EVENTS,
            // Alıcıya SMS yalnız telefonu veren kanallarda; satıcı hangisinin
            // kapsandığını görsün.
            'buyerChannels' => $this->buyerChannels(),
            'messages' => SmsMessage::query()->latest()->limit(20)->get()->map(fn (SmsMessage $m): array => [
                'id' => $m->id,
                'kind' => $m->kind,
                'recipient' => $m->recipient_masked,
                'body' => $m->body,
                'status' => $m->status,
                'error' => $m->error,
                'count' => $m->event_count,
                'at' => $m->created_at?->toIso8601String(),
            ])->all(),
            'balance' => Inertia::defer(fn (): array => $account !== null && $account->status === SmsAccount::STATUS_CONNECTED
                ? $client->balance($account)
                : []),
        ]);
    }

    public function connect(Request $request, ConnectSmsAccount $connect): RedirectResponse
    {
        $data = $request->validate([
            'environment' => ['required', 'string', Rule::in(SmsAccount::ENVIRONMENTS)],
            'username' => ['required', 'string', 'max:64'],
            'password' => ['required', 'string', 'max:128'],
        ]);

        try {
            $connect->run($data['environment'], trim($data['username']), $data['password']);
        } catch (SmsException $e) {
            return back()->withErrors(['sms' => $e->class === ErrorClass::AUTHENTICATION || $e->class === ErrorClass::VALIDATION
                ? $e->getMessage()
                : __('Netgsm\'e şu an ulaşılamadı; biraz sonra yeniden deneyin.')]);
        }

        return back()->with('success', __('Netgsm hesabı bağlandı.'));
    }

    public function update(Request $request): RedirectResponse
    {
        $account = SmsAccount::query()->firstOrFail();

        $data = $request->validate([
            'sender' => ['required', 'string', Rule::in($account->senders ?? [])],
            // Boş bırakılırsa kayıtlı numara korunur.
            'notify_phone' => ['nullable', 'string', 'max:20', function (string $attr, mixed $value, \Closure $fail): void {
                if (is_string($value) && trim($value) !== '' && SmsPhone::normalize($value) === null) {
                    $fail(__('Türkiye cep telefonu numarası girin (05XX XXX XX XX).'));
                }
            }],
            'events' => ['array'],
            'events.*' => ['string', Rule::in(SmsAccount::EVENTS)],
            'quiet_hours' => ['boolean'],
        ]);

        $events = array_values(array_unique($data['events'] ?? []));
        $phone = isset($data['notify_phone']) && trim((string) $data['notify_phone']) !== ''
            ? SmsPhone::normalize($data['notify_phone'])
            : $account->notify_phone;

        if (array_intersect($events, SmsAccount::SELLER_EVENTS) !== [] && ($phone === null || $phone === '')) {
            return back()->withErrors(['notify_phone' => __('Size gidecek bildirimler için cep telefonu numaranızı girin.')]);
        }

        $account->forceFill([
            'sender' => $data['sender'],
            'notify_phone' => $phone,
            'events' => $events,
            'quiet_hours' => (bool) ($data['quiet_hours'] ?? true),
            // Yeni açılan bildirim geçmiş olayları toplu SMS'e çevirmesin.
            'notify_since' => $events !== ($account->events ?? []) ? now() : $account->notify_since,
        ])->save();

        return back()->with('success', __('SMS ayarları kaydedildi.'));
    }

    public function test(SendSms $sms): RedirectResponse
    {
        $account = SmsAccount::query()->firstOrFail();

        if (! $account->isUsable()) {
            return back()->withErrors(['sms' => __('Önce Netgsm hesabını bağlayın.')]);
        }

        if ($account->notify_phone === null || SmsPhone::normalize($account->notify_phone) === null) {
            return back()->withErrors(['notify_phone' => __('Deneme için önce cep telefonu numaranızı kaydedin.')]);
        }

        $message = $sms->send($account, SmsMessage::KIND_TEST, $account->notify_phone,
            __('34Pazar: SMS bildirimleri çalışıyor.'), $sms->claim($account, ['test:'.Str::uuid()->toString()]));

        return $message->status === SmsMessage::STATUS_SENT
            ? back()->with('success', $account->environment === SmsAccount::ENV_TEST
                ? __('Netgsm test ortamı mesajı kabul etti (test ortamında gerçek SMS gitmez).')
                : __('Deneme SMS\'i gönderildi.'))
            : back()->withErrors(['sms' => $message->error ?? __('Deneme SMS\'i gönderilemedi.')]);
    }

    public function destroy(NetgsmClient $client): RedirectResponse
    {
        $account = SmsAccount::query()->first();

        if ($account !== null) {
            $client->forgetToken($account);
            $account->delete();
        }

        return back()->with('success', __('Netgsm hesabı kaldırıldı; SMS gönderimi durdu.'));
    }

    /** @return list<string> alıcıya SMS gidebilen bağlı kanalların adı */
    private function buyerChannels(): array
    {
        $names = [];

        foreach (ChannelConnection::query()->get(['id', 'channel_type_code', 'label']) as $connection) {
            $class = DB::table('channel_types')->where('code', $connection->channel_type_code)->value('adapter_class');

            if (is_string($class) && class_exists($class) && is_subclass_of($class, SupportsShippingRecipient::class)) {
                $names[] = (string) $connection->label;
            }
        }

        return array_values(array_unique($names));
    }
}
