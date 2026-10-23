<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Support\Fx;

use App\Domain\Catalog\Models\ExchangeRate;
use App\Domain\Catalog\Models\FxPricingRate;
use Carbon\CarbonImmutable;

/**
 * Fiyatlamada kullanılacak kur — iş/istek ömrü boyunca önbellekli.
 *
 * KUR YOKSA YA DA BAYATSA NULL DÖNER ve fiyat GÖNDERİLMEZ (`Listing`
 * fiyat kapısı). Eski kurla ya da TL rakamıyla sessizce gitmek yanlış
 * fiyattan satış demekti; durdurmanın bedeli kanalda eski fiyatın kalmasıdır.
 *
 * BAYATLIK ÇAPAYA DEĞİL EN SON BÜLTENE BAKAR. Çapa haftalarca değişmeyebilir
 * (kur eşik içinde kaldıkça) ve bu doğrudur; bayat olan, TCMB'den günlerdir
 * hiç bülten gelmemesidir. Tarih İstanbul saatine göre sayılır.
 *
 * ⚠️ `scoped` BAĞLANIR (`ChannelPriceRules` ile aynı gerekçe): Horizon işçisi
 * uzun yaşar, singleton önbelleği yeni çapayı hiç görmezdi.
 */
final class FxRates
{
    /** @var array<string, FxQuote|null> */
    private array $quotes = [];

    public function quote(string $currency): ?FxQuote
    {
        $currency = strtoupper(trim($currency));

        if (! array_key_exists($currency, $this->quotes)) {
            $this->quotes[$currency] = $this->load($currency);
        }

        return $this->quotes[$currency];
    }

    /** Kur kullanılamıyorsa satıcıya gösterilecek neden; kullanılabiliyorsa null. */
    public function unavailableReason(string $currency): ?string
    {
        if ($this->quote($currency) !== null) {
            return null;
        }

        $latest = $this->latestDate($currency);

        return $latest === null
            ? __(':currency kuru henüz alınmadı; çevrilmiş fiyat gönderilmedi.', ['currency' => $currency])
            : __(':currency kuru :date tarihinden beri güncellenmedi; bayat kurla fiyat gönderilmedi.', ['currency' => $currency, 'date' => $latest->format('d.m.Y')]);
    }

    public function forget(): void
    {
        $this->quotes = [];
    }

    public static function today(): CarbonImmutable
    {
        return CarbonImmutable::now('Europe/Istanbul')->startOfDay();
    }

    private function load(string $currency): ?FxQuote
    {
        $anchor = FxPricingRate::query()->find($currency);
        $latest = $this->latestDate($currency);

        if ($anchor === null || $latest === null) {
            return null;
        }

        $maxAge = (int) config('entegrasyon.fx.max_age_days', 4);

        if ($latest->diffInDays(self::today(), false) > $maxAge) {
            return null;
        }

        return new FxQuote(
            $currency,
            (string) $anchor->rate,
            CarbonImmutable::parse($anchor->rate_date->format('Y-m-d')),
            $latest,
        );
    }

    private function latestDate(string $currency): ?CarbonImmutable
    {
        $date = ExchangeRate::query()
            ->where('currency', $currency)
            ->whereNotNull('forex_buying')
            ->max('rate_date');

        return $date === null ? null : CarbonImmutable::parse((string) $date, 'Europe/Istanbul')->startOfDay();
    }
}
