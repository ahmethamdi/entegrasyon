<?php

declare(strict_types=1);

namespace App\Domain\Ai\Support;

/**
 * Kanal başına başlık/açıklama kuralları — TEK YER (S8).
 *
 * Hem modele söylenir hem modelin çıktısı sunucuda bununla YENİDEN
 * denetlenir; onaylanan (ya da satıcının düzenlediği) metin de buradan
 * geçer. Sınırı aşan metin KIRPILMAZ: kırpılmış başlık yarım bir sözcükle
 * biter ve satıcı bunu fark etmeden kanala gönderirdi.
 *
 * `verified` ALANI DÜRÜSTLÜK İÇİNDİR: kanalın resmî belgesinde okunan
 * sınır `true`; belge bulunamadıysa ihtiyatlı bir değer konur ve `false`
 * kalır. Panel doğrulanmamış sınırı "tahmini" diye gösterir. Kaynak
 * (`source`) 23 Ekim 2026'da okundu.
 *
 * `banned` — kanalın YASAKLADIĞI karakter/ifadeler (yalnız belgede
 * okunanlar). `house` — 34Pazar kuralı: iletişim bilgisi ve bağlantı
 * hiçbir başlık/açıklamaya girmez (pazar yerleri satıcıyı platform dışına
 * yönlendirmeyi yasaklar; kanal kanal belgelenmedi, bu yüzden kanal
 * kuralı DEĞİL, bizim kuralımız olarak işaretli).
 */
final class ChannelContentRules
{
    /**
     * @var array<string, array{
     *     title_max: int, title_min?: int, description_max: int,
     *     language: string, verified: array{title: bool, description: bool},
     *     banned_chars?: list<string>, max_word_repeat?: int, source: string
     * }>
     */
    private const RULES = [
        'trendyol' => [
            'title_max' => 100,
            'description_max' => 30000,
            'language' => 'tr',
            'verified' => ['title' => true, 'description' => true],
            'source' => 'developers.trendyol.com · Product Create v2 (title 100, description 30.000 karakter)',
        ],
        'hepsiburada' => [
            'title_max' => 200,
            'description_max' => 30000,
            'language' => 'tr',
            'verified' => ['title' => false, 'description' => false],
            'source' => 'Resmî sınır bulunamadı (OpenAPI belgesinde yok); ihtiyatlı değer',
        ],
        'n11' => [
            'title_max' => 100,
            'title_min' => 15,
            'description_max' => 30000,
            'language' => 'tr',
            'verified' => ['title' => false, 'description' => false],
            'source' => 'En az 15 karakter n11 kodunda (N11Catalog); üst sınır belgede bulunamadı',
        ],
        'amazon' => [
            'title_max' => 200,
            'description_max' => 2000,
            'language' => 'source',
            'verified' => ['title' => true, 'description' => false],
            'banned_chars' => ['!', '$', '?', '_', '{', '}', '^', '¬', '¦'],
            'max_word_repeat' => 2,
            'source' => 'sellercentral.amazon.com · Product title requirements (21 Oca 2025): 200 karakter, yasaklı karakterler, aynı sözcük en çok 2 kez',
        ],
        'ebay' => [
            'title_max' => 80,
            'description_max' => 500000,
            'language' => 'en',
            'verified' => ['title' => true, 'description' => false],
            'source' => 'eBay hata metni "title length should be between 1 and 80"; açıklama sınırı doğrulanmadı',
        ],
        'etsy' => [
            'title_max' => 140,
            'description_max' => 100000,
            'language' => 'en',
            'verified' => ['title' => false, 'description' => false],
            'source' => '140 karakter üçüncü taraf kaynaklarda; Etsy resmî sayfası okunmadı',
        ],
        'shopify' => [
            'title_max' => 255,
            'description_max' => 100000,
            'language' => 'source',
            'verified' => ['title' => false, 'description' => false],
            'source' => '255 karakter üçüncü taraf kaynaklarda; Shopify resmî sayfası okunmadı',
        ],
        'otto' => [
            'title_max' => 150,
            'description_max' => 20000,
            'language' => 'de',
            'verified' => ['title' => false, 'description' => false],
            'source' => 'Belge okunmadı; ihtiyatlı değer',
        ],
        'kaufland' => [
            'title_max' => 150,
            'description_max' => 20000,
            'language' => 'de',
            'verified' => ['title' => false, 'description' => false],
            'source' => 'Belge okunmadı; ihtiyatlı değer',
        ],
    ];

    /** Listede olmayan kanal (Woo, ikas, Ticimax, Pazarama, Çiçeksepeti). */
    private const FALLBACK = [
        'title_max' => 150,
        'description_max' => 20000,
        'language' => 'tr',
        'verified' => ['title' => false, 'description' => false],
        'source' => 'Kanal kuralı tanımlı değil; ihtiyatlı değer',
    ];

