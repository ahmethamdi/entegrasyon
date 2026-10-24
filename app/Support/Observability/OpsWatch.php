<?php

declare(strict_types=1);

namespace App\Support\Observability;

use App\Domain\Catalog\Support\Fx\FxRates;
use App\Domain\Channels\Support\CircuitBreaker;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Horizon\WaitTimeCalculator;
use Throwable;

/**
 * İşletme izleme turu — S7 · Black Friday (23 Ekim 2026).
 *
 * `metrics:capture` + `alerts:dispatch` SATICIYA günlük özet içindir
 * (saatlik ölçüm, sabah 09:00 posta). Black Friday gecesi kuyruk 20 dakika
 * tıkanırsa bunu ertesi sabah öğrenmek geç; bu tur 5 dakikada bir CANLI
 * ölçer ve 34Devs'e yazar.
 *
 * DÖRT SİNYAL:
 *   kuyruk bekleme  Horizon'un hesapladığı bekleme (kuyruk başına)
 *   devre kesici    açık devreli aktif bağlantı (kanal başına)
 *   hız sınırı      son 15 dk 429 (kanal başına)
 *   ölü işlem       son 1 saatte `dead`e düşen senkron (sistem)
 *   kur             çevirisi açık bir birimin kuru yok ya da bayat (S9)
 *   sağlıksız kanal canlı ilanı olan bağlantı 24 saatten uzun sağlıksız
 *                   (24 Ekim: test kiracısında Etsy anahtarı 6 Ekim'den beri
 *                   403 veriyordu; bağlantı `pending` olduğu için devre
 *                   kesici sinyali onu hiç görmedi, kimse haber almadı).
 *                   Günde bir kez gider — kendiliğinden düzelmeyen bir
 *                   durumda saatlik tekrar yalnız gürültüdür.
 *
 * Her sinyal kendi anahtarıyla saatte en çok bir kez gider (`OpsNotifier`).
 * Ölçümlerden biri hata verirse (Redis yok, Horizon yok) diğerleri yine
 * ölçülür: tek kırık kaynak bütün izlemeyi susturmamalı.
 */
final class OpsWatch
{
    public function __construct(
        private readonly OpsNotifier $notifier,
        private readonly CircuitBreaker $breaker,
    ) {}

    /** @return list<array{key: string, title: string, body: string, throttle?: int}> */
    public function findings(): array
    {
        return TenantContext::runAsSystem(fn (): array => [
            ...$this->guard('queue', fn () => $this->queueWait()),
            ...$this->guard('circuit', fn () => $this->openCircuits()),
            ...$this->guard('rate_limit', fn () => $this->rateLimits()),
            ...$this->guard('dead', fn () => $this->deadOperations()),
            ...$this->guard('ai', fn () => $this->aiErrors()),
            ...$this->guard('fx', fn () => $this->staleExchangeRates()),
            ...$this->guard('unhealthy', fn () => $this->unhealthyConnections()),
        ]);
    }

    /** @return array{found: int, sent: int} */
    public function run(): array
    {
        $findings = $this->findings();
        $sent = 0;

        foreach ($findings as $f) {
            $throttle = $f['throttle'] ?? (int) config('entegrasyon.ops.throttle_seconds', 3600);

            if ($this->notifier->send($f['title'], $f['body'], 'rotating_light', $f['key'], $throttle)) {
                $sent++;
            }
        }

        return ['found' => count($findings), 'sent' => $sent];
    }

    /** @return list<array{key: string, title: string, body: string}> */
    private function queueWait(): array
    {
        $limit = (int) config('entegrasyon.ops.queue_wait_seconds');
        $out = [];

        foreach (app(WaitTimeCalculator::class)->calculate() as $queue => $seconds) {
            if ((int) $seconds > $limit) {
                $out[] = [
                    'key' => "queue:{$queue}",
                    'title' => "Kuyruk gecikiyor: {$queue}",
                    'body' => sprintf('%s kuyruğunda bekleme ~%d sn (eşik %d sn). Horizon panelinde işçi sayısına ve takılı işlere bakın.', $queue, (int) $seconds, $limit),
                ];
            }
        }

        return $out;
    }

    /** @return list<array{key: string, title: string, body: string}> */
    private function openCircuits(): array
    {
        $out = [];

        $connections = DB::table('channel_connections')
            ->join('tenants', 'tenants.id', '=', 'channel_connections.tenant_id')
            ->where('channel_connections.status', 'active')
            ->get(['channel_connections.id', 'channel_connections.label', 'channel_connections.channel_type_code', 'tenants.name as tenant']);

        foreach ($connections as $c) {
            if ($this->breaker->state((string) $c->id) === 'open') {
                $out[] = [
                    'key' => "circuit:{$c->id}",
                    'title' => "Devre kesici açık: {$c->channel_type_code}",
                    'body' => sprintf('%s · "%s" · kiracı %s — kanala istek gitmiyor. Kanal sağlığını ve son hataları kontrol edin.', $c->channel_type_code, $c->label, $c->tenant),
                ];
            }
        }

        return $out;
    }

    /** @return list<array{key: string, title: string, body: string}> */
    private function rateLimits(): array
    {
        $limit = (int) config('entegrasyon.ops.rate_limit_hits_15m');

        $rows = DB::select(<<<'SQL'
            SELECT a.channel_connection_id AS id, count(*) AS hits,
                   max(c.channel_type_code) AS channel, max(c.label) AS label
              FROM api_calls a
              JOIN channel_connections c ON c.id = a.channel_connection_id
             WHERE a.status_code = 429
               AND a.called_at > clock_timestamp() - interval '15 minutes'
             GROUP BY a.channel_connection_id
            HAVING count(*) > ?
        SQL, [$limit]);

        return array_map(static fn (object $r): array => [
            'key' => "rate_limit:{$r->id}",
            'title' => "Hız sınırı (429): {$r->channel}",
            'body' => sprintf('"%s" son 15 dakikada %d kez 429 aldı (eşik %d). Stok/fiyat itmesi gecikir.', $r->label, (int) $r->hits, $limit),
        ], $rows);
    }

