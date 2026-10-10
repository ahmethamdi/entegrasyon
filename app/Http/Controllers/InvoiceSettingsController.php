<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Channels\Contracts\SupportsInvoiceData;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Identity\Actions\RecordAuditLog;
use App\Domain\Identity\Enums\AuditAction;
use App\Domain\Invoicing\Exceptions\InvoiceProviderException;
use App\Domain\Invoicing\Models\InvoiceAccount;
use App\Domain\Invoicing\Providers\Parasut\ParasutApp;
use App\Domain\Invoicing\Providers\Parasut\ParasutAuth;
use App\Domain\Invoicing\Providers\Parasut\ParasutClient;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Fatura ve muhasebe ayarları — Paraşüt hesabını bağlama, firma ve seri,
 * kip (e-belge / yalnız muhasebe), otomatik aktarım, tahsilat hesapları.
 *
 * ⚠️ KOMİSYON VE KARGO GİDERİ BURADAN AKTARILMAZ: Trendyol onları aylık
 * e-fatura olarak keser ve fatura Paraşüt'ün gelen kutusuna kendiliğinden
 * düşer; sipariş başına da yazılsaydı gider iki kez sayılırdı
 * (`ParasutInvoiceProvider` başlığı).
 *
 * OAuth iskeleti Etsy'ninkiyle aynıdır (`EtsyOAuthController`): yönlendirme
 * POST (yan etkisi var: oturuma tek kullanımlık `state` yazar), callback
 * GET (biçimi Paraşüt belirler), `state` doğrulaması HER ŞEYDEN ÖNCE —
 * doğrulanmasaydı saldırgan kendi yetkilendirme kodunu kurbanın oturumuna
 * sokar ve kurbanın faturaları SALDIRGANIN Paraşüt firmasına kesilirdi.
 *
 * Token'lar `InvoiceAccount::credentials`'ta ŞİFRELİ durur ve ekrana
 * yalnız durum, firma adı ve seri gider.
 */
final class InvoiceSettingsController extends Controller
{
    private const SESSION_STATE = 'parasut.oauth.state';

    public function __construct(private readonly RecordAuditLog $audit) {}

    public function index(): InertiaResponse
    {
        $account = InvoiceAccount::query()->first();

        [$ledgerAccounts, $ledgerError] = $account !== null && $account->isUsable()
            ? $this->ledgerAccountsForScreen($account)
            : [[], null];

        return Inertia::render('Settings/Invoicing', [
            'available' => ParasutApp::configured(),
            'account' => $account === null ? null : [
                'provider' => $account->provider,
                'status' => $account->status,
                'companyId' => $account->company_id,
                'companyName' => $account->company_name,
                'companies' => array_values((array) ($account->settings['companies'] ?? [])),
                'invoiceSeries' => $account->setting(InvoiceAccount::SETTING_SERIES),
                'lastError' => $account->last_error,
                'mode' => $account->mode(),
                'autoIssue' => $account->autoIssue(),
                'autoIssueSince' => $account->autoIssueSince()?->toIso8601String(),
                'paymentAccounts' => (object) array_filter(
                    (array) ($account->settings[InvoiceAccount::SETTING_PAYMENT_ACCOUNTS] ?? []),
                    static fn (mixed $v): bool => is_scalar($v) && (string) $v !== '',
                ),
            ],
            'channels' => $this->invoicingChannels(),
            'ledgerAccounts' => $ledgerAccounts,
            'ledgerAccountsError' => $ledgerError,
        ]);
    }

    public function redirect(Request $request): Response|RedirectResponse
    {
        if (! ParasutApp::configured()) {
            return back()->withErrors(['parasut' => __('Paraşüt bağlantısı henüz açık değil.')]);
        }

        $state = Str::random(40);
        $request->session()->put(self::SESSION_STATE, $state);

        $target = ParasutAuth::authorizeUrl(route(ParasutAuth::REDIRECT_ROUTE), $state);

        // Inertia XHR'ı dış adrese 302'yi izleyemez (Etsy'de bulundu).
        if ($request->header('X-Inertia')) {
            return Inertia::location($target);
        }

        return redirect()->away($target);
    }

