<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Channels\Actions\CheckChannelHealth;
use App\Domain\Channels\Adapters\Etsy\EtsyAdapter;
use App\Domain\Channels\Adapters\Etsy\EtsyAuth;
use App\Domain\Channels\Adapters\Etsy\EtsyEndpoints;
use App\Domain\Channels\Models\ChannelConnection;
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
 * Etsy OAuth 2 + PKCE bağlama akışı — projede İLK OAuth callback'i.
 *
 * V3.0 · §11.2 · §19 (güvenlik · madde 2) · P0-10 · T-V3-24.
 *
 * ─────────────────────────────────────────────────────────────────────
 * NEDEN `ConnectChannel` KULLANILMIYOR
 * ─────────────────────────────────────────────────────────────────────
 * O action kimlik bilgilerinin ÖNCEDEN elde olduğunu varsayar (satıcı
 * formu doldurur, anahtarlar kasaya yazılır, sağlık kontrolü koşar).
 * OAuth bu sırayı TERSİNE çevirir: bağlantı satırı önce açılır, kimlik
 * ancak satıcı Etsy'de onayladıktan SONRA gelir.
 *
 * Bu yüzden burada iki adım vardır ve İKİSİ AYRI İSTEKTİR:
 *   1. `redirect()`  — el sıkışma sırlarını üretir, oturuma yazar
 *   2. `callback()`  — `state` doğrular, kodu token'a takas eder
 *
 * ─────────────────────────────────────────────────────────────────────
 * ⚠️ ROTA `web` GRUBUNDADIR — WEBHOOK ROTALARININ AKSİNE (§19)
 * ─────────────────────────────────────────────────────────────────────
 * Oturum ZORUNLUDUR: `state` ve `code_verifier` oradan okunur ve
 * kullanıcı kimliği bilinmek zorundadır. Webhook rotaları oturumsuzdur
 * ve muafiyetin bedelini HMAC ile öder; burada ödeyen şey OTURUMUN
 * KENDİSİDİR.
 */
final class EtsyOAuthController extends Controller
{
    /** Oturum anahtarları — bağlantı başına ayrı tutulur. */
    private const SESSION_STATE = 'etsy.oauth.state';

    private const SESSION_VERIFIER = 'etsy.oauth.code_verifier';

    private const SESSION_CONNECTION = 'etsy.oauth.connection';

    public function __construct(
        private readonly CredentialVault $vault,
        private readonly CheckChannelHealth $checkHealth,
        private readonly RecordAuditLog $audit,
    ) {}

    /**
     * Satıcıyı Etsy'nin yetkilendirme ekranına gönderir.
     *
     * ⚠️ `code_verifier` OTURUMDA YAŞAR, KALICI DEPOYA YAZILMAZ (§11.2).
     * Tek kullanımlık bir sırdır ve yalnızca bu iki istek arasında
     * anlamlıdır. `channel_credentials`'a yazılsaydı kasada hiçbir zaman
     * geçerli olmayacak ölü bir sır birikirdi; `settings`'e yazılsaydı
     * ŞİFRESİZ bir kolona düşer ve panele Inertia prop'u olarak giderdi.
     */
    public function redirect(Request $request, string $connectionId): Response|RedirectResponse
    {
        $connection = $this->findConnection($connectionId);

        if ($connection === null) {
            return redirect()->route('channels.index')
                ->with('success', 'Bağlantı bulunamadı.');
        }

        $handshake = EtsyAuth::newHandshake();

        // Oturuma YAZILIR — callback bunları geri okur.
        $request->session()->put(self::SESSION_STATE, $handshake['state']);
        $request->session()->put(self::SESSION_VERIFIER, $handshake['code_verifier']);
        $request->session()->put(self::SESSION_CONNECTION, $connection->id);

        $target = EtsyAuth::authorizeUrl(
            keystring: $this->keystring($connection),
            redirectUri: route('channels.etsy.callback'),
            state: $handshake['state'],
            codeVerifier: $handshake['code_verifier'],
        );

        // ⚠️ INERTIA İSTEĞİ DIŞ ADRESE 302 İLE GÖNDERİLEMEZ.
        //
        // Panel formu bu akışı bir Inertia XHR'ı olarak başlatır ve XHR
        // bir 302'yi ŞEFFAF olarak izler: tarayıcı Etsy'nin HTML'ini
        // alır, Inertia onu bir sayfa yanıtı sanmaz ve ekranda HAM JSON
        // (ya da boş sayfa) kalır — satıcı Etsy'yi HİÇ GÖRMEZ.
        // Inertia'nın bunun için ayrı bir sözleşmesi vardır: 409 +
        // `X-Inertia-Location`, istemci onu TAM SAYFA gezinmesine
        // çevirir.
        //
        // GERÇEK TARAYICI ÇALIŞTIRMASINDA bulundu — testler göremez,
        // çünkü `assertRedirect` bir XHR'ın yönlendirmeyi nasıl
        // izlediğini modellemez.
        //
        // Rota DOĞRUDAN da çağrılabilir (Inertia başlığı olmayan düz bir
        // POST); o hâlde normal 302 doğru cevaptır ve korunur.
        if ($request->header('X-Inertia')) {
            return Inertia::location($target);
        }

        return redirect()->away($target);
    }

