<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\Models\ChannelPriceRule;
use App\Domain\Catalog\Models\ExchangeRate;
use App\Domain\Catalog\Models\FxPricingRate;
use App\Domain\Catalog\Support\Fx\FxRates;
use App\Domain\Catalog\Support\Fx\TcmbBulletin;
use App\Domain\Sync\Actions\RequestResync;
use App\Domain\Sync\Enums\SyncDomain;
use App\Domain\Sync\Models\Listing;
use App\Support\Tenancy\TenantContext;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * TCMB bültenini çeker, ham kuru saklar, fiyatlama kurunu (çapa) günceller
 * ve gerekiyorsa etkilenen ilanların fiyatını yeniden gönderir.
 *
 * ÇAPA YALNIZ EŞİK AŞILINCA DEĞİŞİR. Kur her gün binde birkaç oynar; her
 * bültende çapa değişseydi kur çevirisi açık her ilan her gün yeniden
 * gönderilir ve kanal kotası (Etsy 10/sn, eBay günlük çağrı sınırı) fiyat
 * gürültüsüne harcanırdı. Fark eşik altındaysa kanala hiçbir istek gitmez.
 *
 * RESYNC İKİ DURUMDA: (1) çapa değişti, (2) kur önce kullanılamıyordu
 * (hiç yoktu ya da bayattı) ve şimdi kullanılabiliyor — o arada fiyat işleri
 * `markBlocked` ile durdurulmuştu ve kendiliğinden geri gelmezler.
 *
 * İdempotent: aynı bülten ikinci kez çekilirse satırlar aynı kalır, çapa
 * değişmez, resync olmaz. Zamanlayıcı bu yüzden gün içinde birkaç kez dener.
 */
final class FetchExchangeRates
{
    public function __construct(
        private readonly RequestResync $requestResync,
        private readonly FxRates $rates,
    ) {}

    /**
     * @return array{date: string, stored: int, anchored: list<string>, resynced: int}
     */
    public function run(): array
    {
        $response = Http::timeout((int) config('entegrasyon.fx.timeout_seconds', 15))
            ->withHeaders(['Accept' => 'application/xml'])
            ->get((string) config('entegrasyon.fx.source_url'))
            ->throw();

        return $this->ingest(TcmbBulletin::parse($response->body()));
    }

    /**
     * @return array{date: string, stored: int, anchored: list<string>, resynced: int}
     */
    public function ingest(TcmbBulletin $bulletin): array
    {
        return TenantContext::runAsSystem(function () use ($bulletin): array {
            $watched = $this->watchedCurrencies();

            $this->rates->forget();
            $usableBefore = [];
            foreach ($watched as $currency) {
                $usableBefore[$currency] = $this->rates->quote($currency) !== null;
            }

            $anchored = DB::transaction(function () use ($bulletin): array {
                $now = now();
                $anchored = [];

                foreach ($bulletin->rates as $row) {
                    ExchangeRate::query()->updateOrCreate(
                        ['source' => ExchangeRate::SOURCE_TCMB, 'rate_date' => $bulletin->date->format('Y-m-d'), 'currency' => $row['currency']],
                        [
                            'unit' => $row['unit'],
                            'forex_buying' => $row['forex_buying'],
                            'forex_selling' => $row['forex_selling'],
                            'bulletin_no' => $bulletin->bulletinNo,
                            'fetched_at' => $now,
                        ],
                    );

                    if ($row['forex_buying'] === null) {
                        continue;
                    }

                    $rate = (string) BigDecimal::of($row['forex_buying'])->dividedBy($row['unit'], 6, RoundingMode::HALF_UP);

                    if ($this->moveAnchor($row['currency'], $rate, $bulletin)) {
                        $anchored[] = $row['currency'];
                    }
                }

                return $anchored;
            });

            $this->rates->forget();
            $resynced = 0;

            foreach ($watched as $currency) {
                $usableNow = $this->rates->quote($currency) !== null;

                if ($usableNow && (in_array($currency, $anchored, true) || ! $usableBefore[$currency])) {
                    $resynced += $this->resync($currency);
                }
            }

            Log::info('fx.fetched', ['date' => $bulletin->date->format('Y-m-d'), 'anchored' => $anchored, 'resynced' => $resynced]);

            return [
                'date' => $bulletin->date->format('Y-m-d'),
                'stored' => count($bulletin->rates),
                'anchored' => $anchored,
                'resynced' => $resynced,
            ];
        });
    }

    /** Çapa yoksa kurar; varsa yalnız eşik aşılınca ve bülten daha yeniyse taşır. */
    private function moveAnchor(string $currency, string $rate, TcmbBulletin $bulletin): bool
    {
        $anchor = FxPricingRate::query()->lockForUpdate()->find($currency);

        if ($anchor !== null) {
            if ($bulletin->date->format('Y-m-d') <= $anchor->rate_date->format('Y-m-d')) {
                return false;
            }

            $old = BigDecimal::of((string) $anchor->rate);
            $deviation = BigDecimal::of($rate)->minus($old)->abs()
                ->multipliedBy(100)
                ->dividedBy($old, 6, RoundingMode::HALF_UP);

            if ($deviation->isLessThan(BigDecimal::of((string) config('entegrasyon.fx.anchor_threshold_percent', 0.5)))) {
                return false;
            }
        }

        FxPricingRate::query()->updateOrCreate(['currency' => $currency], [
            'rate' => $rate,
            'rate_date' => $bulletin->date->format('Y-m-d'),
            'source' => ExchangeRate::SOURCE_TCMB,
            'anchored_at' => now(),
        ]);

        return true;
    }

    /** @return list<string> Kur çevirisi açık bir bağlantının hedeflediği birimler. */
    private function watchedCurrencies(): array
    {
        return ChannelPriceRule::query()
            ->where('fx_enabled', true)
            ->whereNotNull('fx_currency')
            ->distinct()
            ->pluck('fx_currency')
            ->map(fn ($c): string => strtoupper((string) $c))
            ->values()
            ->all();
    }

    /** Bu birime çeviren bağlantıların canlı ilanlarının fiyatını yeniden gönderir. */
    private function resync(string $currency): int
    {
        $count = 0;

        $rules = ChannelPriceRule::query()
            ->where('fx_enabled', true)
            ->where('fx_currency', $currency)
            ->get(['tenant_id', 'channel_connection_id']);

        foreach ($rules as $rule) {
            // Bir bağlantının hatası ötekileri durdurmaz ama sessizce de geçmez.
            try {
                $count += TenantContext::runFor($rule->tenant_id, function () use ($rule): int {
                    $n = 0;

                    Listing::query()
                        ->with('variant:id,currency')
                        ->where('channel_connection_id', $rule->channel_connection_id)
                        ->where('lifecycle_status', 'live')
                        ->chunkById(200, function ($listings) use (&$n): void {
                            foreach ($listings as $listing) {
                                if (! $listing->convertsCurrency()) {
                                    continue;
                                }

                                $this->requestResync->run($listing, SyncDomain::PRICE, RequestResync::REASON_EXCHANGE_RATE_CHANGED);
                                $n++;
                            }
                        });

                    return $n;
                });
            } catch (Throwable $e) {
                Log::error('fx.resync_failed', ['connection' => $rule->channel_connection_id, 'error' => $e->getMessage()]);
                report($e);
            }
        }

        return $count;
    }
}