    public function callback(Request $request): RedirectResponse
    {
        // Tek kullanımlık: sonuç ne olursa olsun oturumdan silinir.
        $expected = $request->session()->pull(self::SESSION_STATE);
        $given = $request->query('state');

        if (! is_string($expected) || $expected === '' || ! is_string($given) || ! hash_equals($expected, $given)) {
            Log::warning('parasut.oauth.state_mismatch');

            return $this->back(__('Paraşüt bağlantısı doğrulanamadı. Lütfen yeniden deneyin.'));
        }

        $code = $request->query('code');

        if (! is_string($code) || $code === '') {
            return $this->back(__('Paraşüt yetkilendirmesi tamamlanmadı.'));
        }

        try {
            $token = ParasutAuth::exchangeCode($code, route(ParasutAuth::REDIRECT_ROUTE));
            $companies = ParasutAuth::companies($token['credentials']['access_token']);
        } catch (InvoiceProviderException $e) {
            Log::warning('parasut.oauth.exchange_failed', ['error' => $e->getMessage()]);

            return $this->back(__('Paraşüt kimlik bilgisi alınamadı. Lütfen yeniden deneyin.'));
        }

        if ($companies === []) {
            return $this->back(__('Paraşüt hesabında firma bulunamadı.'));
        }

        $account = InvoiceAccount::query()->firstOrNew(['tenant_id' => TenantContext::idOrFail()]);

        // Tek firma → doğrudan seçilir; birden çoksa satıcı seçer. Yeniden
        // bağlamada önceki seçim listede hâlâ varsa korunur.
        $only = count($companies) === 1
            ? $companies[0]
            : collect($companies)->firstWhere('id', $account->company_id);

        $account->forceFill([
            'provider' => InvoiceAccount::PROVIDER_PARASUT,
            'credentials' => $token['credentials'],
            'token_expires_at' => $token['expires_at'],
            'company_id' => $only['id'] ?? null,
            'company_name' => $only['name'] ?? null,
            'settings' => [...$account->settings ?? [], 'companies' => $companies],
            'status' => $only === null ? InvoiceAccount::STATUS_NEEDS_COMPANY : InvoiceAccount::STATUS_CONNECTED,
            'last_error' => null,
        ])->save();

        $this->audit->run(
            action: AuditAction::INVOICE_ACCOUNT_CONNECTED,
            subjectType: 'invoice_accounts',
            subjectId: $account->id,
            changes: ['provider' => InvoiceAccount::PROVIDER_PARASUT, 'company_id' => $account->company_id],
            userId: $request->user()?->id,
        );

        return $this->back($only === null
            ? __('Paraşüt bağlandı. Fatura kesilecek firmayı seçin.')
            : __('Paraşüt bağlandı.'));
    }

