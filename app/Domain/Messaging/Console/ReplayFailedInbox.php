<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Console;

use App\Domain\Messaging\Jobs\ProcessInboxMessage;
use App\Support\Tenancy\TenantContext;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * `failed` / `expired` inbox mesajlarını yeniden oynatır (mimari denetim P0-6).
 *
 * Önceden `failed` bir mesajı geri almanın tek yolu elle SQL'di.
 *
 * GÜVENLİDİR: mesaj işleme idempotenttir — sipariş `(bağlantı, external_id)`
 * tekilliği, stok hareketleri `idempotency_key`, olaylar `order_events`
 * çıpasıyla korunur. Aynı mesajı iki kez oynatmak çift etki üretmez.
 *
 * SAYFA KİMLİKLE İLERLER (`id > son`), ofsetle değil: oynatılan satır
 * `pending` olduğu için sorgudan düşer ve ofset kayarak satır ATLARDI.
 * UUIDv7 zaman sıralıdır, yani sıra da korunur.
 *
 * Tüm kiracıları gezer (`runAsSystem`); `--connection` ile tek bağlantıya
 * daraltılır. Her mesaj kendi kiracısının işiyle kuyruğa girer.
 */
final class ReplayFailedInbox extends Command
{
    protected $signature = 'inbox:replay-failed
        {--connection= : Yalnız bu bağlantının mesajları}
        {--since= : Yalnız bu andan sonra alınmış mesajlar (ör. 2026-10-11 08:00)}
        {--id=* : Yalnız bu mesaj kimlikleri}
        {--include-expired : Sipariş beklerken süresi dolmuş (expired) mesajları da al}
        {--dry-run : Yalnız say, değiştirme}';

    protected $description = 'Başarısız inbox mesajlarını güvenle yeniden kuyruğa alır';

    private const PAGE = 200;

    public function handle(): int
    {
        try {
            $since = $this->option('since') ? Carbon::parse((string) $this->option('since')) : null;
        } catch (Throwable) {
            $this->error('--since okunamadı.');

            return self::INVALID;
        }

        $statuses = $this->option('include-expired') ? ['failed', 'expired'] : ['failed'];
        $ids = array_values(array_filter((array) $this->option('id'), 'is_string'));
        $connection = $this->option('connection');
        $dryRun = (bool) $this->option('dry-run');

        $total = 0;
        $after = null;

        do {
            $page = TenantContext::runAsSystem(function () use ($statuses, $ids, $connection, $since, $after) {
                $query = DB::table('inbox_messages')
                    ->whereIn('status', $statuses)
                    ->orderBy('id')
                    ->limit(self::PAGE);

                if (is_string($connection) && $connection !== '') {
                    $query->where('channel_connection_id', $connection);
                }

                if ($since !== null) {
                    $query->where('received_at', '>=', $since);
                }

                if ($ids !== []) {
                    $query->whereIn('id', $ids);
                }

                if ($after !== null) {
                    $query->where('id', '>', $after);
                }

                return $query->get(['id', 'tenant_id']);
            });

            foreach ($page as $row) {
                $after = (string) $row->id;

                if ($dryRun) {
                    $total++;

                    continue;
                }

                // KOŞULLU: arada başka biri oynatmışsa dokunulmaz.
                $updated = TenantContext::runAsSystem(fn (): int => DB::table('inbox_messages')
                    ->where('id', $row->id)
                    ->whereIn('status', $statuses)
                    ->update([
                        'status' => 'pending',
                        'attempt_count' => 0,
                        'next_attempt_at' => null,
                        'updated_at' => now(),
                    ]));

                if ($updated === 0) {
                    continue;
                }

                ProcessInboxMessage::dispatch((string) $row->tenant_id, (string) $row->id)
                    ->onQueue('inbox:process');

                $total++;
            }
        } while ($page->count() === self::PAGE);

        if (! $dryRun && $total > 0) {
            Log::warning('inbox.replayed', ['count' => $total, 'connection' => $connection]);
        }

        $this->line((string) $total);

        return self::SUCCESS;
    }
}
