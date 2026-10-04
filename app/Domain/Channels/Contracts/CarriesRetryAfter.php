<?php

declare(strict_types=1);

namespace App\Domain\Channels\Contracts;

/**
 * Kanalın "şu kadar bekle" dediği hata.
 *
 * Mimari Karar Dokümanı v2.2 · §12 · `RetryPolicy` — RATE_LIMITED kanalın
 * söylediği süreye uyar, yoksa 60 sn.
 *
 * NEDEN ARAYÜZ: bekleme süresi her kanalda başka yerde yaşar (Woo/Trendyol
 * `Retry-After` başlığı, Shopify GraphQL gövdesindeki `throttleStatus`).
 * Çekirdek istisnanın SINIFINI bilseydi her yeni kanal push işlerine bir
 * `instanceof` satırı eklerdi — `if ($channel === '...')`'in kılık
 * değiştirmiş hâli. Adapter istisnası bu arayüzü taşır, çekirdek yalnızca
 * arayüzü sorar.
 */
interface CarriesRetryAfter
{
    /** Kaç saniye sonra yeniden denensin; bilinmiyorsa null. */
    public function retryAfterSeconds(): ?int;
}
