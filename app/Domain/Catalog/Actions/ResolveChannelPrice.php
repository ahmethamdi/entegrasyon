<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\Models\PriceOverride;
use App\Domain\Sync\Models\Listing;

/**
 * "Bu listing'e hangi fiyat gider" — sorunun TEK KAYNAĞI.
 *
 * Mimari Karar Dokümanı v2.2 · §3 (`ResolveChannelPrice`), §9 (domain
 * başına çakışma politikası).
 *
 * ─────────────────────────────────────────────────────────────────────
 * NEDEN AYRI BİR ACTION — İKİ YERDE HESAPLANAMAZ
 * ─────────────────────────────────────────────────────────────────────
 * Bu soruyu İKİ yer sorar: fiyat yükünü kuran `PriceBatchBuilder` ("ne
 * göndereyim") ve fiyat mutabakatı ("kanaldaki değer beklediğim mi").
 * İki yerde hesaplansaydı biri değiştiğinde diğeri eski kuralı uygular ve
 * sonuç EN KÖTÜ biçimde bozulurdu: mutabakat kanonik fiyatı bekler,
 * gönderim override'ı yollar ve her tur SAHTE bir çakışma raporlanırdı —
 * satıcı kabul ettiği kampanyayı sonsuza kadar yeniden kabul ederdi.
 *
 * "Hazır mı" mantığının `PrerequisiteGate::missingRequiredAttributes()`
 * içinde tek kaynak olmasıyla aynı gerekçe.
 *
 * ─────────────────────────────────────────────────────────────────────
 * OVERRIDE VARSA KANALINKİ GİDER — VE ASLINDA HİÇ GİTMEZ
 * ─────────────────────────────────────────────────────────────────────
 * §9 · PRICE politikası "üzerine YAZMA" der. Override'lı listing fiyat
 * fan-out'undan ELENİR (`PriceBatchBuilder`), yani pratikte o listing'e
 * fiyat İSTEĞİ HİÇ GİTMEZ. Bu sınıfın döndürdüğü "geçerli fiyat" o yüzden
 * asıl olarak MUTABAKATIN beklediği değerdir: kanalda ne olmalı.
 *
 * İkisi aynı sorunun iki yüzüdür ve tam da bu yüzden tek yerde yaşar.
 *
 * ─────────────────────────────────────────────────────────────────────
 * BAYAT OVERRIDE — KANONİK FİYAT DEĞİŞTİYSE KARAR ESKİMİŞTİR
 * ─────────────────────────────────────────────────────────────────────
 * Satıcı "kanalınki 89.90 kalsın, benimki 99.90'dı" diye karar verdi. Sonra
 * panelden kanonik fiyatı 149.90 yaptı. O KARAR ARTIK BAŞKA BİR SORUYA
 * verilmiş bir cevaptır: satıcı 149.90 yerine 89.90'ı seçmedi, 99.90 yerine
 * seçti.
 *
 * Bu durumda override YOK SAYILIR ve kanonik fiyat geçerli olur. Aksi
 * halde panelden yapılan fiyat değişikliği o kanala SESSİZCE hiç gitmez ve
 * satıcı zam yaptığını sanırken eski fiyattan satmaya devam eder — sessiz,
 * sürekli ve doğrudan gelir kaybı.
 *
 * Karşılaştırma KURUŞ ölçeğinde tam sayı üzerindendir: `decimal(12,2)`
 * PHP'ye STRING döner ve `"99.90" !== "99.9"` iken ikisi AYNI fiyattır.
 */
final class ResolveChannelPrice
{
    /**
     * Bu listing için kanalda geçerli olması gereken fiyat.
     *
     * @return array{price: string, override: PriceOverride|null}
     */
    public function run(Listing $listing, ?PriceOverride $override = null): array
    {
        $canonical = (string) ($listing->variant?->price ?? '0');

        $override ??= PriceOverride::query()
            ->where('listing_id', $listing->id)
            ->first();

        if ($override === null || ! $this->applies($override, $canonical)) {
            return ['price' => $canonical, 'override' => null];
        }

        return ['price' => (string) $override->channel_price, 'override' => $override];
    }

    /**
     * Bu override hâlâ geçerli mi?
     *
     * İKİ KAPI VARDIR ve ikisi de gereklidir:
     *   · Süre — satıcının yazdığı bitiş tarihi geçtiyse karar sona ermiştir.
     *   · Bayatlık — kanonik fiyat karar anından beri değiştiyse karar
     *     BAŞKA bir soruya verilmiştir (gerekçe sınıf başlığında).
     */
    private function applies(PriceOverride $override, string $canonicalPrice): bool
    {
        return $override->isActive()
            && $this->toMinorUnits((string) $override->our_price) === $this->toMinorUnits($canonicalPrice);
    }

    /**
     * "19.90" → 1990 — para karşılaştırması TAM SAYI üzerinden.
     *
     * `round()` ZORUNLUDUR: `19.90 * 100` IEEE-754'te `1989.9999...`
     * olabilir ve `(int)` cast'i onu AŞAĞI keser. Bir kuruşluk kayma
     * override'ı sahte biçimde "bayat" gösterir ve satıcının kabul ettiği
     * kampanya sessizce ezilirdi.
     */
    private function toMinorUnits(string $price): int
    {
        return (int) round(((float) $price) * 100);
    }
}
