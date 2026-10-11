<?php

declare(strict_types=1);

namespace App\Domain\Sync\Exceptions;

use RuntimeException;

/**
 * Önceki yaratma isteğinin sonucu belirsiz ve kanalda kayıt bulunamadı.
 *
 * Kanal isteği almış ve henüz işlememiş olabilir: yeniden yaratmak kopya
 * ilan riski taşır. Otomatik tekrar YAPILMAZ; yalnız arama yapılır.
 * Yeniden yaratma satıcının AÇIK "yeniden yayınla" kararıdır
 * (`PublishListing` talebi sıfırlar).
 */
final class RemoteListingStateUnknown extends RuntimeException {}
