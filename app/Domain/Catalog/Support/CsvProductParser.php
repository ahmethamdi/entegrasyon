<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Support;

/**
 * CSV ürün dosyasını ayrıştırır — SAF, YAN ETKİSİZ.
 *
 * Mimari Karar Dokümanı v2.2 · §13 · Faz 3 · "Toplu içe aktarma
 * (Excel/CSV)", §17 · öncelik tablosu ("TEMEL").
 *
 * AYRIŞTIRMA İLE YAZMA AYRIDIR: bu sınıf veritabanına dokunmaz, kuyruğa iş
 * atmaz ve kiracı bağlamı istemez. Girdi metin, çıktı satır listesi. Yazma
 * `ImportProducts` action'ının işidir. Birleştirilselerdi ayrıştırma
 * kuralları (ondalık ayırıcı, BOM, kolon eşleme) ancak veritabanı kurup
 * ürün yaratarak test edilebilirdi.
 *
 * DEĞİŞMEZ KURAL — KOLONLAR ADIYLA EŞLENİR, KONUMLA DEĞİL:
 *   Satıcının Excel'inde kolon sırası sabit değildir. Konumla eşlenseydi
 *   fiyat kolonu stok sanılır ve 500 ürün yanlış fiyatla kanala giderdi —
 *   geri alınamaz bir hata.
 *
 * DEĞİŞMEZ KURAL — TÜRKÇE ONDALIK AYIRICI KABUL EDİLİR:
 *   Türkçe Excel "1.299,90" yazar. `(float) "1.299,90"` PHP'de **1.0**
 *   eder — kuruşlar değil, LİRALAR sessizce düşer ve satıcı bunu ancak
 *   kanalda yanlış fiyat görünce fark eder.
 *
 * DEĞİŞMEZ KURAL — BAŞLIK HATASI SATIR HATASINDAN FARKLIDIR:
 *   Zorunlu kolon hiç yoksa her satır geçersizdir; 500 hata satırı basmak
 *   kullanıcıya "dosyandaki `fiyat` kolonu eksik" demekten kötüdür.
 */
final class CsvProductParser
{
    /** Kolon adı → iç alan. Eş anlamlılar aynı alana bağlanır. */
    private const COLUMNS = [
        'sku' => 'sku',
        'stok kodu' => 'sku',
        'stok_kodu' => 'sku',
        'baslik' => 'title',
        'başlık' => 'title',
        'urun adi' => 'title',
        'ürün adı' => 'title',
        'title' => 'title',
        'fiyat' => 'price',
        'price' => 'price',
        'satis fiyati' => 'price',
        'satış fiyatı' => 'price',
        'stok' => 'opening_stock',
        'stock' => 'opening_stock',
        'adet' => 'opening_stock',
        'miktar' => 'opening_stock',
        'aciklama' => 'description',
        'açıklama' => 'description',
        'description' => 'description',
        'marka' => 'brand',
        'brand' => 'brand',
        'barkod' => 'barcode',
        'barcode' => 'barcode',
        'kategori' => 'internal_category_id',
        'category' => 'internal_category_id',
    ];

    /** Bu alanlar olmadan ürün yaratılamaz. */
    private const REQUIRED = ['sku', 'title', 'price', 'opening_stock'];

    /**
     * İç alan adı → KULLANICIYA gösterilecek kolon adı.
     *
     * Eksik kolon raporu iç adla ("price", "opening_stock") verilseydi
     * kullanıcı dosyasında olmayan bir kolon adını arardı: onun
     * dosyasında `fiyat` ve `stok` yazıyor. Hata mesajı kullanıcının
     * GÖRDÜĞÜ dünyayı konuşmalı.
     */
    private const REQUIRED_LABELS = [
        'sku' => 'sku',
        'title' => 'baslik',
        'price' => 'fiyat',
        'opening_stock' => 'stok',
    ];

    /**
     * Ayırıcı adayları — Türkçe Excel varsayılanı NOKTALI VİRGÜLDÜR.
     *
     * Virgül ondalık ayırıcı olarak kullanıldığında Excel alan ayırıcısını
     * noktalı virgüle çevirir; yalnızca virgül desteklenseydi Türkçe
     * kaydedilmiş her dosya tek kolonluk saçmalık olarak okunurdu.
     */
    private const DELIMITERS = [',', ';', "\t"];

    public function parse(string $csv): CsvParseResult
    {
        $lines = $this->splitLines($this->stripBom($csv));

        if ($lines === []) {
            return new CsvParseResult(
                headerValid: false,
                missingColumns: array_values(self::REQUIRED_LABELS),
            );
        }

        $delimiter = $this->detectDelimiter($lines[0]);
        $header = $this->mapHeader(str_getcsv($lines[0], $delimiter, '"', '\\'));

        $missing = array_diff(self::REQUIRED, array_values($header));

        if ($missing !== []) {
            return new CsvParseResult(
                headerValid: false,
                // Kullanıcının dosyasındaki adla raporlanır.
                missingColumns: array_values(array_map(
                    static fn (string $field): string => self::REQUIRED_LABELS[$field],
                    $missing,
                )),
            );
        }

        $valid = [];
        $invalid = [];

        foreach ($lines as $index => $line) {
            if ($index === 0 || trim($line) === '') {
                continue;
            }

            // Satır numarası KULLANICIYA gösterilir ve 1 tabanlıdır:
            // başlık 1. satır, ilk veri 2. satır. Sıfır tabanlı bir sayı
            // kullanıcının editöründeki satırla tutmazdı.
            $lineNumber = $index + 1;

            $row = $this->buildRow($header, str_getcsv($line, $delimiter, '"', '\\'));

            $error = $this->validate($row);

            if ($error !== null) {
                $invalid[] = ['line' => $lineNumber, 'message' => $error];

                continue;
            }

            $row['line'] = $lineNumber;
            $valid[] = $row;
        }

        return new CsvParseResult(headerValid: true, valid: $valid, invalid: $invalid);
    }

