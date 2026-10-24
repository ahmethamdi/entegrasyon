<?php

declare(strict_types=1);

namespace App\Support\Observability;

use App\Support\Backup\HealthcheckPinger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * S13 · zamanlayıcının "yaşıyorum" sinyali (5 dk) → healthchecks.io.
 *
 * ops:watch'ın kör noktası: zamanlayıcı ya da sunucu ölürse ops:watch de
 * ölür ve hiçbir alarm gitmez. Bu ping susunca DIŞARIDAKİ servis alarm
 * verir. Kuyruğun (Horizon) durması zaten ops:watch'ın kuyruk bekleme
 * eşiğine takılır; bu komut yalnız zamanlayıcının kendisini kanıtlar.
 *
 * Adres yoksa: hiçbir şey yapmaz, günde bir uyarı günlüğe yazar.
 */
final class HeartbeatCommand extends Command
{
    protected $signature = 'ops:heartbeat';

    protected $description = 'Zamanlayıcının yaşadığını healthchecks.io\'ya bildirir (S13, dead-man switch)';

    public function handle(HealthcheckPinger $pinger): int
    {
        $url = (string) config('entegrasyon.heartbeat.url');

        if (trim($url) === '') {
            if (Cache::add('ops-heartbeat:unconfigured', 1, 86_400)) {
                Log::warning('ops.heartbeat.not_configured', ['env' => 'HEALTHCHECKS_HEARTBEAT_URL']);
            }
            $this->warn('HEALTHCHECKS_HEARTBEAT_URL yok — ping atlanıyor');

            return self::SUCCESS;
        }

        $ok = $pinger->ping($url);
        $this->line($ok ? 'ping: ok' : 'ping: ulaşılamadı');

        return self::SUCCESS;
    }
}
