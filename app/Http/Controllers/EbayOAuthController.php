<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Channels\Actions\CheckChannelHealth;
use App\Domain\Channels\Adapters\Ebay\EbayAdapter;
use App\Domain\Channels\Adapters\Ebay\EbayAuth;
use App\Domain\Channels\Adapters\Ebay\EbayEndpoints;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Support\CredentialVault;
use App\Domain\Identity\Actions\RecordAuditLog;
use App\Domain\Identity\Enums\AuditAction;
use DateTimeImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * eBay OAuth 2 bağlama akışı — projedeki İKİNCİ OAuth callback'i.
 *
 * V3.0 · §13.3 · §24 (güvenlik · madde 2) · P0-10.
 *
 * ─────────────────────────────────────────────────────────────────────
 * ⚠️ ETSY CONTROLLER'INDAN KOPYALANAMAZ — DÖRT NOKTADA AYRILIR
 * ─────────────────────────────────────────────────────────────────────
 * Akışın İSKELETİ aynıdır (iki adım, iki ayrı istek, `web` grubu) ama
 * içerik ayrışır ve her ayrım sessiz bir hataya karşılık gelir:
 *
 *   1. **PKCE YOK** — oturumda `code_verifier` TUTULMAZ. Etsy'den
 *      kopyalanıp üretilseydi hiç kullanılmayan ölü bir sır oturuma
 *      yazılır, `authorizeUrl()`'e geçirilmeye çalışılır ve eBay
 *      `code_challenge` parametresini KABUL ETMEDİĞİ için istek
 *      REDDEDİLİRDİ.
 *   2. **İSTEMCİ KİMLİĞİ `Authorization: Basic`** başlığındadır, gövdede
 *      değil (§13.3). Gövdeye yazılsaydı eBay isteği reddederdi.
 *   3. **GÖVDE FORM-ENCODED** (RFC 6749 · §4.1.3). Etsy'nin uç noktası
 *      JSON'u da kabul ettiği için bu fark beşinci kanalda HİÇ
 *      görünmemişti; eBay JSON gövdesindeki alanları HİÇ OKUMAZ ve
 *      `invalid_request` döner — sebebi de gövdede görünmez.
 *   4. **`client_id` KASADADIR**, `settings`'te değil. Etsy'nin
 *      keystring'i tek başına bir kimliktir ve şifresiz kolonda durur;
 *      eBay'de `client_id` + `client_secret` AYRILMAZ bir Basic auth
 *      çiftidir ve ikisi tek yerde durmazsa biri güncellenip öteki eski
 *      kalabilir.
 *
 * ─────────────────────────────────────────────────────────────────────
 * ⚠️ ROTA `web` GRUBUNDADIR — WEBHOOK ROTALARININ AKSİNE (§24)
 * ─────────────────────────────────────────────────────────────────────
 * Oturum ZORUNLUDUR: `state` oradan okunur ve kullanıcı kimliği bilinmek
 * zorundadır. Webhook rotaları oturumsuzdur ve muafiyetin bedelini HMAC
 * ile öder; burada ödeyen şey OTURUMUN KENDİSİDİR.
 */
final class EbayOAuthController extends Controller
{
    /**
     * Oturum anahtarları.
     *
     * ⚠️ `code_verifier` ANAHTARI YOKTUR — eBay PKCE KULLANMAZ.
     * Etsy'den kopyalanıp eklenseydi hiçbir zaman okunmayan bir oturum
     * anahtarı birikirdi.
     */
    private const SESSION_STATE = 'ebay.oauth.state';

    private const SESSION_CONNECTION = 'ebay.oauth.connection';

    public function __construct(
        private readonly CredentialVault $vault,
        private readonly CheckChannelHealth $checkHealth,
        private readonly RecordAuditLog $audit,
    ) {}

