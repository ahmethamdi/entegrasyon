<?php

declare(strict_types=1);

namespace App\Domain\Shipping\Providers\Yurtici;

use App\Domain\Shipping\Support\ShipmentRequest;

/**
 * Yurtiçi gönderisinin etiketi — firma etiket VERMEZ, biz üretiriz.
 *
 * Kurye paketi barkoddan tanır: barkod BİZİM cargoKey'imizdir (Code 128).
 * Etiket 100×100 mm termal (203 dpi, 799 nokta) — Aras'ın ve Trendyol'un
 * ZPL'iyle aynı yazıcıya basılır. `^CI28` UTF-8 metin içindir (Türkçe harf).
 *
 * A4 PDF ÜRETİLMEZ: S2 kararı (docs/architecture-review/S2-DEPO-AKISI.md §5)
 * — yerel ZPL→PDF işleyici yok, harici servis adresi üçüncü tarafa gönderirdi.
 *
 * DOĞRULANMADI: Yurtiçi şubesinin bu etiketteki Code 128 barkodu okuduğu
 * gerçek gönderide denenmedi (test ortamı kurye süreci içermez).
 */
final class YurticiLabel
{
    public static function zpl(ShipmentRequest $request): string
    {
        $r = $request->recipient;
        $lines = self::wrap($r->address, 42, 4);
        $y = 300;
        $address = '';

        foreach ($lines as $line) {
            $address .= '^FO40,'.$y.'^A0N,28,28^FD'.self::clean($line).'^FS';
            $y += 34;
        }

        return '^XA^CI28^PW799^LL799'
            .'^FO40,30^A0N,40,40^FDYURTICI KARGO^FS'
            .'^FO40,80^A0N,26,26^FDSipariş: '.self::clean($request->orderNumber).'^FS'
            .'^FO40,120^BY3^BCN,110,Y,N,N^FD'.self::clean($request->cargoKey).'^FS'
            .'^FO40,260^A0N,30,30^FDALICI: '.self::clean(mb_substr($r->name, 0, 40)).'^FS'
            .$address
            .'^FO40,'.($y + 10).'^A0N,30,30^FD'.self::clean(mb_strtoupper($r->district.' / '.$r->city, 'UTF-8')).'^FS'
            .'^FO40,'.($y + 50).'^A0N,26,26^FDTel: '.self::clean(self::maskPhone($r->nationalPhone())).'^FS'
            .'^FO40,740^A0N,24,24^FDParça 1/'.max(1, $request->packageCount).'  ·  34Pazar^FS'
            .'^XZ';
    }

    /** ZPL kontrol karakterleri metinden atılır (`^` alan başlatır, `~` komut). */
    private static function clean(string $text): string
    {
        return trim(str_replace(['^', '~', "\r", "\n"], ' ', $text));
    }

    /** Telefonun yalnız son 4 hanesi basılır — etiket paketin dışında dolaşır. */
    private static function maskPhone(string $phone): string
    {
        return $phone === '' ? '-' : str_repeat('*', max(0, strlen($phone) - 4)).substr($phone, -4);
    }

    /** @return list<string> */
    private static function wrap(string $text, int $width, int $maxLines): array
    {
        $words = preg_split('/\s+/u', trim($text)) ?: [];
        $lines = [];
        $current = '';

        foreach ($words as $word) {
            $candidate = $current === '' ? $word : $current.' '.$word;

            if (mb_strlen($candidate) > $width && $current !== '') {
                $lines[] = $current;
                $current = $word;
            } else {
                $current = $candidate;
            }
        }

        if ($current !== '') {
            $lines[] = $current;
        }

        return array_slice($lines, 0, $maxLines);
    }
}
