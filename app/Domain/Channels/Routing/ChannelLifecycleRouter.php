<?php

declare(strict_types=1);

namespace App\Domain\Channels\Routing;

use App\Domain\Channels\Actions\RevokeChannelAccess;
use App\Domain\Messaging\Models\InboxMessage;
use Illuminate\Support\Facades\Log;

/**
 * Kanal YAŞAM DÖNGÜSÜ olaylarını ele alır — sipariş olaylarından ÖNCE.
 *
 * V3.0 · §06.7 · §19 (olay yönlendirme tablosu) · P1-2 · v2.2 §6 · §7.
 *
 * ─────────────────────────────────────────────────────────────────────
 * NEDEN AYRI BİR ROUTER — MODÜL SINIRI
 * ─────────────────────────────────────────────────────────────────────
 * Dal `OrderEventRouter`'a KONMAZ. O sınıf Orders domain'idir ve bu olay
 * `channel_credentials` + `channel_connections` YAZAR; bir domain'in başka
 * domain'in modeline yazması yasaktır (v2.2 · modül sınırı). Orders'a
 * konsaydı sipariş yönlendiricisi kanal kimlik bilgisi iptal eden bir yol
 * taşırdı ve o sınır bir daha geri gelmezdi.
 *
 * ─────────────────────────────────────────────────────────────────────
 * AMA İKİNCİ BİR OLAY SİSTEMİ DE AÇILMAZ (§19)
 * ─────────────────────────────────────────────────────────────────────
 * Mesaj yine AYNI `inbox_messages` hattına girer: aynı imza doğrulaması,
 * aynı tekilleştirme, aynı `inbox:recover`. İkinci bir hat açılsaydı
 * tekilleştirme iki yerde yazılır, biri unutulurdu ve kurtarma taraması
 * iki yeri bilmek zorunda kalırdı. Değişen TEK şey, inbox tüketicisinin
 * sipariş router'ından ÖNCE buraya sormasıdır.
 *
 * ─────────────────────────────────────────────────────────────────────
 * ⚠️ SIRA KRİTİKTİR — SESSİZ HATA TAM BURADA DOĞUYORDU
 * ─────────────────────────────────────────────────────────────────────
 * `ShopifyOrderNormalizer::TOPIC_TO_TYPE` tablosunda `app/uninstalled`
 * YOKTUR ve **bilinmeyen konu `updated` sayılır**. Bu router olmasaydı
 * kaldırma olayı bir SİPARİŞ GÜNCELLEMESİ sanılır, gövdedeki `id` —
 * MAĞAZANIN kimliği — sipariş kimliği yerine okunur ve `resolveOrder()`
 * onu bulamayıp yalnızca uyarı yazardı. Token sessizce geçersizken
 * bağlantı `active` kalır, her istek 401 alır, `AUTHENTICATION` KALICI
 * sayılır ve satıcının tüm listing'leri teker teker ölürdü.
 *
 * ⚠️ KAPI DAR TUTULUR: hem KANAL KODU hem KONU eşleşmelidir. Yalnızca
 * konuya bakılsaydı başka bir kanalın aynı adlı olayı satıcının
 * bağlantısını sessizce kapatabilirdi; yalnızca kanala bakılsaydı
 * Shopify'ın TÜM webhook'ları sipariş yolundan çıkar ve siparişler HİÇ
 * işlenmezdi — kaldırmayı kaçırmaktan çok daha ağır bir kayıp.
 */
final class ChannelLifecycleRouter
{
    /**
     * (kanal kodu, konu) → satıcıya gösterilecek SEBEP.
     *
     * Konu ADIYLA eşlenir, ön ekle değil (normalizer'ın kuralının aynısı):
     * `app/` ön eki aransaydı Shopify'ın GDPR konuları
     * (`customers/redact` gibi) da bu yola düşerdi.
     *
     * §19 · yönlendirme tablosu: `app.uninstalled` YALNIZCA Shopify'da
     * vardır; diğer beş kanalda ❌.
     *
     * ⚠️ SEBEP METNİ TABLODA YAŞAR, ROUTER GÖVDESİNDE DEĞİL. Gövdeye
     * gömülseydi ikinci kanal eklendiğinde satıcıya "Shopify mağazasından
     * kaldırıldı" yazılırdı — kendi kanalında böyle bir şey olmamışken.
     *
     * @var array<string, array<string, string>>
     */
    private const LIFECYCLE_TOPICS = [
        'shopify' => [
            'app/uninstalled' => 'Uygulama Shopify mağazasından kaldırıldı.',
        ],
    ];

    public function __construct(
        private readonly RevokeChannelAccess $revokeAccess = new RevokeChannelAccess,
    ) {}

    /**
     * Mesajı ele aldıysa `true` döner — çağıran sipariş yolunu ATLAR.
     *
     * DÖNÜŞ DEĞERİ SÖZLEŞMEDİR: `false` "bu benim işim değil" demektir ve
     * mesaj sipariş hattına devam eder. `void` dönseydi tüketici olayın
     * ele alınıp alınmadığını bilemez, her mesajı İKİ yoldan da geçirir ve
     * kaldırma olayı yine sipariş güncellemesi sanılırdı.
     */
    public function route(InboxMessage $message): bool
    {
        $connection = $message->connection;

        if ($connection === null) {
            return false;               // bağlantısız mesaj sipariş yolunun sorunu
        }

        $topic = mb_strtolower(trim((string) ($message->event_type ?? '')));
        $channel = (string) $connection->channel_type_code;

        $reason = self::LIFECYCLE_TOPICS[$channel][$topic] ?? null;

        if ($reason === null) {
            return false;               // yaşam döngüsü olayı değil
        }

        Log::info('inbox.channel_uninstalled', [
            'message' => $message->id,
            'connection' => $connection->id,
            'channel' => $channel,
        ]);

        $this->revokeAccess->run($connection, $reason);

        return true;
    }
}
