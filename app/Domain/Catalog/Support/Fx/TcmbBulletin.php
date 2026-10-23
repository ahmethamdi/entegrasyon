<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Support\Fx;

use Carbon\CarbonImmutable;
use RuntimeException;
use SimpleXMLElement;

/**
 * TCMB gösterge niteliğindeki kurlar bülteni (`kurlar/today.xml`).
 *
 * HANGİ KUR: `ForexBuying` (döviz ALIŞ). Satıcı yurt dışı satışta döviz
 * kazanır ve onu bankaya SATAR; banka alış kurundan alır. 1.000 TL'lik ürünü
 * satış kuruyla çevirmek fiyatı binde ~2 düşük çıkarır ve satıcı dövizi
 * bozdurduğunda 1.000 TL'nin altında kalır. Alış kuru kârı korur.
 * Efektif (banknot) kurlar nakit içindir; pazaryeri ödemesi havaledir.
 *
 * `Unit` bölünür: JPY bültende 100 birim üzerinden yazılır.
 *
 * Bozuk XML ya da tarihsiz bülten İSTİSNA fırlatır: yarım okunmuş bülten
 * kaydedilirse bayatlık denetimi yanlış tarihe bakardı.
 */
final class TcmbBulletin
{
    /**
     * @param  list<array{currency: string, unit: int, forex_buying: string|null, forex_selling: string|null}>  $rates
     */
    private function __construct(
        public readonly CarbonImmutable $date,
        public readonly ?string $bulletinNo,
        public readonly array $rates,
    ) {}

    public static function parse(string $xml): self
    {
        $previous = libxml_use_internal_errors(true);

        try {
            $root = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if ($root === false || $root->getName() !== 'Tarih_Date') {
            throw new RuntimeException('TCMB bülteni okunamadı (beklenen kök Tarih_Date).');
        }

        $date = CarbonImmutable::createFromFormat('!m/d/Y', (string) $root['Date']);

        if ($date === false) {
            throw new RuntimeException('TCMB bülteninde tarih yok.');
        }

        $rates = [];

        foreach ($root->Currency as $node) {
            $code = strtoupper(trim((string) $node['CurrencyCode']));
            $unit = (int) trim((string) $node->Unit);

            if (! preg_match('/^[A-Z]{3}$/', $code) || $unit <= 0) {
                continue;
            }

            $rates[] = [
                'currency' => $code,
                'unit' => $unit,
                'forex_buying' => self::decimal((string) $node->ForexBuying),
                'forex_selling' => self::decimal((string) $node->ForexSelling),
            ];
        }

        if ($rates === []) {
            throw new RuntimeException('TCMB bülteninde kur yok.');
        }

        $no = trim((string) $root['Bulten_No']);

        return new self($date, $no === '' ? null : $no, $rates);
    }

    /** Boş ya da sıfır/negatif değer null (XDR gibi satırlarda alan boş gelir). */
    private static function decimal(string $value): ?string
    {
        $value = trim($value);

        return preg_match('/^\d+(\.\d+)?$/', $value) === 1 && (float) $value > 0 ? $value : null;
    }
}
