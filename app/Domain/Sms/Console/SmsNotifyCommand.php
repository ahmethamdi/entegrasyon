<?php

declare(strict_types=1);

namespace App\Domain\Sms\Console;

use App\Domain\Sms\Jobs\NotifyTenantSms;
use App\Domain\Sms\Models\SmsAccount;
use App\Domain\Sms\Models\SmsMessage;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * SMS bildirim turu — dakikada bir. Bildirim seçmiş, bağlı her kiracı için
 * bir iş kuyruğa alınır. Eski gönderim kaydı ve olay anahtarları silinir.
 */
final class SmsNotifyCommand extends Command
{
    protected $signature = 'sms:notify';

    protected $description = 'SMS bildirimlerini (yeni sipariş, stok, kanal sorunu, kargo) gönderir';

    public function handle(): int
    {
        if (! config('sms.enabled')) {
            $this->info('SMS kapalı (SMS_ENABLED=false).');

            return self::SUCCESS;
        }

        $tenants = TenantContext::runAsSystem(fn () => SmsAccount::query()
            ->where('status', SmsAccount::STATUS_CONNECTED)
            ->whereNotNull('sender')
            ->whereRaw('jsonb_array_length(events) > 0')
            ->pluck('tenant_id'));

        foreach ($tenants as $tenantId) {
            NotifyTenantSms::dispatch($tenantId)->onQueue('maintenance');
        }

        // Kayıt saklama süresi — gönderim kaydı ve anahtarlar birlikte.
        $cutoff = now()->subDays((int) config('sms.retention_days', 90));
        TenantContext::runAsSystem(function () use ($cutoff): void {
            SmsMessage::query()->where('created_at', '<', $cutoff)->delete();
            DB::table('sms_event_keys')->where('created_at', '<', $cutoff)->delete();
        });

        $this->info("Kuyruğa alınan kiracı: {$tenants->count()}");

        return self::SUCCESS;
    }
}
