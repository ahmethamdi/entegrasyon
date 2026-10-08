<?php

declare(strict_types=1);

namespace App\Domain\Billing\Actions;

use App\Domain\Billing\Enums\QuotaMetric;
use App\Domain\Billing\Models\Plan;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Str;

/**
 * Müşteriye özel plan oluşturur (yalnız süper admin).
 *
 * `is_public = false` OLARAK YAZILIR ve bu bilinçlidir: abonelik ekranı
 * herkese açık olmayan planı satın aldırmaz ("Bu plan satın alınamaz"), plan
 * listesinde de görünmez. Özel plan yalnız yöneticinin atamasıyla
 * (`AssignPlanManually`) bir kiracıya geçer.
 *
 * Kod adla türetilir ve `ozel-` önekiyle başlar: yerleşik planlarla
 * (free/starter/pro/business) çakışamaz, listede de ayrışır.
 *
 * Limit `null` = sınırsız (`Plan::limitFor()` sözleşmesi).
 */
final class CreateCustomPlan
{
    public function run(
        string $name,
        string $priceMonthly,
        string $currency,
        ?int $maxProducts,
        ?int $maxChannels,
    ): Plan {
        return TenantContext::runAsSystem(function () use ($name, $priceMonthly, $currency, $maxProducts, $maxChannels): Plan {
            $base = 'ozel-'.Str::slug($name);
            $code = $base;

            for ($i = 2; Plan::query()->whereKey($code)->exists(); $i++) {
                $code = $base.'-'.$i;
            }

            return Plan::query()->create([
                'code' => $code,
                'name' => trim($name),
                'price_monthly' => number_format((float) $priceMonthly, 2, '.', ''),
                'currency' => strtoupper($currency),
                'shopify_price_usd' => null,
                'limits' => [
                    QuotaMetric::PRODUCTS->value => $maxProducts,
                    QuotaMetric::CHANNELS->value => $maxChannels,
                ],
                'is_public' => false,
            ]);
        });
    }
}