    /**
     * Satıcıyı eBay'in yetkilendirme ekranına gönderir.
     *
     * ⚠️ `state` OTURUMDA YAŞAR ve TEK KULLANIMLIKTIR (P0-10).
     */
    public function redirect(Request $request, string $connectionId): Response|RedirectResponse
    {
        $connection = $this->findConnection($connectionId);

        if ($connection === null) {
            return redirect()->route('channels.index')
                ->with('success', 'Bağlantı bulunamadı.');
        }

        try {
            $clientId = $this->clientId($connection);
        } catch (Throwable) {
            // ⚠️ KİMLİKSİZ YÖNLENDİRME YAPILMAZ. `client_id` olmadan
            // gidilseydi eBay "invalid_client" ekranı gösterir ve satıcı
            // sebebini ANLAYAMAZDI — oysa eksik olan bizdeki bir alandır.
            return redirect()->route('channels.index')->with(
                'success',
                'eBay uygulama kimliği tanımsız. Bağlantıyı düzenleyip '.
                'App ID ve Cert ID değerlerini gir.',
            );
        }

        $handshake = EbayAuth::newHandshake();

        $request->session()->put(self::SESSION_STATE, $handshake['state']);
        $request->session()->put(self::SESSION_CONNECTION, $connection->id);

        $target = EbayAuth::authorizeUrl(
            clientId: $clientId,
            redirectUri: $this->redirectUri($connection),
            state: $handshake['state'],
            sandbox: $this->useSandbox($connection),
        );

        // ⚠️ INERTIA İSTEĞİ DIŞ ADRESE 302 İLE GÖNDERİLEMEZ.
        //
        // XHR bir 302'yi ŞEFFAF izler: tarayıcı eBay'in HTML'ini alır,
        // Inertia onu sayfa yanıtı saymaz ve ekranda HAM JSON kalır —
        // satıcı eBay'i HİÇ GÖRMEZ. Sözleşme 409 + `X-Inertia-Location`.
        //
        // Inertia başlığı olmayan düz POST için normal 302 KORUNUR.
        if ($request->header('X-Inertia')) {
            return Inertia::location($target);
        }

        return redirect()->away($target);
    }

    /**
     * eBay'den dönen yetkilendirme kodunu token'a takas eder.
     *
     * ⚠️ `state` DOĞRULAMASI HER ŞEYDEN ÖNCE (P0-10). Doğrulanmazsa
     * saldırgan KENDİ yetkilendirme kodunu kurbanın oturumuna enjekte
     * eder ve **kurbanın kiracısına KENDİ mağazasını bağlar** — CSRF'in
     * OAuth'taki biçimi. O noktadan sonra kurbanın stoğu saldırganın
     * mağazasına akar.
     *
     * ⚠️ SIRLAR TEK KULLANIMLIKTIR ve doğrulama SONUCU NE OLURSA OLSUN
     * oturumdan SİLİNİR; silinmeseydi çalınmış bir `state` ikinci kez
     * denenebilirdi.
     */
    public function callback(Request $request): RedirectResponse
    {
        $expectedState = $request->session()->pull(self::SESSION_STATE);
        $connectionId = $request->session()->pull(self::SESSION_CONNECTION);

        // ① `state` — P0-10. Boş değer DAİMA reddedilir.
        if (! EbayAuth::stateMatches(
            is_string($expectedState) ? $expectedState : null,
            $request->query('state') === null ? null : (string) $request->query('state'),
        )) {
            Log::warning('ebay.oauth.state_mismatch', [
                'connection' => is_string($connectionId) ? $connectionId : null,
            ]);

            return redirect()->route('channels.index')->with(
                'success',
                'eBay bağlantısı doğrulanamadı (state uyuşmadı). Lütfen '.
                'yeniden deneyin.',
            );
        }

        // ② eBay hata döndürdüyse kod YOKTUR — sebep satıcıya söylenir.
        $code = $request->query('code');

        if (! is_string($code) || $code === '') {
            return redirect()->route('channels.index')->with(
                'success',
                'eBay yetkilendirmesi tamamlanmadı.',
            );
        }

        $connection = is_string($connectionId) ? $this->findConnection($connectionId) : null;

        if ($connection === null) {
            return redirect()->route('channels.index')
                ->with('success', 'Bağlantı bulunamadı.');
        }

        try {
            $exchanged = $this->exchange($connection, $code);
        } catch (Throwable $e) {
            Log::warning('ebay.oauth.exchange_failed', [
                'connection' => $connection->id,
                'error' => $e->getMessage(),
            ]);

            return redirect()->route('channels.index')->with(
                'success',
                'eBay kimlik bilgisi alınamadı. Lütfen yeniden deneyin.',
            );
        }

        DB::transaction(function () use ($connection, $exchanged): void {
            // ⚠️ İSTEMCİ ÇİFTİ KORUNUR. Kasa `store()` sır kümesini TAM
            // yazar; `client_id`/`client_secret` eklenmeseydi token
            // yenileme SONRAKİ turda kimliksiz kalır ve bağlantı iki saat
            // sonra sessizce ölürdü.
            $this->vault->store(
                $connection,
                $exchanged['secrets'],
                expiresAt: $exchanged['expires_at'],
            );

            // DENETİM KAYDI — YÜKE SIR KONMAZ, yalnızca anahtar ADLARI.
            $this->audit->run(
                action: AuditAction::CHANNEL_CREDENTIAL_UPDATED,
                subjectType: 'channel_connections',
                subjectId: $connection->id,
                changes: [
                    'channel_type_code' => 'ebay',
                    'secret_keys' => array_keys($exchanged['secrets']),
                ],
            );
        });

        // Sağlık kontrolü transaction DIŞINDA: ağ çağrısı transaction
        // tutmaz (`ConnectChannel`'ın kuralının aynısı).
        $this->checkHealth->run($connection);

        return redirect()->route('channels.index')
            ->with('success', 'eBay bağlantısı yetkilendirildi.');
    }

