<?php

declare(strict_types=1);

namespace App\Domain\Fulfillment\Exceptions;

use RuntimeException;

/**
 * Seçimde bu kiracıda çözülemeyen sipariş kimliği var.
 *
 * Başka kiracının siparişi ile hiç var olmayan kimlik AYNI istisnayı verir —
 * cevap, kimliğin başka bir hesapta var olup olmadığını SIZDIRMAZ.
 */
final class SelectionInvalid extends RuntimeException {}
