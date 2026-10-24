<?php

declare(strict_types=1);

namespace Tests\Feature\Backup;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * S13 · `docker/prod/prune-deploy-backups.sh` gerçek kabukla, geçici
 * klasörde: yalnız `oncesi-*.dump`, en yeni N + son G gün kalır.
 */
final class PruneDeployBackupsScriptTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/prune-test-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $f) {
            unlink($f);
        }
        rmdir($this->dir);
        parent::tearDown();
    }

    private function file(string $name, int $ageDays): void
    {
        $path = "{$this->dir}/{$name}";
        file_put_contents($path, 'x');
        touch($path, time() - $ageDays * 86400 - 60);
    }

    private function prune(int $keep, int $days): string
    {
        $process = new Process(['sh', base_path('docker/prod/prune-deploy-backups.sh'), $this->dir, (string) $keep, (string) $days]);
        $process->mustRun();

        return $process->getOutput();
    }

    #[Test]
    public function keeps_newest_n_and_recent_days_and_never_touches_other_files(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->file(sprintf('oncesi-c%02d-34pazar.dump', $i), $i); // 0..29 gün eski
        }
        $this->file('34pazar-2026-09-01.dump', 50);   // gece yedeği: dokunulmaz
        $this->file('env-34pazar-oncesi-x', 60);       // .env yedeği: dokunulmaz

        $out = $this->prune(keep: 5, days: 14);

        $left = array_map('basename', glob($this->dir.'/*') ?: []);
        $this->assertContains('34pazar-2026-09-01.dump', $left);
        $this->assertContains('env-34pazar-oncesi-x', $left);
        $this->assertContains('oncesi-c13-34pazar.dump', $left, 'son 14 gün korunur');
        $this->assertNotContains('oncesi-c14-34pazar.dump', $left);
        $this->assertNotContains('oncesi-c29-34pazar.dump', $left);
        $this->assertCount(14 + 2, $left);
        $this->assertStringContainsString('16 silindi', $out);
    }

    #[Test]
    public function the_newest_n_survive_even_when_all_are_old(): void
    {
        for ($i = 0; $i < 8; $i++) {
            $this->file(sprintf('oncesi-c%02d-34pazar.dump', $i), 100 + $i);
        }

        $this->prune(keep: 3, days: 14);

        $left = array_map('basename', glob($this->dir.'/*') ?: []);
        sort($left);
        $this->assertSame(['oncesi-c00-34pazar.dump', 'oncesi-c01-34pazar.dump', 'oncesi-c02-34pazar.dump'], $left);
    }
}