    /**
     * Yetkilendirme kodunu token'a takas eder.
     *
     * ⚠️ BU ÇAĞRI `ChannelHttpClient` ÜZERİNDEN GİTMEZ ve bu bilinçlidir
     * (Etsy'deki kararın aynısı): istemci kasadaki `access_token`'ı
     * Bearer olarak eklerdi, oysa BURADA HENÜZ TOKEN YOKTUR. Yenileme
     * yolu (`EbayAdapter::refreshCredentials()`) istemciyi KULLANIR
     * çünkü orada token vardır ve §25'in metriği o çağrıyı ölçmelidir.
     *
     * ⚠️ `asForm()` ZORUNLUDUR ve `Authorization: Basic` başlıkta gider.
     *
     * @return array{secrets: array<string, mixed>, expires_at: DateTimeImmutable|null}
     */
    private function exchange(ChannelConnection $connection, string $code): array
    {
        $clientId = $this->clientId($connection);
        $clientSecret = $this->clientSecret($connection);

        $response = Http::asForm()
            ->acceptJson()
            ->timeout(15)
            ->withHeaders([
                'Authorization' => EbayAuth::basicAuthHeader($clientId, $clientSecret),
            ])
            ->post(
                EbayEndpoints::url(
                    EbayEndpoints::TOKEN,
                    sandbox: $this->useSandbox($connection),
                ),
                EbayAuth::tokenRequest(
                    redirectUri: $this->redirectUri($connection),
                    code: $code,
                ),
            );

        $response->throw();

        /** @var array<string, mixed> $body */
        $body = $response->json();

        $access = $body['access_token'] ?? null;
        $refresh = $body['refresh_token'] ?? null;

        if (! is_string($access) || $access === '') {
            throw new RuntimeException('eBay yanıtı access token taşımıyor.');
        }

        // ⚠️ REFRESH TOKEN ZORUNLUDUR — İLK takasta eBay onu DAİMA döner.
        // Yoksa bağlantı iki saat sonra ölür ve satıcı sebebini bulamaz;
        // sessizce kabul etmek o ölümü GARANTİ ederdi.
        if (! is_string($refresh) || $refresh === '') {
            throw new RuntimeException('eBay yanıtı refresh token taşımıyor.');
        }

        $seconds = $body['expires_in'] ?? null;

        return [
            'secrets' => [
                // İstemci çifti KORUNUR — yenileme onlarsız çalışamaz.
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'access_token' => $access,
                'refresh_token' => $refresh,
            ],
            'expires_at' => is_int($seconds) || (is_string($seconds) && ctype_digit($seconds))
                ? new DateTimeImmutable('@'.(time() + (int) $seconds))
                : null,
        ];
    }

