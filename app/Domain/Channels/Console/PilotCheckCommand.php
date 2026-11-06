<?php

declare(strict_types=1);

namespace App\Domain\Channels\Console;

use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Support\PilotCheck;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Pilot satıcı doğrulamasının komut kabuğu — mantık `PilotCheck`'te.
 *
 * Kanala YALNIZ OKUMA isteği atar; rapor `storage/app/pilot-check/`
 * altına JSON olarak yazılır (kişisel veri içermez). Kanıt satırı
 * (`CertificationEvidence`) elle yazılır: bu komut "doğrulandı" demez,
 * kanıtı toplar.
 */
final class PilotCheckCommand extends Command
{
    protected $signature = 'channels:pilot-check
        {connection : Bağlantı kimliği (channel_connections.id)}
        {--days=14 : Kaç günlük sipariş okunsun}
        {--listings=50 : Stoğu karşılaştırılacak en çok ilan}';

    protected $description = 'Pilot satıcının kanal bağlantısını yalnız okuyarak doğrular (sipariş, ürün, stok)';

    public function handle(PilotCheck $check): int
    {
        $connection = TenantContext::runAsSystem(
            fn (): ?ChannelConnection => ChannelConnection::query()->find($this->argument('connection')),
        );

        if ($connection === null) {
            $this->error('Bağlantı bulunamadı.');

            return self::FAILURE;
        }

        $report = $check->run(
            $connection,
            max(1, (int) $this->option('days')),
            max(1, (int) $this->option('listings')),
        );

        $path = sprintf('pilot-check/%s-%s-%s.json', $connection->channel_type_code, substr($connection->id, 0, 8), now()->format('Ymd-His'));
        Storage::disk('local')->put($path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        $this->line(sprintf('%s · %s (%s)', $connection->label, $connection->channel_type_code, $connection->id));
        foreach (['health', 'orders', 'catalog', 'inventory'] as $section) {
            $s = $report[$section];
            if (($s['supported'] ?? true) && ! ($s['ok'] ?? false)) {
                $this->warn("{$section}: ".($s['error'] ?? $s['message'] ?? 'hata'));
            }
        }
        foreach ($report['verdict'] as $line) {
            $this->line($line);
        }
        $this->line('Rapor: '.Storage::disk('local')->path($path));

        return ($report['health']['ok'] ?? false) ? self::SUCCESS : self::FAILURE;
    }
}
