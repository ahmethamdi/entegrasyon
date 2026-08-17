<?php

declare(strict_types=1);

namespace App\Domain\Sync\Support;

/**
 * Kanala gidecek içeriğin parmak izi.
 *
 * Mimari Karar Dokümanı v2.2 · §9 · Listing State Model, §8 · desired_hash.
 *
 * Üç durum alanı üç ayrı soruya cevap verir ve hash ikisinde kullanılır:
 *   desired_hash  ne gönderilmek isteniyor  → OpenSyncOperation yazar
 *   synced_hash   en son ne gönderildi      → SyncResultRecorder yazar
 *   remote_hash   kanalda şu an ne var      → mutabakat yazar
 *
 * DEĞİŞMEZ KURAL — HASH YALNIZCA İÇERİKTEN TÜRER:
 *   Sürüm, zaman damgası veya kimlik hash'e KARIŞMAZ. Sürüm "hangi olay",
 *   hash "hangi içerik" sorusunu cevaplar; ikisi karışırsa içerik değişmeden
 *   yapılan her yeniden gönderim satırı kirli gösterir ve mutabakat gerçek
 *   sürüklenmeyi gürültüde kaybeder.
 *
 * DEĞİŞMEZ KURAL — ANAHTAR SIRASI SONUCU DEĞİŞTİRMEZ:
 *   Öznitelikler sıralanır. Sıralanmasaydı aynı içerik, alanların dizide
 *   hangi sırayla durduğuna göre farklı hash üretir ve her tur "değişmiş"
 *   görünürdü.
 */
final class ContentHasher
{
    /**
     * İçerik alanının parmak izi.
     *
     * Kanonik alanlar ve kanala özgü öznitelikler birlikte özetlenir: ikisi
     * de kanala gider ve ikisinin de değişmesi yeniden gönderim gerektirir.
     */
    public function hash(ListingPayload $payload): string
    {
        $variant = $payload->listing->variant;

        return $this->digest([
            'title' => $payload->title,
            'description' => $payload->description,
            'category_id' => $payload->categoryId,
            'sku' => $variant?->sku,
            'barcode' => $variant?->barcode,
            // Fiyat kanonik olarak varyantta durur ve içerik yüküyle birlikte
            // gider; ayrı PRICE alanı kendi hash'ini kendi payload'ından alır.
            'price' => $variant?->price === null ? null : (string) $variant->price,
            'attributes' => $payload->attributes,
        ]);
    }

    /**
     * Sıralı, deterministik özet.
     *
     * @param  array<string, mixed>  $fields
     */
    private function digest(array $fields): string
    {
        return hash('sha256', $this->encode($fields));
    }

    /**
     * İç içe dizileri de anahtara göre sıralayan kodlama.
     *
     * Kanala özgü öznitelikler serbest biçimlidir; yalnızca üst seviyeyi
     * sıralamak iç içe bir dizinin sırası değiştiğinde hash'i kaydırırdı.
     */
    private function encode(mixed $value): string
    {
        if (! is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }

        $sorted = $value;
        ksort($sorted);

        $parts = [];

        foreach ($sorted as $key => $item) {
            $parts[] = json_encode((string) $key, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
                .':'.$this->encode($item);
        }

        return '{'.implode(',', $parts).'}';
    }
}
