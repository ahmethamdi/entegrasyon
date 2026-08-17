<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Inventory\Models\InventoryLevel;
use App\Domain\Sync\Enums\SyncOperationStatus;
use App\Domain\Sync\Models\ListingSyncState;
use App\Domain\Sync\Models\SyncOperation;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Panel ana ekranı — senkron sağlığı tek bakışta.
 *
 * Mimari Karar Dokümanı v2.2 · §17 · P0 · "Panelde senkron geçmişi ve hata
 * görünürlüğü — destek yükünü belirleyen tek ekran".
 *
 * DÖRT SORUYA CEVAP VERİR:
 *   1. Kanallar ayakta mı?          → bağlantı sağlığı
 *   2. Bekleyen iş var mı?          → kirli satır sayısı
 *   3. Bir şey bozuldu mu?          → hatalı operasyonlar ve kalıcı hatalar
 *   4. Fazla satış var mı?          → negatif available
 *
 * FAZLA SATIŞ AYRI GÖSTERİLİR (§17 · P0): negatif `available` kullanıcıya
 * anlatılmak zorundadır. Eksik miktar gizlenirse satıcı stoğunun neden
 * tutmadığını anlamaz ve sisteme güvenmez.
 *
 * Tüm sorgular kiracı scope'u altında çalışır; bağlamı `tenant` ara katmanı
 * kurar ve istek sonunda bırakır.
 */
final class DashboardController extends Controller
{
    public function __invoke(Request $request): InertiaResponse
    {
        return Inertia::render('Dashboard', [
            'tenant' => $this->tenantSummary($request),
            'connections' => $this->connections(),
            'syncHealth' => $this->syncHealth(),
            'oversold' => $this->oversold(),
            'recentOperations' => $this->recentOperations(),
        ]);
    }

    /** @return array<string, mixed> */
    private function tenantSummary(Request $request): array
    {
        $tenant = $request->attributes->get('tenant');

        return [
            'id' => $tenant?->id,
            'name' => $tenant?->name,
        ];
    }

    /**
     * Bağlantı sağlığı — kanal ayakta mı?
     *
     * @return array<int, array<string, mixed>>
     */
    private function connections(): array
    {
        return ChannelConnection::query()
            ->with('channelType:code,name')
            ->orderBy('channel_type_code')
            ->get()
            ->map(fn (ChannelConnection $c): array => [
                'id' => $c->id,
                'label' => $c->label,
                'channel' => $c->channelType?->name ?? $c->channel_type_code,
                'status' => $c->status,
                'health' => $c->health_status,
                'lastHealthyAt' => $c->last_healthy_at?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * Senkron sağlığı — bekleyen iş ve hata sayıları.
     *
     * `is_dirty` veritabanı tarafından üretilir (`desired > synced`); ayrı
     * bir sayaç tutulmadığı için bu rakam tanım gereği tutarlıdır.
     *
     * @return array<string, int>
     */
    private function syncHealth(): array
    {
        $states = ListingSyncState::query()
            ->selectRaw('count(*) FILTER (WHERE is_dirty) AS dirty')
            ->selectRaw("count(*) FILTER (WHERE status = 'synced') AS synced")
            ->selectRaw("count(*) FILTER (WHERE status = 'error_transient') AS transient")
            ->selectRaw("count(*) FILTER (WHERE status = 'error_permanent') AS permanent")
            ->first();

        $operations = SyncOperation::query()
            ->selectRaw("count(*) FILTER (WHERE status IN ('pending', 'retrying')) AS waiting")
            ->selectRaw("count(*) FILTER (WHERE status = 'dead') AS dead")
            ->first();

        return [
            'dirty' => (int) ($states?->dirty ?? 0),
            'synced' => (int) ($states?->synced ?? 0),
            'errorTransient' => (int) ($states?->transient ?? 0),
            'errorPermanent' => (int) ($states?->permanent ?? 0),
            'waiting' => (int) ($operations?->waiting ?? 0),
            'dead' => (int) ($operations?->dead ?? 0),
        ];
    }

    /**
     * Fazla satılan varyantlar — negatif `available`.
     *
     * NEGATİF DEĞER OLDUĞU GİBİ GÖSTERİLİR, kırpılmaz. Kırpma yalnızca
     * kanala giden yükte meşrudur (`OutboundQuantity`); panelde gizlemek
     * satıcıyı eksik miktardan habersiz bırakırdı.
     *
     * @return array<int, array<string, mixed>>
     */
    private function oversold(): array
    {
        return InventoryLevel::query()
            ->with('variant:id,sku')
            ->where('available', '<', 0)
            ->orderBy('available')
            ->limit(20)
            ->get()
            ->map(fn (InventoryLevel $level): array => [
                'sku' => $level->variant?->sku,
                'available' => $level->available,
                // Eksik miktar açıkça söylenir: "kaç adet açıktasın".
                'shortfall' => abs($level->available),
            ])
            ->all();
    }

    /**
     * Son senkron operasyonları — "ne oldu" sorusunun cevabı.
     *
     * @return array<int, array<string, mixed>>
     */
    private function recentOperations(): array
    {
        return SyncOperation::query()
            ->with('connection.channelType:code,name')
            ->latest('created_at')
            ->limit(15)
            ->get()
            ->map(fn (SyncOperation $op): array => [
                'id' => $op->id,
                'type' => $op->operation_type,
                'channel' => $op->connection?->channelType?->name
                    ?? $op->connection?->channel_type_code,
                'version' => $op->entity_version,
                'status' => $op->status->value,
                'attempts' => $op->attempt_count,
                'errorClass' => $op->last_error_class,
                'createdAt' => $op->created_at?->toIso8601String(),
                'isFailed' => in_array($op->status, [
                    SyncOperationStatus::DEAD,
                    SyncOperationStatus::RETRYING,
                ], true),
            ])
            ->all();
    }
}