    /** 34Pazar kuralı — bağlantı, e-posta, telefon. */
    private const HOUSE_PATTERNS = [
        'url' => '~(https?://|www\.)\S+~iu',
        'email' => '~[\w.+-]+@[\w-]+\.[\w.]+~u',
        'phone' => '~(\+?90|0)?\s?5\d{2}[\s-]?\d{3}[\s-]?\d{2}[\s-]?\d{2}~u',
    ];

    /** @return array<string, mixed> */
    public static function for(string $channelCode): array
    {
        return self::RULES[$channelCode] ?? self::FALLBACK;
    }

    /**
     * Metnin kural ihlalleri — boşsa geçerli.
     *
     * @return list<string>
     */
    public static function violations(string $channelCode, string $title, ?string $description): array
    {
        $rule = self::for($channelCode);
        $out = [];
        $title = trim($title);
        $length = mb_strlen($title);

        if ($length === 0) {
            $out[] = __('Başlık boş olamaz.');
        }

        if ($length > $rule['title_max']) {
            $out[] = __('Başlık :max karakteri aşıyor (:length).', ['max' => $rule['title_max'], 'length' => $length]);
        }

        if (isset($rule['title_min']) && $length > 0 && $length < $rule['title_min']) {
            $out[] = __('Başlık en az :min karakter olmalı (:length).', ['min' => $rule['title_min'], 'length' => $length]);
        }

        if ($title !== strip_tags($title)) {
            $out[] = __('Başlıkta HTML olamaz.');
        }

        foreach ($rule['banned_chars'] ?? [] as $char) {
            if (str_contains($title, $char)) {
                $out[] = __('Başlıkta bu kanalda yasak karakter var: :char', ['char' => $char]);
            }
        }

        if (isset($rule['max_word_repeat'])) {
            $words = array_count_values(array_filter(
                preg_split('/[\s,|\-\/]+/u', mb_strtolower($title)) ?: [],
                static fn (string $w): bool => mb_strlen($w) > 3,
            ));

            foreach ($words as $word => $count) {
                if ($count > $rule['max_word_repeat']) {
                    $out[] = __('":word" başlıkta :count kez geçiyor (en çok :max).', ['word' => $word, 'count' => $count, 'max' => $rule['max_word_repeat']]);
                }
            }
        }

        $descLength = mb_strlen((string) $description);

        if ($descLength > $rule['description_max']) {
            $out[] = __('Açıklama :max karakteri aşıyor (:length).', ['max' => $rule['description_max'], 'length' => $descLength]);
        }

        foreach (self::HOUSE_PATTERNS as $kind => $pattern) {
            if (preg_match($pattern, $title.' '.strip_tags((string) $description)) === 1) {
                $out[] = match ($kind) {
                    'url' => __('Metinde bağlantı olamaz.'),
                    'email' => __('Metinde e-posta adresi olamaz.'),
                    default => __('Metinde telefon numarası olamaz.'),
                };
            }
        }

        return $out;
    }

    /**
     * Modele verilecek kural metni (sistem talimatına girer).
     */
    public static function describe(string $channelCode): string
    {
        $rule = self::for($channelCode);
        $lines = [
            "Başlık en çok {$rule['title_max']} karakter.",
            "Açıklama en çok {$rule['description_max']} karakter.",
            'Başlıkta HTML yok; açıklamada yalnız <p>, <ul>, <li>, <strong> kullanılabilir.',
            'Bağlantı, e-posta, telefon numarası YAZMA.',
        ];

        if (isset($rule['title_min'])) {
            $lines[] = "Başlık en az {$rule['title_min']} karakter.";
        }

        if (isset($rule['banned_chars'])) {
            $lines[] = 'Başlıkta şu karakterler YASAK: '.implode(' ', $rule['banned_chars']);
        }

        if (isset($rule['max_word_repeat'])) {
            $lines[] = "Başlıkta aynı sözcük en çok {$rule['max_word_repeat']} kez geçebilir.";
        }

        $lines[] = match ($rule['language']) {
            'en' => 'Metni İngilizce yaz.',
            'de' => 'Metni Almanca yaz.',
            'tr' => 'Metni Türkçe yaz.',
            default => 'Metni ürün bilgisinin dilinde yaz.',
        };

        return implode("\n", $lines);
    }

    /**
     * Panelde gösterilen kural tablosu.
     *
     * @return array{titleMax: int, titleMin: int|null, descriptionMax: int, titleVerified: bool, descriptionVerified: bool, source: string}
     */
    public static function summary(string $channelCode): array
    {
        $rule = self::for($channelCode);

        return [
            'titleMax' => $rule['title_max'],
            'titleMin' => $rule['title_min'] ?? null,
            'descriptionMax' => $rule['description_max'],
            'titleVerified' => $rule['verified']['title'],
            'descriptionVerified' => $rule['verified']['description'],
            'source' => $rule['source'],
        ];
    }

    /** @return list<string> */
    public static function codes(): array
    {
        return array_keys(self::RULES);
    }
}
