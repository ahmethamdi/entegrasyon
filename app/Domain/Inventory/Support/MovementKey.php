<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Support;

/**
 * Deterministik stok hareketi idempotency anahtarı.
 *
 * Mimari Karar Dokümanı v2.2 · §1 · Karar 10 ve §4 · Hareket türleri.
 *
 * TEMEL KURAL: SALE dışındaki tüm sipariş kaynaklı hareketler OLAY kimliğine
 * bağlanır, sipariş satırı kimliğine değil. Bir sipariş satırı bir kez satılır
 * ama birden fazla kez kısmen iade edilebilir; anahtar satıra bağlanırsa ikinci
 * kısmi iade ON CONFLICT DO NOTHING ile sessizce yutulur ve stok geri gelmez.
 *
 * Anahtar biçimleri şemanın parçasıdır: movements_idempotent tekillik kısıtı
 * (tenant_id, idempotency_key) bunlara dayanır. Biçim değişikliği canlı veriyle
 * migration gerektirir.
 */
final class MovementKey
{
    /** Satış: sipariş satırı bir kez satılır. */
    public static function sale(string $orderLineId): string
    {
        return "sale:{$orderLineId}";
    }

    /** İptal: olay kimliğine bağlı — kısmi iptaller ayrışsın. */
    public static function cancellation(string $orderEventId): string
    {
        return "cancel:{$orderEventId}";
    }

    /** İade: olay kimliğine bağlı — kısmi iadeler ayrışsın. */
    public static function return(string $orderEventId): string
    {
        return "return:{$orderEventId}";
    }

    /**
     * Çok kalemli iade: olay + satır.
     *
     * Tek bir kanal olayı birden fazla kalemi iade edebilir. Anahtar yalnızca
     * olay kimliğine bağlansaydı ikinci kalem ON CONFLICT DO NOTHING ile
     * yutulur ve o kalemin stoğu geri gelmezdi. Satır kimliği eklenerek her
     * kalem kendi hareketini alır; olay kimliği ise aynı iadenin ikinci kez
     * işlenmesini hâlâ engeller.
     */
    public static function returnOf(string $orderEventId, string $orderLineId): string
    {
        return "return:{$orderEventId}:{$orderLineId}";
    }

    /** Çok kalemli iptal — returnOf ile aynı gerekçe. */
    public static function cancellationOf(string $orderEventId, string $orderLineId): string
    {
        return "cancel:{$orderEventId}:{$orderLineId}";
    }

    public static function reservation(string $reservationId): string
    {
        return "reservation:{$reservationId}";
    }

    public static function release(string $reservationEventId): string
    {
        return "release:{$reservationEventId}";
    }

    public static function manualAdjustment(string $adjustmentId): string
    {
        return "manual:{$adjustmentId}";
    }

    public static function import(string $importRowId): string
    {
        return "import:{$importRowId}";
    }

    public static function transferIn(string $transferId): string
    {
        return "transfer_in:{$transferId}";
    }

    public static function transferOut(string $transferId): string
    {
        return "transfer_out:{$transferId}";
    }

    public static function reconciliationAdjustment(string $reconciliationItemId): string
    {
        return "recon:{$reconciliationItemId}";
    }
}
