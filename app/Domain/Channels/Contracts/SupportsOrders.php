<?php

declare(strict_types=1);

namespace App\Domain\Channels\Contracts;

use App\Domain\Messaging\Models\InboxMessage;
use App\Domain\Orders\Models\Order;
use App\Domain\Sync\Support\NormalizedOrderEvent;
use App\Domain\Sync\Support\OrderPage;
use Carbon\CarbonInterface;

/**
 * Sipariş alma yeteneği.
 *
 * Mimari Karar Dokümanı v2.2 · §7, §1 · Karar 24.
 *
 * parseOrderEvent() olayın TİPİNİ de belirler: created / updated / cancelled /
 * returned ayrı yollara gider (OrderEventRouter). Tek yola sokulsaydı iptal ve
 * iade siparişin yeniden yaratılması gibi işlenirdi.
 */
interface SupportsOrders
{
    /** Yoklama ile sipariş çeker; inbox'a yazılmak üzere ham olay döner. */
    public function fetchOrders(CarbonInterface $since, ?string $cursor = null): OrderPage;

    /** Ham gövdeyi kanonik olaya çevirir — tip dahil. */
    public function parseOrderEvent(InboxMessage $message): ?NormalizedOrderEvent;

    public function acknowledgeOrder(Order $order): AdapterResult;
}
