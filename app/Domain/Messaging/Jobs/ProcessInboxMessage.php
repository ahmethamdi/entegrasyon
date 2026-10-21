<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Jobs;

use App\Domain\Billing\Routing\ShopifyBillingRouter;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Routing\ChannelLifecycleRouter;
use App\Domain\Channels\Support\BetaChannelNotifier;
use App\Domain\Messaging\Models\InboxMessage;
use App\Domain\Messaging\Support\DeferUntilOrderArrives;
use App\Domain\Messaging\Support\InboxRetrySchedule;
use App\Domain\Orders\Routing\OrderEventRouter;
use App\Support\Tenancy\TenantAwareJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Gelen mesajı işler — koşullu durum geçişiyle TEK işleyici.
 *
 * Mimari Karar Dokümanı v2.2 · §6 · Bekleyen mesaj kurtarma, §12.
 *
 * DEĞİŞMEZ KURAL — TEK İŞLEYİCİ:
 *   Aynı mesaj birden çok kez kuyruğa girebilir: webhook tekrar gönderir,
 *   RecoverPendingInbox takılı sanıp yeniden atar, Horizon işi yeniden
 *   dener. Bu iş `UPDATE ... WHERE status = 'pending'` koşullu geçişiyle
 *   tek işleyiciyi seçer; kaybeden kopyalar erken çıkar.
 *
 *   Koşullu geçiş olmasaydı iki worker aynı siparişi eşzamanlı işler ve
 *   ikisi de kilit sırasına girerdi. Sipariş yazımı tekillik kısıtıyla zaten
 *   korunuyor ama gereksiz çekişme ve yarı işlenmiş durumlar doğardı.
 *
 * İKİ ROUTER, TEK HAT (V3.0 · §06.7 · §19):
 *   Gelen her webhook bir sipariş olayı değildir. Kanal yaşam döngüsü
 *   olayları (bugün yalnızca Shopify `app/uninstalled`) ÖNCE sorulur ve
 *   ele alınmışsa sipariş yolu HİÇ çalışmaz. İkinci bir olay sistemi
 *   AÇILMAZ: mesaj aynı inbox hattından gelir, aynı tekilleştirmeden geçer
 *   ve aynı `inbox:recover` onu kurtarır.
 */
final class ProcessInboxMessage extends TenantAwareJob
{
    /**
     * Bu sayıda denemeden sonra mesaj `failed` olur.
     *
     * Geçici arızalar (deadlock, bağlantı kopması) saniyeler içinde geçer;
     * `inbox:recover` dakikalık koştuğu için beş deneme ~5 dakikalık bir
     * pencere demektir.
     */
    public const MAX_ATTEMPTS = 5;

    public function __construct(
        string $tenantId,
        public readonly string $inboxMessageId,
    ) {
        parent::__construct($tenantId);
    }

