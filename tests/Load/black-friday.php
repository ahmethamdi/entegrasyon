<?php

declare(strict_types=1);

/*
 * BLACK FRIDAY YÜK TESTİ — S7 (23 Ekim 2026). YALNIZ YEREL.
 *
 *   docker compose exec -d app php artisan horizon --environment=production
 *   docker compose exec -d app php artisan outbox:relay
 *   docker compose exec app php tests/Load/black-friday.php [--minutes=5] [--per-minute=100]
 *       [--burst-per-minute=300] [--variants=2000] [--channels=5] [--latency-ms=150]
 *       [--price-variants=0]   (patlama dakikasında bu kadar varyantın fiyatı değişir)
 *
 * `loadtest:sync` hattı KUYRUĞA KADAR ölçer. Bu betik UÇTAN UCA ölçer:
 * gerçek webhook ucu → inbox → Horizon işçileri → stok düşüşü → outbox →
 * fan-out → beş sahte kanala gönderim (`LoadTestChannelAdapter`, ağa
 * çıkmaz, istek başına gecikme taklidi). Soru: "satıcının en yoğun
 * dakikasında, sipariş düştükten kaç saniye sonra stok BEŞ kanalda da
 * doğru?" — kuyruğun boşalması bunun kanıtı değildir.
 *
 * Kendi kiracısını kurar ve sonunda siler; var olan veriye dokunmaz.
 * Üretimde ÇALIŞMAZ (aşağıdaki kapı) ve üretim imajında adapter sınıfı
 * zaten yoktur (`autoload-dev`).
 */

use App\Domain\Catalog\Actions\SetVariantPrice;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Variant;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Actions\ApplyMovement;
use App\Domain\Inventory\Actions\LockInventoryRows;
use App\Domain\Inventory\Enums\MovementType;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\Support\MovementKey;
use App\Domain\Sync\Models\Listing;
use App\Support\Observability\OpsWatch;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Laravel\Horizon\WaitTimeCalculator;
use Symfony\Component\Uid\UuidV7;
use Tests\Load\LoadTestChannelAdapter;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! app()->environment('local')) {
    fwrite(STDERR, "Yük testi yalnız APP_ENV=local ortamında çalışır.\n");
    exit(1);
}

$opt = getopt('', ['minutes::', 'per-minute::', 'burst-per-minute::', 'variants::', 'channels::', 'latency-ms::', 'webhook-base::', 'price-variants::', 'keep']);
$minutes = (int) ($opt['minutes'] ?? 5);
$perMinute = (int) ($opt['per-minute'] ?? 100);
$burstPerMinute = (int) ($opt['burst-per-minute'] ?? 300);
$variantCount = (int) ($opt['variants'] ?? 2000);
$channelCount = (int) ($opt['channels'] ?? 5);
$latencyMs = (int) ($opt['latency-ms'] ?? 150);
$priceVariants = (int) ($opt['price-variants'] ?? 0);
$webhookBase = rtrim((string) ($opt['webhook-base'] ?? 'http://nginx'), '/');

const OPENING_STOCK = 100_000;
const HOT_SKUS = 20;        // kampanya ürünleri: siparişlerin ~%30'u bunlara düşer
const DRAIN_TIMEOUT_S = 900;

$say = static fn (string $m) => fwrite(STDOUT, '['.date('H:i:s')."] {$m}\n");

function pct(array $values, float $p): ?float
{
    if ($values === []) {
        return null;
    }
    sort($values);

    return round($values[max(0, (int) ceil($p / 100 * count($values)) - 1)], 3);
}

function queueDepths(): array
{
    $out = [];
    foreach (['inbox:process', 'outbox:consume', 'inventory:high', 'orders:high', 'price:high', 'listing:default'] as $q) {
        $out[$q] = (int) Redis::connection()->llen("queues:{$q}")
            + (int) Redis::connection()->zcard("queues:{$q}:delayed")
            + (int) Redis::connection()->zcard("queues:{$q}:reserved");
    }

    return $out;
}

