<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Support;

use RuntimeException;

/**
 * "Bu olay şimdi işlenemez, sipariş henüz yok" — HATA DEĞİL, ERTELEME.
 *
 * Mimari denetim P0-1: iptal/iade olayı, siparişin kendisinden ÖNCE
 * gelebilir (webhook sırası garanti değildir; yoklama kanalı siparişi
 * sonraki turda görür). Önceden bu olay "işlendi" sayılıp kaybolurdu: sipariş
 * sonra gelir, stok düşer ve iptal/iade HİÇ uygulanmazdı — bakiye kalıcı eksik.
 *
 * `ProcessInboxMessage` bu istisnayı ayrı yakalar: mesaj `pending`'e döner,
 * hata bütçesi HARCANMAZ, `next_attempt_at` ileri atılır ve
 * `awaiting_order_ref` yazılır. Sipariş alındığı anda
 * `WakeDeferredOrderEvents` mesajı beklemeden uyandırır. Uyku (`sleep()`)
 * YOKTUR; işçi bloklanmaz.
 */
final class DeferUntilOrderArrives extends RuntimeException
{
    public function __construct(public readonly string $externalOrderId)
    {
        parent::__construct("Sipariş {$externalOrderId} henüz alınmadı; olay ertelendi.");
    }
}
