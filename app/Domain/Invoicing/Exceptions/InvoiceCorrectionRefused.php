<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Exceptions;

use RuntimeException;

/**
 * İade/iptal isteği AÇILMADI — sebep satıcının dilinde (`__()` ile).
 *
 * Satır yazılmaz, iş kuyruğa girmez: ön koşul istek anında söylenir
 * (kargo ve fatura kuralı) — açılıp sonra "başarısız" denseydi satıcı
 * nedenini bilmeden bir deneme görürdü.
 */
final class InvoiceCorrectionRefused extends RuntimeException {}
