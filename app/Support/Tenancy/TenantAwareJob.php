<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Kiracı bağlamını yükünden kuran iş temel sınıfı.
 *
 * Mimari Karar Dokümanı v2.2 · §11 · P0 güvenlik değişmezi.
 *
 * Bağlam iş yükünde taşınır ve handle() başında kurulur; bitişte finally ile
 * temizlenir. QueueServiceProvider kancaları ikinci savunma hattıdır —
 * istisna finally'yi atlarsa bile sonraki iş temiz bağlamla başlar.
 *
 * $tenantId READONLY DEĞİLDİR — PHP kısıtı, tercih değil:
 *   `SerializesModels::__unserialize()` özellikleri ALT SINIFIN kapsamından
 *   yeniden atar. PHP, ana sınıfta tanımlı bir readonly özelliğin alt sınıf
 *   kapsamından ilklenmesine izin vermez ve kuyruktan geri okuma
 *   "Cannot initialize readonly property ... from scope ..." ile DÜŞER.
 *   İş kuyruğa yazılır ama bir daha asla çalışmaz.
 *
 *   Testler işi doğrudan kurup handle() çağırdığı için bu gidiş-dönüş
 *   yaşanmaz ve hata yalnızca gerçek worker'da görünür; `JobSerializationTest`
 *   tam olarak bu boşluğu kapatır.
 *
 *   (PHP 8.3 hedefleniyor; `public protected(set)` 8.4 özelliğidir ve
 *   kullanılamaz.)
 */
abstract class TenantAwareJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public string $tenantId;

    public function __construct(string $tenantId)
    {
        $this->tenantId = $tenantId;
    }

    final public function handle(): void
    {
        TenantContext::set($this->tenantId);

        try {
            $this->handleForTenant();
        } finally {
            TenantContext::clear();
        }
    }

    abstract protected function handleForTenant(): void;
}
