<?php

declare(strict_types=1);

namespace App\Domain\Orders\Routing;

use App\Domain\Catalog\Models\Variant;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Support\IncomingOrder;
use App\Domain\Orders\Support\IncomingOrderLine;
use App\Domain\Sync\Support\NormalizedOrderEvent;

/**
 * Kanonik olay yükünü domain girdisine çevirir.
 *
 * Mimari Karar Dokümanı v2.2 · §6 · Yönlendirme, §4 · order_lines.
 *
 * SKU EŞLEŞTİRME BURADA YAPILIR: kanal SKU gönderir, bizim varyant
 * kimliğimizi bilmez. Eşleşmeyen SKU satırı DÜŞÜRÜLMEZ — variant_id NULL
 * bırakılır ve satır PENDING kalır. Sipariş kaybetmek, stok
 * tutarsızlığından daha kötüdür.
 *
 * Adapter'ın Normalizer'ı kanal formatını bu yüke çevirir; bu sınıf yükü
 * domain nesnelerine bağlar. İki adım ayrı: birincisi kanala özgü,
 * ikincisi değil.
 */
final class OrderPayloadMapper
{
    /**
     * Kanonik olaydan sipariş alım girdisi üretir.
     *
     * Beklenen yük biçimi (adapter Normalizer'ı bunu üretir):
     *   lines: [{ external_line_id, sku, title, quantity, unit_price, line_total }]
     */
    public static function toIncomingOrder(
        NormalizedOrderEvent $normalized,
        string $channelConnectionId,
        ?string $inboxMessageId = null,
    ): IncomingOrder {
        $payload = $normalized->payload;
        $rawLines = $payload['lines'] ?? [];

        $skus = array_values(array_filter(array_map(
            static fn (array $line): ?string => $line['sku'] ?? null,
            $rawLines,
        )));

        $variantsBySku = self::resolveVariants($skus);

        $lines = [];

        foreach ($rawLines as $index => $line) {
            $sku = (string) ($line['sku'] ?? '');

            $lines[] = new IncomingOrderLine(
                externalLineId: (string) ($line['external_line_id'] ?? $index),
                sku: $sku,
                title: (string) ($line['title'] ?? $sku),
                quantity: (int) ($line['quantity'] ?? 0),
                // Eşleşmezse NULL — satır kaydedilir, stok düşülmez.
                variantId: $variantsBySku[$sku] ?? null,
                unitPrice: (string) ($line['unit_price'] ?? '0'),
                lineTotal: (string) ($line['line_total'] ?? '0'),
            );
        }

        return new IncomingOrder(
            channelConnectionId: $channelConnectionId,
            externalId: $normalized->externalOrderId,
            lines: $lines,
            externalNumber: $payload['external_number'] ?? null,
            status: (string) ($payload['status'] ?? 'pending'),
            financialStatus: $payload['financial_status'] ?? null,
            currency: (string) ($payload['currency'] ?? 'TRY'),
            subtotal: (string) ($payload['subtotal'] ?? '0'),
            shippingTotal: (string) ($payload['shipping_total'] ?? '0'),
            taxTotal: (string) ($payload['tax_total'] ?? '0'),
            grandTotal: (string) ($payload['grand_total'] ?? '0'),
            placedAt: $normalized->occurredAt,
            customerRef: $payload['customer_ref'] ?? [],
            inboxMessageId: $inboxMessageId,
        );
    }

    /**
     * İptal/iade olayındaki kalemleri mevcut sipariş satırlarına bağlar.
     *
     * Kanal genellikle external_line_id gönderir; yoksa SKU ile eşleşilir.
     * Hiçbiri tutmuyorsa kalem atlanır ve çağıran uyarı düşer — sessizce
     * yutulmaz.
     *
     * @return list<array{line_id: string, quantity: int}>
     */
    public static function toAffectedLines(NormalizedOrderEvent $normalized, Order $order): array
    {
        $rawLines = $normalized->payload['lines'] ?? [];

        if ($rawLines === []) {
            return [];
        }

        $orderLines = $order->lines()->get();
        $byExternalId = $orderLines->keyBy('external_line_id');
        $bySku = $orderLines->keyBy('sku');

        $resolved = [];

        foreach ($rawLines as $line) {
            $quantity = (int) ($line['quantity'] ?? 0);

            if ($quantity <= 0) {
                continue;
            }

            $match = null;

            if (isset($line['external_line_id'])) {
                $match = $byExternalId->get((string) $line['external_line_id']);
            }

            if ($match === null && isset($line['sku'])) {
                $match = $bySku->get((string) $line['sku']);
            }

            if ($match === null) {
                continue;
            }

            $resolved[] = ['line_id' => $match->id, 'quantity' => $quantity];
        }

        return $resolved;
    }

    /**
     * SKU → variant_id haritası.
     *
     * Tek sorguda çözülür; satır başına sorgu N+1 üretirdi ve sipariş alımı
     * transaction içinde çalıştığı için kilit süresini uzatırdı.
     *
     * @param  list<string>  $skus
     * @return array<string, string>
     */
    private static function resolveVariants(array $skus): array
    {
        if ($skus === []) {
            return [];
        }

        return Variant::query()
            ->whereIn('sku', array_unique($skus))
            ->pluck('id', 'sku')
            ->all();
    }
}
