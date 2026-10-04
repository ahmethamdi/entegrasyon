<?php

declare(strict_types=1);

namespace App\Domain\Orders\Console;

use App\Domain\Orders\Actions\ResolveUnmatchedOrderLines;
use App\Domain\Orders\Enums\StockStatus;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Eşleşmemiş sipariş satırı taraması — ince kabuk (A12).
 *
 * NEDEN TARAMA, OLAY DEĞİL: SKU kataloğa birden çok yoldan girer — ürün
 * yaratma, CSV içe aktarma, kanaldan içe aktarma, SKU düzeltme — ve bazıları
 * toplu yazar. Her yola tetik eklemek, eklenmeyen ilk yolda sessizce
 * çalışmamak demektir. Tarama hepsini aynı sorguyla yakalar; gecikme en çok
 * bir tur (5 dk).
 *
 * `order_lines_unmatched_idx` kısmi indeksi taramayı besler: yalnızca
 * eşleşmemiş bekleyen satırlara dokunur.
 *
 * KAYIT VE ZAMANLAMA AYRI KOŞULLARDIR: `bootstrap/app.php` + `routes/console.php`
 * (ScheduledScansTest doğrular).
 */
final class ResolveUnmatchedOrderLinesCommand extends Command
{
    protected $signature = 'orders:resolve-unmatched';

    protected $description = 'SKU\'su sonradan kataloğa giren sipariş satırlarını bağlar ve stoğu düşer';

    public function handle(ResolveUnmatchedOrderLines $resolve): int
    {
        // Tarama TÜM kiracıları görmek zorundadır; sistem erişimi açıktır.
        $tenantIds = TenantContext::runAsSystem(fn () => DB::table('order_lines')
            ->join('variants', function ($join): void {
                $join->on('variants.tenant_id', '=', 'order_lines.tenant_id')
                    ->on('variants.sku', '=', 'order_lines.sku');
            })
            ->whereNull('order_lines.variant_id')
            ->where('order_lines.stock_status', StockStatus::PENDING->value)
            ->distinct()
            ->pluck('order_lines.tenant_id')
            ->all());

        $total = 0;

        foreach ($tenantIds as $tenantId) {
            // Bir kiracının hatası ötekileri DURDURMAZ ama sessizce de geçmez.
            try {
                $total += TenantContext::runFor((string) $tenantId, fn (): int => $resolve->run());
            } catch (Throwable $e) {
                Log::error('orders.resolve_unmatched_failed', [
                    'tenant' => $tenantId,
                    'error' => $e->getMessage(),
                ]);
                report($e);
            }
        }

        $this->line("Bağlanan satır: {$total}");

        return self::SUCCESS;
    }
}