    // ---------------------------------------------------------------- iç

    /**
     * Excel dosyayı UTF-8 BOM ile kaydeder.
     *
     * Atılmazsa ilk kolonun adı `"\u{FEFF}sku"` olur, hiçbir eşleme tutmaz
     * ve dosya "sku kolonu yok" diye reddedilirdi — kullanıcı gözüyle
     * kolon ORADA olduğu için teşhis edilemez bir hata.
     */
    private function stripBom(string $csv): string
    {
        return str_starts_with($csv, "\u{FEFF}") ? substr($csv, 3) : $csv;
    }

    /** @return list<string> */
    private function splitLines(string $csv): array
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", $csv);

        return array_values(array_filter(
            explode("\n", $normalized),
            static fn (string $line): bool => trim($line) !== '',
        ));
    }

    /**
     * Ayırıcı BAŞLIK SATIRINDAN öğrenilir — en çok kolon üreten kazanır.
     *
     * Veri satırından öğrenilseydi tırnak içindeki bir metin ("Kırmızı,
     * mavi") ayırıcıyı yanıltırdı.
     */
    private function detectDelimiter(string $headerLine): string
    {
        $best = ',';
        $bestCount = 0;

        foreach (self::DELIMITERS as $candidate) {
            $count = count(str_getcsv($headerLine, $candidate, '"', '\\'));

            if ($count > $bestCount) {
                $best = $candidate;
                $bestCount = $count;
            }
        }

        return $best;
    }

    /**
     * Başlık kolonlarını iç alan adlarına eşler.
     *
     * @param  list<string|null>  $cells
     * @return array<int, string> kolon indeksi → alan adı
     */
    private function mapHeader(array $cells): array
    {
        $map = [];

        foreach ($cells as $index => $cell) {
            $key = $this->normalizeHeader((string) $cell);

            if (isset(self::COLUMNS[$key])) {
                $map[$index] = self::COLUMNS[$key];
            }
        }

        return $map;
    }

    /** Başlık karşılaştırması küçük harf ve boşluksuz yapılır. */
    private function normalizeHeader(string $value): string
    {
        return mb_strtolower(trim($value), 'UTF-8');
    }

    /**
     * @param  array<int, string>  $header
     * @param  list<string|null>  $cells
     * @return array<string, mixed>
     */
    private function buildRow(array $header, array $cells): array
    {
        $row = [];

        foreach ($header as $index => $field) {
            $row[$field] = isset($cells[$index]) ? trim((string) $cells[$index]) : '';
        }

        return [
            'sku' => $row['sku'] ?? '',
            'title' => $row['title'] ?? '',
            'price' => $this->parseDecimal($row['price'] ?? ''),
            'opening_stock' => $this->parseInteger($row['opening_stock'] ?? ''),
            'description' => ($row['description'] ?? '') === '' ? null : $row['description'],
            'brand' => ($row['brand'] ?? '') === '' ? null : $row['brand'],
            'barcode' => ($row['barcode'] ?? '') === '' ? null : $row['barcode'],
            'internal_category_id' => ($row['internal_category_id'] ?? '') === ''
                ? null
                : $row['internal_category_id'],
        ];
    }

    /**
     * "1.299,90" → 1299.90 · "1299.90" → 1299.90
     *
     * TÜRKÇE BİÇİM ÖNCE KONTROL EDİLİR: virgül varsa nokta BİNLİK
     * ayırıcıdır ve atılır. Aksi hâlde "1.299,90" → `(float)` → 1.0 eder
     * ve satıcı 1299 liralık ürünü 1 liraya satar.
     */
    private function parseDecimal(string $value): ?float
    {
        $value = trim(str_replace([' ', "\u{00A0}"], '', $value));

        if ($value === '') {
            return null;
        }

        if (str_contains($value, ',')) {
            $value = str_replace('.', '', $value);
            $value = str_replace(',', '.', $value);
        }

        return is_numeric($value) ? (float) $value : null;
    }

    private function parseInteger(string $value): ?int
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        return preg_match('/^-?\d+$/', $value) === 1 ? (int) $value : null;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return string|null Hata mesajı; geçerliyse null
     */
    private function validate(array $row): ?string
    {
        if (($row['sku'] ?? '') === '') {
            return 'SKU boş olamaz.';
        }

        if (($row['title'] ?? '') === '') {
            return 'Başlık boş olamaz.';
        }

        if ($row['price'] === null) {
            return 'Fiyat sayı olmalı (örnek: 199,90).';
        }

        if ($row['price'] < 0) {
            return 'Fiyat negatif olamaz.';
        }

        if ($row['opening_stock'] === null) {
            return 'Stok tam sayı olmalı.';
        }

        // Açılış stoğu negatif olamaz: `CreateProduct` da bunu reddeder
        // ama hatayı BURADA yakalamak kullanıcıya satır numarasıyla
        // söylemeyi sağlar.
        if ($row['opening_stock'] < 0) {
            return 'Açılış stoğu negatif olamaz.';
        }

        return null;
    }
}
