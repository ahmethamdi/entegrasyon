<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Domain\Catalog\Models\Product;
use App\Domain\Identity\Models\Tenant;
use App\Providers\QueueServiceProvider;
use App\Support\Tenancy\Exceptions\MissingTenantContextException;
use App\Support\Tenancy\TenantAwareJob;
use App\Support\Tenancy\TenantContext;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\Looping;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Test M — worker yaşam döngüsünde kiracı bağlamı sızıntısı.
 *
 * Mimari Karar Dokümanı v2.2 · §11 · P0 güvenlik değişmezi, Karar 21.
 *
 * Senaryo: Job A kiracı A bağlamında çalışıp İSTİSNA fırlatır (finally'ye
 * ulaşamayan bir yol taklit edilir). Aynı worker sürecinde Job B kiracı B
 * bağlamında çalışır ve hiçbir koşulda A'nın verisini görmemelidir.
 */
final class QueueTenantContextLeakTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function context_does_not_leak_between_jobs_in_same_worker(): void
    {
        [$tenantA, $tenantB] = $this->makeTwoTenantsWithProducts();

        // --- Job A: kiracı A bağlamında çalışıp istisna fırlatıyor ---
        try {
            (new LeakTestFailingJob($tenantA->id))->handle();
        } catch (RuntimeException) {
            // yutuldu; worker bir sonraki işe geçiyor
        }

        // --- Worker döngüsü: QueueServiceProvider kancası ---
        $this->fireWorkerLoopHook();

        // KRİTİK KONTROL: bir sonraki iş ALINMADAN ÖNCE bağlam temiz olmalı.
        //
        // Bu kontrol testin özüdür. TenantAwareJob kendi bağlamını kurduğu
        // için, doğrudan Job B'nin gördüğü veriye bakmak sızıntıyı maskeler:
        // B zaten kendi kiracısını set eder ve doğru sonucu görür. Gerçek
        // risk, bağlamı KENDİ kurmayan bir iş (üçüncü taraf paket işi,
        // closure job, elle yazılmış legacy job) çalıştığında ortaya çıkar —
        // o iş önceki kiracının verisini görür.
        $this->assertFalse(
            TenantContext::hasTenant(),
            'Job A sonrası bağlam temizlenmedi — bağlamını kendi kurmayan '.
            'bir sonraki iş kiracı A verisini görürdü.'
        );

        // Bağlamını kendi kurmayan bir işi taklit et: worker döngüsünden
        // sonra doğrudan sorgu yapan kod fail-closed davranmalı.
        $this->assertThrows(
            fn () => Product::count(),
            MissingTenantContextException::class,
        );

        // --- Job B: kiracı B, kendi bağlamıyla ---
        $job = new LeakTestCollectingJob($tenantB->id);
        $job->handle();

        $this->assertSame(
            [$tenantB->id],
            array_values($job::$seenTenantIds),
            'Job B, Job A kiracısının verisini gördü — bağlam sızdı.'
        );
        $this->assertSame(2, $job::$seenCount);
    }

    #[Test]
    public function context_is_cleared_before_each_job_even_without_finally(): void
    {
        $tenant = Tenant::factory()->create();

        // Bağlamı elle kirlet — bir önceki işin bıraktığını taklit eder.
        TenantContext::set($tenant->id);
        $this->assertTrue(TenantContext::hasTenant());

        $this->fireWorkerLoopHook();

        $this->assertFalse(
            TenantContext::hasTenant(),
            'Queue::looping kancası bağlamı temizlemedi.'
        );
    }

    #[Test]
    public function job_lifecycle_events_clear_context(): void
    {
        // JobProcessing / JobProcessed / JobFailed dinleyicileri ikinci
        // savunma hattıdır. Bunları izole biçimde doğruluyoruz: gerçek olay
        // nesnesi bir Job örneği ister ve onu sahtelemek framework sürümü
        // değiştikçe kırılgan olur. Bunun yerine kendi provider'ımızın
        // kaydettiği dinleyicileri temiz bir dağıtıcı üzerinde çalıştırıyoruz.
        $dispatcher = new Dispatcher($this->app);

        $app = clone $this->app;
        $app->instance('events', $dispatcher);

        (new QueueServiceProvider($app))->boot();

        foreach ([JobProcessing::class, JobProcessed::class, JobFailed::class] as $event) {
            $listeners = $dispatcher->getListeners($event);

            $this->assertNotEmpty(
                $listeners,
                "{$event} için kiracı temizleme dinleyicisi kayıtlı değil."
            );

            $tenant = Tenant::factory()->create();
            TenantContext::set($tenant->id);
            $this->assertTrue(TenantContext::hasTenant());

            // Dinleyicimiz olay nesnesini kullanmaz; yalnızca temizlik yapar.
            foreach ($listeners as $listener) {
                $listener($event, []);
            }

            $this->assertFalse(
                TenantContext::hasTenant(),
                "{$event} sonrası bağlam temizlenmedi."
            );
        }
    }

    #[Test]
    public function tenant_aware_job_clears_context_on_success(): void
    {
        $tenant = Tenant::factory()->create();

        (new LeakTestCollectingJob($tenant->id))->handle();

        $this->assertFalse(TenantContext::hasTenant());
    }

    #[Test]
    public function tenant_aware_job_clears_context_on_failure(): void
    {
        $tenant = Tenant::factory()->create();

        try {
            (new LeakTestFailingJob($tenant->id))->handle();
        } catch (RuntimeException) {
            // beklenen
        }

        // TenantAwareJob::handle() finally bloğu bunu garanti eder.
        $this->assertFalse(TenantContext::hasTenant());
    }

    /** @return array{0: Tenant, 1: Tenant} */
    private function makeTwoTenantsWithProducts(): array
    {
        $a = Tenant::factory()->create();
        $b = Tenant::factory()->create();

        $this->asTenant($a, fn () => Product::factory()->count(3)->create());
        $this->asTenant($b, fn () => Product::factory()->count(2)->create());

        return [$a, $b];
    }

    /**
     * QueueServiceProvider'ın Queue::looping kancasını tetikler.
     *
     * Laravel 12'de Queue::looping(), Illuminate\Queue\Events\Looping
     * olayına dinleyici bağlar. Gerçek worker döngüsünü taklit etmek için
     * olayı olduğu gibi yayımlıyoruz.
     */
    private function fireWorkerLoopHook(): void
    {
        $this->app['events']->dispatch(new Looping('redis', 'default'));
    }
}

/** Kiracı A bağlamında çalışıp istisna fırlatan iş. */
final class LeakTestFailingJob extends TenantAwareJob
{
    protected function handleForTenant(): void
    {
        Product::count();                       // bağlam gerçekten kuruldu

        throw new RuntimeException('iş başarısız');
    }
}

/** Gördüğü kiracıları kaydeden iş. */
final class LeakTestCollectingJob extends TenantAwareJob
{
    /** @var list<string> */
    public static array $seenTenantIds = [];

    public static int $seenCount = 0;

    protected function handleForTenant(): void
    {
        $products = Product::all();

        self::$seenTenantIds = $products->pluck('tenant_id')->unique()->values()->all();
        self::$seenCount = $products->count();
    }
}