    /**
     * Firma, seri, kip, otomatik aktarım ve tahsilat hesapları.
     *
     * Firma yalnız Paraşüt'ün döndürdüğü listeden seçilir. Kip, otomatik
     * aktarım ve tahsilat alanları GÖNDERİLMEZSE eski değer korunur (firma
     * seçimi bekleyen hesabın formunda bu bölümler yoktur).
     *
     * TAHSİLAT HESABI DA YALNIZ LİSTEDEN: kayıtta Paraşüt'e yeniden sorulur.
     * Ekrandan gelen kimliğe güvenilseydi elle yazılmış bir kimlik başka bir
     * hesaba (ya da hiç olmayan bir hesaba) tahsilat işletirdi — hata ancak
     * ilk siparişte, satıcının görmediği bir iş içinde çıkardı.
     */
    public function update(Request $request): RedirectResponse
    {
        $account = InvoiceAccount::query()->firstOrFail();

        $companies = collect((array) ($account->settings['companies'] ?? []));

        $validated = $request->validate([
            'company_id' => ['required', 'string', Rule::in($companies->pluck('id')->all())],
            'invoice_series' => ['nullable', 'string', 'max:16', 'regex:/^[A-Za-z0-9]*$/'],
            'mode' => ['sometimes', 'string', Rule::in(InvoiceAccount::MODES)],
            'auto_issue' => ['sometimes', 'string', Rule::in(InvoiceAccount::AUTO_ISSUE_OPTIONS)],
            'payment_accounts' => ['sometimes', 'array'],
            'payment_accounts.*' => ['nullable', 'string', 'max:64'],
        ]);

        $company = $companies->firstWhere('id', $validated['company_id']);
        $series = trim((string) ($validated['invoice_series'] ?? ''));

        $settings = [...$account->settings ?? [], InvoiceAccount::SETTING_SERIES => $series === '' ? null : strtoupper($series)];

        if (array_key_exists('mode', $validated)) {
            $settings[InvoiceAccount::SETTING_MODE] = $validated['mode'];
        }

        if (array_key_exists('auto_issue', $validated)) {
            $settings = $this->withAutoIssue($account, $settings, $validated['auto_issue']);
        }

        if (array_key_exists('payment_accounts', $validated)) {
            $settings[InvoiceAccount::SETTING_PAYMENT_ACCOUNTS] = $this->validatedPaymentAccounts($account, (array) $validated['payment_accounts']);
        }

        $account->forceFill([
            'company_id' => $validated['company_id'],
            'company_name' => $company['name'] ?? null,
            'settings' => $settings,
            // Firma seçimi bekleyen hesap seçimle açılır; kopmuş hesap
            // ise ancak yeniden bağlanarak açılır, burada değil.
            'status' => $account->status === InvoiceAccount::STATUS_NEEDS_COMPANY ? InvoiceAccount::STATUS_CONNECTED : $account->status,
        ])->save();

        return back()->with('success', __('Fatura ve muhasebe ayarları kaydedildi.'));
    }

