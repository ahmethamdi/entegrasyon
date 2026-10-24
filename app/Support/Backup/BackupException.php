<?php

declare(strict_types=1);

namespace App\Support\Backup;

use RuntimeException;

/** Off-site yedek ya da geri yükleme provası adımı başarısız. Mesajda sır yok. */
final class BackupException extends RuntimeException {}
