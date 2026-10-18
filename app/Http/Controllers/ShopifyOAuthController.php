<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Channels\Actions\CheckChannelHealth;
use App\Domain\Channels\Actions\RegisterChannelWebhooks;
use App\Domain\Channels\Actions\SetOutboundWrite;
use App\Domain\Channels\Adapters\Shopify\ShopifyAdapter;
use App\Domain\Channels\Adapters\Shopify\ShopifyAuth;
use App\Domain\Channels\Enums\OutboundWrite;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Registry\AdapterRegistry;
use App\Domain\Channels\Support\CredentialVault;
use App\Domain\Identity\Actions\RecordAuditLog;
use App\Domain\Identity\Enums\AuditAction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Shopify'ı 34Pazar uygulaması üzerinden bağlar (OAuth, authorization code grant).
 *
 * İskelet `EtsyOAuthController`'ın aynısı (oturumda tek kullanımlık state,
 * bağlantı önce `pending` açılır, kimlik callback'te kasaya yazılır) —
 * gerekçeler orada. Shopify'a özgü olanlar:
 *
 * - GERİ DÖNÜŞ ÜÇ KEZ DOĞRULANIR: `state` (bizim başlattığımız akış mı),
 *   `hmac` (Shopify mı gönderdi), `shop` (bağlantının mağazası mı). `shop`
 *   kontrolü olmasaydı satıcı A'nın akışı satıcı B'nin mağazasıyla
 *   tamamlanabilir ve A, B'nin stoğunu yönetirdi.
 * - STOK KONUMU: tek aktif konum varsa seçilir; birden fazlaysa SEÇTİRİLİR
 *   (`ShopifyAdapter::healthCheck` gerekçesi: varsayılanı sessizce seçmek
 *   çok depolu satıcının stoğunu yanlış depoya yazar). Eskiden satıcı
 *   `gid://shopify/Location/…` değerini elle yazıyordu.
 * - Webhook'lar sağlıklı bağlantıda kurulur (`RegisterChannelWebhooks`).
 */
final class ShopifyOAuthController extends Controller
{
    private const SESSION_STATE = 'shopify.oauth.state';

    private const SESSION_CONNECTION = 'shopify.oauth.connection';

    /** Birden fazla konumlu mağazada seçim listesi (`settings`). */
    public const LOCATION_CHOICES_KEY = 'location_choices';

    public function __construct(
        private readonly CredentialVault $vault,
        private readonly CheckChannelHealth $checkHealth,
        private readonly RecordAuditLog $audit,
        private readonly RegisterChannelWebhooks $registerWebhooks,
        private readonly SetOutboundWrite $writes,
    ) {}

    public function redirect(Request $request, string $connectionId): Response|RedirectResponse
    {
        $connection = $this->findConnection($connectionId);

        if ($connection === null) {
            return redirect()->route('channels.index')->with('success', __('Bağlantı bulunamadı.'));
        }

        if (! ShopifyAuth::configured()) {
            Log::error('shopify.oauth.not_configured');

            return redirect()->route('channels.index')->with(
                'success',
                __('Shopify bağlantısı şu an kullanılamıyor. Lütfen bizimle iletişime geçin.'),
            );
        }

        $state = ShopifyAuth::newState();
        $request->session()->put(self::SESSION_STATE, $state);
        $request->session()->put(self::SESSION_CONNECTION, $connection->id);

        $target = ShopifyAuth::authorizeUrl(
            shop: (string) $connection->external_account_id,
            redirectUri: route('channels.shopify.callback'),
            state: $state,
        );

        if ($request->header('X-Inertia')) {
            return Inertia::location($target);
        }

        return redirect()->away($target);
    }

