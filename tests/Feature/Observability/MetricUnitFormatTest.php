<?php

declare(strict_types=1);

namespace Tests\Feature\Observability;

use App\Support\Observability\Metric;
use App\Support\Observability\MetricUnit;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Metrik değerinin metne çevrilmesi — §11.
 *
 * E-POSTA JAVASCRIPT ÇALIŞTIRMAZ, bu yüzden biçimlendirmenin bir SUNUCU
 * kopyası gerekir. Kurallar panelin `format()` fonksiyonuyla AYNIDIR
 * (`Pages/Metrics/Index.vue`): ayrışsalardı satıcı "e-postada 62000
 * yazıyordu, panelde 62 sn görüyorum" der ve iki sayının aynı olduğuna
 * güvenemezdi.
 *
 * BU TEST GERÇEK BİR HATANIN ARDINDAN YAZILDI: kırpma tüm sayıya
 * uygulanıyordu ve `0` BOŞ DİZEYE düşüyordu. Eşiği sıfır olan iki metrik
 * tam da uyarı üreten metriklerdi, yani e-posta "uyarı eşiği: " diye
 * BOŞ çıkıyordu. Aynı hata `10` sayısını da `1`'e düşürürdü.
 */
final class MetricUnitFormatTest extends TestCase
{
    /**
     * SIFIR BOŞ DİZE DEĞİLDİR.
     *
     * Eşiği sıfır olan metrikler (`outbox_consume_gap`,
     * `sync_delivery_gap`) uyarı üreten metriklerdir; boş çıkması her
     * uyarı e-postasında görünürdü.
     */
    #[Test]
    public function zero_is_rendered_as_zero_not_as_an_empty_string(): void
    {
        $this->assertSame('0', MetricUnit::COUNT->format(0));
        $this->assertSame('%0', MetricUnit::PERCENT->format(0));
        $this->assertSame('0 ms', MetricUnit::MILLISECONDS->format(0));
        $this->assertSame('0 sn', MetricUnit::SECONDS->format(0));
    }

    /** SONDAKİ SIFIR TAM SAYIDAN KIRPILMAZ: `10` → `1` olurdu. */
    #[Test]
    public function trailing_zeros_of_whole_numbers_survive(): void
    {
        $this->assertSame('10', MetricUnit::COUNT->format(10));
        $this->assertSame('100', MetricUnit::COUNT->format(100));
        $this->assertSame('500', MetricUnit::COUNT->format(500));
    }

    /** Ondalık ayırıcı VİRGÜLDÜR ve gereksiz sıfır taşınmaz. */
    #[Test]
    public function decimals_use_a_turkish_comma(): void
    {
        $this->assertSame('%5,3', MetricUnit::PERCENT->format(5.3));
        $this->assertSame('%5', MetricUnit::PERCENT->format(5.0), 'Gereksiz ",0" taşınmaz.');
    }

    /**
     * Ölçek yükselince birim de yükselir — panelle AYNI eşikler.
     *
     * "62000 ms" eşikle (60 sn) doğrudan karşılaştırılamaz; "62 sn"
     * karşılaştırılabilir.
     */
    #[Test]
    public function large_values_climb_to_the_next_unit(): void
    {
        $this->assertSame('62 sn', MetricUnit::MILLISECONDS->format(62_000));
        $this->assertSame('999 ms', MetricUnit::MILLISECONDS->format(999));
        $this->assertSame('5 dk', MetricUnit::SECONDS->format(300));
        $this->assertSame('59 sn', MetricUnit::SECONDS->format(59));
    }

    /**
     * HER METRİĞİN EŞİĞİ BOŞ DİZEYE DÜŞMEDEN BİÇİMLENEBİLMELİ.
     *
     * Tek tek sınamak yerine on üç metriğin hepsi taranır: yeni bir
     * metrik eklendiğinde bu test onu da kapsar ve boş eşikli bir uyarı
     * e-postası ÜRETİLEMEZ.
     */
    #[Test]
    public function every_metric_threshold_formats_to_visible_text(): void
    {
        foreach (Metric::cases() as $metric) {
            $text = $metric->unit()->format($metric->threshold());

            $this->assertNotSame(
                '',
                trim($text),
                "{$metric->value} eşiği boş metne düşüyor — e-postada 'uyarı eşiği: ' görünürdü.",
            );
        }
    }
}
