<?php

declare(strict_types=1);

namespace App\Domain\Channels\Console;

use App\Domain\Channels\Support\ChannelHealthSweep;
use Illuminate\Console\Command;

/**
 * Sağlık taramasının komut kabuğu — mantık `ChannelHealthSweep`'te.
 *
 * Ayrım `RefreshExpiringTokensCommand` ile aynı gerekçeye dayanır:
 * `Command::run()` rezerve imzadır. Zamanlaması `routes/console.php` içinde.
 */
final class CheckChannelsHealthCommand extends Command
{
    protected $signature = 'channels:health
        {--confirm-delay=20 : İlk başarısız ölçümden sonra kaç saniye beklenip yeniden ölçülsün}';

    protected $description = 'Bağlı kanalların sağlığını ölçer; kopan bağlantıyı panelde kırmızıya çeker';

    public function handle(ChannelHealthSweep $sweep): int
    {
        $result = $sweep->run(max(0, (int) $this->option('confirm-delay')));

        $this->line(sprintf(
            'healthy=%d unhealthy=%d recovered=%d blips=%d',
            $result['healthy'],
            $result['unhealthy'],
            $result['recovered'],
            $result['blips'],
        ));

        return self::SUCCESS;
    }
}
