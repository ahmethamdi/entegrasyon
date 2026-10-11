<?php

declare(strict_types=1);

namespace App\Domain\Fulfillment\Support;

use App\Domain\Fulfillment\Models\ShippingLabel;
use Illuminate\Support\Collection;

/**
 * Depo GÖRÜNÜMÜ katmanı — kanalın etiketinden yazdırılabilir çıktı üretir.
 *
 * Adapter otoriter etiketi getirir (`SupportsCommonLabel`); bu sınıf onu
 * OKUR, değiştirmez. Saklanan asıl (`shipping_labels.payload`) hiçbir
 * zaman ezilmez.
 *
 *   · ZPL  → termal yazıcı için birleşik `.zpl` dosyası (etiketler
 *            dokunulmadan, sırayla)
 *   · PDF  → kanal PDF adresi verdiyse o adres (A4/yazdırma)
 *
 * ⚠️ ZPL → PDF dönüşümü YOK: güvenilir yerel ZPL işleyicisi yok; harici
 * servis (ör. Labelary) alıcı adresini üçüncü tarafa gönderirdi. Karar
 * kullanıcıda (S2 raporu). Dönüşüm eklenecekse YALNIZ bu sınıfa eklenir.
 */
final class LabelDocuments
{
    /** @param Collection<int, ShippingLabel> $labels */
    public function zplBundle(Collection $labels): string
    {
        $parts = [];

        foreach ($labels as $label) {
            foreach ($label->labels() as $item) {
                if (strtoupper($item['format']) === 'ZPL') {
                    $parts[] = rtrim($item['label']);
                }
            }
        }

        return $parts === [] ? '' : implode("\n", $parts)."\n";
    }

    /**
     * Kanalın verdiği PDF etiket adresleri (yalnız https).
     *
     * @return list<string>
     */
    public function pdfLinks(ShippingLabel $label): array
    {
        $links = [];

        foreach ($label->labels() as $item) {
            if (strtoupper($item['format']) === 'PDF' && str_starts_with($item['label'], 'https://')) {
                $links[] = $item['label'];
            }
        }

        return $links;
    }
}
