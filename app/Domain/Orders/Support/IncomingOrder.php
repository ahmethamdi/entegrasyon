<?php

declare(strict_types=1);

namespace App\Domain\Orders\Support;

/**
 * Kanaldan gelen kanonik sipariş — alım girdisi.
 *
 * Mimari Karar Dokümanı v2.2 · §5 · Sipariş alımı, §1 · Karar 24.
 *
 * Adapter'ın OrderNormalizer'ı ham gövdeyi bu biçime çevirir; IngestChannelOrder
 * kanaldan haberdar olmaz. Bu ayrım olmadan alım mantığı her kanal için
 * yeniden yazılırdı.
 */
final readonly class IncomingOrder
{
    /**
     * @param  list<IncomingOrderLine>  $lines
     * @param  array<string, mixed>  $customerRef  Maskelenmiş kişisel veri
     */
    public function __construct(
        public string $channelConnectionId,
        public string $externalId,
        public array $lines,
        public ?string $externalNumber = null,
        public string $status = 'pending',
        public ?string $financialStatus = null,
        public string $currency = 'TRY',
        public string $subtotal = '0',
        public string $shippingTotal = '0',
        public string $taxTotal = '0',
        public string $grandTotal = '0',
        public ?\DateTimeInterface $placedAt = null,
        public array $customerRef = [],
        public ?string $inboxMessageId = null,
    ) {}

    /** Stok düşülecek satırlar — eşleşmemiş SKU'lar hariç. */
    public function stockableLines(): array
    {
        return array_values(array_filter(
            $this->lines,
            static fn (IncomingOrderLine $line): bool => $line->variantId !== null,
        ));
    }

    /**
     * Kilitlenecek varyantlar — TEKİL.
     *
     * Aynı varyant iki satırda geçebilir (farklı kalem, aynı SKU); kilit
     * sorgusu onu bir kez ister.
     *
     * @return list<string>
     */
    public function variantIds(): array
    {
        return array_values(array_unique(array_map(
            static fn (IncomingOrderLine $line): string => $line->variantId,
            $this->stockableLines(),
        )));
    }
}
