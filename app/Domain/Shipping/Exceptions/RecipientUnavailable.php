<?php

declare(strict_types=1);

namespace App\Domain\Shipping\Exceptions;

use RuntimeException;

/**
 * Kanal gönderilebilir bir alıcı vermedi (iptal, teslimat adresi yok,
 * veri izni yok) — KALICIDIR, yeniden denenmez; metin satıcıya gösterilir.
 */
final class RecipientUnavailable extends RuntimeException {}
