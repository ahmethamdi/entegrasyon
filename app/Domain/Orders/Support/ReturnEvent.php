<?php

declare(strict_types=1);

namespace App\Domain\Orders\Support;

/**
 * Kanaldan gelen iade olayı — çok kalemli olabilir.
 *
 * Mimari Karar Dokümanı v2.2 · §5 · Çok kalemli iptal ve iade, §1 · Karar 10.
 *
 * externalRef KANALIN OLAY KİMLİĞİDİR ve idempotency çıpasıdır: aynı iade
 * ikinci kez geldiğinde order_events satırı çakışır ve hiçbir hareket
 * oluşmaz. Farklı iki kısmi iade farklı externalRef taşır ve ayrışır.
 *
 * KÜMÜLATİF MOD — kanal "bu satırdan TOPLAM şu kadar iade edildi" der:
 *   Bazı kanallar (Woo) iadeyi tek tek olay olarak değil, siparişin
 *   o anki durumu olarak gönderir: her güncelleme TÜM iadeleri taşır.
 *   Artımlı okunsaydı her sipariş güncellemesi aynı iadeyi yeniden
 *   stoğa eklerdi. Kümülatif modda miktar HEDEFTİR ve yalnızca
 *   şimdiye kadar uygulanandan FARKI hareket üretir — tekrar gelen
 *   mesaj sıfır fark üretir, kaçırılmış bir iade sonraki mesajda
 *   yakalanır.
 */
final readonly class ReturnEvent
{
    /**
     * @param  list<ReturnedLine>  $lines
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $orderId,
        public ?string $externalRef,
        public array $lines,
        public array $payload = [],
        public ?\DateTimeInterface $occurredAt = null,
        public ?string $inboxMessageId = null,
        public bool $cumulative = false,
    ) {}

    /** @return list<string> */
    public function orderLineIds(): array
    {
        return array_map(
            static fn (ReturnedLine $line): string => $line->orderLineId,
            $this->lines,
        );
    }
}
