<?php

declare(strict_types=1);

namespace App\Domain\Sms\Jobs;

use App\Domain\Sms\Actions\RunSmsNotifications;
use App\Domain\Sms\Models\SmsAccount;
use App\Support\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

/**
 * Bir kiracının SMS bildirim turu.
 *
 * TEK DENEME (`tries = 1`): başarısız tur bir sonraki dakikada zaten
 * yeniden koşar; kuyruk tekrarı aynı dakikada ikinci turu açardı.
 * Aynı kiracının iki turu üst üste binmez (kilit); binseydi de olay
 * anahtarı ikinci SMS'i engellerdi — kilit yalnız boş Netgsm çağrısını önler.
 */
final class NotifyTenantSms implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public readonly string $tenantId) {}

    public function handle(RunSmsNotifications $notifications): void
    {
        $lock = Cache::lock('sms:notify:'.$this->tenantId, 150);

        if (! $lock->get()) {
            return;
        }

        TenantContext::set($this->tenantId);

        try {
            $account = SmsAccount::query()->first();

            if ($account !== null) {
                $notifications->run($account);
            }
        } finally {
            TenantContext::clear();
            $lock->release();
        }
    }
}
