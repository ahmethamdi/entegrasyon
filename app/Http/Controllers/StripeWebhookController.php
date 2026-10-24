<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Billing\Actions\HandleStripeBillingEvent;
use App\Domain\Billing\Actions\SyncSubscriptionFromStripe;
use App\Domain\Billing\Support\WebhookEventLedger;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;

/**
 * Stripe webhook uç noktası — abonelik durumunun geldiği yer.
 *
 * Mimari Karar Dokümanı v2.2 · §6 · Inbox HTTP katmanı, §11 · Güvenlik,
 * §13 · Faz 4.
 *
 * SIRA KRİTİKTİR ve kanal webhook'larıyla AYNIDIR:
 *   1. HAM gövde alınır
 *   2. İmza doğrulanır — AYRIŞTIRMADAN ÖNCE
 *   3. Olay işlenir
 *   4. Tanınsın tanınmasın 2xx
 *
 * DEĞİŞMEZ KURAL — İMZA HAM GÖVDE ÜZERİNDEN:
 *   `$request->all()` ile ayrıştırıp yeniden serileştirmek baytları
 *   değiştirir ve imza TUTMAZ. Burada bedeli kanal webhook'undan da
 *   ağırdır: doğrulanmamış bir `checkout.session.completed` ÜCRETSİZ
 *   ABONELİK açmak demektir.
 *
 * DEĞİŞMEZ KURAL — GEÇERSİZ İMZA HİÇBİR ŞEY YAZMAZ ve 400 alır.
 *   Stripe 400'ü "bu isteği bir daha gönderme" olarak okur; sahte
 *   isteği yeniden denemesinin anlamı da yoktur.
 *
 * DEĞİŞMEZ KURAL — TANINMAYAN OLAY 2xx ALIR:
 *   Stripe onlarca olay türü gönderir. Hata dönmek uç noktayı "bozuk"
 *   saydırır, Stripe yeniden dener ve sonunda webhook'u DEVRE DIŞI
 *   bırakır — o noktadan sonra GERÇEK ödemeler de gelmez. Kanal
 *   webhook'undaki "her durumda 202" kuralının aynısı.
 *
 * DEĞİŞMEZ KURAL — ROTA `web` GRUBUNDA DEĞİLDİR: CSRF muaf ve
 *   oturumsuz. Muafiyetin bedeli imza doğrulamasıyla ödenir.
 *
 * KİRACI BAĞLAMI YOKTUR: istek Stripe'tan gelir ve kiracı ancak
 * metadata'dan öğrenilir. Yazma `runAsSystem` altında yapılır.
 *
 * S14 · OLAY TEKİLLİĞİ: aynı `evt_…` ikinci kez gelirse İŞLENMEZ
 * (`WebhookEventLedger`); işleme ortasında düşen olay damgalanmaz ve
 * Stripe'ın yeniden denemesiyle tekrar işlenir. Bu uç noktada dinlenmesi
 * gereken olaylar (Stripe Dashboard → Webhooks):
 *   checkout.session.completed, customer.subscription.created|updated|deleted,
 *   invoice.payment_failed, invoice.paid, charge.refunded
 */
final class StripeWebhookController extends Controller
{
    /** İmza zaman damgası toleransı — tekrar saldırısına karşı. */
    private const TOLERANCE_SECONDS = 300;

    /** Dinlenen olaylar — dashboard'daki uç nokta bunlarla eşleşmeli (test sınar). */
    public const HANDLED_EVENTS = [
        'checkout.session.completed',
        'customer.subscription.created',
        'customer.subscription.updated',
        'customer.subscription.deleted',
        'invoice.payment_failed',
        'invoice.paid',
        'charge.refunded',
    ];

    public function __invoke(Request $request, SyncSubscriptionFromStripe $sync, HandleStripeBillingEvent $billing, WebhookEventLedger $ledger): Response
    {
        $secret = config('entegrasyon.stripe.webhook_secret');

        if (! is_string($secret) || $secret === '') {
            // Sır tanımsızsa DOĞRULAMA YAPILAMAZ ve istek KABUL EDİLMEZ.
            // Kabul edilseydi yapılandırması eksik bir kurulumda herkes
            // kendine abonelik açabilirdi — sessiz ve ölümcül.
            Log::error('stripe.webhook_secret_missing');

            return response()->noContent(500);
        }

        // (1) HAM gövde — ayrıştırma YAPILMADAN.
        $raw = $request->getContent();

        try {
            // (2) İmza doğrulama. Stripe SDK'sı hem HMAC'i hem zaman
            // damgası toleransını kontrol eder; elle yazılsaydı
            // tolerans unutulur ve eski bir istek sonsuza kadar yeniden
            // oynatılabilirdi.
            $event = Webhook::constructEvent(
                $raw,
                $request->header('Stripe-Signature') ?? '',
                $secret,
                self::TOLERANCE_SECONDS,
            );
        } catch (SignatureVerificationException|\UnexpectedValueException $e) {
            Log::warning('stripe.signature_invalid', ['message' => $e->getMessage()]);

            return response()->noContent(400);
        }

        // (3) Olay işlenir. Tanınmayan tür sessizce geçilir.
        //
        // `data.object` HER ZAMAN `StripeObject` DEĞİLDİR: boş gövdeli
        // veya beklenmedik biçimli bir olayda düz dizi gelir ve
        // `toArray()` ölümcül hata verir. O hata 500'e dönüşür, Stripe
        // uç noktayı bozuk sayar ve sonunda webhook'u DEVRE DIŞI
        // bırakır — yani tanımadığımız TEK bir olay, GERÇEK ödemelerin
        // de gelmemesine yol açardı.
        $raw_object = $event->data->object ?? null;

        $object = match (true) {
            is_object($raw_object) && method_exists($raw_object, 'toArray') => $raw_object->toArray(),
            is_array($raw_object) => $raw_object,
            default => [],
        };

        if (! in_array($event->type, self::HANDLED_EVENTS, true)) {
            Log::info('stripe.unhandled_event', ['type' => $event->type]);

            return response()->noContent(200);
        }

        $eventId = is_string($event->id ?? null) ? $event->id : '';
        if ($eventId !== '' && ! $ledger->claim('stripe', $eventId, $event->type)) {
            Log::info('stripe.duplicate_event', ['id' => $eventId, 'type' => $event->type]);

            return response()->noContent(200);
        }

        match ($event->type) {
            'checkout.session.completed' => $sync->fromCheckoutSession($object),
            'customer.subscription.updated',
            'customer.subscription.created' => $sync->fromSubscriptionEvent($object),
            'customer.subscription.deleted' => $sync->fromSubscriptionEvent($object, deleted: true),
            'invoice.payment_failed' => $billing->paymentFailed($object),
            'invoice.paid' => $billing->paymentSucceeded($object),
            'charge.refunded' => $billing->chargeRefunded($object),
        };

        if ($eventId !== '') {
            $ledger->markProcessed('stripe', $eventId);
        }

        // (4) HER DURUMDA 2xx.
        return response()->noContent(200);
    }
}
