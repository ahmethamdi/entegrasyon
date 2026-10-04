<?php

declare(strict_types=1);

namespace App\Domain\Sync\Support;

/**
 * Kanalda gözlenen listing durumu.
 *
 * Mimari Karar Dokümanı v2.2 · §7, §10.
 *
 * listing_sync_states.remote_* alanlarını besler — üç sürüm alanının
 * üçüncüsü. Bu okuma olmadan çakışma tespiti imkânsızdır: neyin
 * gönderildiğini biliriz ama kanalda ne olduğunu bilmeyiz.
 */
final readonly class RemoteListing
{
    /** @param array<string, mixed> $raw */
    public function __construct(
        public string $externalId,
        public ?string $title = null,
        public ?int $quantity = null,
        public ?string $price = null,
        public ?string $status = null,
        public ?string $url = null,
        public array $raw = [],
        public ?\DateTimeImmutable $observedAt = null,
        // Üst ürün kimliği — kanal iki seviyeli kimlik taşıyorsa (Shopify
        // product gid, Etsy listing_id). Benimseme anında `external_id`
        // ile BİRLİKTE alınmazsa güncelleme yolu hangi ürünü yazacağını
        // bilemez.
        public ?string $parentExternalId = null,
    ) {}
}
