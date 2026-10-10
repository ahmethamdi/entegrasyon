<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Identity\Actions\RecordAuditLog;
use App\Domain\Identity\Enums\AuditAction;
use App\Domain\Invoicing\Exceptions\InvoiceProviderException;
use App\Domain\Invoicing\Models\InvoiceAccount;
use App\Domain\Invoicing\Providers\Parasut\ParasutApp;
use App\Domain\Invoicing\Providers\Parasut\ParasutAuth;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * e-fatura ayarları — Paraşüt hesabını bağlama, firma ve seri seçimi.
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
            ],
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

    /** Firma ve fatura serisi. Firma yalnız Paraşüt'ün döndürdüğü listeden seçilir. */
    public function update(Request $request): RedirectResponse
    {
        $account = InvoiceAccount::query()->firstOrFail();

        $companies = collect((array) ($account->settings['companies'] ?? []));

        $validated = $request->validate([
            'company_id' => ['required', 'string', Rule::in($companies->pluck('id')->all())],
            'invoice_series' => ['nullable', 'string', 'max:16', 'regex:/^[A-Za-z0-9]*$/'],
        ]);

        $company = $companies->firstWhere('id', $validated['company_id']);
        $series = trim((string) ($validated['invoice_series'] ?? ''));

        $account->forceFill([
            'company_id' => $validated['company_id'],
            'company_name' => $company['name'] ?? null,
            'settings' => [...$account->settings ?? [], InvoiceAccount::SETTING_SERIES => $series === '' ? null : strtoupper($series)],
            // Firma seçimi bekleyen hesap seçimle açılır; kopmuş hesap
            // ise ancak yeniden bağlanarak açılır, burada değil.
            'status' => $account->status === InvoiceAccount::STATUS_NEEDS_COMPANY ? InvoiceAccount::STATUS_CONNECTED : $account->status,
        ])->save();

        return back()->with('success', __('e-fatura ayarları kaydedildi.'));
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
}
