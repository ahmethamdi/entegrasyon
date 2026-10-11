<?php

declare(strict_types=1);

namespace App\Domain\Channels\Contracts;

/**
 * İşaret: kanalda ürün yaratma ASENKRON ve sonucu belirsiz kalabilir
 * (HB `import` → trackingId, N11 `product-create` → taskId).
 *
 * Çekirdek (`PushListing`) bu kanallarda yaratma isteğini listing başına
 * KOŞULLU bir talep ile bir kez gönderir; zaman aşımı/5xx sonrası tekrar
 * GÖNDERMEZ, yalnız `findExistingListing` ile arar. Bulamazsa
 * `REMOTE_STATE_UNKNOWN` — yeniden yaratma satıcının açık kararıdır.
 *
 * Diğer kanalların (Trendyol, Shopify, Woo…) davranışı DEĞİŞMEZ.
 */
interface GuardsAmbiguousCreate {}
