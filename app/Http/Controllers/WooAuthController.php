<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Billing\Actions\EnforceQuota;
use App\Domain\Billing\Enums\QuotaMetric;
use App\Domain\Billing\Exceptions\QuotaExceededException;
use App\Domain\Channels\Actions\ConnectChannel;
use App\Domain\Channels\Exceptions\AccountAlreadyConnectedException;
use App\Domain\Channels\Jobs\FinishWooConnection;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Support\CredentialVault;
use App\Domain\Channels\Support\StoreUrl;
use App\Domain\Channels\Support\WooAuth;
use App\Domain\Identity\Actions\RecordAuditLog;
use App\Domain\Identity\Enums\AuditAction;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * WooCommerce bağlantı sihirbazı — resmî `/wc-auth/v1/authorize` (S5).
 *
 *   1. authorize (panel, oturum + CSRF): mağaza adresi → bağlantı satırı
 *      SIRSIZ açılır ya da mevcut satır yeniden kullanılır (anahtar
 *      yenileme), tek kullanımlık nonce yazılır, satıcı mağazasının onay
 *      ekranına gider.
 *   2. callback (mağaza sunucusundan JSON POST, oturumsuz, CSRF muaf):
 *      nonce doğrulanır → anahtarlar kasaya → nonce SİLİNİR → sağlık +
 *      webhook kuyruğa. Hemen 200.
 *   3. return (satıcının tarayıcısı, oturumlu): bilgi mesajı, /channels.
 *
 * Yazma izinleri (yayın/fiyat/stok) hiçbir adımda AÇILMAZ (ADR-010).
 * Sırlar günlüğe yazılmaz; hata günlüğünde yalnız bağlantı kimliği.
 */
final class WooAuthController extends Controller
{
    private const CALLBACK_PER_MINUTE = 30;

    public function authorize(Request $request, ConnectChannel $connect, WooAuth $auth): Response
    {
        $fields = $request->validate([
            'label' => ['required', 'string', 'max:120'],
            'store_url' => ['required', 'string', 'max:255'],
            'channel_type_code' => ['nullable', Rule::in(['woocommerce'])],
        ]);

        $active = TenantContext::runAsSystem(fn () => DB::table('channel_types')
            ->where('code', 'woocommerce')->where('is_active', true)->exists());

        if (! $active) {
            throw ValidationException::withMessages(['store_url' => __('Bu kanalın 34Pazar uygulaması henüz etkin değil. Kısa süre içinde açılacak.')]);
        }

        try {
            $account = StoreUrl::parse($fields['store_url'])->host;
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['store_url' => __($e->getMessage())]);
        }

        $isNew = ! ChannelConnection::query()->where('channel_type_code', 'woocommerce')->where('external_account_id', $account)->exists();

        if ($isNew) {
            try {
                app(EnforceQuota::class)->check(QuotaMetric::CHANNELS);
            } catch (QuotaExceededException $e) {
                throw ValidationException::withMessages(['store_url' => $e->metric->exceededMessage($e->current, $e->limit)]);
            }
        }