function deadlocks(): int
{
    return (int) DB::selectOne('SELECT deadlocks FROM pg_stat_database WHERE datname = current_database()')->deadlocks;
}

// ── kanal türü (yalnız bu tur için) ───────────────────────────────────
DB::table('channel_types')->updateOrInsert(['code' => 'loadtest'], [
    'name' => 'Yük testi',
    'kind' => 'marketplace',
    'adapter_class' => LoadTestChannelAdapter::class,
    'capabilities' => json_encode(['inventory' => true, 'orders' => true, 'pricing' => true]),
    // Trendyol düzeyi: 5 istek/sn, 100'lük stok paketi.
    'rate_limit_profile' => json_encode(['strategy' => 'fixed_window', 'requests_per_second' => 5, 'burst_capacity' => 10, 'max_inventory_batch' => 100]),
    'supports_webhooks' => true,
    'is_active' => false,
    'is_beta' => false,
    'created_at' => now(),
    'updated_at' => now(),
]);
Redis::set(LoadTestChannelAdapter::LATENCY_KEY, $latencyMs);
Redis::del(LoadTestChannelAdapter::PUSHES_KEY, LoadTestChannelAdapter::REMOTE_KEY, LoadTestChannelAdapter::PRICE_PUSHES_KEY, LoadTestChannelAdapter::REMOTE_PRICE_KEY);

$tenantId = null;
$ownerId = null;
$result = [];

