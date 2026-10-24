<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Support\Tenancy\BelongsToTenant;
use App\Support\Tenancy\Exceptions\MissingTenantContextException;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use Tests\TestCase;

/**
 * S13 · KİRACI İZOLASYONU SÖZLEŞMESİ — elle liste YOK, kod ve şema taranır.
 *
 * 1. `tenant_id` kolonu olan HER tablo ya `BelongsToTenant` kullanan bir
 *    modele aittir ya da aşağıdaki gerekçeli istisna listesindedir. Yeni bir
 *    tablo trait'siz eklenirse test adıyla kırılır — sızıntı sessiz kalmaz.
 * 2. `BelongsToTenant` kullanan HER model: bağlam varken sorgusu kiracıya
 *    bağlanır (SQL + bağ değeri), bağlam yokken istisna fırlatır (fail-closed).
 *
 * Tek modelin gerçek verili çapraz okuma senaryosu `TenantIsolationTest`'te;
 * bu test aynı garantinin BÜTÜN modellere yayıldığını sınar.
 */
final class TenantScopeContractTest extends TestCase
{
    use RefreshDatabase;

    /**
     * `tenant_id` taşıyan ama `BelongsToTenant` modeli OLMAYAN tablolar.
     * Buraya eklemek bir karardır: gerekçe yaz.
     *
     * @var array<string, string>
     */
    private const UNSCOPED_TABLES = [
        'tenant_users' => 'üyelik tablosu; kiracı seçiminden ÖNCE okunur (EstablishTenantContext), kapsam yapılamaz',
        'alert_deliveries' => 'sistem işi (alerts:dispatch) bütün kiracılar için yazar; satıcıya hiçbir ekranda dönmez',
        'api_calls' => 'kanal HTTP günlüğü, ChannelHttpClient sistem olarak yazar; okuma yalnız admin/metrik',
        'price_campaign_variants' => 'ara tablo; yalnız kapsamlı PriceCampaign kimliğiyle okunur (ActiveCampaigns, PushCampaignPrices)',
        'price_campaign_connections' => 'ara tablo; yalnız kapsamlı PriceCampaign kimliğiyle okunur',
        'listing_images' => 'kodda kullanılmıyor (2026-08 şemasından kalan); kullanılmaya başlarsa model + trait şart',
    ];

    #[Test]
    public function every_tenant_table_is_scoped_or_explicitly_excused(): void
    {
        $scopedTables = array_keys($this->scopedModels());
        $tenantTables = array_map(
            static fn ($row) => (string) $row->table_name,
            DB::select("SELECT table_name FROM information_schema.columns WHERE table_schema = 'public' AND column_name = 'tenant_id' ORDER BY table_name"),
        );

        $unscoped = array_values(array_diff($tenantTables, $scopedTables, array_keys(self::UNSCOPED_TABLES)));
        $this->assertSame([], $unscoped, 'tenant_id kolonu var ama BelongsToTenant modeli yok (ve istisna listesinde değil): '.implode(', ', $unscoped));

        $stale = array_values(array_diff(array_keys(self::UNSCOPED_TABLES), $tenantTables));
        $this->assertSame([], $stale, 'istisna listesinde artık var olmayan tablo: '.implode(', ', $stale));

        $nowScoped = array_values(array_intersect(array_keys(self::UNSCOPED_TABLES), $scopedTables));
        $this->assertSame([], $nowScoped, 'artık kapsamlı modeli olan tablo istisna listesinden çıkarılmalı: '.implode(', ', $nowScoped));
    }

    #[Test]
    public function every_scoped_model_binds_queries_to_the_current_tenant(): void
    {
        $models = $this->scopedModels();
        $this->assertGreaterThan(50, count($models), 'model taraması çalışmıyor (beklenenden az model bulundu)');

        $tenantId = (string) Str::uuid();

        foreach ($models as $table => $class) {
            TenantContext::runFor($tenantId, function () use ($class, $table, $tenantId): void {
                $query = $class::query()->toBase();
                $wheres = collect($query->wheres);

                $scope = $wheres->first(fn (array $w) => ($w['column'] ?? null) === "{$table}.tenant_id");
                $this->assertNotNull($scope, "{$class}: sorgu {$table}.tenant_id ile süzülmüyor");
                $this->assertSame($tenantId, $scope['value'] ?? null, "{$class}: süzgeç başka kiracıya bağlı");
                $this->assertSame('=', $scope['operator'] ?? null, "{$class}: süzgeç eşitlik değil");
            });
        }
    }

    #[Test]
    public function every_scoped_model_fails_closed_without_context(): void
    {
        TenantContext::clear();

        foreach ($this->scopedModels() as $class) {
            try {
                $class::query()->toBase();
                $this->fail("{$class}: kiracı bağlamı yokken sorgu kuruldu (sessiz sızıntı)");
            } catch (MissingTenantContextException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /** @return array<string, class-string<Model>> tablo => model (BelongsToTenant kullananlar) */
    private function scopedModels(): array
    {
        $models = [];
        $root = base_path('app');

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $class = 'App\\'.str_replace(['/', '.php'], ['\\', ''], substr($file->getPathname(), strlen($root) + 1));
            if (! class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);
            if ($reflection->isAbstract() || ! $reflection->isSubclassOf(Model::class)) {
                continue;
            }
            if (! in_array(BelongsToTenant::class, class_uses_recursive($class), true)) {
                continue;
            }

            $models[(new $class)->getTable()] = $class;
        }

        ksort($models);

        return $models;
    }
}
