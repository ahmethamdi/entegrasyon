<?php

declare(strict_types=1);

namespace App\Support\Observability;

/**
 * Metrik değerinin birimi — panel biçimlendirmesi buradan okur.
 *
 * Mimari Karar Dokümanı v2.2 · §11.
 *
 * Birim değerin YANINDA taşınmazsa panel `1247.5` yazar ve satıcı bunun
 * milisaniye mi, saniye mi, adet mi olduğunu bilemez. Aynı grafik hem
 * `%5.3` hem `1247.5 ms` hem `3 adet` göstereceği için tek bir
 * biçimlendirme kuralı yeterli değildir.
 */
enum MetricUnit: string
{
    case MILLISECONDS = 'ms';
    case SECONDS = 's';
    case PERCENT = '%';
    case COUNT = 'count';

    /**
     * Değeri satıcının okuyabileceği metne çevirir.
     *
     * PANELDEKİ `format()` İLE AYNI KURALLAR (`Pages/Metrics/Index.vue`):
     * saniye ölçeğine çıkan milisaniye saniyeye, dakikaya çıkan saniye
     * dakikaya döner. Uyarı e-postası panelden FARKLI bir biçim
     * kullansaydı satıcı "e-postada 62000 yazıyordu, panelde 62 sn
     * görüyorum" der ve iki sayının aynı olduğuna güvenemezdi.
     *
     * Biçimlendirme SUNUCUDA da gerekir çünkü e-posta JavaScript
     * çalıştırmaz; panel kendi kopyasını taşımaya devam eder ve ikisinin
     * AYNI kalması testle korunur.
     */
    public function format(float $value): string
    {
        return match ($this) {
            self::MILLISECONDS => $value >= 1000
                ? $this->decimal($value / 1000, 1).' sn'
                : $this->decimal(round($value), 0).' ms',

            self::SECONDS => $value >= 60
                ? $this->decimal(round($value / 60), 0).' dk'
                : $this->decimal(round($value), 0).' sn',

            self::PERCENT => '%'.$this->decimal($value, 1),

            self::COUNT => $this->decimal(round($value), 0),
        };
    }

    /**
     * Türkçe ondalık ayırıcı VİRGÜLDÜR — binlik ayırıcı kullanılmaz.
     *
     * SIFIR BOŞ DİZEYE DÜŞMEZ. Kırpma yalnızca ONDALIK kısma uygulanır:
     * tüm sayıya `rtrim(..., '0')` uygulanırsa `"0"` BOŞ DİZEYE düşer ve
     * eşiği sıfır olan metrikler ("uyarı eşiği: ") e-postada eşiksiz
     * görünür. GERÇEK ÇALIŞTIRMADA bulundu: eşiği sıfır olan iki metrik
     * (`outbox_consume_gap`, `sync_delivery_gap`) tam da uyarı üreten
     * metriklerdi, yani hata her uyarıda görünürdü. `10` → `1` gibi bir
     * bozulma da aynı kırpmanın ürünüdür.
     */
    private function decimal(float $value, int $decimals): string
    {
        $formatted = number_format($value, $decimals, ',', '');

        if (! str_contains($formatted, ',')) {
            return $formatted;
        }

        return rtrim(rtrim($formatted, '0'), ',');
    }
}
