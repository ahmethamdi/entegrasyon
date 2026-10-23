<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Console;

use App\Domain\Catalog\Actions\FetchExchangeRates;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * TCMB kurlarını çeker (S9 · otomatik kur çevirisi).
 *
 * Çekim hatası fiyatı BOZMAZ: çapa yerinde kalır, bülten bayatlayınca
 * (`fx.max_age_days`) çevrilmiş fiyat gönderilmez ve `ops:watch` alarm verir.
 * Hata burada yalnız günlüğe yazılır; alarmı tek yerden veren izleme turudur.
 */
final class FetchExchangeRatesCommand extends Command
{
    protected $signature = 'fx:fetch';

    protected $description = 'TCMB kurlarını çeker, fiyatlama kurunu günceller, gerekirse fiyatları yeniden gönderir';

    public function handle(FetchExchangeRates $fetch): int
    {
        try {
            $result = $fetch->run();
        } catch (Throwable $e) {
            Log::warning('fx.fetch_failed', ['error' => $e->getMessage()]);
            $this->error('Kur çekilemedi: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->line(sprintf(
            'Bülten %s · %d kur · güncellenen fiyatlama kuru: %s · yeniden gönderilen ilan: %d',
            $result['date'],
            $result['stored'],
            $result['anchored'] === [] ? '-' : implode(', ', $result['anchored']),
            $result['resynced'],
        ));

        return self::SUCCESS;
    }
}