try {
    // ── hazırlık ─────────────────────────────────────────────────────────
    $say("Hazırlık: 1 kiracı · {$channelCount} kanal · {$variantCount} varyant (her biri her kanalda listelenmiş)…");
    $owner = User::factory()->create(['email' => 'yuk-testi-'.uniqid().'@34pazar.local']);
    $ownerId = $owner->id;
    $tenant = app(CreateTenant::class)->run(name: 'yuk-testi-bf-'.date('His'), owner: $owner);
    $tenantId = $tenant->id;

    [$warehouseId, $connections, $skus] = TenantContext::runFor($tenantId, function () use ($tenantId, $channelCount, $variantCount, $say): array {
        $warehouseId = Warehouse::query()->where('is_default', true)->value('id');
        $connections = [];
        for ($c = 0; $c < $channelCount; $c++) {
            $connections[] = ChannelConnection::factory()->create([
                'channel_type_code' => 'loadtest',
                'label' => 'Yük kanalı '.($c + 1),
            ])->id;
        }

        $skus = [];
        for ($v = 0; $v < $variantCount; $v++) {
            $variant = Variant::factory()->for(Product::factory()->create())->create(['sku' => sprintf('BF-%05d', $v)]);
            foreach ($connections as $connectionId) {
                Listing::query()->create([
                    'tenant_id' => $tenantId,
                    'channel_connection_id' => $connectionId,
                    'variant_id' => $variant->id,
                    'external_id' => "bf-{$connectionId}-{$v}",
                    'lifecycle_status' => 'live',
                    'listed_at' => now(),
                ]);
            }
            DB::transaction(function () use ($warehouseId, $variant): void {
                app(LockInventoryRows::class)->run($warehouseId, [$variant->id]);
                app(ApplyMovement::class)->run(
                    warehouseId: $warehouseId,
                    variantId: $variant->id,
                    type: MovementType::IMPORT,
                    quantity: OPENING_STOCK,
                    idempotencyKey: MovementKey::import((string) new UuidV7),
                    sourceType: 'load_test',
                );
            });
            $skus[] = $variant->sku;
            if (($v + 1) % 500 === 0) {
                $say('  '.($v + 1).' varyant');
            }
        }

        return [$warehouseId, $connections, $skus];
    });

    // Açılış stoğunun kanallara ilk gönderimi ölçüme karışmasın.
    $say('Açılış stoğu kanallara gidiyor, kuyruk boşalana kadar bekleniyor…');
    $waitDrain = function (int $timeout) use ($tenantId): float {
        $started = microtime(true);
        $quiet = 0;
        while (microtime(true) - $started < $timeout) {
            $pendingOutbox = TenantContext::runAsSystem(fn () => DB::table('outbox_events')->where('tenant_id', $tenantId)->whereNull('consumed_at')->count());
            $pendingOps = TenantContext::runAsSystem(fn () => DB::table('sync_operations')->where('tenant_id', $tenantId)->whereIn('status', ['pending', 'retrying'])->count());
            $pendingInbox = TenantContext::runAsSystem(fn () => DB::table('inbox_messages')->where('tenant_id', $tenantId)->whereIn('status', ['pending', 'processing'])->count());
            $queued = array_sum(queueDepths());
            $quiet = ($pendingOutbox + $pendingOps + $pendingInbox + $queued) === 0 ? $quiet + 1 : 0;
            if ($quiet >= 3) {
                return microtime(true) - $started;
            }
            usleep(1_000_000);
        }

        return -1.0;
    };
    $seedDrain = $waitDrain(DRAIN_TIMEOUT_S);
    $say('  hazır ('.round($seedDrain, 1).' sn)');
    Redis::del(LoadTestChannelAdapter::PUSHES_KEY, LoadTestChannelAdapter::REMOTE_KEY, LoadTestChannelAdapter::PRICE_PUSHES_KEY, LoadTestChannelAdapter::REMOTE_PRICE_KEY);
    foreach ($connections as $connectionId) {
        foreach ($skus as $sku) {
            Redis::hset(LoadTestChannelAdapter::REMOTE_KEY, "{$connectionId}|{$sku}", OPENING_STOCK);
        }
    }

    // ── yük ─────────────────────────────────────────────────────────────
    // Dakika planı: ortadaki dakika "kampanya başladı" patlamasıdır.
    $plan = array_fill(0, $minutes, $perMinute);
    if ($minutes >= 3 && $burstPerMinute > 0) {
        $plan[intdiv($minutes, 2)] = $burstPerMinute;
    }
    $say('Yük: dakika başı sipariş '.implode(' · ', $plan).' (toplam '.array_sum($plan).')');

    $deadlocksBefore = deadlocks();
    $failedJobsBefore = (int) DB::table('failed_jobs')->count();
    $sent = [];          // external_order_id => [t0, [sku => qty]]
    $soldThrough = [];   // sku => bu siparişle birlikte toplam satılan (gönderim sırasıyla)
    $postLatencies = [];
    $postErrors = [];
    $samples = [];
    $orderNo = 0;
    $hot = min(HOT_SKUS, intdiv(count($skus), 2));
    $loadStarted = microtime(true);
    $nextSample = $loadStarted;

    // Kampanya başladı: patlama dakikasında fiyatlar da toplu değişir —
    // siparişlerin arasına serpilir (satıcı paneli / fiyat kuralı gibi).
    $burstMinute = ($minutes >= 3 && $burstPerMinute > 0) ? intdiv($minutes, 2) : 0;
    $priceQueue = array_slice($skus, 0, min($priceVariants, count($skus)));
    $pricePerOrder = $priceQueue === [] ? 0 : (int) ceil(count($priceQueue) / max(1, $plan[$burstMinute]));
    $priceSetAt = [];   // sku => [t, fiyat]

    foreach ($plan as $minute => $count) {
        $interval = 60.0 / max(1, $count);
        $minuteStart = $loadStarted + $minute * 60;
        for ($i = 0; $i < $count; $i++) {
            $due = $minuteStart + $i * $interval;
            while (($now = microtime(true)) < $due) {
                if ($now >= $nextSample) {
                    $samples[] = [
                        't' => round($now - $loadStarted, 1),
                        'queues' => queueDepths(),
                        'wait' => app(WaitTimeCalculator::class)->calculate(),
                        'lock_waits' => (int) DB::selectOne('SELECT count(*) AS c FROM pg_locks WHERE NOT granted')->c,
                        'outbox_pending' => TenantContext::runAsSystem(fn () => DB::table('outbox_events')->where('tenant_id', $tenantId)->whereNull('consumed_at')->count()),
                    ];
                    $nextSample = $now + 2.0;
                }
                usleep((int) min(50_000, max(1000, ($due - $now) * 1_000_000)));
            }

            if ($minute === $burstMinute && $priceQueue !== []) {
                $batch = array_splice($priceQueue, 0, $pricePerOrder);
                TenantContext::runFor($tenantId, function () use ($batch, &$priceSetAt): void {
                    foreach (Variant::query()->whereIn('sku', $batch)->get() as $variant) {
                        $price = round(random_int(5000, 20000) / 100, 2);
                        $priceSetAt[$variant->sku] = [microtime(true), number_format($price, 2, '.', '')];
                        app(SetVariantPrice::class)->run($variant, $price);
                    }
                });
            }

            $orderNo++;
            $externalId = sprintf('BF-ORD-%06d', $orderNo);
            $lines = [];
            $lineCount = random_int(1, 3);
            for ($l = 0; $l < $lineCount; $l++) {
                $sku = random_int(1, 100) <= 30 ? $skus[random_int(0, $hot - 1)] : $skus[random_int($hot, count($skus) - 1)];
                $qty = random_int(1, 2);
                $lines[$sku] = ($lines[$sku] ?? 0) + $qty;
            }
            $through = [];
            foreach ($lines as $sku => $qty) {
                $soldThrough[$sku] = ($soldThrough[$sku] ?? 0) + $qty;
                $through[$sku] = $soldThrough[$sku];
            }

            $connectionId = $connections[$orderNo % count($connections)];
            $payload = [
                'type' => 'created',
                'external_order_id' => $externalId,
                'external_number' => $externalId,
                'status' => 'created',
                'currency' => 'TRY',
                'lines' => array_values(array_map(
                    static fn (string $sku, int $qty): array => ['external_line_id' => "{$externalId}-{$sku}", 'sku' => $sku, 'quantity' => $qty, 'unit_price' => '100.00', 'line_total' => (string) (100 * $qty)],
                    array_keys($lines),
                    $lines,
                )),
            ];

            // Pazaryeri satışı ANINDA kendi stoğundan düşer — webhook'tan önce.
            foreach ($lines as $sku => $qty) {
                Redis::hincrby(LoadTestChannelAdapter::REMOTE_KEY, "{$connectionId}|{$sku}", -$qty);
            }

            $t0 = microtime(true);
            try {
                $response = Http::timeout(10)
                    ->withHeaders(['x-loadtest-event-id' => $externalId])
                    ->post("{$webhookBase}/webhooks/{$connectionId}", $payload);
                if ($response->status() !== 202) {
                    $postErrors[] = $response->status();
                }
            } catch (Throwable $e) {
                $postErrors[] = $e::class;
            }
            $postLatencies[] = (microtime(true) - $t0) * 1000;
            $sent[$externalId] = [$t0, $through, $connectionId];
        }
        $say('  dakika '.($minute + 1)."/{$minutes}: {$count} sipariş gönderildi");
    }
    $loadSeconds = microtime(true) - $loadStarted;

    $say('Yük bitti, kuyruğun erimesi bekleniyor…');
    $drainStarted = microtime(true);
    $drain = $waitDrain(DRAIN_TIMEOUT_S);
    $say('  eridi ('.round($drain, 1).' sn)');

    // ── ölçüm ───────────────────────────────────────────────────────────
    $say('Ölçüm…');

    // Kanalın aldığı her değer: bağlantı|sku → [[ts, qty], …] (zamana göre).
    $pushes = [];
    $requests = [];
    foreach (Redis::lrange(LoadTestChannelAdapter::PUSHES_KEY, 0, -1) as $row) {
        [$connectionId, $sku, $qty, $ts, $request] = explode('|', $row);
        $pushes["{$connectionId}|{$sku}"][] = [(float) $ts, (int) $qty];
        $requests[$request] = true;
    }
    foreach ($pushes as &$list) {
        usort($list, static fn ($a, $b) => $a[0] <=> $b[0]);
    }
    unset($list);

    // UÇTAN UCA: sipariş gönderildi → bu siparişin düşüşünü İÇEREN değer
    // (qty ≤ açılış − o siparişe kadar satılan) DİĞER kanalların hepsinde
    // görüldü. Siparişin geldiği kanal sayılmaz: pazaryeri kendisi düşer.
    // Yaklaşıktır: sonradan gönderilip önce işlenen sipariş aynı koşulu
    // karşılayabilir; yalnız aşağı doğru yanılır, belgede yazılı.
    $endToEnd = [];
    $neverArrived = 0;
    foreach ($sent as [$t0, $through, $origin]) {
        $worst = 0.0;
        foreach ($through as $sku => $soldUpTo) {
            $expectedAtMost = OPENING_STOCK - $soldUpTo;
            foreach ($connections as $connectionId) {
                if ($connectionId === $origin) {
                    continue;
                }
                $hit = null;
                foreach ($pushes["{$connectionId}|{$sku}"] ?? [] as [$ts, $qty]) {
                    if ($ts >= $t0 && $qty <= $expectedAtMost) {
                        $hit = $ts;
                        break;
                    }
                }
                if ($hit === null) {
                    $neverArrived++;

                    continue 3;
                }
                $worst = max($worst, $hit - $t0);
            }
        }
        $endToEnd[] = $worst;
    }

    // Son durum: kanalın elindeki değer (gönderim + kendi düşüşleri) =
    // yerel kullanılabilir stok mu? Fark = yük bittiğinde kanalda yanlış
    // görünen satır; mutabakat turu (§10) onu sonradan onarır.
    $local = TenantContext::runAsSystem(fn () => DB::table('inventory_levels as il')
        ->join('variants as v', 'v.id', '=', 'il.variant_id')
        ->where('il.tenant_id', $tenantId)
        ->pluck('il.available', 'v.sku')
        ->all());
    $remote = Redis::hgetall(LoadTestChannelAdapter::REMOTE_KEY);
    $wrongAtEnd = 0;
    $overselling = 0;
    $wrongExamples = [];
    foreach (array_keys($soldThrough) as $sku) {
        foreach ($connections as $connectionId) {
            $diff = (int) ($remote["{$connectionId}|{$sku}"] ?? 0) - (int) $local[$sku];
            if ($diff !== 0) {
                $wrongAtEnd++;
                $overselling += $diff > 0 ? 1 : 0;
                if (count($wrongExamples) < 5) {
                    $wrongExamples[] = "{$sku}: kanal ".($remote["{$connectionId}|{$sku}"] ?? '?').' · yerel '.$local[$sku];
                }
            }
        }
    }

    // FİYAT: değişen her varyantın YENİ fiyatı bütün listing'lerinde mi,
    // ve ne kadar sürede? Kanalda son kalan değer yerel fiyattan farklıysa
    // eski paket yenisini ezmiştir (kilidin önlemesi gereken yarış).
    $priceLatency = [];
    $priceWrongAtEnd = 0;
    $priceNeverArrived = 0;
    if ($priceSetAt !== []) {
        $listingsBySku = TenantContext::runAsSystem(fn () => DB::table('listings as l')
            ->join('variants as v', 'v.id', '=', 'l.variant_id')
            ->where('l.tenant_id', $tenantId)
            ->whereIn('v.sku', array_keys($priceSetAt))
            ->get(['l.id', 'v.sku', 'v.price']));
        $pricePushes = [];
        foreach (Redis::lrange(LoadTestChannelAdapter::PRICE_PUSHES_KEY, 0, -1) as $row) {
            [$listingId, $price, $ts] = explode('|', $row);
            $pricePushes[$listingId][] = [(float) $ts, $price];
        }
        $remotePrice = Redis::hgetall(LoadTestChannelAdapter::REMOTE_PRICE_KEY);
        $worstBySku = [];
        foreach ($listingsBySku as $row) {
            [$t0, $price] = $priceSetAt[$row->sku];
            if (number_format((float) ($remotePrice[$row->id] ?? -1), 2, '.', '') !== number_format((float) $row->price, 2, '.', '')) {
                $priceWrongAtEnd++;
            }
            $hit = null;
            foreach ($pricePushes[$row->id] ?? [] as [$ts, $p]) {
                if ($ts >= $t0 && number_format((float) $p, 2, '.', '') === $price) {
                    $hit = $ts;
                    break;
                }
            }
            if ($hit === null) {
                $priceNeverArrived++;
                $worstBySku[$row->sku] = INF;

                continue;
            }
            $worstBySku[$row->sku] = max($worstBySku[$row->sku] ?? 0.0, $hit - $t0);
        }
        $priceLatency = array_values(array_filter($worstBySku, 'is_finite'));
    }

    $orders = TenantContext::runAsSystem(fn () => DB::table('orders')->where('tenant_id', $tenantId)->count());
    $saleMovements = TenantContext::runAsSystem(fn () => DB::table('inventory_movements')->where('tenant_id', $tenantId)->where('type', 'SALE')->count());
    $expectedSold = array_sum($soldThrough);
    $actualSold = (int) TenantContext::runAsSystem(fn () => DB::table('inventory_movements')->where('tenant_id', $tenantId)->where('type', 'SALE')->sum(DB::raw('-on_hand_delta')));
    $integrity = (int) TenantContext::runAsSystem(fn () => DB::selectOne(<<<'SQL'
        SELECT COUNT(*) AS c FROM inventory_levels il
          JOIN (SELECT warehouse_id, variant_id, SUM(on_hand_delta) t FROM inventory_movements WHERE tenant_id = ? GROUP BY 1, 2) m
            ON m.warehouse_id = il.warehouse_id AND m.variant_id = il.variant_id
         WHERE il.on_hand <> m.t
    SQL, [$tenantId])->c);
    $inboxStatus = TenantContext::runAsSystem(fn () => DB::table('inbox_messages')->where('tenant_id', $tenantId)->selectRaw('status, count(*) AS c')->groupBy('status')->pluck('c', 'status')->all());
    $opStatus = TenantContext::runAsSystem(fn () => DB::table('sync_operations')->where('tenant_id', $tenantId)->selectRaw('status, count(*) AS c')->groupBy('status')->pluck('c', 'status')->all());
    $attemptFailures = TenantContext::runAsSystem(fn () => DB::table('sync_attempts')->where('tenant_id', $tenantId)->whereNotIn('outcome', ['success', 'pending'])->count());
    $outboxConsumed = TenantContext::runAsSystem(fn () => DB::table('outbox_events')->where('tenant_id', $tenantId)->whereNotNull('consumed_at')->count());
    $pushRequests = array_sum(array_map('count', $pushes));

    $maxQueue = [];
    $maxWait = 0;
    $maxLockWaits = 0;
    $maxOutbox = 0;
    foreach ($samples as $s) {
        foreach ($s['queues'] as $q => $n) {
            $maxQueue[$q] = max($maxQueue[$q] ?? 0, $n);
        }
        $maxWait = max($maxWait, ...array_values($s['wait'] ?: [0]));
        $maxLockWaits = max($maxLockWaits, $s['lock_waits']);
        $maxOutbox = max($maxOutbox, $s['outbox_pending']);
    }

    $result = [
        'senaryo' => [
            'dakika' => $minutes, 'plan_dakika_basi' => $plan, 'siparis' => count($sent), 'satir_adedi' => $expectedSold,
            'kanal' => $channelCount, 'varyant' => $variantCount, 'listing' => $channelCount * $variantCount,
            'kanal_gecikmesi_ms' => $latencyMs, 'sicak_sku' => HOT_SKUS,
            'horizon' => 'production havuzu (critical 6–8 işçi)',
        ],
        'webhook' => [
            'p50_ms' => pct($postLatencies, 50), 'p95_ms' => pct($postLatencies, 95), 'max_ms' => pct($postLatencies, 100),
            'hata' => count($postErrors), 'hata_ornek' => array_slice(array_count_values(array_map('strval', $postErrors)), 0, 5, true),
        ],
        'uctan_uca_stok_bes_kanalda_s' => [
            'p50' => pct($endToEnd, 50), 'p95' => pct($endToEnd, 95), 'p99' => pct($endToEnd, 99), 'max' => pct($endToEnd, 100),
            'kanala_hic_ulasmayan_siparis' => $neverArrived,
        ],
        'kuyruk' => [
            'yuk_suresi_s' => round($loadSeconds, 1), 'yuk_sonrasi_erime_s' => round($drain, 1),
            'tepe_derinlik' => $maxQueue, 'tepe_bekleme_s' => $maxWait, 'tepe_outbox_bekleyen' => $maxOutbox,
        ],
        'postgres' => ['deadlock' => deadlocks() - $deadlocksBefore, 'tepe_kilit_bekleyen' => $maxLockWaits],
        'dogruluk' => [
            'siparis_yazilan' => $orders, 'satis_hareketi' => $saleMovements,
            'beklenen_satilan_adet' => $expectedSold, 'dusulen_adet' => $actualSold,
            'ledger_projeksiyon_uyumsuz' => $integrity,
            'sonda_kanalda_yanlis_stok' => $wrongAtEnd,
            'sonda_kanalda_fazla_gorunen' => $overselling,
            'yanlis_ornek' => $wrongExamples,
        ],
        'hatalar' => [
            'failed_jobs_artis' => (int) DB::table('failed_jobs')->count() - $failedJobsBefore,
            'inbox_durum' => $inboxStatus, 'sync_operation_durum' => $opStatus, 'basarisiz_deneme' => $attemptFailures,
        ],
        'verim' => [
            'outbox_tuketilen' => $outboxConsumed, 'kanala_giden_kalem' => $pushRequests,
            'kanala_istek_sayisi' => count($requests),
            'istek_basina_kalem' => $requests === [] ? null : round($pushRequests / count($requests), 2),
        ],
        'fiyat' => [
            'degisen_varyant' => count($priceSetAt),
            'uctan_uca_tum_kanallarda_s' => ['p50' => pct($priceLatency, 50), 'p95' => pct($priceLatency, 95), 'max' => pct($priceLatency, 100)],
            'hic_ulasmayan_listing' => $priceNeverArrived,
            'sonda_kanalda_yanlis_fiyat' => $priceWrongAtEnd,
            'kanala_giden_fiyat_kalemi' => (int) Redis::llen(LoadTestChannelAdapter::PRICE_PUSHES_KEY),
        ],
        'ops_watch_bulgu' => app(OpsWatch::class)->findings(),
    ];
} finally {
    $say(isset($opt['keep']) ? "Temizlik ATLANDI (--keep): kiracı {$tenantId}" : 'Temizlik…');
    if ($tenantId !== null && ! isset($opt['keep'])) {
        TenantContext::runAsSystem(function () use ($tenantId): void {
            foreach (['sync_attempts', 'sync_operations', 'listing_sync_states', 'listings', 'outbox_events', 'inbox_messages'] as $table) {
                DB::table($table)->where('tenant_id', $tenantId)->delete();
            }
            DB::table('tenants')->where('id', $tenantId)->delete();
        });
    }
    if ($ownerId !== null) {
        DB::table('users')->where('id', $ownerId)->delete();
    }
    DB::table('channel_types')->where('code', 'loadtest')->whereNotExists(
        fn ($q) => $q->from('channel_connections')->whereColumn('channel_connections.channel_type_code', 'channel_types.code')
    )->delete();
    if (! isset($opt['keep'])) {
        Redis::del(LoadTestChannelAdapter::PUSHES_KEY, LoadTestChannelAdapter::LATENCY_KEY, LoadTestChannelAdapter::REMOTE_KEY, LoadTestChannelAdapter::PRICE_PUSHES_KEY, LoadTestChannelAdapter::REMOTE_PRICE_KEY);
    }
}

$json = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
@mkdir(storage_path('app/loadtest'), 0775, true);
file_put_contents(storage_path('app/loadtest/black-friday-'.date('Ymd-His').'.json'), $json);
echo $json, "\n";
