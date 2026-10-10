<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Exceptions;

use RuntimeException;

/**
 * Sipariş faturalanamaz — yeniden denemek sonucu değiştirmez.
 *
 * İptal edilmiş paket, kanalda bulunamayan sipariş, kalemsiz yanıt.
 * Mesaj satıcıya olduğu gibi gösterilir; kişisel veri İÇERMEZ.
 */
final class InvoiceDataUnavailable extends RuntimeException {}
