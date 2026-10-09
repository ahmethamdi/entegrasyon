<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Console;

use App\Domain\Catalog\Actions\PushCampaignPrices;
use App\Domain\Catalog\Models\PriceCampaign;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Başlangıcı ya da bitişi gelen kampanyaların fiyatlarını kanala yeniden gönderir.
 *
 * Fiyat zaten saatle hesaplanır; bu tur olmasa kanaldaki fiyat ancak başka
 * bir sebeple (ürün fiyatı değişimi, mutabakat) güncellenirdi — kampanya
 * kanalda hiç başlamaz ya da bitmezdi.
 *
 * İptal edilmiş kampanya başlatılmaz; iptal anında bitiş itmesi zaten yapılır.
 */
final class TickPriceCampaignsCommand extends Command
{
    protected $signature = 'campaigns:tick';

    protected $description = 'Başlayan/biten fiyat kampanyalarını kanallara yeniden gönderir';

    public function handle(PushCampaignPrices $push): int
    {
        $now = now();

        // Tarama TÜM kiracıları görür; itme kiracı bağlamında yapılır.
        $due = TenantContext::runAsSystem(fn () => PriceCampaign::query()
            ->whereNull('cancelled_at')
            ->where(function ($query) use ($now): void {
                $query->where(fn ($q) => $q->whereNull('start_pushed_at')->where('starts_at', '<=', $now)->where('ends_at', '>', $now))
                    ->orWhere(fn ($q) => $q->whereNull('end_pushed_at')->where('ends_at', '<=', $now)->whereNotNull('start_pushed_at'));
            })
            ->orderBy('starts_at')
            ->get());

        $total = 0;

        foreach ($due as $campaign) {
            // Bir kampanyanın hatası ötekileri DURDURMAZ ama sessizce de geçmez.
            try {
                $total += TenantContext::runFor($campaign->tenant_id, fn (): int => $campaign->ends_at->lte($now)
                    ? $push->end($campaign)
                    : $push->start($campaign));
            } catch (Throwable $e) {
                Log::error('campaigns.tick_failed', ['campaign' => $campaign->id, 'error' => $e->getMessage()]);
                report($e);
            }
        }

        $this->line("Yeniden gönderilen ilan: {$total}");

        return self::SUCCESS;
    }
}
