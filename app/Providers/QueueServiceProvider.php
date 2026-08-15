<?php

declare(strict_types=1);

namespace App\Providers;

use App\Support\Tenancy\TenantContext;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;

/**
 * Kuyruk worker'larında kiracı bağlamı izolasyonu.
 *
 * Mimari Karar Dokümanı v2.2 · §11 · P0 güvenlik değişmezi, Karar 21.
 *
 * Worker süreçleri uzun ömürlüdür. Bir iş istisna fırlatıp kendi finally
 * bloğuna ulaşamazsa bağlam sonraki işe sızar ve o iş başka kiracının
 * verisini görür — hiçbir günlükte iz bırakmayan çapraz kiracı sızıntısı.
 *
 * Bu yüzden temizlik manuel disipline bırakılmaz; framework kancalarıyla
 * dört noktada zorlanır:
 *   Queue::looping()  → her iş ALINMADAN önce
 *   JobProcessing     → iş çalıştırılmadan hemen önce
 *   JobProcessed      → başarılı bitişten sonra
 *   JobFailed         → başarısız bitişten sonra
 */
class QueueServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Worker döngüsü: bir sonraki iş kuyruktan alınmadan önce.
        // Önceki iş nasıl biterse bitsin bağlam burada sıfırlanır.
        Queue::looping(static function (): void {
            TenantContext::clear();
        });

        // İş çalıştırılmadan hemen önce — ikinci savunma hattı.
        $this->app['events']->listen(JobProcessing::class, static function (): void {
            TenantContext::clear();
        });

        $this->app['events']->listen(JobProcessed::class, static function (): void {
            TenantContext::clear();
        });

        $this->app['events']->listen(JobFailed::class, static function (): void {
            TenantContext::clear();
        });
    }
}
