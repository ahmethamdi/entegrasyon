<?php

declare(strict_types=1);

namespace App\Domain\Channels\Adapters\Shopify;

use App\Domain\Messaging\Models\InboxMessage;
use App\Domain\Sync\Support\NormalizedOrderEvent;
use DateTimeImmutable;
use Throwable;

/**
 * Shopify sipariş gövdesini kanonik olaya çevirir.
 *
 * V3.0 · §06.6 · §19 · v2.2 §1 · Karar 24, §6 · Yönlendirme, §7.
 *
 * ─────────────────────────────────────────────────────────────────────
 * ⚠️ TİP KONUDAN OKUNUR — DURUM ALANI TOPIC'İ EZMEZ (WOO'NUN TERSİ)
 * ─────────────────────────────────────────────────────────────────────
 * `WooOrderNormalizer` durum alanının topic'i EZMESİNİ gerektirir çünkü
 * Woo iptali `order.updated` topic'iyle gönderir ve topic'e güvenilemez.
 *
 * SHOPIFY'DA İPTAL AYRI BİR KONUDUR (`orders/cancelled`) ve o kural
 * BURAYA KOPYALANMAZ (§06.6'nın açık uyarısı). Kopyalansaydı `cancelled_at`
 * DOLU bir `orders/updated` olayı — yani iptal edilmiş bir siparişin
 * SONRAKİ güncellemesi (etiket, not) — YENİDEN iptal sanılır ve stok
 * İKİNCİ KEZ geri eklenirdi. Bakiye kalıcı olarak ŞİŞERDİ ve bu, iptalin
 * kaçırılmasından farklı ama eşit derecede sessiz bir hatadır.
 *
 * `order_events` idempotency çıpası ikinci hareketi ELEMEZ: çıpa aynı
 * `external_ref` içindir ve o güncellemenin KENDİ olay kimliği farklıdır.
 *
 * ─────────────────────────────────────────────────────────────────────
 * ⚠️ İADE `refunds/create` KONUSUNDADIR
 * ─────────────────────────────────────────────────────────────────────
 * Yalnızca sipariş konuları dinlenseydi iade HİÇ görülmez ve stok geri
 * eklenmezdi — bakiye kalıcı eksik kalırdı (§06.6).
 *
 * Gövde de FARKLIDIR: iade olayının kökü `refund` nesnesidir ve sipariş
 * kimliği `order_id` alanındadır, `id` DEĞİL — `id` iadenin KENDİ
 * kimliğidir.
 */
final class ShopifyOrderNormalizer
{
    /**
     * Shopify konusu → kanonik olay tipi.
     *
     * Konu ADIYLA eşlenir, ön ekle değil: `orders/` ön eki aransaydı
     * `orders/edited` (sipariş düzenleme) `created` veya `cancelled`
     * sanılabilirdi.
     */
    private const TOPIC_TO_TYPE = [
        'orders/create' => 'created',
        'orders/updated' => 'updated',
        'orders/cancelled' => 'cancelled',
        'orders/fulfilled' => 'fulfilled',
        'orders/partially_fulfilled' => 'fulfilled',
        'refunds/create' => 'returned',
        'fulfillments/create' => 'fulfilled',
        'fulfillments/update' => 'fulfilled',
    ];

    public static function normalize(InboxMessage $message): ?NormalizedOrderEvent
    {
        /** @var array<string, mixed> $payload */
        $payload = is_array($message->payload) ? $message->payload : [];

        $topic = mb_strtolower(trim((string) ($message->event_type ?? '')));
        $type = self::TOPIC_TO_TYPE[$topic] ?? 'updated';

        // İADE GÖVDESİNİN KÖKÜ FARKLIDIR: `id` iadenin kendi kimliğidir ve
        // sipariş kimliği `order_id`'dedir. `id` okunsaydı iade HİÇ VAR
        // OLMAYAN bir siparişe bağlanır, eşleşme başarısız olur ve stok
        // geri eklenmezdi.
        $externalOrderId = $type === 'returned'
            ? ($payload['order_id'] ?? null)
            : ($payload['id'] ?? null);

        if ($externalOrderId === null) {
            // Sipariş kimliği yoksa hiçbir şey yapılamaz; inbox satırı hata
            // durumuna düşer ve elle incelenir — sessizce yutulmaz.
            return null;
        }

        return new NormalizedOrderEvent(
            type: $type,
            externalOrderId: (string) $externalOrderId,
            // ÇIPA BAŞLIKTAN GELİR (§19: `X-Shopify-Event-Id` BİRİNCİL
            // kimliktir). Başlık yoksa türetilir ve TİP DE TAŞINIR:
            // yalnızca numaraya bağlansaydı aynı siparişin sonraki İPTALİ
            // birincil tekillik indeksine takılır ve `insertOrIgnore`
            // tarafından SESSİZCE YUTULURDU (v2.2 · Karar 24).
            externalRef: $message->external_event_id ?? "{$externalOrderId}:{$type}",
            payload: self::toCanonicalPayload($payload, $type),
            occurredAt: self::parseDate($payload),
        );
    }

