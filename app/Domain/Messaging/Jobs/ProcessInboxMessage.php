<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Jobs;

use App\Domain\Channels\Routing\ChannelLifecycleRouter;
use App\Domain\Messaging\Models\InboxMessage;
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
            $handled = app(ChannelLifecycleRouter::class)->route($message);

            if (! $handled) {
                app(OrderEventRouter::class)->route($message);
            }

            $message->markProcessed();
        } catch (Throwable $e) {
            // Hata mesajı kaybolmaz: satır failed olur, yükü durur ve
            // panelden yeniden denenebilir. Kuyruk yeniden denemesi de
            // koşullu geçişe takılmaması için durumu pending'e döndürür.
            $message->forceFill([
                'status' => 'failed',
                'last_error' => mb_substr($e->getMessage(), 0, 2000),
            ])->save();

            Log::error('inbox.process_failed', [
                'message' => $message->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
