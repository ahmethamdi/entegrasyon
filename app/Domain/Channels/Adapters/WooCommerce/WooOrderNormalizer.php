<?php

declare(strict_types=1);

namespace App\Domain\Channels\Adapters\WooCommerce;

use App\Domain\Messaging\Models\InboxMessage;
use App\Domain\Sync\Support\NormalizedOrderEvent;
use DateTimeImmutable;
use Throwable;

/**
 * Woo sipariş gövdesini kanonik olaya çevirir.
 *
 * Mimari Karar Dokümanı v2.2 · §1 · Karar 24, §6 · Yönlendirme, §7.
 *
 * DEĞİŞMEZ KURAL — TİP AYRIMI:
 *   created / updated / cancelled / returned AYRI yollara gider. Tek yola
 *   sokulsaydı iptal ve iade siparişin yeniden yaratılması gibi işlenir ve
 *   stok iki kez düşerdi. Woo tipi ikisinden okur: webhook topic başlığı
 *   (`order.created`) ve siparişin `status` alanı (`cancelled`, `refunded`).
 *
 * TİP SIRASI ÖNEMLİ: Woo iptali `order.updated` topic'iyle gönderir ve
 * durumu `cancelled` yapar. Yalnızca topic'e baksaydık iptal bir güncelleme
 * sanılır, stok geri EKLENMEZ ve bakiye kalıcı olarak eksik kalırdı.
 * Bu yüzden durum alanı topic'i EZER.
 *
 * externalRef stok hareketi idempotency anahtarının çıpasıdır: aynı iptal
 * ikinci kez geldiğinde order_events satırı çakışır ve hareket oluşmaz.
 */
final class WooOrderNormalizer
{
    /** Woo durumları → kanonik olay tipi. */
    private const STATUS_TO_TYPE = [
        'cancelled' => 'cancelled',
        'refunded' => 'returned',
        'completed' => 'updated',
        'processing' => 'updated',
        'on-hold' => 'updated',
        'pending' => 'updated',
        'failed' => 'updated',
    ];

    public static function normalize(InboxMessage $message): ?NormalizedOrderEvent
    {
        /** @var array<string, mixed> $payload */
        $payload = is_array($message->payload) ? $message->payload : [];

        $externalOrderId = $payload['id'] ?? null;

        if ($externalOrderId === null) {
            // Sipariş kimliği yoksa hiçbir şey yapılamaz; inbox satırı hata
            // durumuna düşer ve elle incelenir — sessizce yutulmaz.
            return null;
        }

        $type = self::resolveType($message, $payload);

        return new NormalizedOrderEvent(
            type: $type,
            externalOrderId: (string) $externalOrderId,
            // Olay çıpası: teslim kimliği varsa o, yoksa durum+kimlik bileşimi.
            // Yalnızca sipariş kimliğine bağlansaydı aynı siparişin iptali ve
            // iadesi çakışır, ikincisi sessizce yutulurdu.
            externalRef: $message->external_event_id ?? "{$externalOrderId}:{$type}",
            payload: self::toCanonicalPayload($payload, $type),
            occurredAt: self::parseDate($payload),
        );
    }

    /**
     * Olay tipi — durum alanı topic'i EZER.
     *
     * @param  array<string, mixed>  $payload
     */
    private static function resolveType(InboxMessage $message, array $payload): string
    {
        $status = mb_strtolower((string) ($payload['status'] ?? ''));

        // İptal ve iade her koşulda kendi yoluna gider; Woo bunları
        // `order.updated` topic'iyle gönderdiği için topic'e güvenilemez.
        if ($status === 'cancelled' || $status === 'refunded') {
            return self::STATUS_TO_TYPE[$status];
        }

        // Kısmi iade: refunds dizisi doluysa iade yoludur.
        if (is_array($payload['refunds'] ?? null) && $payload['refunds'] !== []) {
            return 'returned';
        }

        $topic = mb_strtolower((string) ($message->event_type ?? ''));

        if (str_contains($topic, 'created')) {
            return 'created';
        }

        if (str_contains($topic, 'deleted')) {
            return 'cancelled';
        }

        if (str_contains($topic, 'updated')) {
            return self::STATUS_TO_TYPE[$status] ?? 'updated';
        }

        // Topic okunamadıysa durumdan türet; o da yoksa yeni sipariş varsay.
        return self::STATUS_TO_TYPE[$status] ?? 'created';
    }

