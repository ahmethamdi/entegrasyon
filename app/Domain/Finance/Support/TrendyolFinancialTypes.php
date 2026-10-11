<?php

declare(strict_types=1);

namespace App\Domain\Finance\Support;

use App\Domain\Finance\Models\ChannelFinancialTransaction as Tx;
use App\Support\Money\Money;

/**
 * Trendyol finans işlem türlerinin AÇIK normalizasyonu ve sınıflandırması.
 *
 * Kaynak: canlı hesap yoklaması 11 Eki 2026 + resmî enum listesi
 * (`docs/architecture-review/S1-TRENDYOL-HAKEDIS-ANALIZI.md`).
 *
 * ⚠️ API `transactionType`'ı TÜRKÇE ETİKETLE döndürür ("Satış"); sorguda
 * İngilizce enum gönderilir. İkisi de tanınır, ham değer HER ZAMAN saklanır.
 *
 * SINIF KURALI:
 *   - VERIFIED: yalnız canlı hesapta GÖZLENEN etiketler, ve satışta yalnız
 *     gözlenen davranış (bkz. `classifySale`).
 *   - UNVERIFIED: belgede adı olan ama canlı örneği görülmemiş tür. Saklanır,
 *     görünür, kâra GİRMEZ; sipariş düzeyindeyse gerçekleşen katkıyı
 *     EKSİK yapar.
 *   - UNKNOWN: tanınmayan etiket. Aynı muamele; tahmin edilmez.
 *
 * Bir türü VERIFIED'a yükseltmek için gerçek hesapta örnek görülmeli —
 * test/mock yetmez (S1 kararı 13).
 */
final class TrendyolFinancialTypes
{
    public const SALE = 'sale';

    public const PAYOUT = 'payout';

    public const COMMISSION_INVOICE = 'commission_invoice';

    public const INCOMING_TRANSFER = 'incoming_transfer';

    /**
     * Canlıda gözlenen etiketler → [tür, sipariş düzeyi mi].
     *
     * @var array<string, array{0: string, 1: bool}>
     */
    private const OBSERVED = [
        'Satış' => [self::SALE, true],
        'Ödeme' => [self::PAYOUT, false],
        'Komisyon Faturası' => [self::COMMISSION_INVOICE, false],
        'Gelen Havale' => [self::INCOMING_TRANSFER, false],
    ];

    /**
     * Resmî enum adları (settlements + otherfinancials) → [tür, sipariş düzeyi mi].
     * Canlı örneği olmadığından hepsi UNVERIFIED.
     *
     * @var array<string, array{0: string, 1: bool}>
     */
    private const DOCUMENTED = [
        'Sale' => [self::SALE, true],
        'Return' => ['return', true],
        'Discount' => ['discount', true],
        'DiscountCancel' => ['discount_cancel', true],
        'Coupon' => ['coupon', true],
        'CouponCancel' => ['coupon_cancel', true],
        'ProvisionPositive' => ['provision_positive', true],
        'ProvisionNegative' => ['provision_negative', true],
        'ManualRefund' => ['manual_refund', true],
        'ManualRefundCancel' => ['manual_refund_cancel', true],
        'TyDiscount' => ['ty_discount', true],
        'TyDiscountCancel' => ['ty_discount_cancel', true],
        'TyCoupon' => ['ty_coupon', true],
        'TyCouponCancel' => ['ty_coupon_cancel', true],
        'SellerRevenuePositive' => ['seller_revenue_positive', true],
        'SellerRevenueNegative' => ['seller_revenue_negative', true],
        'CommissionPositive' => ['commission_positive', true],
        'CommissionNegative' => ['commission_negative', true],
        'SellerRevenuePositiveCancel' => ['seller_revenue_positive_cancel', true],
        'SellerRevenueNegativeCancel' => ['seller_revenue_negative_cancel', true],
        'CommissionPositiveCancel' => ['commission_positive_cancel', true],
        'CommissionNegativeCancel' => ['commission_negative_cancel', true],
        'DeliveryFee' => ['delivery_fee', true],
        'DeliveryFeeCancel' => ['delivery_fee_cancel', true],
        'PayByLink' => ['pay_by_link', true],
        'Stoppage' => ['stoppage', false],
        'CashAdvance' => ['cash_advance', false],
        'WireTransfer' => ['wire_transfer', false],
        'IncomingTransfer' => [self::INCOMING_TRANSFER, false],
        'ReturnInvoice' => ['return_invoice', false],
        'CommissionAgreementInvoice' => ['commission_agreement_invoice', false],
        'PaymentOrder' => [self::PAYOUT, false],
        'DeductionInvoices' => ['deduction_invoice', false],
        'FinancialItem' => ['financial_item', false],
    ];

