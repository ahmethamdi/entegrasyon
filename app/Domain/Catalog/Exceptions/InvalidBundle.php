<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Exceptions;

use RuntimeException;

/**
 * Set tanımı kurallara uymuyor — metin satıcıya gösterilir.
 */
final class InvalidBundle extends RuntimeException {}
