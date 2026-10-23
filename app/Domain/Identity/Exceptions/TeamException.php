<?php

declare(strict_types=1);

namespace App\Domain\Identity\Exceptions;

use RuntimeException;

/**
 * Ekip kuralı ihlali (S7) — mesajı kullanıcıya OLDUĞU GİBİ gösterilir.
 *
 * Örnek: son sahip çıkarılamaz, aynı kişi iki kez davet edilemez.
 */
final class TeamException extends RuntimeException {}