    public function callback(Request $request): RedirectResponse
    {
        // Tek kullanımlık: sonuç ne olursa olsun oturumdan SİLİNİR.
        $expectedState = $request->session()->pull(self::SESSION_STATE);
        $connectionId = $request->session()->pull(self::SESSION_CONNECTION);

        $fail = fn (string $message, string $log, array $context = []): RedirectResponse => tap(
            redirect()->route('channels.index')->with('success', $message),
            fn () => Log::warning($log, ['connection' => is_string($connectionId) ? $connectionId : null, ...$context]),
        );

        if (! ShopifyAuth::stateMatches(
            is_string($expectedState) ? $expectedState : null,
            is_string($request->query('state')) ? $request->query('state') : null,
        )) {
            return $fail(__('Shopify bağlantısı doğrulanamadı. Lütfen yeniden deneyin.'), 'shopify.oauth.state_mismatch');
        }

        if (! ShopifyAuth::callbackHmacValid($request->query())) {
            return $fail(__('Shopify bağlantısı doğrulanamadı. Lütfen yeniden deneyin.'), 'shopify.oauth.hmac_invalid');
        }

        $connection = is_string($connectionId) ? $this->findConnection($connectionId) : null;

        if ($connection === null) {
            return redirect()->route('channels.index')->with('success', __('Bağlantı bulunamadı.'));
        }

        $shop = $request->query('shop');

        if (! ShopifyAuth::validShopDomain(is_string($shop) ? $shop : null)
            || strcasecmp((string) $shop, (string) $connection->external_account_id) !== 0) {
            return $fail(
                __('Shopify başka bir mağaza için onay verdi. Bağlamak istediğin mağazayla giriş yapıp yeniden dene.'),
                'shopify.oauth.shop_mismatch',
                ['shop' => is_string($shop) ? $shop : null],
            );
        }

        $code = $request->query('code');

        if (! is_string($code) || $code === '') {
            return redirect()->route('channels.index')->with('success', __('Shopify yetkilendirmesi tamamlanmadı.'));
        }

        try {
            $response = Http::asForm()->acceptJson()->timeout(15)
                ->post(ShopifyAuth::tokenUrl((string) $shop), ShopifyAuth::tokenRequest($code));
            $response->throw();
            $credentials = ShopifyAuth::credentialsFrom((array) $response->json());
        } catch (Throwable $e) {
            return $fail(
                __('Shopify kimlik bilgisi alınamadı. Lütfen yeniden deneyin.'),
                'shopify.oauth.exchange_failed',
                ['error' => $e->getMessage()],
            );
        }

        return $this->complete($connection, $credentials);
    }

    /**
     * Anahtar elde → kasaya yaz, konumu çöz, sağlık + webhook. Panelden
     * başlayan akış ve Shopify'dan kurulum (`ShopifyInstallController`)
     * AYNI yoldan biter; iki kopya zamanla ayrışırdı.
     *
     * @param  array{secrets: array<string, string>, expires_at: ?\DateTimeImmutable, refresh_expires_at: ?\DateTimeImmutable, scope: ?string}  $credentials
     */
    public function complete(ChannelConnection $connection, array $credentials): RedirectResponse
    {
        // İLK YETKİLENDİRME mi: sağlık kontrolü `connected_at`'i ilk başarıda
        // yazar. Yeniden yetkilendirme (anahtar yenileme, kapsam değişikliği)
        // satıcının kapattığı izni GERİ AÇMAZ.
        //
        // KALDIRILIP YENİDEN KURULAN uygulama da ilk kurulumdur: `app/uninstalled`
        // bağlantıyı silmez, `inactive` işaretler (RevokeChannelAccess) ve
        // kurulum aynı satırı yeniden kullanır. `connected_at` dolu kaldığı için
        // izinler kapalı kalıyordu — App Store inceleyicisi aynı mağazaya yeniden
        // kurduğunda 2.1.4 reddi tekrar ederdi (18 Eki, ej5ezr-cd ölçüldü).
        $firstAuthorization = $connection->connected_at === null || $connection->status === 'inactive';

        DB::transaction(function () use ($connection, $credentials, $firstAuthorization): void {
            $this->vault->store(
                $connection,
                $credentials['secrets'],
                $credentials['scope'],
                $credentials['expires_at'],
                $credentials['refresh_expires_at'],
            );

            $this->audit->run(
                action: AuditAction::CHANNEL_CREDENTIAL_UPDATED,
                subjectType: 'channel_connections',
                subjectId: $connection->id,
                changes: [
                    'channel_type_code' => 'shopify',
                    'secret_keys' => array_keys($credentials['secrets']),
                ],
            );

            if ($firstAuthorization) {
                $this->grantInstallWrites($connection);
            }
        });

        $this->resolveLocation($connection);

        return $this->finish($connection);
    }

