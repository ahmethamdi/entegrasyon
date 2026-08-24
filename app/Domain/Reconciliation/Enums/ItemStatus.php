<?php

declare(strict_types=1);

namespace App\Domain\Reconciliation\Enums;

/**
 * Mutabakat kaleminin sınıflandırması.
 *
 * Mimari Karar Dokümanı v2.2 · §10 · CLASSIFY, §9 · karar tablosu.
 *
 * REMOTE_UNREACHABLE SÜRÜKLENME DEĞİLDİR: API hatası altyapı sorunudur ve
 * fark KANITLANMAMIŞTIR. Onarım açmak, bilinmeyen bir duruma karşı yazmak
 * demektir.
 */
enum ItemStatus: string
{
    /** local == remote — yapılacak bir şey yok. */
    case MATCHED = 'MATCHED';

    /** Fark var, ikisi de mevcut. */
    case DRIFT_DETECTED = 'DRIFT_DETECTED';

    /** Kanalda ürün yok (404 veya yanıtta hiç görünmedi). */
    case REMOTE_MISSING = 'REMOTE_MISSING';

    /** Onarım operasyonu açıldı. */
    case REPAIR_QUEUED = 'REPAIR_QUEUED';

    /** Doğrulama turunda eşleşti — onarım tuttu. */
    case REPAIRED = 'REPAIRED';

    /**
     * Üç tur üst üste sürüklenme — otomatik onarım DURDURULDU (§10).
     *
     * `DRIFT_DETECTED` bırakılsaydı kalem bir sonraki turda yine
     * `drift_detected` sebebiyle aday olur ve panel onu "onarım bekliyor"
     * gibi gösterirdi — oysa hiçbir onarım gelmeyecek. Kullanıcı sonsuza
     * kadar bekleyen bir satıra bakardı.
     */
    case MANUAL_REVIEW = 'MANUAL_REVIEW';

    /** API hatası — sürüklenme DEĞİL, altyapı sorunu. */
    case REMOTE_UNREACHABLE = 'REMOTE_UNREACHABLE';

    /**
     * Fiyat kanalda değişmiş — KULLANICI KARARI BEKLENİYOR (§9 · PRICE).
     *
     * SÜRÜKLENME DEĞİLDİR ve `isDrift()` bunun için `false` DÖNER.
     * `REMOTE_UNREACHABLE` kuralının kardeşidir ama gerekçesi TERSTİR:
     * orada fark KANITLANMAMIŞTIR, burada fark KANITLIDIR — yalnızca
     * onarım MEŞRU DEĞİLDİR.
     *
     * §9 domain başına politika tanımlar ve PRICE'ı ayırır: "ÜZERİNE
     * YAZMA. Çakışma rozeti. Kullanıcı seçer." Gerekçesi de yazılı:
     * satıcılar kanal panelinden kampanya yapıyor ve sessizce ezmek EN SIK
     * ŞİKAYET. `isDrift()` true dönseydi `ReconcileConnection` bu kalem için
     * `QueueRepair` çağırır, kanonik fiyat kanala gider ve satıcının
     * kampanyası beş dakika içinde SESSİZCE silinirdi — özelliğin
     * engellemek için var olduğu şeyin ta kendisi.
     *
     * Stokta bu durum YOKTUR: orada tek otorite biziz (§9 · INVENTORY).
     */
    case PRICE_CONFLICT = 'PRICE_CONFLICT';

    /**
     * Bu sınıflandırma sürüklenme sayılır mı — tur sayacını besler.
     *
     * Sayaç aynı zamanda ONARIM KAPISIDIR (`ReconcileConnection::run()`):
     * `false` dönen her durum onarımdan da muaftır. `PRICE_CONFLICT` ve
     * `REMOTE_UNREACHABLE` bu yüzden burada YOKTUR.
     */
    public function isDrift(): bool
    {
        return $this === self::DRIFT_DETECTED
            || $this === self::REPAIR_QUEUED
            || $this === self::MANUAL_REVIEW;
    }
}
