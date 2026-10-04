<?php

declare(strict_types=1);

namespace Tests\Feature\Messaging;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * KODUN İŞ ATTIĞI HER KUYRUĞU BİR HORIZON HAVUZU DİNLİYOR MU?
 *
 * Horizon yalnız `config/horizon.php`'de adı geçen kuyrukları işler.
 * Adı geçmeyen kuyruğa atılan iş hata VERMEZ — Redis'te sonsuza kadar
 * bekler. `outbox:consume` bu yüzden üretimde hiç işlenmeyecekti: stok
 * ve fiyat değişikliği kanala dağılmazdı. Testler işleri senkron
 * koştuğu için bu boşluk hiçbir davranış testinde görünmez; bu test
 * kaynak koddaki kuyruk adlarını yapılandırmayla karşılaştırır.
 */
final class HorizonQueueContractTest extends TestCase
{
    #[Test]
    public function every_queue_used_in_code_is_served_by_a_horizon_supervisor(): void
    {
        $served = [];

        foreach (config('horizon.defaults') as $supervisor) {
            $served = [...$served, ...(array) $supervisor['queue']];
        }

        $used = $this->queuesUsedInCode();

        $this->assertNotEmpty($used, 'Tarama hiç kuyruk bulamadı — desen bozulmuş olabilir.');

        foreach ($used as $queue => $file) {
            $this->assertContains(
                $queue,
                $served,
                "'{$queue}' kuyruğuna iş atılıyor ({$file}) ama hiçbir Horizon havuzu onu dinlemiyor — iş hiç çalışmaz.",
            );
        }
    }

    /** Her üretim ortamı havuzu `defaults`'ta tanımlı (tanımsız havuz hiç başlamaz). */
    #[Test]
    public function every_production_supervisor_has_a_definition(): void
    {
        foreach (array_keys(config('horizon.environments.production')) as $name) {
            $this->assertArrayHasKey($name, config('horizon.defaults'));
        }
    }

    /** @return array<string, string> kuyruk → ilk görüldüğü dosya */
    private function queuesUsedInCode(): array
    {
        $found = [];

        foreach ((new Finder)->files()->in(app_path())->name('*.php') as $file) {
            $source = $file->getContents();

            // onQueue('ad') · $this->onQueue('ad') · QUEUE = 'ad' · [Job::class, 'ad'] eşlemeleri
            preg_match_all("/onQueue\\(\\s*'([^']+)'\\s*\\)/", $source, $a);
            preg_match_all("/const QUEUE\\s*=\\s*'([^']+)'/", $source, $b);
            preg_match_all("/\\[\\w+::class,\\s*'([a-z]+:[a-z]+)'\\]/", $source, $c);

            foreach ([...$a[1], ...$b[1], ...$c[1]] as $queue) {
                $found[$queue] ??= $file->getRelativePathname();
            }
        }

        return $found;
    }
}
