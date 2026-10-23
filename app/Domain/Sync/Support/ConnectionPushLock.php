<?php

declare(strict_types=1);

namespace App\Domain\Sync\Support;

use App\Domain\Sync\Enums\SyncDomain;
use Illuminate\Support\Facades\DB;

/**
 * BAĞLANTI BAŞINA TEK GÖNDERİCİ — S7 · Black Friday yük testi (23 Ekim 2026).
 *
 * `InventoryBatchBuilder` bekleyen operasyonları `lockForUpdate()` ile
 * toplar ama iş bir transaction içinde DEĞİLDİR: kilit SELECT bitince
 * düşer. Yük testinde (dakikada 300 sipariş, 5 kanal) aynı bağlantının
 * iki işi aynı operasyonları topladı ve ikisi de gönderdi:
 *   - sonuç kayıtları aynı satırları FARKLI sırayla güncelledi (tetikleyici
 *     yükün başına alınır) → 5 dakikada 159 deadlock, 2.038 başarısız
 *     deneme, 110 ölü operasyon;
 *   - eski değerli paket yeni değerli paketten SONRA kanala vardı → yük
 *     bittiğinde kanalda stok 1–2 adet FAZLA (aşırı satış riski).
 *
 * Kilidi transaction'a almak HTTP çağrısı boyunca satır kilidi tutmak
 * olurdu. Bunun yerine bağlantı × alan başına OTURUM düzeyinde Postgres
 * advisory kilidi: aynı anda bağlantıya tek paket gider, sırayla.
 * Verim düşmez: bağlantı zaten hız sınırına tabidir ve paket 100 kalem
 * taşır. İşçi ölürse bağlantısı kapanır ve kilit kendiliğinden düşer —
 * Redis kilidindeki gibi süre tahmini gerekmez.
 *
 * `pg_try_*`: BEKLEMEZ. Kilidi alamayan iş kısa süre sonra yeniden
 * kuyruğa girer; o arada operasyonu çoğu zaman kilidi tutan işin bir
 * sonraki paketi götürür ve iş döndüğünde tamamlanmış bulur.
 */
final class ConnectionPushLock
{
    public function acquire(string $connectionId, SyncDomain $domain): bool
    {
        return (bool) DB::selectOne(
            'SELECT pg_try_advisory_lock(hashtextextended(?, 0)) AS ok',
            [self::key($connectionId, $domain)],
        )->ok;
    }

    public function release(string $connectionId, SyncDomain $domain): void
    {
        DB::selectOne(
            'SELECT pg_advisory_unlock(hashtextextended(?, 0)) AS ok',
            [self::key($connectionId, $domain)],
        );
    }

    /** Kilit adı — testler başka bir oturumdan aynı kilidi tutar. */
    public static function key(string $connectionId, SyncDomain $domain): string
    {
        return "push:{$domain->value}:{$connectionId}";
    }
}
