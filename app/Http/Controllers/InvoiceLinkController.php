<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Invoicing\Exceptions\InvoiceProviderException;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceAccount;
use App\Domain\Invoicing\Support\InvoiceProviders;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Faturanın kalıcı vekil bağlantısı — `GET /f/{token}.pdf` (S6, oturumsuz).
 *
 * Hepsiburada ve n11 alıcıya bu adresi gösterir. PDF BİZDE DURMAZ: her
 * istekte entegratörden TAZE süreli adres alınır ve 302 ile yönlendirilir;
 * gövde okunmaz, loglanmaz. Kullanıcı kararı (20 Eki 2026).
 *
 * YANITLAR:
 *   302  fatura kesilmiş, Paraşüt adresi verdi
 *   410  fatura İPTAL edildi (iade faturayı geçersiz kılmaz: kısmi iadede
 *        müşterinin elindeki satış faturası hâlâ geçerli belgedir)
 *   404  bilinmeyen token, kesilmemiş fatura ya da Paraşüt şu an vermiyor
 *        (bağlantı koptu / belge hazırlanıyor) — açıklayıcı sayfa
 *
 * GÜVENLİK: token 64 onaltılık karakter (32 rastgele bayt) — biçime
 * uymayan istek veritabanına sorulmaz. IP başına hız sınırı (`invoice-link`).
 * Her yanıt `noindex`, `no-referrer` (adres token taşır; tıklanan
 * Paraşüt bağlantısına sızmasın) ve önbelleğe alınmaz.
 */
final class InvoiceLinkController extends Controller
{
    public function show(string $token, InvoiceProviders $providers): RedirectResponse|Response
    {
        if (preg_match('/^[0-9a-f]{64}$/', $token) !== 1) {
            return $this->page(404);
        }

        $invoice = TenantContext::runAsSystem(fn (): ?Invoice => Invoice::query()
            ->where('public_token', $token)
            ->where('kind', Invoice::KIND_SALE)
            ->first());

        if ($invoice === null) {
            return $this->page(404);
        }

        TenantContext::set($invoice->tenant_id);

        try {
            return $this->resolve($invoice, $providers);
        } finally {
            TenantContext::clear();
        }
    }

    private function resolve(Invoice $invoice, InvoiceProviders $providers): RedirectResponse|Response
    {
        $cancelled = Invoice::query()
            ->where('parent_invoice_id', $invoice->id)
            ->where('kind', Invoice::KIND_CANCEL)
            ->where('status', Invoice::STATUS_ISSUED)
            ->exists();

        if ($cancelled) {
            return $this->page(410);
        }

        if ($invoice->status !== Invoice::STATUS_ISSUED || $invoice->document_type === null) {
            return $this->page(404);
        }

        $account = InvoiceAccount::query()->first();

        if ($account === null || ! $account->isUsable()) {
            return $this->page(404);
        }

        try {
            $url = $providers->for($account)->pdfUrl($invoice);
        } catch (InvoiceProviderException) {
            $url = null;
        }

        if ($url === null) {
            return $this->page(404);
        }

        return $this->secure(redirect()->away($url, 302));
    }

    private function page(int $status): Response
    {
        return $this->secure(response()->view('invoice-link', ['status' => $status], $status));
    }

    /**
     * @template T of SymfonyResponse
     *
     * @param  T  $response
     * @return T
     */
    private function secure(SymfonyResponse $response): SymfonyResponse
    {
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