    /**
     * ADR-010 İSTİSNASI — Shopify'da stok ve fiyat gönderimi kurulumla açılır.
     *
     * Satıcı 34Pazar'ı mağazasına KENDİSİ kurdu ve Shopify'ın onay ekranında
     * stok/ürün yazma kapsamlarını onayladı; uygulamanın işi bu ikisi. Kapalı
     * doğduğunda panelde değişen stok Shopify'a hiç gitmiyordu ve App Store
     * incelemesi 2.1.4 (veri senkronu) ile reddetti (16 Eki 2026, ref 140131).
     *
     * ÜRÜN YAYINI KAPALI KALIR: içe aktarılan ürünün içeriği ilk yayında
     * Shopify'dakini ezebilir — o hâlâ satıcının ürün başına açık eylemi.
     * Diğer kanallarda ADR-010 aynen geçerli.
     */
    private function grantInstallWrites(ChannelConnection $connection): void
    {
        foreach ([OutboundWrite::STOCK_SYNC, OutboundWrite::PRICE_SYNC] as $write) {
            $this->writes->run($connection, $write, true);
        }

        Log::info('shopify.install.writes_enabled', ['connection' => $connection->id]);
    }

    /**
     * Birden fazla konumlu mağazada satıcının seçimi.
     */
    public function chooseLocation(Request $request, string $connectionId): RedirectResponse
    {
        $connection = $this->findConnection($connectionId);
        abort_if($connection === null, 404);

        $choices = collect($connection->settings[self::LOCATION_CHOICES_KEY] ?? [])->pluck('id')->all();
        $chosen = $request->validate(['location' => ['required', 'string']])['location'];

        // Liste DIŞI değer kabul edilmez: başka mağazanın konum kimliği
        // yazılırsa stok oraya gitmeye çalışırdı.
        abort_unless(in_array($chosen, $choices, true), 422, __('Geçersiz konum.'));

        $connection->forceFill(['settings' => [
            ...$connection->settings ?? [],
            ShopifyAdapter::LOCATION_KEY => $chosen,
        ]])->save();

        return $this->finish($connection);
    }

    private function finish(ChannelConnection $connection): RedirectResponse
    {
        if (($connection->settings[ShopifyAdapter::LOCATION_KEY] ?? null) === null
            && ($connection->settings[self::LOCATION_CHOICES_KEY] ?? []) !== []) {
            return redirect()->route('channels.index')->with(
                'success',
                __('Shopify bağlandı. Mağazanda birden fazla depo var — stoğun hangi depoya yazılacağını seç.'),
            );
        }

        $connection = $this->checkHealth->run($connection);

        if ($connection->status === 'active') {
            $this->registerWebhooks->run($connection);
        }

        return redirect()->route('channels.index')->with(
            'success',
            $connection->status === 'active'
                ? __('Shopify mağazan bağlandı.')
                : __('Shopify bağlandı ama mağaza yanıt vermedi. "Tekrar dene"ye bas.'),
        );
    }

    /**
     * Tek aktif konum → seçilir. Birden fazla → liste `settings`'e yazılır,
     * seçim satıcıya bırakılır. Konum zaten seçiliyse (yeniden bağlama)
     * DOKUNULMAZ.
     */
    private function resolveLocation(ChannelConnection $connection): void
    {
        $settings = $connection->settings ?? [];

        if (is_string($settings[ShopifyAdapter::LOCATION_KEY] ?? null)) {
            return;
        }

        try {
            $adapter = app(AdapterRegistry::class)->for($connection);
            $locations = $adapter instanceof ShopifyAdapter ? $adapter->fetchLocations() : [];
        } catch (Throwable $e) {
            Log::warning('shopify.oauth.locations_failed', ['connection' => $connection->id, 'error' => $e->getMessage()]);

            return;   // sağlık kontrolü "konum seçilmedi" diyecek; "Tekrar dene" yeniden dener
        }

        $settings[self::LOCATION_CHOICES_KEY] = $locations;

        if (count($locations) === 1) {
            $settings[ShopifyAdapter::LOCATION_KEY] = $locations[0]['id'];
        }

        $connection->forceFill(['settings' => $settings])->save();
    }

    private function findConnection(string $connectionId): ?ChannelConnection
    {
        return ChannelConnection::query()
            ->where('id', $connectionId)
            ->where('channel_type_code', 'shopify')
            ->first();
    }
}
