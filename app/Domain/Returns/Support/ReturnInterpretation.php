<?php

declare(strict_types=1);

namespace App\Domain\Returns\Support;

use App\Domain\Returns\Enums\ReturnStatus;

/**
 * Kanalın ham iade durumunu kanonik duruma çevirir — YORUM çekirdektedir.
 *
 * Adapter ham durumu döndürür; yorum burada. Yorum değişince ham olgu
 * yeniden çekilmeden satırlar yeniden yorumlanabilir.
 *
 * Trendyol (belge: getClaims, approveClaimLineItems, claim audit):
 *   Created → talep açıldı, ürün yolda
 *   WaitingInAction → ürün satıcının deposuna ULAŞTI, karar bekleniyor
 *     (onay ucu "deponuza dönen iadeleri onaylamak" içindir ve yalnız bu
 *     durumda çalışır). FİZİKSEL VARIŞIN KANITI budur.
 *   InAnalysis / WaitingFraudCheck → kanal inceliyor
 *   Accepted / Rejected / Cancelled → sonuç; Unresolved → ihtilaf
 *
 * Hepsiburada (OpenAPI talep-iade): NewRequest, AwaitingAction,
 *   AwaitingPreApproval, InDispute, Accepted, Refunded, Rejected, Cancelled.
 *   HB'de "kabul" ürünün depoya dönmesini KANITLAMAZ ("ürün müşteride
 *   kalsın, para iadesi" seçeneği var) — fiziksel varış kanıtı YOK ve S4
 *   yolundan HB stoğu dönmez.
 */
final class ReturnInterpretation
{
    private const MAP = [
        'trendyol' => [
            'Created' => ReturnStatus::REQUESTED,
            'WaitingInAction' => ReturnStatus::AWAITING_ACTION,
            'InAnalysis' => ReturnStatus::UNDER_REVIEW,
            'WaitingFraudCheck' => ReturnStatus::UNDER_REVIEW,
            'Accepted' => ReturnStatus::ACCEPTED,
            'Rejected' => ReturnStatus::REJECTED,
            'Unresolved' => ReturnStatus::DISPUTED,
            'Cancelled' => ReturnStatus::CANCELLED,
        ],
        'hepsiburada' => [
            'NewRequest' => ReturnStatus::REQUESTED,
            'AwaitingPreApproval' => ReturnStatus::REQUESTED,
            'AwaitingAction' => ReturnStatus::AWAITING_ACTION,
            'InDispute' => ReturnStatus::DISPUTED,
            'Accepted' => ReturnStatus::ACCEPTED,
            'Refunded' => ReturnStatus::ACCEPTED,
            'Rejected' => ReturnStatus::REJECTED,
            'Cancelled' => ReturnStatus::CANCELLED,
        ],
    ];

    /** Fiziksel varışı KANITLAYAN ham durumlar (kanal başına). */
    private const RECEIPT_EVIDENCE = [
        'trendyol' => ['WaitingInAction'],
    ];

    public static function status(string $channel, ?string $raw): ReturnStatus
    {
        if ($raw === null || $raw === '') {
            return ReturnStatus::UNKNOWN;
        }

        return self::MAP[$channel][$raw] ?? ReturnStatus::UNKNOWN;
    }

    /** Bu ham durum ürünün satıcı deposuna ulaştığını kanıtlıyor mu. */
    public static function provesReceipt(string $channel, ?string $raw): bool
    {
        return $raw !== null && in_array($raw, self::RECEIPT_EVIDENCE[$channel] ?? [], true);
    }

    /**
     * Bu kanalda kabul edilen iadenin stoğu S4 yolundan dönebilir mi.
     *
     * Yalnız fiziksel varış kanıtı tanımlı kanallar. HB'nin mevcut
     * (S4 öncesi) `Accepted` stok yolu sipariş yoklamasında kalır; iki
     * yol aynı iadeyi iki kez stoklamasın diye burada HB KAPALIDIR.
     */
    public static function restocks(string $channel): bool
    {
        return isset(self::RECEIPT_EVIDENCE[$channel]);
    }
}