    /**
     * Woo gövdesini OrderPayloadMapper'ın beklediği kanonik biçime çevirir.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private static function toCanonicalPayload(array $payload, string $type): array
    {
        $lines = $type === 'returned'
            ? self::refundLines($payload)
            : self::orderLines($payload);

        return [
            'type' => $type,
            'external_number' => isset($payload['number']) ? (string) $payload['number'] : null,
            'status' => (string) ($payload['status'] ?? 'pending'),
            'financial_status' => self::financialStatus($payload),
            'currency' => (string) ($payload['currency'] ?? 'TRY'),
            'subtotal' => self::subtotal($payload),
            'shipping_total' => (string) ($payload['shipping_total'] ?? '0'),
            'tax_total' => (string) ($payload['total_tax'] ?? '0'),
            'grand_total' => (string) ($payload['total'] ?? '0'),
            'lines' => $lines,
            // Kişisel veri taşınmaz; yalnızca referans. PayloadRedactor
            // e-posta ve adı zaten maskeler, ama kanonik yükte hiç tutmamak
            // daha güvenlidir.
            'customer_ref' => array_filter([
                'external_customer_id' => isset($payload['customer_id'])
                    ? (string) $payload['customer_id']
                    : null,
            ]),
        ];
    }

    /**
     * Sipariş kalemleri.
     *
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    private static function orderLines(array $payload): array
    {
        $lines = [];

        foreach ((array) ($payload['line_items'] ?? []) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $lines[] = [
                'external_line_id' => (string) ($item['id'] ?? ''),
                // SKU eşleşmezse order_lines.variant_id NULL kalır ve satır
                // PENDING olur — sipariş KAYBEDİLMEZ (Karar 24).
                'sku' => (string) ($item['sku'] ?? ''),
                'title' => (string) ($item['name'] ?? ($item['sku'] ?? '')),
                'quantity' => (int) ($item['quantity'] ?? 0),
                'unit_price' => (string) ($item['price'] ?? '0'),
                'line_total' => (string) ($item['total'] ?? '0'),
            ];
        }

        return $lines;
    }

    /**
     * İade kalemleri — Woo iade miktarlarını NEGATİF gönderir.
     *
     * Mutlak değere çevrilir: ApplyMovement daima POZİTİF miktar bekler ve
     * yönü hareket TÜRÜNDEN türetir. Çağıranın işaret hesaplaması gerekmez,
     * böylece "eksi mi artı mı" hatası imkânsızlaşır.
     *
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    private static function refundLines(array $payload): array
    {
        $lines = [];

        foreach ((array) ($payload['refunds'] ?? []) as $refund) {
            if (! is_array($refund)) {
                continue;
            }

            foreach ((array) ($refund['line_items'] ?? []) as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $quantity = abs((int) ($item['quantity'] ?? 0));

                if ($quantity === 0) {
                    continue;
                }

                $lines[] = [
                    'external_line_id' => (string) ($item['id'] ?? ''),
                    'sku' => (string) ($item['sku'] ?? ''),
                    'title' => (string) ($item['name'] ?? ''),
                    'quantity' => $quantity,
                    'unit_price' => (string) ($item['price'] ?? '0'),
                    'line_total' => (string) abs((float) ($item['total'] ?? 0)),
                ];
            }
        }

        // Kalem bazlı iade yoksa tüm siparişin iadesi: kalemler sipariş
        // satırlarından alınır.
        return $lines === [] ? self::orderLines($payload) : $lines;
    }

    /** @param array<string, mixed> $payload */
    private static function financialStatus(array $payload): ?string
    {
        if (($payload['date_paid'] ?? null) !== null) {
            return 'paid';
        }

        return ($payload['status'] ?? null) === 'refunded' ? 'refunded' : null;
    }

    /**
     * Ara toplam — Woo doğrudan vermez, kalem toplamlarından hesaplanır.
     *
     * @param  array<string, mixed>  $payload
     */
    private static function subtotal(array $payload): string
    {
        $sum = 0.0;

        foreach ((array) ($payload['line_items'] ?? []) as $item) {
            if (is_array($item)) {
                $sum += (float) ($item['subtotal'] ?? $item['total'] ?? 0);
            }
        }

        return number_format($sum, 2, '.', '');
    }

    /** @param array<string, mixed> $payload */
    private static function parseDate(array $payload): ?DateTimeImmutable
    {
        $raw = $payload['date_created_gmt'] ?? $payload['date_created'] ?? null;

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($raw);
        } catch (Throwable) {
            return null;
        }
    }
}