    /**
     * Etsy'den dönen yetkilendirme kodunu token'a takas eder.
     *
     * ⚠️ `state` DOĞRULAMASI HER ŞEYDEN ÖNCE (P0-10 · T-V3-24).
     * Doğrulanmazsa saldırgan KENDİ yetkilendirme kodunu kurbanın
     * oturumuna enjekte eder ve **kurbanın kiracısına KENDİ mağazasını
     * bağlar** — CSRF'in OAuth'taki biçimi. O noktadan sonra kurbanın
     * stoğu saldırganın mağazasına akar.
     *
     * ⚠️ SIRLAR TEK KULLANIMLIKTIR ve doğrulama SONUCU NE OLURSA OLSUN
     * oturumdan SİLİNİR. Silinmeseydi çalınmış bir `state` ikinci kez
     * denenebilirdi.
     */
    public function callback(Request $request): RedirectResponse
    {
        $expectedState = $request->session()->pull(self::SESSION_STATE);
        $verifier = $request->session()->pull(self::SESSION_VERIFIER);
        $connectionId = $request->session()->pull(self::SESSION_CONNECTION);

        // ① `state` — P0-10. Boş değer DAİMA reddedilir.
        if (! EtsyAuth::stateMatches(
            is_string($expectedState) ? $expectedState : null,
            $request->query('state') === null ? null : (string) $request->query('state'),
        )) {
            Log::warning('etsy.oauth.state_mismatch', [
                'connection' => is_string($connectionId) ? $connectionId : null,
            ]);

            return redirect()->route('channels.index')->with(
                'success',
                'Etsy bağlantısı doğrulanamadı (state uyuşmadı). Lütfen '.
                'yeniden deneyin.',
            );
        }

        // ② Etsy hata döndürdüyse kod YOKTUR — sebep satıcıya söylenir.
        $code = $request->query('code');

        if (! is_string($code) || $code === '' || ! is_string($verifier)) {
            return redirect()->route('channels.index')->with(
                'success',
                'Etsy yetkilendirmesi tamamlanmadı.',
            );
        }

        $connection = is_string($connectionId) ? $this->findConnection($connectionId) : null;

        if ($connection === null) {
            return redirect()->route('channels.index')
                ->with('success', 'Bağlantı bulunamadı.');
        }

        try {
            $secrets = $this->exchange($connection, $code, $verifier);
        } catch (Throwable $e) {
            Log::warning('etsy.oauth.exchange_failed', [
                'connection' => $connection->id,
                'error' => $e->getMessage(),
            ]);

            return redirect()->route('channels.index')->with(
                'success',
                'Etsy kimlik bilgisi alınamadı. Lütfen yeniden deneyin.',
            );
        }

        DB::transaction(function () use ($connection, $secrets): void {
            // Kimlik bilgisi KASAYA yazılır — `settings`'e ASLA (§19 · 3).
            $this->vault->store(
                $connection,
                $secrets['secrets'],
                expiresAt: $secrets['expires_at'],
            );

            // DENETİM KAYDI — YÜKE SIR KONMAZ, yalnızca anahtar ADLARI.
            $this->audit->run(
                action: AuditAction::CHANNEL_CREDENTIAL_UPDATED,
                subjectType: 'channel_connections',
                subjectId: $connection->id,
                changes: [
                    'channel_type_code' => 'etsy',
                    'secret_keys' => array_keys($secrets['secrets']),
                ],
            );
        });

        // Sağlık kontrolü transaction DIŞINDA: ağ çağrısı transaction
        // tutmaz (`ConnectChannel`'ın kuralının aynısı).
        $this->checkHealth->run($connection);

        return redirect()->route('channels.index')
            ->with('success', 'Etsy bağlantısı yetkilendirildi.');
    }