    /**
     * Shopify gövdesini `OrderPayloadMapper`'ın beklediği biçime çevirir.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private static function toCanonicalPayload(array $payload, string $type): array
    {
        return [
            'type' => $type,
            'external_number' => isset($payload['order_number'])
                ? (string) $payload['order_number']
                : null,
            'status' => self::status($payload, $type),
            'financial_status' => isset($payload['financial_status'])
                ? (string) $payload['financial_status']
                : null,
            'currency' => (string) ($payload['currency'] ?? 'TRY'),
            // ⚠️ TOPLAMLAR `current_*` ALANLARINDAN OKUNUR. Shopify kısmi
            // iade veya sipariş düzenlemesinden SONRA `total_price` alanını
            // ORİJİNAL değerde bırakır; güncel tutar `current_total_price`
            // içindedir. Orijinal okunsaydı kısmen iade edilmiş siparişin
            // paneldeki tutarı gerçekten tahsil edilenden yüksek görünürdü.
            'subtotal' => self::money($payload, 'current_subtotal_price', 'subtotal_price'),
            'shipping_total' => self::shopMoney($payload, 'total_shipping_price_set'),
            'tax_total' => self::money($payload, 'current_total_tax', 'total_tax'),
            'grand_total' => self::money($payload, 'current_total_price', 'total_price'),
            'lines' => $type === 'returned'
                ? self::refundLines($payload)
                : self::orderLines($payload),
            // KİŞİSEL VERİ TAŞINMAZ, yalnızca referans. `PayloadRedactor`
            // e-postayı ve adı zaten maskeler ama kanonik yükte HİÇ
            // tutmamak daha güvenlidir (Woo normalizer'ıyla aynı kural).
            'customer_ref' => array_filter([
                'external_customer_id' => isset($payload['customer']['id'])
                    ? (string) $payload['customer']['id']
                    : null,
            ]),
        ];
    }

    /**
     * Sipariş kalemleri.
     *
     * ⚠️ SKU'SUZ KALEM DÜŞÜRÜLMEZ. Shopify'da SKU zorunlu DEĞİLDİR; kalem
     * atılsaydı sipariş EKSİK kaydedilirdi. Boş SKU ile taşındığında
     * `order_lines.variant_id` NULL kalır, satır PENDING olur ve stok
     * düşülmez — SİPARİŞ KAYBETMEK STOK TUTARSIZLIĞINDAN KÖTÜDÜR
     * (Karar 24).
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

            $quantity = (int) ($item['quantity'] ?? 0);
            $unitPrice = (string) ($item['price'] ?? '0');

            $lines[] = [
                'external_line_id' => (string) ($item['id'] ?? ''),
                'sku' => (string) ($item['sku'] ?? ''),
                'title' => (string) ($item['title'] ?? ($item['name'] ?? ($item['sku'] ?? ''))),
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                // Shopify kalem toplamını GÖNDERMEZ; fiyat × miktar.
                // Para float taşımaz ama burada çarpım kaçınılmaz —
                // `number_format` ile iki hane sabitlenir ve string döner.
                'line_total' => number_format((float) $unitPrice * $quantity, 2, '.', ''),
            ];
        }

        return $lines;
    }

    /**
     * İade kalemleri — `refund_line_items` altında.
     *
     * ⚠️ KALEM KİMLİĞİ `line_item_id`'DİR, iade satırının kendi `id`'si
     * DEĞİL. Kendi kimliği taşınsaydı `OrderPayloadMapper::toAffectedLines()`
     * onu sipariş satırlarında BULAMAZ ve iade kalemi sessizce atlanırdı —
     * stok geri eklenmez, bakiye kalıcı eksik kalırdı.
     *
     * ⚠️ MİKTAR POZİTİFTİR. `ApplyMovement` DAİMA pozitif miktar bekler ve
     * yönü hareket TÜRÜNDEN türetir; böylece "eksi mi artı mı" hatası
     * imkânsızlaşır (Woo normalizer'ıyla aynı kural).
     *
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    private static function refundLines(array $payload): array
    {
        $lines = [];

        foreach ((array) ($payload['refund_line_items'] ?? []) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $quantity = abs((int) ($item['quantity'] ?? 0));

            if ($quantity === 0) {
                continue;
            }

            /** @var array<string, mixed> $lineItem */
            $lineItem = is_array($item['line_item'] ?? null) ? $item['line_item'] : [];