    /** Sorguda gönderilen enum listeleri (kaynak başına). */
    public const QUERY_TYPES = [
        'settlements' => 'Sale,Return,Discount,DiscountCancel,Coupon,CouponCancel,ProvisionPositive,ProvisionNegative,ManualRefund,ManualRefundCancel,TyDiscount,TyDiscountCancel,TyCoupon,TyCouponCancel,SellerRevenuePositive,SellerRevenueNegative,CommissionPositive,CommissionNegative,SellerRevenuePositiveCancel,SellerRevenueNegativeCancel,CommissionPositiveCancel,CommissionNegativeCancel,DeliveryFee,DeliveryFeeCancel,PayByLink',
        'otherfinancials' => 'Stoppage,CashAdvance,WireTransfer,IncomingTransfer,ReturnInvoice,CommissionAgreementInvoice,PaymentOrder,DeductionInvoices,FinancialItem',
    ];

    /**
     * @return array{type: ?string, classification: string, reason: ?string, order_level: bool}
     */
    public static function classify(RemoteFinancialTransaction $tx): array
    {
        $label = trim($tx->typeRaw);

        if (isset(self::OBSERVED[$label])) {
            [$type, $orderLevel] = self::OBSERVED[$label];

            if ($type === self::SALE) {
                return [...self::classifySale($tx), 'type' => $type, 'order_level' => true];
            }

            return ['type' => $type, 'classification' => Tx::VERIFIED, 'reason' => null, 'order_level' => $orderLevel];
        }

        if (isset(self::DOCUMENTED[$label])) {
            [$type, $orderLevel] = self::DOCUMENTED[$label];

            return [
                'type' => $type,
                'classification' => Tx::UNVERIFIED,
                'reason' => 'İngilizce enum etiketiyle geldi; canlıda bu biçim gözlenmedi',
                'order_level' => $orderLevel,
            ];
        }

        return [
            'type' => null,
            'classification' => Tx::UNKNOWN,
            'reason' => "Tanınmayan işlem türü: {$label}",
            // Tanınmayan işlem siparişe bağlanabiliyorsa sipariş düzeyidir —
            // siparişin gerçekleşen katkısını EKSİK yapsın, gizlenmesin.
            'order_level' => $tx->shipmentPackageId !== null || $tx->orderNumber !== null,
        ];
    }

    /**
     * Satış YALNIZ gözlenen biçimde VERIFIED (S1 kararı 4):
     *   borç 0, alacak > 0, komisyon ve oranı dolu, hakediş dolu ve
     *   hakediş = alacak − komisyon (kuruşu kuruşuna), TRY, paket numarası dolu.
     * Biri tutmazsa UNVERIFIED — sebebiyle.
     *
     * @return array{classification: string, reason: ?string}
     */
    private static function classifySale(RemoteFinancialTransaction $tx): array
    {
        $fail = static fn (string $why): array => ['classification' => Tx::UNVERIFIED, 'reason' => "Satış gözlenen biçimde değil: {$why}"];

        if ($tx->debt === null || Money::toMinor($tx->debt) !== 0) {
            return $fail('borç sıfır değil');
        }

        if ($tx->credit === null || Money::toMinor($tx->credit) <= 0) {
            return $fail('alacak pozitif değil');
        }

        if ($tx->commissionAmount === null || $tx->commissionRate === null) {
            return $fail('komisyon bilgisi yok');
        }

        if ($tx->sellerRevenue === null) {
            return $fail('hakediş tutarı yok');
        }

        if (Money::toMinor($tx->credit) - Money::toMinor($tx->commissionAmount) !== Money::toMinor($tx->sellerRevenue)) {
            return $fail('hakediş ≠ alacak − komisyon');
        }

        if (strtoupper((string) $tx->currency) !== 'TRY') {
            return $fail('para birimi TRY değil');
        }

        if ($tx->shipmentPackageId === null) {
            return $fail('paket numarası yok');
        }

        return ['classification' => Tx::VERIFIED, 'reason' => null];
    }
}
