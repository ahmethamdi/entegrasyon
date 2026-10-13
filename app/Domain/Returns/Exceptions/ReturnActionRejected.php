<?php

declare(strict_types=1);

namespace App\Domain\Returns\Exceptions;

use RuntimeException;

/**
 * Kanal iade kararını KESİN olarak reddetti (4xx, 429 hariç).
 *
 * İstek kanalda iz bırakmadı; satır `decision_requested_at` geri alınarak
 * satıcının yeniden denemesine açılır. Geçici hatalar (429, 5xx, zaman
 * aşımı) bu istisnayı DEĞİL HTTP istisnasını taşır.
 */
final class ReturnActionRejected extends RuntimeException {}