    /**
     * Yetkilendirme kodunu token'a takas eder.
     *
     * ⚠️ BU ÇAĞRI `ChannelHttpClient` ÜZERİNDEN GİTMEZ ve bu bilinçlidir:
     * istemci kasadaki `access_token`'ı Bearer olarak eklerdi, oysa
     * BURADA HENÜZ TOKEN YOKTUR. Ayrıca token uç noktası kimlik
     * doğrulaması istemez — kimliği `client_id` + `code_verifier` taşır.
     *
     * @return array{secrets: array<string, mixed>, expires_at: \DateTimeImmutable|null}
     */
    private function exchange(ChannelConnection $connection, string $code, string $verifier): array
    {
        $response = Http::asJson()
            ->acceptJson()
            ->timeout(15)
            ->post(
                EtsyEndpoints::url(EtsyEndpoints::TOKEN),
                EtsyAuth::tokenRequest(
                    keystring: $this->keystring($connection),
                    redirectUri: route('channels.etsy.callback'),
                    code: $code,
                    codeVerifier: $verifier,
                ),
            );

        $response->throw();

        /** @var array<string, mixed> $body */
        $body = $response->json();

        $access = $body['access_token'] ?? null;
        $refresh = $body['refresh_token'] ?? null;

        if (! is_string($access) || $access === '') {
            throw new \RuntimeException('Etsy yanıtı access token taşımıyor.');
        }

        // Biçim doğrulanır: bozuksa SESSİZCE geçmez (`EtsyAuth`).
        EtsyAuth::userIdFromToken($access);

        $seconds = $body['expires_in'] ?? null;

        return [
            'secrets' => array_filter([
                'access_token' => $access,
                'refresh_token' => is_string($refresh) ? $refresh : null,
            ], static fn (mixed $v): bool => $v !== null),
            'expires_at' => is_int($seconds) || (is_string($seconds) && ctype_digit($seconds))
                ? new \DateTimeImmutable('@'.(time() + (int) $seconds))
                : null,
        ];
    }

    /**
     * Bağlantıyı KİRACI KAPSAMINDA bulur.
     *
     * ⚠️ ROTA MODEL BAĞLAMASI KULLANILMAZ (sipariş ekranının kuralı):
     * `SubstituteBindings` `web` grubundadır ve rota seviyesindeki
     * `tenant` ara katmanından ÖNCE çalışır; bağlama kullanılsaydı sorgu
     * kiracı bağlamı kurulmadan atılır ve izolasyon istisnası fırlatırdı.
     *
     * Kapsamlı sorgu ayrıca YETKİLENDİRMEDİR: başka kiracının bağlantı
     * kimliğini adres çubuğuna yazan biri onu BULAMAZ.
     */
    private function findConnection(string $connectionId): ?ChannelConnection
    {
        return ChannelConnection::query()
            ->where('id', $connectionId)
            ->where('channel_type_code', 'etsy')
            ->first();
    }

    /**
     * Uygulama anahtarı — `settings` içinde, KİMLİK olarak.
     *
     * Sır değildir (§19 · madde 4: kimlik ≠ sır); token'lar kasadadır.
     */
    private function keystring(ChannelConnection $connection): string
    {
        $settings = $connection->settings;
        $keystring = is_array($settings) ? ($settings[EtsyAdapter::KEYSTRING_KEY] ?? null) : null;

        if (! is_string($keystring) || $keystring === '') {
            throw new \RuntimeException(
                'Etsy uygulama anahtarı (keystring) tanımsız.'
            );
        }

        return $keystring;
    }
}