            $lines[] = [
                // SİPARİŞ SATIRININ kimliği — iade satırının kendi id'si DEĞİL.
                'external_line_id' => (string) ($item['line_item_id'] ?? ($lineItem['id'] ?? '')),
                'sku' => (string) ($lineItem['sku'] ?? ''),
                'title' => (string) ($lineItem['title'] ?? ''),
                'quantity' => $quantity,
                'unit_price' => (string) ($lineItem['price'] ?? '0'),
                'line_total' => (string) ($item['subtotal'] ?? '0'),
            ];
        }

        return $lines;
    }

    /**
     * Sipariş durumu — kanonik metin.
     *
     * Shopify tek bir `status` alanı VERMEZ: durum `financial_status`,
     * `fulfillment_status` ve `cancelled_at` üçlüsünden okunur. Kanonik
     * alan panelde gösterilir ve stok kararı ONDAN TÜREMEZ — o kararı
     * olay TİPİ verir.
     *
     * @param  array<string, mixed>  $payload
     */
    private static function status(array $payload, string $type): string
    {
        if ($type === 'cancelled' || ($payload['cancelled_at'] ?? null) !== null) {
            return 'cancelled';
        }

        $fulfillment = $payload['fulfillment_status'] ?? null;

        if (is_string($fulfillment) && $fulfillment !== '') {
            return $fulfillment;
        }

        $financial = $payload['financial_status'] ?? null;

        return is_string($financial) && $financial !== '' ? $financial : 'pending';
    }

    /**
     * Para alanı — güncel değer tercih edilir, yoksa orijinale düşülür.
     *
     * NULL "ALAN YOK" DEMEKTİR ve orijinale düşmek doğru davranıştır;
     * `current_*` alanları yalnızca sipariş düzenlenmişse dolar.
     *
     * @param  array<string, mixed>  $payload
     */
    private static function money(array $payload, string $current, string $fallback): string
    {
        $value = $payload[$current] ?? null;

        if ($value === null || $value === '') {
            $value = $payload[$fallback] ?? null;
        }

        // Para STRING taşınır — float dönüşümü kuruş kayması üretir (§7).
        return $value === null ? '0' : (string) $value;
    }

    /**
     * `*_price_set` alanından MAĞAZA para birimindeki tutar.
     *
     * ⚠️ `shop_money` OKUNUR, `presentment_money` DEĞİL. Shopify para
     * alanlarını iki para biriminde döndürür; `presentment_money` ALICININ
     * gördüğü kurdur. Satıcının muhasebesi MAĞAZA para birimidir ve
     * presentment okunsaydı yabancı müşterili siparişte tutar yanlış para
     * biriminde kaydedilirdi.
     *
     * @param  array<string, mixed>  $payload
     */
    private static function shopMoney(array $payload, string $key): string
    {
        $set = $payload[$key] ?? null;

        if (! is_array($set)) {
            return '0';
        }

        $amount = $set['shop_money']['amount'] ?? null;

        return $amount === null ? '0' : (string) $amount;
    }

    /** @param array<string, mixed> $payload */
    private static function parseDate(array $payload): ?DateTimeImmutable
    {
        $raw = $payload['created_at'] ?? $payload['processed_at'] ?? null;

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
