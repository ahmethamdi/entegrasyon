<?php

declare(strict_types=1);

namespace App\Domain\Suppliers\Support;

/**
 * Örnek akıştan YAPI çıkarır — kullanıcı XPath yazmasın, seçsin.
 *
 *   1. Tekrarlanan kayıt düğümü adayları: en az 2 kez geçen, alt
 *      elemanı olan yollar (çoktan aza).
 *   2. Seçilen düğümün ilk kayıtlarından alan yolları + örnek değerler.
 *   3. Kayıt İÇİNDE tekrarlanan alt düğümler → iç içe varyant adayları.
 *   4. Ondalık ayırıcı İPUCU (karar kullanıcınındır).
 */
final class FeedStructure
{
    public const SAMPLE_RECORDS = 20;

    public function __construct(private readonly XmlFeedReader $reader) {}

    /**
     * @return array{
     *     record_paths: list<array{path: string, count: int}>,
     *     record_path: string|null,
     *     variant_paths: list<array{path: string, count: int}>,
     *     fields: list<array{path: string, samples: list<string>}>,
     *     number_format_hint: string|null,
     *     complete: bool,
     *     error: string|null,
     *     record_count: int
     * }
     */
    public function describe(string $file, ?string $recordPath = null, ?string $variantPath = null): array
    {
        $survey = $this->reader->survey($file);
        $paths = $survey['paths'];

        $candidates = [];

        foreach ($paths as $path => $info) {
            if ($info['count'] >= 2 && $info['has_children']) {
                $candidates[] = ['path' => $path, 'count' => $info['count']];
            }
        }

        usort($candidates, fn ($a, $b) => [$b['count'], substr_count($a['path'], '/')] <=> [$a['count'], substr_count($b['path'], '/')]);

        // En dıştaki tekrarlayan düğüm genellikle üründür; iç içe varyant
        // düğümü ürün sayısından çok olabilir — sığ olanı tercih et.
        $chosen = $recordPath;

        if ($chosen === null && $candidates !== []) {
            $minDepth = min(array_map(fn ($c) => substr_count($c['path'], '/'), $candidates));
            $shallow = array_values(array_filter($candidates, fn ($c) => substr_count($c['path'], '/') === $minDepth));
            $chosen = $shallow[0]['path'];
        }

        $variantCandidates = [];
        $fields = [];
        $hint = null;
        $recordCount = $chosen !== null ? ($paths[$chosen]['count'] ?? 0) : 0;

        if ($chosen !== null) {
            foreach ($candidates as $candidate) {
                if (str_starts_with($candidate['path'], $chosen.'/') && $candidate['count'] > $recordCount) {
                    $variantCandidates[] = [
                        'path' => substr($candidate['path'], strlen($chosen) + 1),
                        'count' => $candidate['count'],
                    ];
                }
            }

            $samples = [];

            foreach ($this->reader->sampleItems($file, $chosen, $variantPath, self::SAMPLE_RECORDS) as $item) {
                foreach ($item as $path => $values) {
                    foreach ($values as $value) {
                        if ($value !== '' && count($samples[$path] ?? []) < 3 && ! in_array(mb_substr($value, 0, 80), $samples[$path] ?? [], true)) {
                            $samples[$path][] = mb_substr($value, 0, 80);
                        }
                    }

                    $samples[$path] ??= [];
                }
            }

            foreach ($samples as $path => $values) {
                $fields[] = ['path' => (string) $path, 'samples' => $values];
            }

            $hint = $this->numberHint($samples);
        }

        return [
            'record_paths' => array_slice($candidates, 0, 10),
            'record_path' => $chosen,
            'variant_paths' => array_slice($variantCandidates, 0, 5),
            'fields' => $fields,
            'number_format_hint' => $hint,
            'complete' => $survey['complete'],
            'error' => $survey['error'],
            'record_count' => $recordCount,
        ];
    }

    /**
     * @param  array<string, list<string>>  $samples
     */
    private function numberHint(array $samples): ?string
    {
        $comma = 0;
        $dot = 0;

        foreach ($samples as $values) {
            foreach ($values as $value) {
                if (preg_match('/^\d{1,3}(\.\d{3})*,\d{1,2}$/', $value) === 1 || preg_match('/^\d+,\d{2}$/', $value) === 1) {
                    $comma++;
                } elseif (preg_match('/^\d{1,3}(,\d{3})*\.\d{1,2}$/', $value) === 1 || preg_match('/^\d+\.\d{2}$/', $value) === 1) {
                    $dot++;
                }
            }
        }

        if ($comma === 0 && $dot === 0) {
            return null;
        }

        return $comma > $dot ? 'comma' : 'dot';
    }
}
