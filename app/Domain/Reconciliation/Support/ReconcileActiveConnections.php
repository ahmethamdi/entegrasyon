<?php

declare(strict_types=1);

namespace App\Domain\Reconciliation\Support;

use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Reconciliation\Actions\ReconcileConnection;
use App\Domain\Reconciliation\Enums\ReconciliationScope;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Tüm kiracıların aktif bağlantılarını sırayla mutabık kılar.
 *
 * Mimari Karar Dokümanı v2.2 · §10 · üç katman, §15 · zamanlanmış işler.
 *
 * MANTIK BURADA, KOMUT İNCE KABUK: `Command::run()` rezerve imzadır ve
 * `Command`'dan türeyen sınıf kendi `run(...)` metodunu tanımlayamaz
 * (`OutboxRelay` / `OutboxRelayCommand` ayrımının aynısı).
 *
 * SİSTEM BAĞLAMI AÇIKÇA ALINIR: tarama TÜM kiracıları görmek zorundadır.
 * Ama her bağlantı KENDİ kiracı bağlamında işlenir — `ReconcileConnection`
 * tenant-scoped sorgular yapar ve sistem bağlamında koşarsa kiracı filtresi
 * hiç uygulanmaz.
 *
 * BİR BAĞLANTININ HATASI DİĞERLERİNİ DURDURMAZ: kanal ölü olabilir, kimlik
 * bilgisi süresi dolmuş olabilir. Tur devam eder ve hata günlüğe yazılır;
 * tek bir bozuk bağlantı tüm kiracıların mutabakatını durdurmamalıdır.
 *
 * YALNIZCA `active` BAĞLANTILAR: sağlıksız bağlantıya istek atmak hem
 * kotayı harcar hem devre kesiciyi gereksiz yere açar.
 */
final class ReconcileActiveConnections
{
    public function __construct(
        private readonly ReconcileConnection $reconcile,
    ) {}

    /**
     * @param  int|null  $budget  null → katmanın kendi bütçesi (§10 tablosu)
     * @return int İşlenen bağlantı sayısı
     */
    public function sweep(
        ReconciliationScope $scope = ReconciliationScope::HOT,
        ?int $budget = null,
    ): int {
        // Bağlantı listesi sistem bağlamında okunur; işlem kiracı
        // bağlamında yapılır.
        $connections = TenantContext::runAsSystem(
            fn () => ChannelConnection::query()
                ->where('status', 'active')
                ->orderBy('tenant_id')
                ->get(['id', 'tenant_id'])
                ->all(),
        );

        $processed = 0;

        foreach ($connections as $connection) {
            try {
                TenantContext::runFor($connection->tenant_id, function () use ($connection, $scope, $budget): void {
                    // Model kiracı bağlamında YENİDEN okunur: yukarıdaki
                    // kopya yalnızca kimlik taşıyor ve adapter kurulumu
                    // bağlantının tüm alanlarına ihtiyaç duyuyor.
                    $fresh = ChannelConnection::query()->find($connection->id);

                    if ($fresh === null) {
                        return;
                    }

                    $this->reconcile->run(
                        connection: $fresh,
                        scope: $scope,
                        budget: $budget,
                    );
                });

                $processed++;
            } catch (Throwable $e) {
                // SESSİZCE YUTULMAZ: bir bağlantının hatası turu durdurmaz
                // ama iz bırakır. Yutulsaydı mutabakat "çalıştı" görünürken
                // hiçbir şey kontrol etmemiş olurdu.
                Log::warning('reconciliation.connection_failed', [
                    'connection' => $connection->id,
                    'tenant' => $connection->tenant_id,
                    'scope' => $scope->value,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $processed;
    }
}
