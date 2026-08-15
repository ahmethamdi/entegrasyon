<?php

declare(strict_types=1);

namespace App\Domain\Sync\Enums;

/**
 * Kanal hatasının çekirdeğin anladığı sınıfı.
 *
 * Mimari Karar Dokümanı v2.2 · §1 · Karar 26, §7 · Sorumluluk dağılımı, §12.
 *
 * Sınıflandırmayı ADAPTER yapar (`classifyError()`) çünkü kanal hata gövdesini
 * yalnızca o anlar; ne yapılacağına ÇEKİRDEK karar verir. Bu ayrım olmadan
 * her adapter kendi yeniden deneme politikasını taşırdı.
 *
 * DEĞİŞMEZ KURAL — KALICI VE GEÇİCİ AYRILIR:
 *   VALIDATION ve AUTHENTICATION → error_permanent
 *   diğerleri                    → error_transient
 *
 * Mutabakat yalnızca GEÇİCİ olanları aday seçer: düzeltilemeyecek bir listing
 * her beş dakikada yeniden kontrol edilirse mutabakat bütçesi boşa gider.
 */
enum ErrorClass: string
{
    /** Kanal hız sınırına takıldı; Retry-After başlığı olabilir. */
    case RATE_LIMITED = 'RATE_LIMITED';

    /** Kanal 5xx döndü. */
    case SERVER_ERROR = 'SERVER_ERROR';

    /** İstek zaman aşımına uğradı. */
    case TIMEOUT = 'TIMEOUT';

    /** Bağlantı kurulamadı, DNS, TLS. */
    case NETWORK = 'NETWORK';

    /** Eşzamanlı değişiklik çakışması. */
    case CONFLICT = 'CONFLICT';

    /** Kanal yükü reddetti — kullanıcı müdahalesi gerekir. */
    case VALIDATION = 'VALIDATION';

    /** Token geçersiz veya süresi dolmuş — kullanıcı müdahalesi gerekir. */
    case AUTHENTICATION = 'AUTHENTICATION';

    /** Kaynak kanalda yok; mutabakat devralır. */
    case NOT_FOUND = 'NOT_FOUND';

    /**
     * Bu hata kullanıcı müdahalesi olmadan düzelmez mi?
     *
     * Kalıcı hatada operasyon yeniden denenmez ve listing_sync_states satırı
     * error_permanent olur. Oradan çıkış YALNIZCA açık bir geçişle olur ve o
     * geçiş ListingResyncRequested olayı üretmek ZORUNDADIR (§9, Karar 18).
     */
    public function isPermanent(): bool
    {
        return match ($this) {
            self::VALIDATION, self::AUTHENTICATION => true,
            default => false,
        };
    }

    /** Sync state'e yazılacak durum. */
    public function syncStateStatus(): string
    {
        return $this->isPermanent() ? 'error_permanent' : 'error_transient';
    }

    /**
     * Mutabakat bu hatayı taşıyan satırı aday seçer mi?
     *
     * NOT_FOUND geçicidir ama yeniden DENENMEZ; onu mutabakat devralır ve
     * uzak durumu okuyup gerçek farkı kanıtlar.
     */
    public function isReconcilable(): bool
    {
        return ! $this->isPermanent();
    }
}