        try {
            $connection = $connect->run(
                channelTypeCode: 'woocommerce',
                label: $fields['label'],
                storeUrl: $fields['store_url'],
                secrets: [],
                checkHealth: false,
            );
        } catch (AccountAlreadyConnectedException) {
            throw ValidationException::withMessages(['store_url' => __('Bu mağaza başka bir hesaba bağlı.')]);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['store_url' => __($e->getMessage())]);
        }

        // Inertia isteğinde dış adrese gitmek için 409 + X-Inertia-Location.
        return Inertia::location($auth->begin($connection));
    }

    public function callback(Request $request, CredentialVault $vault, RecordAuditLog $audit): JsonResponse
    {
        $throttle = 'wc-auth-callback:'.$request->ip();

        if (RateLimiter::tooManyAttempts($throttle, self::CALLBACK_PER_MINUTE)) {
            return response()->json(['ok' => false], 429);
        }

        RateLimiter::hit($throttle, 60);

        $data = json_decode($request->getContent(), true);

        if (! is_array($data)) {
            return response()->json(['ok' => false, 'error' => 'invalid_body'], 400);
        }

        $nonce = (string) ($data['user_id'] ?? '');
        $key = (string) ($data['consumer_key'] ?? '');
        $secret = (string) ($data['consumer_secret'] ?? '');
        $permissions = (string) ($data['key_permissions'] ?? '');

        if (strlen($nonce) !== 48
            || preg_match('/^ck_[A-Za-z0-9]{20,64}$/', $key) !== 1
            || preg_match('/^cs_[A-Za-z0-9]{20,64}$/', $secret) !== 1
            || ! in_array($permissions, ['read', 'read_write'], true)) {
            return response()->json(['ok' => false, 'error' => 'invalid_payload'], 400);
        }

        // Nonce kiracıdan bağımsız aranır (istek oturumsuz). Bulunan satırın
        // kiracısında devam edilir.
        $hash = WooAuth::nonceHash($nonce);
        $found = TenantContext::runAsSystem(fn () => ChannelConnection::query()
            ->where('channel_type_code', 'woocommerce')
            ->whereRaw("settings->'".WooAuth::SETTINGS_KEY."'->>'nonce_hash' = ?", [$hash])
            ->first(['id', 'tenant_id']));

        if ($found === null) {
            return response()->json(['ok' => false, 'error' => 'unknown_request'], 404);
        }

        return TenantContext::runFor($found->tenant_id, function () use ($found, $hash, $key, $secret, $permissions, $data, $vault, $audit): JsonResponse {
            $accepted = DB::transaction(function () use ($found, $hash, $key, $secret, $permissions, $data, $vault, $audit): bool {
                $connection = ChannelConnection::query()->lockForUpdate()->find($found->id);

                // Tek kullanımlık: kilit altında yeniden doğrula.
                if ($connection === null
                    || ($connection->settings[WooAuth::SETTINGS_KEY]['nonce_hash'] ?? null) !== $hash
                    || ! WooAuth::stillValid($connection)) {
                    return false;
                }

                $previous = $connection->activeCredential()->first() !== null ? $vault->read($connection) : [];

                $vault->store($connection, [
                    ...array_intersect_key($previous, ['webhook_secret' => true]),
                    'consumer_key' => $key,
                    'consumer_secret' => $secret,
                ]);

                $settings = $connection->settings ?? [];
                unset($settings[WooAuth::SETTINGS_KEY]);
                $settings['wc_auth_key_permissions'] = $permissions;
                $settings['wc_auth_key_id'] = is_scalar($data['key_id'] ?? null) ? (string) $data['key_id'] : null;
                $connection->forceFill(['settings' => $settings])->save();

                $audit->run(
                    action: $previous === [] ? AuditAction::CHANNEL_CONNECTED : AuditAction::CHANNEL_CREDENTIAL_UPDATED,
                    subjectType: 'channel_connections',
                    subjectId: $connection->id,
                    changes: [
                        'channel_type_code' => 'woocommerce',
                        'via' => 'wc-auth',
                        'key_permissions' => $permissions,
                    ],
                );

                return true;
            });

            if (! $accepted) {
                return response()->json(['ok' => false, 'error' => 'expired_or_used'], 409);
            }

            FinishWooConnection::dispatch($found->tenant_id, $found->id)->afterCommit();
            Log::info('woocommerce.auth_completed', ['connection' => $found->id]);

            return response()->json(['ok' => true]);
        });
    }

    public function return(Request $request): RedirectResponse
    {
        if ($request->query('success') !== '1') {
            return redirect('/channels')->with('warning', __('WooCommerce mağazasında izin verilmedi. Yeniden deneyebilir ya da anahtarları elle girebilirsin.'));
        }

        return redirect('/channels')->with('success', __('WooCommerce izni alındı; bağlantı birkaç saniye içinde hazır olur. Kanala yazma izinleri kapalı başlar.'));
    }
}