    /**
     * Bağlantıyı KİRACI KAPSAMINDA bulur.
     *
     * ⚠️ ROTA MODEL BAĞLAMASI KULLANILMAZ: `SubstituteBindings` `web`
     * grubundadır ve rota seviyesindeki `tenant` ara katmanından ÖNCE
     * çalışır; bağlama kullanılsaydı sorgu kiracı bağlamı kurulmadan
     * atılır ve izolasyon istisnası fırlatırdı.
     *
     * Kapsamlı sorgu ayrıca YETKİLENDİRMEDİR: başka kiracının bağlantı
     * kimliğini adres çubuğuna yazan biri onu BULAMAZ.
     */
    private function findConnection(string $connectionId): ?ChannelConnection
    {
        return ChannelConnection::query()
            ->where('id', $connectionId)
            ->where('channel_type_code', 'ebay')
            ->first();
    }

    /**
     * `redirect_uri` — eBay'de HAM ADRES DEĞİL "RuName"dir (§13.3).
     *
     * eBay geliştirici panelinde tanımlanan takma addır ve gerçek adres
     * orada saklanır; ham callback adresi gönderilseydi `invalid_request`
     * alınırdı.
     *
     * ⚠️ BAĞLANTIDAN OKUNUR, UYDURULMAZ. Satıcının kendi RuName'i
     * kendi uygulamasına aittir; sabit yazılsaydı yalnızca TEK bir
     * geliştirici hesabı çalışırdı.
     */
    private function redirectUri(ChannelConnection $connection): string
    {
        $settings = $connection->settings;
        $ruName = is_array($settings) ? ($settings[EbayAdapter::RU_NAME_KEY] ?? null) : null;

        if (! is_string($ruName) || $ruName === '') {
            throw new RuntimeException(
                'eBay RuName tanımsız — yetkilendirme adresi kurulamaz.'
            );
        }

        return $ruName;
    }

    private function useSandbox(ChannelConnection $connection): bool
    {
        $settings = $connection->settings;

        return is_array($settings)
            && ($settings[EbayAdapter::SANDBOX_KEY] ?? false) === true;
    }

    /**
     * İstemci kimliği — KASADAN okunur (Etsy'nin keystring'inin AKSİNE).
     *
     * ⚠️ SİSTEM BAĞLAMINDA OKUNMAZ ve buna gerek YOKTUR: bu controller
     * her zaman oturum açmış bir satıcının isteğinde çalışır ve kiracı
     * bağlamı kuruludur. `EbayAdapter` ise kuyruk işinden çağrılabilir ve
     * orada `runAsSystem()` ZORUNLUDUR.
     */
    private function clientId(ChannelConnection $connection): string
    {
        return $this->requiredSecret($connection, 'client_id', 'uygulama kimliği (App ID)');
    }

    private function clientSecret(ChannelConnection $connection): string
    {
        return $this->requiredSecret($connection, 'client_secret', 'uygulama sırrı (Cert ID)');
    }

    private function requiredSecret(
        ChannelConnection $connection,
        string $key,
        string $label,
    ): string {
        $value = $this->vault->read($connection)[$key] ?? null;

        if (! is_string($value) || $value === '') {
            throw new RuntimeException("eBay {$label} tanımsız.");
        }

        return $value;
    }
}
