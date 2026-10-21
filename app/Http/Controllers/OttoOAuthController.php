<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Channels\Actions\CheckChannelHealth;
use App\Domain\Channels\Adapters\Otto\OttoAdapter;
use App\Domain\Channels\Adapters\Otto\OttoApp;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Support\ChannelConnectForm;
use App\Domain\Identity\Actions\RecordAuditLog;
use App\Domain\Identity\Enums\AuditAction;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Inertia\Inertia;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * OTTO Market kurulum bağlama (Service Partner akışı, resmî `sp-integration`).
 *
 * Satıcı 34Pazar uygulamasını OTTO'da kurar, sonra panelden "Yetkilendir"e
 * basar: OTTO'da `installation partnerId` kapsamıyla oturum açar, dönüşte
 * `GET /v1/apps/{appId}/installation` kurulum kimliğini verir. Kurulum
 * kimliği bağlantının hesap kimliği ve ayarı olur; satıcı anahtarı SAKLANMAZ
 * (erişim anahtarı her seferinde uygulama kimliğiyle üretilir).
 *
 * `state` doğrulaması CSRF'in yerini tutar (Etsy/eBay akışıyla aynı).
 */
final class OttoOAuthController extends Controller
{
    private const SESSION_STATE = 'otto.oauth.state';

    private const SESSION_CONNECTION = 'otto.oauth.connection';

    public function __construct(
        private readonly CheckChannelHealth $checkHealth,
        private readonly RecordAuditLog $audit,
    ) {}

    public function redirect(Request $request, string $connectionId): Response|RedirectResponse
    {
        $connection = $this->findConnection($connectionId);

        if ($connection === null) {
            return redirect()->route('channels.index')->with('success', __('Bağlantı bulunamadı.'));
        }

        $state = Str::random(40);
        $request->session()->put(self::SESSION_STATE, $state);
        $request->session()->put(self::SESSION_CONNECTION, $connection->id);

        $target = OttoApp::host().'/oauth2/auth?'.http_build_query([
            'response_type' => 'code',
            'client_id' => OttoApp::clientId(),
            'redirect_uri' => route('channels.otto.callback'),
            'scope' => 'installation partnerId',
            'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986);

        return $request->header('X-Inertia') ? Inertia::location($target) : redirect()->away($target);
    }

    public function callback(Request $request): RedirectResponse
    {
        $expected = $request->session()->pull(self::SESSION_STATE);
        $connectionId = $request->session()->pull(self::SESSION_CONNECTION);
        $state = (string) $request->query('state', '');

        if (! is_string($expected) || $expected === '' || ! hash_equals($expected, $state)) {
            Log::warning('otto.oauth.state_mismatch');

            return redirect()->route('channels.index')->with('success', __('OTTO bağlantısı doğrulanamadı. Lütfen yeniden deneyin.'));
        }

        $code = $request->query('code');
        $connection = is_string($connectionId) ? $this->findConnection($connectionId) : null;

        if (! is_string($code) || $code === '' || $connection === null) {
            return redirect()->route('channels.index')->with('success', __('OTTO yetkilendirmesi tamamlanmadı.'));
        }

        try {
            $installationId = $this->installationIdFor($this->exchange($code));
        } catch (Throwable $e) {
            Log::warning('otto.oauth.installation_failed', ['connection' => $connection->id, 'error' => $e->getMessage()]);

            return redirect()->route('channels.index')->with(
                'success',
                __('OTTO kurulumu bulunamadı. Önce 34Pazar uygulamasını OTTO Partner Connect\'te kur, sonra yeniden yetkilendir.'),
            );
        }

        $owner = TenantContext::runAsSystem(fn (): ?ChannelConnection => ChannelConnection::query()
            ->where('channel_type_code', 'otto')
            ->where('external_account_id', $installationId)
            ->first());

        if ($owner !== null && $owner->tenant_id !== $connection->tenant_id) {
            $this->discardPending($connection);

            return redirect()->route('channels.index')->with('success', __('Bu mağaza başka bir hesaba bağlı.'));
        }

        if ($owner !== null && $owner->id !== $connection->id) {
            $this->discardPending($connection);
            $connection = $owner;
        }

        $connection->forceFill([
            'external_account_id' => $installationId,
            'settings' => [...$connection->settings ?? [], OttoAdapter::INSTALLATION_ID_KEY => $installationId],
        ])->save();

        $this->audit->run(
            action: AuditAction::CHANNEL_CREDENTIAL_UPDATED,
            subjectType: 'channel_connections',
            subjectId: $connection->id,
            changes: ['channel_type_code' => 'otto', 'installation' => true],
        );

        $this->checkHealth->run($connection);

        return redirect()->route('channels.index')->with('success', __('OTTO bağlantısı yetkilendirildi.'));
    }

    /** Yetki kodu → kullanıcı erişim anahtarı (yalnız kurulumu bulmak için). */
    private function exchange(string $code): string
    {
        $response = Http::asForm()->acceptJson()->timeout(15)->post(OttoApp::host().'/oauth2/token', [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => route('channels.otto.callback'),
            'client_id' => OttoApp::clientId(),
            'client_secret' => OttoApp::clientSecret(),
        ]);

        $response->throw();

        $token = $response->json('access_token');

        if (! is_string($token) || $token === '') {
            throw new RuntimeException('OTTO yanıtı access token taşımıyor.');
        }

        return $token;
    }

    private function installationIdFor(string $userToken): string
    {
        $response = Http::acceptJson()->timeout(15)->withToken($userToken)
            ->get(OttoApp::host().'/v1/apps/'.rawurlencode(OttoApp::appId()).'/installation');

        $response->throw();

        $id = $response->json('installationId');

        if (! is_string($id) || trim($id) === '') {
            throw new RuntimeException('OTTO kurulum yanıtı installationId taşımıyor.');
        }

        return trim($id);
    }

    private function findConnection(string $connectionId): ?ChannelConnection
    {
        return ChannelConnection::query()->where('id', $connectionId)->where('channel_type_code', 'otto')->first();
    }

    private function discardPending(ChannelConnection $connection): void
    {
        if (str_starts_with((string) $connection->external_account_id, ChannelConnectForm::PENDING_ACCOUNT_PREFIX)) {
            $connection->delete();
        }
    }
}
