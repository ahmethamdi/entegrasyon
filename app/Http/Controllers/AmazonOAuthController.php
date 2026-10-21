<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Channels\Actions\CheckChannelHealth;
use App\Domain\Channels\Adapters\Amazon\AmazonAdapter;
use App\Domain\Channels\Adapters\Amazon\AmazonApp;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Support\ChannelConnectForm;
use App\Domain\Channels\Support\CredentialVault;
use App\Domain\Identity\Actions\RecordAuditLog;
use App\Domain\Identity\Enums\AuditAction;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Inertia\Inertia;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Amazon SP-API "website authorization workflow" (resmî belge, 21 Eki 2026).
 *
 * Satıcı Seller Central'da (`/apps/authorize/consent`) 34Pazar'a izin verir;
 * dönüşte `selling_partner_id` + `spapi_oauth_code` (5 dk geçerli) gelir, kod
 * LWA'da yenileme anahtarına çevrilir. Satıcı şifresi bize gelmez.
 */
final class AmazonOAuthController extends Controller
{
    private const SESSION_STATE = 'amazon.oauth.state';

    private const SESSION_CONNECTION = 'amazon.oauth.connection';

    public function __construct(
        private readonly CredentialVault $vault,
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

        $market = AmazonApp::MARKETPLACES[strtolower((string) ($connection->settings[AmazonAdapter::MARKETPLACE_KEY] ?? 'de'))] ?? AmazonApp::MARKETPLACES['de'];

        $target = $market['seller_central'].'/apps/authorize/consent?'.http_build_query(array_filter([
            'application_id' => AmazonApp::applicationId(),
            'state' => $state,
            'version' => AmazonApp::isDraft() ? 'beta' : null,
        ]), '', '&', PHP_QUERY_RFC3986);

        return $request->header('X-Inertia') ? Inertia::location($target) : redirect()->away($target);
    }

    public function callback(Request $request): RedirectResponse
    {
        $expected = $request->session()->pull(self::SESSION_STATE);
        $connectionId = $request->session()->pull(self::SESSION_CONNECTION);

        if (! is_string($expected) || $expected === '' || ! hash_equals($expected, (string) $request->query('state', ''))) {
            Log::warning('amazon.oauth.state_mismatch');

            return redirect()->route('channels.index')->with('success', __('Amazon bağlantısı doğrulanamadı. Lütfen yeniden deneyin.'));
        }

        $code = $request->query('spapi_oauth_code');
        $sellerId = trim((string) $request->query('selling_partner_id', ''));
        $connection = is_string($connectionId) ? $this->findConnection($connectionId) : null;

        if (! is_string($code) || $code === '' || $sellerId === '' || $connection === null) {
            return redirect()->route('channels.index')->with('success', __('Amazon yetkilendirmesi tamamlanmadı.'));
        }

        try {
            $tokens = $this->exchange($code);
        } catch (Throwable $e) {
            Log::warning('amazon.oauth.exchange_failed', ['connection' => $connection->id, 'error' => $e->getMessage()]);

            return redirect()->route('channels.index')->with('success', __('Amazon kimlik bilgisi alınamadı. Lütfen yeniden deneyin.'));
        }

        $owner = TenantContext::runAsSystem(fn (): ?ChannelConnection => ChannelConnection::query()
            ->where('channel_type_code', 'amazon')
            ->where('external_account_id', $sellerId)
            ->first());

        if ($owner !== null && $owner->tenant_id !== $connection->tenant_id) {
            $this->discardPending($connection);

            return redirect()->route('channels.index')->with('success', __('Bu mağaza başka bir hesaba bağlı.'));
        }

        if ($owner !== null && $owner->id !== $connection->id) {
            $settings = $connection->settings ?? [];
            $this->discardPending($connection);
            $connection = $owner;
            $connection->settings = [...$connection->settings ?? [], ...array_intersect_key($settings, [AmazonAdapter::MARKETPLACE_KEY => true])];
        }

        DB::transaction(function () use ($connection, $sellerId, $tokens): void {
            $connection->forceFill([
                'external_account_id' => $sellerId,
                'settings' => [...$connection->settings ?? [], AmazonAdapter::SELLER_ID_KEY => $sellerId],
            ])->save();

            $this->vault->store($connection, $tokens['secrets'], expiresAt: $tokens['expires_at']);

            $this->audit->run(
                action: AuditAction::CHANNEL_CREDENTIAL_UPDATED,
                subjectType: 'channel_connections',
                subjectId: $connection->id,
                changes: ['channel_type_code' => 'amazon', 'secret_keys' => array_keys($tokens['secrets'])],
            );
        });

        $this->checkHealth->run($connection);

        return redirect()->route('channels.index')->with('success', __('Amazon bağlantısı yetkilendirildi.'));
    }

    /** @return array{secrets: array<string, string>, expires_at: \DateTimeImmutable} */
    private function exchange(string $code): array
    {
        $response = Http::asForm()->acceptJson()->timeout(15)->post(AmazonApp::TOKEN_URL, [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => route('channels.amazon.callback'),
            'client_id' => AmazonApp::clientId(),
            'client_secret' => AmazonApp::clientSecret(),
        ]);

        $response->throw();

        $access = $response->json('access_token');
        $refresh = $response->json('refresh_token');

        if (! is_string($access) || $access === '' || ! is_string($refresh) || $refresh === '') {
            throw new RuntimeException('Amazon LWA yanıtı anahtar taşımıyor.');
        }

        return [
            'secrets' => [AmazonAdapter::ACCESS_TOKEN_SECRET => $access, 'refresh_token' => $refresh],
            'expires_at' => new \DateTimeImmutable('@'.(time() + max(60, (int) ($response->json('expires_in') ?? 3600)))),
        ];
    }

    private function findConnection(string $connectionId): ?ChannelConnection
    {
        return ChannelConnection::query()->where('id', $connectionId)->where('channel_type_code', 'amazon')->first();
    }

    private function discardPending(ChannelConnection $connection): void
    {
        if (str_starts_with((string) $connection->external_account_id, ChannelConnectForm::PENDING_ACCOUNT_PREFIX)) {
            $connection->delete();
        }
    }
}
