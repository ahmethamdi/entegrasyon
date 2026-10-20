<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Support;

/**
 * İade kaydının içeriği — KANALDAN DEĞİL, bizim kanonik kayıtlarımızdan.
 *
 * Kalemler kabul edilen iade talebi kalemleri (`return_request_lines`),
 * fiyatlar siparişin SATIŞ ANI görüntüsüdür (`order_lines.net_line_total`,
 * `vat_rate`) — ADR-007. Kanaldan anlık okunsaydı iade sonrası değişen
 * paket (Trendyol iade edilen kalemi pakette göstermeyebilir) faturadan
 * farklı tutar verirdi.
 *
 * Alıcı TAŞIMAZ: iade kaydı satış faturasının carisine bağlanır
 * (`Invoice::provider_contact_id`); kişisel veri yeniden okunmaz.
 */
final readonly class ReturnDraft
{
    /**
     * @param  list<InvoiceLine>  $lines
     */
    public function __construct(
        public array $lines,
        public string $orderNumber,
        public string $platformName,
        public string $currency = 'TRY',
    ) {}
}
