<?php

declare(strict_types=1);

namespace App\Domain\Identity\Console;

use App\Domain\Identity\Support\DemoAccount;
use Illuminate\Console\Command;

/**
 * S12 · `/demo` hesabını kurar ya da (`--fresh`) baştan kurar.
 *
 * Zamanlayıcı her gece `--fresh` çalıştırır: siparişler "1 saat önce"
 * kalsın ve kimse demo verisini kalıcı biçimde eskitmesin.
 */
final class InstallDemoCommand extends Command
{
    protected $signature = 'demo:install {--fresh : Var olan demo kiracısını silip yeniden kurar}';

    protected $description = 'Herkese açık salt okunur demo hesabını kurar';

    public function handle(DemoAccount $demo): int
    {
        if (! config('entegrasyon.demo.enabled')) {
            $this->warn('Demo kapalı (DEMO_ENABLED=false) — kurulmadı.');

            return self::SUCCESS;
        }

        $result = $demo->install(fresh: (bool) $this->option('fresh'));

        $this->line(sprintf(
            '%s: %s (%s)',
            $result['created'] ? 'Kuruldu' : 'Zaten var',
            $result['tenant']->name,
            $result['tenant']->id,
        ));

        return self::SUCCESS;
    }
}
