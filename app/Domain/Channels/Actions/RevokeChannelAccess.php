<?php

declare(strict_types=1);

namespace App\Domain\Channels\Actions;

use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Support\CredentialVault;
use Illuminate\Support\Facades\DB;

/**
 * Kanal erişimini iptal eder — bağlantı İŞARETLENİR, SİLİNMEZ.
 *
 * V3.0 · §06.7 · P1-2 · T-V3-22 · v2.2 §7 · §13 · faz 1.4.
 *
 * Kanal "bu erişim artık geçerli değil" dediğinde çağrılır (bugün yalnızca
 * Shopify `app/uninstalled`). Davranış §06.7'de birebir yazılı:
 *
 *   channel_credentials.revoked_at = now()
 *   channel_connections.status     = 'inactive'
 *   channel_connections.last_error = <sebep>
 *
 * ─────────────────────────────────────────────────────────────────────
 * ⚠️ BAĞLANTI SİLİNMEZ, İŞARETLENİR
 * ─────────────────────────────────────────────────────────────────────
 * `CheckChannelHealth`'in ve `ConnectChannel`'in kuralının aynısı: listing
 * ve sipariş geçmişi bağlantıya bağlıdır. Silinseydi satıcının tüm geçmişi
 * TEK bir webhook'la yok olurdu ve bu GERİ ALINAMAZ. Satıcı uygulamayı
 * yeniden kurarsa `ConnectChannel` aynı satırı `firstOrNew` ile yeniden
 * kullanır (anahtar yenileme akışı) ve KOTADAN ETKİLENMEZ.
 *
 * ─────────────────────────────────────────────────────────────────────
 * ⚠️ DURUM `inactive`, `pending` DEĞİL — SAĞLIKSIZLIKTAN FARKLI
 * ─────────────────────────────────────────────────────────────────────
 * `CheckChannelHealth` sağlıksız bağlantıyı `pending`'e ÇEKER çünkü orada
 * durum GEÇİCİDİR: kanal toparlanabilir ve sonraki sağlık kontrolü satırı
 * kendiliğinden `active` yapar. Burada durum geçici DEĞİLDİR — erişim
 * kanal tarafında kaldırılmıştır ve kendiliğinden geri gelmez; satıcı
 * uygulamayı yeniden kurmadıkça hiçbir tur bunu düzeltemez. `pending`
 * yazılsaydı sağlık kontrolü her turda boşuna denenir ve satır "birazdan
 * düzelir" gibi görünürdü. §06.7 açıkça `inactive` diyor.
 *
 * ─────────────────────────────────────────────────────────────────────
 * ⚠️ İKİSİ TEK TRANSACTION
 * ─────────────────────────────────────────────────────────────────────
 * Araya düşen bir hata, kimlik bilgisi iptal edilmiş ama bağlantısı hâlâ
 * `active` bir satır bırakırdı: fan-out ona iş atmaya devam eder, her iş
 * kimliksiz gider ve `AUTHENTICATION` KALICI sayılır — tam olarak bu
 * maddenin önlemek için yazıldığı hata biçimi.
 *
 * ─────────────────────────────────────────────────────────────────────
 * ⚠️ SEBEP METNİ MASKELENMEZ — ve maskelenmesine GEREK YOKTUR
 * ─────────────────────────────────────────────────────────────────────
 * `ChannelErrorText` kanalın YANIT GÖVDESİNDEN gelen metni maskeler
 * (§11 · katman 2): o gövde kimlik bilgisini yansıtabilir. Buradaki metin
 * KENDİ yazdığımız sabittir ve kanaldan hiçbir şey taşımaz. Maskeleme
 * çağrısı eklenseydi kasadan okuma yapan gereksiz bir yol açılırdı.
 */
final class RevokeChannelAccess
{
    public function __construct(
        private readonly CredentialVault $vault = new CredentialVault,
    ) {}

    public function run(ChannelConnection $connection, string $reason): ChannelConnection
    {
        DB::transaction(function () use ($connection, $reason): void {
            // TEKRAR ZARARSIZDIR: webhook'lar EN AZ BİR KEZ gönderilir ve
            // ikinci tur zaten iptal edilmiş kimlik bilgisini arar.
            // `activeCredential()` ilişkisi `revoked_at IS NULL` filtresi
            // taşıdığı için ikinci turda boş döner ve `?->` sessizce geçer.
            $this->vault->revoke($connection);

            $connection->forceFill([
                'status' => 'inactive',
                // Sağlık durumu da düşer: panel rozeti `health_status`
                // okur ve `healthy` bırakılsaydı kapalı bir bağlantı
                // panelde yeşil görünürdü.
                'health_status' => 'unhealthy',
                'last_error' => $reason,
            ])->save();
        });

        return $connection;
    }
}