    /** @return list<array{key: string, title: string, body: string}> */
    private function deadOperations(): array
    {
        $limit = (int) config('entegrasyon.ops.dead_operations_1h');

        $count = DB::table('sync_operations')
            ->where('status', 'dead')
            ->where('completed_at', '>', now()->subHour())
            ->count();

        return $count > $limit ? [[
            'key' => 'dead:system',
            'title' => 'Ölü senkron işlemleri arttı',
            'body' => sprintf('Son 1 saatte %d senkron işlemi gönderilemedi (eşik %d). Gönderilemeyenler ekranında hata sınıflarına bakın.', $count, $limit),
        ]] : [];
    }

    /**
     * S8 · AI öneri çağrılarının son 1 saatteki hata oranı (tüm sistem).
     * Az çağrıda oran yanıltır (1/1 = %100); en az çağrı eşiği aşılmalı.
     *
     * @return list<array{key: string, title: string, body: string}>
     */
    private function aiErrors(): array
    {
        $row = DB::table('ai_usage')
            ->where('created_at', '>', now()->subHour())
            ->selectRaw("count(*) AS total, count(*) FILTER (WHERE status = 'error') AS errors")
            ->first();

        $total = (int) ($row->total ?? 0);
        $errors = (int) ($row->errors ?? 0);
        $limit = (int) config('entegrasyon.ops.ai_error_rate_percent');

        if ($total < (int) config('entegrasyon.ops.ai_error_min_calls') || $errors * 100 <= $total * $limit) {
            return [];
        }

        return [[
            'key' => 'ai:errors',
            'title' => 'AI öneri hataları arttı',
            'body' => sprintf('Son 1 saatte %d AI çağrısının %d tanesi yapılamadı (eşik %%%d). Anahtarı ve Anthropic durumunu kontrol edin; ai_usage.error sütununa bakın.', $total, $errors, $limit),
        ]];
    }

    /**
     * S9 · kur çevirisi açık bir bağlantının hedef birimi için kur yok ya da
     * bayat: o bağlantıların fiyatı gönderilmiyor. Birim başına tek alarm.
     *
     * @return list<array{key: string, title: string, body: string}>
     */
    private function staleExchangeRates(): array
    {
        $rows = DB::table('channel_price_rules')
            ->where('fx_enabled', true)
            ->whereNotNull('fx_currency')
            ->selectRaw('upper(fx_currency) AS currency, count(*) AS connections')
            ->groupByRaw('upper(fx_currency)')
            ->get();

        $rates = app(FxRates::class);
        $rates->forget();
        $out = [];

        foreach ($rows as $row) {
            $reason = $rates->unavailableReason((string) $row->currency);

            if ($reason !== null) {
                $out[] = [
                    'key' => "fx:{$row->currency}",
                    'title' => "Kur bayat: {$row->currency}",
                    'body' => sprintf('%s Etkilenen bağlantı: %d. `php artisan fx:fetch` çıktısına ve TCMB erişimine bakın.', $reason, (int) $row->connections),
                ];
            }
        }

        return $out;
    }

    /**
     * Canlı ilanı olan ve uzun süredir sağlıksız bağlantılar.
     *
     * Sağlıksız bağlantıda stok ve fiyat gitmez, mutabakat "ulaşılamadı"
     * yazar; satıcı paneli görmezse fark etmez. `inactive` (erişim iptal,
     * mağazadan kaldırma) bilinçli kapanıştır, sayılmaz.
     *
     * @return list<array{key: string, title: string, body: string, throttle: int}>
     */
    private function unhealthyConnections(): array
    {
        $hours = (int) config('entegrasyon.ops.unhealthy_connection_hours', 24);

        $rows = DB::select(<<<'SQL'
            SELECT c.id, c.label, c.channel_type_code AS channel, t.name AS tenant,
                   coalesce(c.last_healthy_at, c.connected_at, c.created_at) AS since,
                   left(coalesce(c.last_error, ''), 160) AS error
              FROM channel_connections c
              JOIN tenants t ON t.id = c.tenant_id
             WHERE c.health_status = 'unhealthy'
               AND c.status <> 'inactive'
               AND coalesce(c.last_healthy_at, c.connected_at, c.created_at) < clock_timestamp() - make_interval(hours => ?)
               AND EXISTS (SELECT 1 FROM listings l
                            WHERE l.channel_connection_id = c.id AND l.lifecycle_status = 'live')
        SQL, [$hours]);

        return array_map(static fn (object $r): array => [
            'key' => "unhealthy:{$r->id}",
            'title' => "Kanal {$hours} saatten uzun sağlıksız: {$r->channel}",
            'body' => sprintf('"%s" · kiracı %s · son sağlıklı %s. Bu bağlantıya stok ve fiyat gitmiyor. Son hata: %s', $r->label, $r->tenant, $r->since, $r->error !== '' ? $r->error : '—'),
            'throttle' => 86400,
        ], $rows);
    }

    /**
     * @param  callable(): list<array{key: string, title: string, body: string}>  $probe
     * @return list<array{key: string, title: string, body: string}>
     */
    private function guard(string $signal, callable $probe): array
    {
        try {
            return $probe();
        } catch (Throwable $e) {
            Log::warning('ops_watch.probe_failed', ['signal' => $signal, 'error' => $e->getMessage()]);

            return [];
        }
    }
}
