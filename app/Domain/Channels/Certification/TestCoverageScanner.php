<?php

declare(strict_types=1);

namespace App\Domain\Channels\Certification;

/**
 * S15 · MOCK_TESTED'in kaynağı: test dosyaları taranır, etiket elle yazılmaz.
 *
 * Bir test yöntemi bir (kanal, yetenek) hücresini ancak şu iki şart birlikte
 * tutarsa sayar:
 *   1. KANAL: yöntem gövdesi kanalın adaptör sınıfını adıyla anar; anmıyorsa
 *      dosya TEK bir kanalın dosyasıdır (adaptörü anan ya da adıyla başlayan).
 *   2. YETENEK: gövde yeteneğin adaptör yöntemlerinden birini ÇAĞIRIR
 *      (`->pushInventory(` …) ve gerekiyorsa anahtar kelimeyi taşır.
 *
 * TUTUCUDUR: yardımcı yöntem üzerinden ya da iş (job) üzerinden yapılan
 * dolaylı çağrılar SAYILMAZ — eksik sayım "IMPLEMENTED" bırakır, fazla sayım
 * yalan "test edildi" derdi. Kanıt olarak test yöntemleri listelenir.
 *
 * Testler prod imajında YOK (.dockerignore): sonuç `resources/certification/
 * test-coverage.json`'a yazılır (`certification:scan-tests`) ve sözleşme
 * testi dosyanın güncel olduğunu her koşuda doğrular.
 */
final class TestCoverageScanner
{
    public const LEDGER = 'resources/certification/test-coverage.json';

    /** Bir hücre için saklanan en çok kanıt sayısı (dosya boyu ve okunurluk). */
    private const MAX_EVIDENCE = 5;

    /** @return array<string, array<string, list<string>>> kanal => yetenek => ["yol::yöntem", …] */
    public function scan(?string $testsPath = null): array
    {
        $testsPath ??= base_path('tests');
        $adapters = ChannelAdapters::all();
        $short = [];
        foreach ($adapters as $code => $class) {
            $short[$code] = substr($class, strrpos($class, '\\') + 1);
        }

        $result = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($testsPath, \FilesystemIterator::SKIP_DOTS));

        $paths = [];
        foreach ($files as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), 'Test.php')) {
                $paths[] = $file->getPathname();
            }
        }
        sort($paths);

        foreach ($paths as $path) {
            $source = (string) file_get_contents($path);
            $relative = ltrim(substr($path, strlen(base_path())), '/');
            $fileChannels = $this->fileChannels(basename($path), $source, $short);

            foreach ($this->testMethods($source) as $name => $body) {
                $channels = array_keys(array_filter($short, fn (string $s): bool => preg_match('/\b'.$s.'\b/', $body) === 1));
                if ($channels === []) {
                    $channels = count($fileChannels) === 1 ? $fileChannels : [];
                }
                if ($channels === []) {
                    continue;
                }

                $lower = mb_strtolower($body);
                foreach (ChannelCapability::cases() as $capability) {
                    if (! $this->calls($body, $capability->testNeedles())) {
                        continue;
                    }
                    $keywords = $capability->testKeywords();
                    if ($keywords !== [] && ! $this->containsAny($lower, $keywords)) {
                        continue;
                    }

                    foreach ($channels as $code) {
                        $result[$code][$capability->value][] = "{$relative}::{$name}";
                    }
                }
            }
        }

        ksort($result);
        foreach ($result as &$capabilities) {
            ksort($capabilities);
            foreach ($capabilities as &$evidence) {
                $evidence = array_slice(array_values(array_unique($evidence)), 0, self::MAX_EVIDENCE);
            }
        }

        return $result;
    }

    public function write(array $coverage): void
    {
        $path = base_path(self::LEDGER);
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        file_put_contents($path, self::encode($coverage));
    }

    /** @return array<string, array<string, list<string>>> */
    public static function load(): array
    {
        $path = base_path(self::LEDGER);
        if (! is_file($path)) {
            return [];
        }

        $data = json_decode((string) file_get_contents($path), true);

        return is_array($data) ? $data : [];
    }

    public static function encode(array $coverage): string
    {
        return json_encode($coverage, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
    }

    /**
     * @param  array<string, string>  $short  kod => adaptör kısa adı
     * @return list<string>
     */
    private function fileChannels(string $filename, string $source, array $short): array
    {
        $byName = array_keys(array_filter($short, fn (string $s): bool => str_starts_with($filename, substr($s, 0, -strlen('Adapter')))));
        $byMention = array_keys(array_filter($short, fn (string $s): bool => preg_match('/\b'.$s.'\b/', $source) === 1));

        return array_values(array_unique([...$byName, ...$byMention]));
    }

    /** @return array<string, string> test yöntemi adı => gövde */
    private function testMethods(string $source): array
    {
        preg_match_all('/(#\[Test\]\s*)?(?:public|protected|private)\s+function\s+(\w+)\s*\(/', $source, $m, PREG_OFFSET_CAPTURE);

        $methods = [];
        $count = count($m[0]);
        for ($i = 0; $i < $count; $i++) {
            $name = $m[2][$i][0];
            $isTest = $m[1][$i][0] !== '' || str_starts_with($name, 'test');
            if (! $isTest) {
                continue;
            }
            $start = $m[0][$i][1];
            $end = $i + 1 < $count ? $m[0][$i + 1][1] : strlen($source);
            $methods[$name] = substr($source, $start, $end - $start);
        }

        return $methods;
    }

    /** @param list<string> $needles */
    private function calls(string $body, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($body, "->{$needle}(") || str_contains($body, "::{$needle}(")) {
                return true;
            }
        }

        return false;
    }

    /** @param list<string> $keywords */
    private function containsAny(string $haystack, array $keywords): bool
    {
        foreach ($keywords as $keyword) {
            if (str_contains($haystack, $keyword)) {
                return true;
            }
        }

        return false;
    }
}