    /**
     * Bağlantıyı kaldırır — token'lar silinir. Kesilmiş faturaların
     * kaydı (`invoices`) KALIR: satıcı hangi siparişin faturalandığını
     * görmeye devam eder.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $account = InvoiceAccount::query()->first();

        if ($account !== null) {
            $account->delete();

            $this->audit->run(
                action: AuditAction::INVOICE_ACCOUNT_DISCONNECTED,
                subjectType: 'invoice_accounts',
                subjectId: $account->id,
                changes: ['provider' => $account->provider],
                userId: $request->user()?->id,
            );
        }

        return back()->with('success', __('Paraşüt bağlantısı kaldırıldı.'));
    }

    private function back(string $message): RedirectResponse
    {
        return redirect()->route('settings.invoicing')->with('success', $message);
    }

    /**
     * Otomatik aktarım — KAPALIDAN AÇIĞA geçişte başlangıç anı yazılır.
     *
     * ⚠️ GERİYE DÖNÜK FATURA YOK (`MaybeAutoInvoice`): yalnız bu andan sonra
     * verilen siparişler faturalanır. "Kargoda" → "teslimde" geçişi anı
     * KORUR — ayar zaten açıktı, aradaki siparişler kapsamdadır. Kapatınca
     * an silinir: yeniden açan satıcı yine o andan başlar, aradaki kapalı
     * dönemin siparişleri sessizce faturalanmaz.
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private function withAutoIssue(InvoiceAccount $account, array $settings, string $wanted): array
    {
        $settings[InvoiceAccount::SETTING_AUTO_ISSUE] = $wanted;

        if ($wanted === InvoiceAccount::AUTO_OFF) {
            $settings[InvoiceAccount::SETTING_AUTO_ISSUE_SINCE] = null;
        } elseif ($account->autoIssue() === InvoiceAccount::AUTO_OFF || $account->autoIssueSince() === null) {
            $settings[InvoiceAccount::SETTING_AUTO_ISSUE_SINCE] = now()->toIso8601String();
        }

        return $settings;
    }

    /**
     * Kanal → tahsilat hesabı eşlemesi; boş seçim "eşleme yok" demektir.
     *
     * Anahtar yalnız kiracının faturalanabilir kanalı, değer yalnız
     * Paraşüt'ün o an döndürdüğü hesap olabilir.
     *
     * @param  array<array-key, mixed>  $given
     * @return array<string, string>
     */
    private function validatedPaymentAccounts(InvoiceAccount $account, array $given): array
    {
        $map = [];

        foreach ($given as $channel => $accountId) {
            $accountId = trim((string) ($accountId ?? ''));

            if ($accountId !== '') {
                $map[(string) $channel] = $accountId;
            }
        }

        $channels = array_column($this->invoicingChannels(), 'code');
        $unknownChannels = array_diff(array_keys($map), $channels);

        if ($unknownChannels !== []) {
            throw ValidationException::withMessages([
                'payment_accounts' => __('Bu kanal faturalanabilir kanallarınız arasında değil.'),
            ]);
        }

        if ($map === []) {
            return [];
        }

        try {
            $known = array_column((new ParasutClient($account))->accounts(), 'id');
        } catch (Throwable $e) {
            Log::warning('parasut.accounts_unavailable', ['error' => $e->getMessage()]);

            throw ValidationException::withMessages([
                'payment_accounts' => __('Paraşüt hesapları okunamadı; tahsilat eşlemesi kaydedilmedi. Biraz sonra yeniden deneyin.'),
            ]);
        }

        foreach ($map as $accountId) {
            if (! in_array($accountId, $known, true)) {
                throw ValidationException::withMessages([
                    'payment_accounts' => __('Seçilen kasa/banka hesabı Paraşüt\'te bulunamadı.'),
                ]);
            }
        }

        return $map;
    }

    /**
     * Kasa/banka listesi ekran için — HATA EKRANI DÜŞÜRMEZ.
     *
     * Paraşüt o an cevap vermese de satıcı firma, kip ve otomatik aktarımı
     * değiştirebilmeli; liste boş gelir ve ekran uyarı gösterir.
     *
     * @return array{0: list<array{id: string, name: string, type: string|null}>, 1: string|null}
     */
    private function ledgerAccountsForScreen(InvoiceAccount $account): array
    {
        try {
            return [(new ParasutClient($account))->accounts(), null];
        } catch (Throwable $e) {
            Log::warning('parasut.accounts_unavailable', ['error' => $e->getMessage()]);

            return [[], __('Paraşüt kasa/banka hesapları şu an okunamadı. Tahsilat eşlemesini daha sonra yapabilirsiniz.')];
        }
    }

    /**
     * Kiracının faturalanabilir kanalları — tahsilat eşlemesinin satırları.
     *
     * Adapter KURULMADAN sınıftan okunur (`OrderController` kuralı): ekranı
     * açmak kanal kimlik bilgisini çözmeyi gerektirmemeli.
     *
     * @return list<array{code: string, name: string}>
     */
    private function invoicingChannels(): array
    {
        $channels = [];

        $connections = ChannelConnection::query()
            ->with('channelType:code,name,adapter_class')
            ->get(['id', 'channel_type_code']);

        foreach ($connections as $connection) {
            $type = $connection->channelType;
            $class = $type?->adapter_class;

            if ($type === null || ! is_string($class) || $class === '' || ! is_subclass_of($class, SupportsInvoiceData::class)) {
                continue;
            }

            $channels[$type->code] = ['code' => (string) $type->code, 'name' => (string) $type->name];
        }

        return array_values($channels);
    }
}