    protected function handleForTenant(): void
    {
        // Koşullu geçiş: yalnızca pending olan satırı processing yapar.
        // affected = 0 → başka bir işleyici almış veya zaten işlenmiş.
        $claimed = DB::table('inbox_messages')
            ->where('id', $this->inboxMessageId)
            // KİRACI FİLTRESİ AÇIKÇA YAZILIR (§11).
            //
            // `DB::table()` Eloquent global scope'una TABİ DEĞİLDİR. Filtre
            // olmasaydı yanlış eşleşmiş bir çift (bu kiracının işi, başka
            // kiracının mesaj kimliği) o satırı `processing` yapardı;
            // ardından gelen `find()` KAPSAMLI olduğu için satırı bulamaz ve
            // iş sessizce çıkardı. Satır artık `pending` olmadığı için
            // `inbox:recover` de onu toplamaz — o sipariş HİÇ işlenmez.
            ->where('tenant_id', $this->tenantId)
            ->where('status', 'pending')
            // ZAMANI GELMEMİŞ MESAJ ALINMAZ (P0-6): hata sonrası bekleme ya
            // da sipariş bekleyen erteleme. Kuyruğun hemen yeniden denemesi
            // burada boş çıkar; mesajı vakti gelince `inbox:recover` atar.
            ->where(static fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->update([
                'status' => 'processing',
                'attempt_count' => DB::raw('attempt_count + 1'),
                'updated_at' => now(),
            ]);

        if ($claimed === 0) {
            return;                     // başka işleyici aldı — sessizce çık
        }

        $message = InboxMessage::query()->find($this->inboxMessageId);

        if ($message === null) {
            Log::warning('inbox.message_missing', ['message' => $this->inboxMessageId]);

            return;
        }

        try {
            // KANAL YAŞAM DÖNGÜSÜ ÖNCE SORULUR (V3.0 · §06.7).
            //
            // Her webhook bir sipariş olayı DEĞİLDİR. Shopify'ın
            // `app/uninstalled` konusu `ShopifyOrderNormalizer`'ın konu
            // tablosunda YOKTUR ve **bilinmeyen konu `updated` sayılır** —
            // yani bu kapı olmasaydı kaldırma olayı bir sipariş güncellemesi
            // sanılır, gövdedeki `id` (MAĞAZANIN kimliği) sipariş kimliği
            // yerine okunur ve `resolveOrder()` onu bulamayıp yalnızca uyarı
            // yazardı. Token sessizce geçersizken bağlantı `active` kalır ve
            // satıcının tüm listing'leri teker teker `AUTHENTICATION` ile
            // ölürdü.
            //
            // İKİNCİ BİR OLAY SİSTEMİ DEĞİLDİR (§19): mesaj aynı inbox
            // hattından geldi, aynı tekilleştirmeden geçti ve aynı
            // `inbox:recover` onu kurtarır. Değişen tek şey, sipariş
            // router'ından ÖNCE sorulmasıdır.
            // FATURALAMA en önce (Shopify `app_subscriptions/update`;
            // `app/uninstalled`'da yerel aboneliği kapatır ama TÜKETMEZ).
            $handled = app(ShopifyBillingRouter::class)->route($message)
                || app(ChannelLifecycleRouter::class)->route($message);

            if (! $handled) {
                app(OrderEventRouter::class)->route($message);
            }

            $message->markProcessed();
        } catch (DeferUntilOrderArrives $deferred) {
            $this->defer($message, $deferred);
        } catch (Throwable $e) {
            // ⚠️ HATA MESAJI ÖLDÜRMEZ — BÜTÇE TÜKENENE KADAR GERİ DÖNER.
            //
            // Önceden satır İLK hatada `failed` yapılıyordu ve hiçbir yol
            // onu geri almıyordu: kuyruk yeniden denemesi `status =
            // 'pending'` koşuluna takılıp boş çıkar, `inbox:recover`
            // yalnızca `pending` toplar. Tek bir deadlock veya DB kopması
            // SİPARİŞİ KAYBETTİRİRDİ — stok düşmez, webhook 202 döndüğü için
            // kanal da yeniden göndermez.
            //
            // Sipariş yolu idempotenttir (sipariş tekilliği + hareket
            // `idempotency_key`'i), yani yeniden işlemek çift etki ÜRETMEZ.
            //
            // Bütçe tükenince `failed` olur: kalıcı bozuk bir gövde her
            // dakika sonsuza kadar denenmesin. `failed` satırlar
            // `inbox_failed` metriğinde GÖRÜNÜR (§11).
            $exhausted = $message->attempt_count >= self::MAX_ATTEMPTS;

            $message->forceFill([
                'status' => $exhausted ? 'failed' : 'pending',
                'last_error' => mb_substr($e->getMessage(), 0, 2000),
                // BEKLEMELİ YENİDEN DENEME (P0-6): 10 sn → 1 dk → 5 dk →
                // 15 dk. Önceden beş deneme saniyeler içinde biterdi.
                'next_attempt_at' => $exhausted ? null : InboxRetrySchedule::afterError($message->attempt_count),
            ])->save();

            if ($exhausted) {
                $connection = ChannelConnection::query()->find($message->channel_connection_id);

                if ($connection !== null) {
                    app(BetaChannelNotifier::class)->failed($connection, 'sipariş işlenemedi', (string) $message->last_error);
                }
            }

            Log::error($exhausted ? 'inbox.process_failed' : 'inbox.process_retrying', [
                'message' => $message->id,
                'attempt' => $message->attempt_count,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Sipariş henüz yok — mesaj ERTELENİR, işlenmiş SAYILMAZ (P0-1).
     *
     * Hata bütçesi GERİ VERİLİR: sahiplenme `attempt_count`'u artırdı ama
     * bekleme bir arıza değildir; beş ertelemede `failed` olsaydı yoklama
     * turunu bekleyen meşru bir iptal kaybolurdu.
     *
     * Pencere dolunca terminal `expired` olur — `failed` DEĞİL: hiç gelmeyen
     * siparişin stoğu hiç düşülmemiştir, iptali/iadesi geri alacak bir şey
     * bulmaz (Trendyol paketi yoklamada ilk kez "Cancelled" görülürse tam
     * olarak budur). `failed` alarmı bu meşru durumla dolmasın.
     * `awaiting_order_ref` KALIR: sipariş günler sonra (ör. elle yeniden
     * oynatmayla) gelirse olay yine uyandırılır.
     * İstisna yeniden FIRLATILMAZ — kuyruk hatası değildir, Horizon'u
     * gereksiz yeniden denemeyle doldurmaz.
     */
    private function defer(InboxMessage $message, DeferUntilOrderArrives $deferred): void
    {
        $receivedAt = $message->received_at ?? now();
        $expired = InboxRetrySchedule::deferralExpired($receivedAt);

        $message->forceFill([
            'status' => $expired ? 'expired' : 'pending',
            'attempt_count' => max(0, $message->attempt_count - 1),
            'awaiting_order_ref' => mb_substr($deferred->externalOrderId, 0, 255),
            'next_attempt_at' => $expired ? null : InboxRetrySchedule::afterDeferral($receivedAt),
            'last_error' => $expired
                ? 'Sipariş '.InboxRetrySchedule::DEFER_WINDOW_HOURS.' saat içinde gelmedi; olay uygulanmadı.'
                : $deferred->getMessage(),
        ])->save();

        Log::info($expired ? 'inbox.deferral_expired' : 'inbox.deferred_until_order', [
            'message' => $message->id,
            'external_order_id' => $deferred->externalOrderId,
        ]);
    }
}
