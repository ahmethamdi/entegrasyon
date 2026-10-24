<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Support;

use App\Domain\Invoicing\Models\Invoice;
use Illuminate\Http\Response;

/**
 * Dosyayı doğrudan veren entegratörün (`ProvidesPdfContent`) PDF'ini
 * tarayıcıya akıtır — sipariş ekranı ve kalıcı bağlantı aynı yanıtı verir.
 *
 * Dosya diske, önbelleğe ya da günlüğe YAZILMAZ (Paraşüt'te bağlantıya
 * yönlendirmenin eşi): `no-store`, `inline`.
 */
final class InvoicePdfResponse
{
    /** Tek bir e-fatura PDF'i için yeterli; bozuk bir yanıt belleği şişirmesin. */
    public const MAX_BYTES = 10 * 1024 * 1024;

    public static function make(string $pdf, Invoice $invoice): Response
    {
        $name = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($invoice->invoice_number ?? '')) ?: 'fatura';

        return new Response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "inline; filename=\"{$name}.pdf\"",
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
