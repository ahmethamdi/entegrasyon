<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Support;

use App\Domain\Invoicing\Models\Invoice;

/**
 * Faturanın kalıcı vekil adresi — pazaryerine verilen bağlantı.
 *
 * Biçim `{APP_URL}/f/{token}.pdf`: n11 bağlantının `.pdf` (ya da png/jpeg)
 * ile bitmesini şart koşar, HTTPS ister ve 2048 karakteri geçmemeli.
 *
 * Token BİR KEZ üretilir ve satıra yazılır; yeniden deneme aynı adresi
 * gönderir (kanal "değişti" sanmaz, n11 son gönderileni aktif tutar).
 */
final class InvoicePublicLink
{
    /** 32 bayt = 64 onaltılık karakter. */
    public const TOKEN_BYTES = 32;

    public static function for(Invoice $invoice): string
    {
        if ($invoice->public_token === null) {
            $invoice->forceFill(['public_token' => bin2hex(random_bytes(self::TOKEN_BYTES))])->save();
        }

        return rtrim((string) config('app.url'), '/').'/f/'.$invoice->public_token.'.pdf';
    }
}
