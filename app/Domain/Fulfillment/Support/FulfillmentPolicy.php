<?php

declare(strict_types=1);

namespace App\Domain\Fulfillment\Support;

use App\Domain\Orders\Enums\OrderState;
use App\Domain\Orders\Models\Order;

/**
 * Hangi KANONİK durum hangi depo işlemine izin verir — TEK KAYNAK.
 *
 * Ham kanal durumu (Trendyol `Picking`, `Invoiced`…) burada OKUNMAZ;
 * adapter sınırında `OrderState`'e çevrilmiştir (ADR-001). Durumu
 * bilinmeyen (NULL) sipariş hiçbir işleme alınmaz: yanlış pakete etiket
 * basmaktansa satıcıya "durum bilinmiyor" demek doğrudur.
 */
final class FulfillmentPolicy
{
    public const CREATE_LABEL = 'create_label';

    public const DOWNLOAD_LABEL = 'download_label';

    public const INVOICE_DOCUMENT = 'invoice_document';

    public const PICK = 'pick';

    /** @var array<string, list<OrderState>> */
    private const ALLOWED = [
        // Etiket kargoya vermeden ÖNCE istenir.
        self::CREATE_LABEL => [OrderState::AWAITING_SHIPMENT],
        // Alınmış etiket kargolandıktan sonra da yeniden basılabilir.
        self::DOWNLOAD_LABEL => [OrderState::AWAITING_SHIPMENT, OrderState::SHIPPED],
        self::INVOICE_DOCUMENT => [OrderState::AWAITING_SHIPMENT, OrderState::SHIPPED, OrderState::DELIVERED, OrderState::PARTIALLY_RETURNED],
        // Toplama/paketleme yalnız gönderilecek siparişte anlamlı.
        self::PICK => [OrderState::AWAITING_SHIPMENT],
    ];

    public static function allows(Order $order, string $operation): bool
    {
        return self::allowsState(OrderState::tryFrom((string) $order->state), $operation);
    }

    public static function allowsState(?OrderState $state, string $operation): bool
    {
        return $state !== null && in_array($state, self::ALLOWED[$operation] ?? [], true);
    }
}
