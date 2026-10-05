<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Billing\Actions\EnforceQuota;
use App\Domain\Billing\Enums\QuotaMetric;
use App\Domain\Billing\Exceptions\QuotaExceededException;
use App\Domain\Channels\Actions\ConnectChannel;
use App\Domain\Channels\Adapters\Shopify\ShopifyAuth;
use App\Domain\Channels\Adapters\Shopify\ShopifyEndpoints;
use App\Domain\Channels\Exceptions\AccountAlreadyConnectedException;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Models\ChannelType;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Shopify'dan BAŞLAYAN kurulum — App Store / mağaza yöneticisinden.
 *
 * Panelden başlayan akış (`ShopifyOAuthController`) satıcının bizde hesabı
 * ve bağlantı satırı olduğunu varsayar. Shopify'dan gelen satıcının ikisi
 * de olmayabilir. Shopify'ın inceleme kuralı: kurulumdan sonra uygulamanın
 * HİÇBİR ekranı görünmeden OAuth yapılır. Bu yüzden sıra:
 *
 *   1. `/shopify` (App URL) — imza + taze zaman damgası → HEMEN OAuth
 *   2. `/shopify/auth/callback` — anahtar alınır, ŞİFRELİ olarak oturumda
 *      bekletilir (henüz kiracı yok); mağaza adı ve e-postası okunur
 *   3. Giriş yoksa kayıt sayfası (alanlar Shopify'dan dolu); varsa doğrudan
 *   4. `/shopify/finish` — kiracıda bağlantı açılır, panel akışının
 *      `complete()`'iyle biter (konum, sağlık, webhook)
 *
 * DÖNÜŞ ADRESİ AYRI (`/shopify/auth/callback`): panel akışınınki oturum ve
 * kiracı ister; kurulumda ikisi de yok. Uygulama ayarlarında İKİSİ DE izinli
 * yönlendirme olarak kayıtlı olmalı.
 *
 * GÜVENLİK: bekleyen anahtar yalnız bu oturumdadır ve tek kullanımlıktır;
 * başka kiracıya bağlı mağaza `ConnectChannel` korumasına takılır.
 */
final class ShopifyInstallController extends Controller
{
    private const SESSION_STATE = 'shopify.install.state';

    private const SESSION_SHOP = 'shopify.install.shop';

    public const SESSION_PENDING = 'shopify.install.pending';

    /** Bekleyen kurulumun ömrü — kayıt + e-posta doğrulaması sığmalı. */
    private const PENDING_TTL = 86400;

    public function launch(Request $request): Response|RedirectResponse
    {
        if (! ShopifyAuth::configured() || ! $this->shopifyOpen()) {
            Log::error('shopify.install.unavailable');
            abort(503, 'Shopify bağlantısı şu an kullanılamıyor.');
        }

        if (! ShopifyAuth::launchRequestValid($request->query())) {
            Log::warning('shopify.install.launch_invalid', ['shop' => $request->query('shop')]);
            abort(400, 'Geçersiz Shopify isteği.');
        }

        $shop = strtolower((string) $request->query('shop'));
        $state = ShopifyAuth::newState();

        $request->session()->put(self::SESSION_STATE, $state);
        $request->session()->put(self::SESSION_SHOP, $shop);

        return redirect()->away(ShopifyAuth::authorizeUrl(
            shop: $shop,
            redirectUri: route('shopify.install.callback'),
            state: $state,
        ));
    }

    public function callback(Request $request): RedirectResponse
    {
        // Tek kullanımlık: sonuç ne olursa olsun oturumdan SİLİNİR.
        $expectedState = $request->session()->pull(self::SESSION_STATE);
        $expectedShop = $request->session()->pull(self::SESSION_SHOP);
        $shop = $request->query('shop');
        $code = $request->query('code');

        $valid = ShopifyAuth::stateMatches(
            is_string($expectedState) ? $expectedState : null,
            is_string($request->query('state')) ? $request->query('state') : null,
        )
            && ShopifyAuth::callbackHmacValid($request->query())
            && is_string($shop) && ShopifyAuth::validShopDomain($shop)
            && is_string($expectedShop) && strcasecmp($shop, $expectedShop) === 0
            && is_string($code) && $code !== '';

        if (! $valid) {
            Log::warning('shopify.install.callback_invalid', ['shop' => is_string($shop) ? $shop : null]);

            return redirect()->route('login')->with(
                'success',
                __('Shopify kurulumu doğrulanamadı. Uygulamayı Shopify yöneticinden yeniden aç.'),
            );
        }

        try {
            $response = Http::asForm()->acceptJson()->timeout(15)
                ->post(ShopifyAuth::tokenUrl($shop), ShopifyAuth::tokenRequest($code));
            $response->throw();
            $credentials = ShopifyAuth::credentialsFrom((array) $response->json());
        } catch (Throwable $e) {
            Log::warning('shopify.install.exchange_failed', ['shop' => $shop, 'error' => $e->getMessage()]);

            return redirect()->route('login')->with(
                'success',
                __('Shopify kimlik bilgisi alınamadı. Uygulamayı Shopify yöneticinden yeniden aç.'),
            );
        }

        $profile = $this->shopProfile(strtolower($shop), $credentials['secrets']['access_token']);

        $request->session()->put(self::SESSION_PENDING, Crypt::encrypt([
            'shop' => strtolower($shop),
            'name' => $profile['name'],
            'email' => $profile['email'],
            'credentials' => $credentials,
            'at' => now()->getTimestamp(),
        ]));

        if (Auth::check()) {
            return redirect()->route('shopify.install.finish');
        }

        // Kayıt/giriş sonrası `intended` buraya döner (e-posta doğrulamasından
        // sonra da — `EmailVerificationController` intended'a gider).
        $request->session()->put('url.intended', route('shopify.install.finish'));

        return redirect()->route('register');
    }

    /** Kiracı bağlamında: bağlantıyı aç ve panel akışıyla bitir. */
    public function finish(Request $request, ConnectChannel $connect): Response|RedirectResponse
    {
        $pending = self::pending($request, pull: true);

        if ($pending === null) {
            return redirect()->route('channels.index')->with(
                'success',
                __('Shopify kurulumunun süresi doldu. Mağazanı buradan yeniden bağlayabilirsin.'),
            );
        }

        $shop = $pending['shop'];

        $existing = ChannelConnection::query()
            ->where('channel_type_code', 'shopify')
            ->where('external_account_id', $shop)
            ->first();

        if ($existing === null) {
            try {
                app(EnforceQuota::class)->check(QuotaMetric::CHANNELS);
            } catch (QuotaExceededException $e) {
                return redirect()->route('billing.index')->with('success', $e->userMessage());
            }
        }

        try {
            $connection = $existing ?? $connect->run(
                channelTypeCode: 'shopify',
                label: $pending['name'] ?? $shop,
                storeUrl: $shop,
                secrets: [],
                checkHealth: false,
            );
        } catch (AccountAlreadyConnectedException) {
            Log::warning('shopify.install.foreign_tenant', ['shop' => $shop, 'tenant' => TenantContext::id()]);

            return redirect()->route('channels.index')->with(
                'success',
                __(':shop başka bir 34Pazar hesabına bağlı. O hesapla giriş yap ya da bizimle iletişime geç.', ['shop' => $shop]),
            );
        }

        return app(ShopifyOAuthController::class)->complete($connection, $pending['credentials']);
    }

    /**
     * Oturumdaki bekleyen kurulum — süresi dolmuş ya da bozuksa null.
     *
     * @return array{shop: string, name: ?string, email: ?string, credentials: array<string, mixed>, at: int}|null
     */
    public static function pending(Request $request, bool $pull = false): ?array
    {
        $raw = $pull
            ? $request->session()->pull(self::SESSION_PENDING)
            : $request->session()->get(self::SESSION_PENDING);

        if (! is_string($raw)) {
            return null;
        }

        try {
            $data = Crypt::decrypt($raw);
        } catch (Throwable) {
            return null;
        }

        if (! is_array($data) || now()->getTimestamp() - (int) ($data['at'] ?? 0) > self::PENDING_TTL) {
            return null;
        }

        return $data;
    }

    /**
     * Mağaza adı ve sahibinin e-postası — kayıt formunu doldurmak için.
     * Başarısızlık kurulumu durdurmaz: form boş açılır.
     *
     * @return array{name: ?string, email: ?string}
     */
    private function shopProfile(string $shop, string $accessToken): array
    {
        try {
            $response = Http::acceptJson()->timeout(10)
                ->withHeaders(['X-Shopify-Access-Token' => $accessToken])
                ->post('https://'.$shop.'/'.str_replace('{version}', ShopifyEndpoints::API_VERSION, ShopifyEndpoints::GRAPHQL), [
                    'query' => '{ shop { name email } }',
                ]);

            $data = $response->successful() ? $response->json('data.shop') : null;
        } catch (Throwable) {
            $data = null;
        }

        $name = is_array($data) && is_string($data['name'] ?? null) && $data['name'] !== '' ? $data['name'] : null;
        $email = is_array($data) && is_string($data['email'] ?? null) && filter_var($data['email'], FILTER_VALIDATE_EMAIL)
            ? strtolower($data['email'])
            : null;

        return ['name' => $name, 'email' => $email];
    }

    private function shopifyOpen(): bool
    {
        return TenantContext::runAsSystem(fn (): bool => ChannelType::query()
            ->where('code', 'shopify')
            ->where('is_active', true)
            ->exists());
    }
}
