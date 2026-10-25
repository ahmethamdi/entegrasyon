<?php

declare(strict_types=1);

namespace App\Domain\Channels\Certification;

use App\Domain\Channels\Contracts\DeclaresUnsupportedCapabilities;
use App\Domain\Channels\Models\ChannelType;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * S15 · kanal × yetenek sertifikasyon matrisi — TEK hesap yeri.
 *
 * Kaynaklar (öncelik sırasıyla):
 *   1. Adaptör beyanı (`DeclaresUnsupportedCapabilities`)        → NOT_SUPPORTED
 *   2. Arayüz yok                                                  → NOT_IMPLEMENTED
 *   3. Kanıtlı kayıt (`CertificationEvidence`, en yeni tarih)       → SANDBOX / PRODUCTION / FAILED
 *   4. Test taraması (`resources/certification/test-coverage.json`) → MOCK_TESTED
 *   5. Yalnız arayüz                                               → IMPLEMENTED
 *
 * Site, yardım, panel ve yönetim ekranı BURADAN okur; ikinci bir yetenek
 * listesi yazılmaz (`ChannelCertificationContractTest`).
 */
final class ChannelCertification
{
    /** @var array<string, array<string, list<string>>>|null */
    private ?array $coverage = null;

    /** @param array<string, class-string>|null $adapters kod => sınıf (null = `ChannelAdapters::all()`; testte sahte adaptör) */
    public function __construct(private readonly ?array $adapters = null) {}

    /** @return array<string, class-string> */
    private function adapters(): array
    {
        return $this->adapters ?? ChannelAdapters::all();
    }

    /**
     * @return array{status: CertificationStatus, tests: list<string>, evidence: ?array{date: string, by: string, evidence: string}, note: ?string}
     */
    public function cell(string $channel, ChannelCapability $capability): array
    {
        $class = $this->adapters()[$channel] ?? null;
        $note = $this->noteFor($channel, $capability);

        if ($class === null) {
            return ['status' => CertificationStatus::NOT_IMPLEMENTED, 'tests' => [], 'evidence' => null, 'note' => $note];
        }

        if (is_a($class, DeclaresUnsupportedCapabilities::class, true)) {
            $reason = $class::unsupportedCapabilities()[$capability->value] ?? null;
            if (is_string($reason) && $reason !== '') {
                return ['status' => CertificationStatus::NOT_SUPPORTED, 'tests' => [], 'evidence' => null, 'note' => $reason];
            }
        }

        if (! $capability->implementedBy($class)) {
            return ['status' => CertificationStatus::NOT_IMPLEMENTED, 'tests' => [], 'evidence' => null, 'note' => $note];
        }

        $tests = $this->coverage()[$channel][$capability->value] ?? [];
        $record = $this->latestRecord($channel, $capability);

        $status = match (true) {
            $record !== null => $record['status'],
            $tests !== [] => CertificationStatus::MOCK_TESTED,
            default => CertificationStatus::IMPLEMENTED,
        };

        return [
            'status' => $status,
            'tests' => $tests,
            'evidence' => $record === null ? null : ['date' => $record['date'], 'by' => $record['by'], 'evidence' => $record['evidence']],
            'note' => $note,
        ];
    }

    /** @return array<string, array<string, array{status: CertificationStatus, tests: list<string>, evidence: ?array, note: ?string}>> */
    public function matrix(): array
    {
        $out = [];
        foreach (array_keys($this->adapters()) as $channel) {
            foreach (ChannelCapability::cases() as $capability) {
                $out[$channel][$capability->value] = $this->cell($channel, $capability);
            }
        }

        return $out;
    }

    /** Satıcıya "çalışır" denebilen yetenekler (iç durum adı yok). @return list<ChannelCapability> */
    public function workingFor(string $channel): array
    {
        return array_values(array_filter(
            ChannelCapability::cases(),
            fn (ChannelCapability $c): bool => $this->cell($channel, $c)['status']->works(),
        ));
    }

    public function works(string $channel, ChannelCapability $capability): bool
    {
        return $this->cell($channel, $capability)['status']->works();
    }

    /**
     * Kanal başına son 24 saatin kritik hataları: kanala giden başarısız
     * istekler (api_calls 4xx/5xx/ağ) ve ölen senkron işlemleri (dead).
     *
     * @return array<string, array{api_errors: int, dead_operations: int}>
     */
    public function errorsLast24h(): array
    {
        return TenantContext::runAsSystem(function (): array {
            $since = now()->subDay();

            $api = DB::table('api_calls as a')
                ->join('channel_connections as c', 'c.id', '=', 'a.channel_connection_id')
                ->where('a.called_at', '>=', $since)
                ->where(fn ($q) => $q->where('a.status_code', '>=', 400)->orWhere(fn ($q) => $q->whereNull('a.status_code')->whereNotNull('a.error_class')))
                ->groupBy('c.channel_type_code')
                ->pluck(DB::raw('count(*)'), 'c.channel_type_code');

            $dead = DB::table('sync_operations as s')
                ->join('channel_connections as c', 'c.id', '=', 's.channel_connection_id')
                ->where('s.status', 'dead')
                ->where('s.updated_at', '>=', $since)
                ->groupBy('c.channel_type_code')
                ->pluck(DB::raw('count(*)'), 'c.channel_type_code');

            $out = [];
            foreach (array_keys($this->adapters()) as $channel) {
                $out[$channel] = ['api_errors' => (int) ($api[$channel] ?? 0), 'dead_operations' => (int) ($dead[$channel] ?? 0)];
            }

            return $out;
        });
    }

    /** Görünen ad (kanal türü tablosundan; yoksa kod). */
    public function names(): array
    {
        $names = TenantContext::runAsSystem(fn () => ChannelType::query()->pluck('name', 'code')->all());

        $out = [];
        foreach (array_keys($this->adapters()) as $channel) {
            $out[$channel] = (string) ($names[$channel] ?? $channel);
        }

        return $out;
    }

    /** @return array<string, array<string, list<string>>> */
    private function coverage(): array
    {
        return $this->coverage ??= TestCoverageScanner::load();
    }

    /** @return array{channel: string, capabilities: list<ChannelCapability>, status: CertificationStatus, date: string, by: string, evidence: string}|null */
    private function latestRecord(string $channel, ChannelCapability $capability): ?array
    {
        return self::latestOf(CertificationEvidence::records(), $channel, $capability);
    }

    /**
     * Aynı hücrede birden çok kayıt varsa EN YENİ tarih geçerlidir (FAILED
     * sonra düzelirse yeni VERIFIED kaydı onu geçer).
     *
     * @param  list<array{channel: string, capabilities: list<ChannelCapability>, status: CertificationStatus, date: string, by: string, evidence: string}>  $records
     */
    public static function latestOf(array $records, string $channel, ChannelCapability $capability): ?array
    {
        $matching = array_filter(
            $records,
            fn (array $r): bool => $r['channel'] === $channel && in_array($capability, $r['capabilities'], true),
        );

        if ($matching === []) {
            return null;
        }

        usort($matching, fn (array $a, array $b): int => strcmp($b['date'], $a['date']));

        return $matching[0];
    }

    private function noteFor(string $channel, ChannelCapability $capability): ?string
    {
        foreach (CertificationEvidence::notes() as $note) {
            if ($note['channel'] === $channel && $note['capability'] === $capability) {
                return $note['note'];
            }
        }

        return null;
    }
}
