<?php

declare(strict_types=1);

namespace Tests\Feature\Returns;

use App\Domain\Catalog\Models\Variant;
use App\Domain\Channels\Enums\OutboundWrite;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Inventory\Actions\ApplyMovement;
use App\Domain\Inventory\Actions\LockInventoryRows;
use App\Domain\Inventory\Enums\MovementType;
use App\Domain\Inventory\Support\MovementKey;
use App\Domain\Orders\Actions\IngestChannelOrder;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Support\IncomingOrder;
use App\Domain\Orders\Support\IncomingOrderLine;
use App\Domain\Questions\Actions\IngestQuestions;
use App\Domain\Returns\Actions\IngestReturns;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\Uid\UuidV7;
use Tests\Support\Finance\BuildsFinanceScenario;

/**
 * S4 testleri: Trendyol iade talebi (claims) ve soru (qna) uçlarının sahte
 * cevapları. Gövdeler belgedeki biçimdir (getClaims, approveClaimLineItems,
 * createClaimIssue, claim audit, questions filter/by id/answers — 13 Eki
 * 2026 okundu). Canlı hesapta talep YOKTU; soru alanları canlı yanıtın
 * anahtar kümesiyle aynıdır (metinler uydurma, kişisel veri yok).
 */
trait BuildsReturnScenario
{
    use BuildsFinanceScenario;

    /** claimId → claim gövdesi. */
    protected array $claims = [];

    /** claimItemId → denetim kaydı durumları. */
    protected array $audits = [];

    /** questionId → soru gövdesi. */
    protected array $questions = [];

    /** @var list<string> */
    protected array $sent = [];

    protected ?\Closure $onApprove = null;

    protected ?\Closure $onReject = null;

    protected ?\Closure $onAnswer = null;

    protected ?\Closure $onClaimList = null;

    /** Trendyol dışı istekler (HB) için cevap. */
    protected ?\Closure $onOther = null;

    protected int $answerId = 777;

    protected function setUpReturns(): void
    {
        $this->travelTo('2026-10-13 10:00:00');
        Redis::connection()->flushdb();
        $this->fakeTrendyolCustomerService();
    }

    protected function fakeTrendyolCustomerService(): void
    {
        Http::fake(function (Request $request) {
            $url = $request->url();
            $path = (string) parse_url($url, PHP_URL_PATH);
            parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
            $method = $request->method();

            if ($method !== 'GET') {
                $this->sent[] = "{$method} {$path}";
            }

            if (preg_match('#/order/sellers/\d+/claims/items/([^/]+)/audit$#', $path, $m)) {
                return Http::response(array_map(static fn (string $s): array => ['newStatus' => $s], $this->audits[$m[1]] ?? []));
            }

            if (preg_match('#/order/sellers/\d+/claims/([^/]+)/items/approve$#', $path, $m) && $method === 'PUT') {
                $answer = $this->onApprove instanceof \Closure ? ($this->onApprove)($m[1], $request) : null;

                if ($answer !== null) {
                    return $answer;
                }

                $this->setClaimItems($m[1], (array) $request['claimLineItemIdList'], 'Accepted');

                return Http::response('', 200);
            }

            if (preg_match('#/order/sellers/\d+/claims/([^/]+)/issue$#', $path, $m) && $method === 'POST') {
                $answer = $this->onReject instanceof \Closure ? ($this->onReject)($m[1], $q) : null;

                if ($answer !== null) {
                    return $answer;
                }

                $this->setClaimItems($m[1], [(string) $q['claimItemIdList']], 'Unresolved');

                return Http::response('', 200);
            }

            if (preg_match('#/order/sellers/\d+/claims$#', $path)) {
                if ($this->onClaimList instanceof \Closure && ($answer = ($this->onClaimList)($q)) !== null) {
                    return $answer;
                }

                $rows = isset($q['claimIds'])
                    ? array_values(array_filter($this->claims, static fn (array $c): bool => $c['id'] === $q['claimIds']))
                    : array_values($this->claims);

                return Http::response(['totalElements' => count($rows), 'totalPages' => 1, 'page' => 0, 'size' => 50, 'content' => $rows]);
            }

            if (preg_match('#/qna/sellers/\d+/questions/filter$#', $path)) {
                $rows = array_values($this->questions);

                return Http::response(['content' => $rows, 'page' => 0, 'size' => 50, 'totalElements' => count($rows), 'totalPages' => 1]);
            }

            if (preg_match('#/qna/sellers/\d+/questions/([^/]+)/answers$#', $path, $m) && $method === 'POST') {
                $answer = $this->onAnswer instanceof \Closure ? ($this->onAnswer)($m[1], $request) : null;

                if ($answer !== null) {
                    return $answer;
                }

                $this->questions[$m[1]]['status'] = 'WAITING_FOR_APPROVE';

                return Http::response(['answerId' => $this->answerId]);
            }

            if (preg_match('#/qna/sellers/\d+/questions/([^/]+)$#', $path, $m)) {
                return isset($this->questions[$m[1]]) ? Http::response($this->questions[$m[1]]) : Http::response(['error' => 'yok'], 404);
            }

            if ($this->onOther instanceof \Closure) {
                return ($this->onOther)($request);
            }

            return Http::response(['message' => 'beklenmeyen istek '.$url], 599);
        });
    }

    /** @param  list<string>  $itemIds */
    protected function setClaimItems(string $claimId, array $itemIds, string $status): void
    {
        foreach ($this->claims[$claimId]['items'] as $i => $item) {
            foreach ($item['claimItems'] as $j => $claimItem) {
                if (in_array($claimItem['id'], $itemIds, true)) {
                    $this->claims[$claimId]['items'][$i]['claimItems'][$j]['claimItemStatus']['name'] = $status;
                }
            }
        }
    }

    /**
     * Belgedeki biçimde talep. `$items`: [kalem kimliği, sipariş satırı, sku, durum].
     *
     * @param  list<array{0: string, 1: string, 2: string, 3: string}>  $items
     */
    protected function claim(string $claimId, string $package, array $items, ?string $orderNumber = null): array
    {
        $byLine = [];

        foreach ($items as [$itemId, $lineId, $sku, $status]) {
            $byLine[$lineId]['orderLine'] = ['id' => (int) $lineId, 'productName' => 'Tişört', 'barcode' => '869'.$lineId, 'merchantSku' => $sku, 'price' => 100.0];
            $byLine[$lineId]['claimItems'][] = [
                'id' => $itemId,
                'orderLineItemId' => random_int(1000, 9999),
                'customerClaimItemReason' => ['name' => 'Beden uymama', 'externalReasonId' => 23, 'code' => 'UNFIT'],
                'trendyolClaimItemReason' => ['name' => 'Beden uymama', 'externalReasonId' => 23, 'code' => 'UNFIT'],
                'claimItemStatus' => ['name' => $status],
                'note' => '',
                'customerNote' => 'Küçük geldi',
                'resolved' => false,
                'autoAccepted' => false,
                'acceptedBySeller' => false,
                'acceptDetail' => '',
            ];
        }

        return $this->claims[$claimId] = [
            'id' => $claimId,
            'claimId' => $claimId,
            'channelId' => 1,
            'orderNumber' => $orderNumber ?? 'N-'.$package,
            'orderDate' => 1791000000000,
            'claimDate' => 1791800000000,
            'orderShipmentPackageId' => 99000 + (int) $package,
            'orderOutboundPackageId' => (int) $package,
            'items' => array_values($byLine),
            'lastModifiedDate' => 1791850000000,
        ];
    }

    protected function question(string $id, string $status = 'WAITING_FOR_ANSWER', array $overrides = []): array
    {
        return $this->questions[$id] = [
            'id' => (int) $id,
            'text' => 'Bu ürün pamuklu mu?',
            'status' => $status,
            'creationDate' => 1791800000000,
            'customerId' => 1,
            'userName' => '',
            'showUserName' => false,
            'public' => true,
            'productName' => 'Tişört',
            'productMainId' => 'TS-ANA',
            'imageUrl' => 'https://cdn.example/x.jpg',
            'webUrl' => 'https://www.trendyol.com/x',
            ...$overrides,
        ];
    }

    /**
     * Ledger üzerinden stoklu varyant + o varyantı satan Trendyol siparişi
     * (stok GERÇEKTEN düşülür — `IngestChannelOrder`).
     *
     * @param  list<array{line: string, sku: string, qty: int, variant?: Variant}>  $lines
     * @return array{0: Order, 1: Variant, 2: string}
     */
    protected function soldOrder(Tenant $tenant, ChannelConnection $connection, string $package, array $lines, int $stock = 10): array
    {
        $warehouse = (string) TenantContext::runAsSystem(fn () => DB::table('warehouses')->where('tenant_id', $tenant->id)->where('is_default', true)->value('id'));
        $variant = $lines[0]['variant'] ?? TenantContext::runFor($tenant->id, fn () => Variant::factory()->create(['tenant_id' => $tenant->id, 'sku' => $lines[0]['sku']]));

        if ($stock > 0) {
            TenantContext::runFor($tenant->id, fn () => DB::transaction(function () use ($warehouse, $variant, $stock): void {
                (new LockInventoryRows)->run($warehouse, [$variant->id]);
                (new ApplyMovement)->run(
                    warehouseId: $warehouse,
                    variantId: $variant->id,
                    type: MovementType::IMPORT,
                    quantity: $stock,
                    idempotencyKey: MovementKey::import((string) new UuidV7),
                    sourceType: 'import_row',
                );
            }));
        }

        $order = TenantContext::runFor($tenant->id, fn () => (new IngestChannelOrder)->run(new IncomingOrder(
            channelConnectionId: $connection->id,
            externalId: $package,
            lines: array_map(static fn (array $l): IncomingOrderLine => new IncomingOrderLine(
                externalLineId: $l['line'],
                sku: $l['sku'],
                title: 'Tişört',
                quantity: $l['qty'],
                variantId: ($l['variant'] ?? $variant)->id,
            ), $lines),
            externalNumber: 'N-'.$package,
            placedAt: now()->subDays(5),
        ), $warehouse));

        return [$order, $variant, $warehouse];
    }

    protected function onHand(Tenant $tenant, string $warehouse, Variant $variant): int
    {
        return (int) TenantContext::runAsSystem(fn () => DB::table('inventory_levels')
            ->where('tenant_id', $tenant->id)->where('warehouse_id', $warehouse)->where('variant_id', $variant->id)->value('on_hand'));
    }

    protected function returnMovements(Tenant $tenant): int
    {
        return (int) TenantContext::runAsSystem(fn () => DB::table('inventory_movements')->where('tenant_id', $tenant->id)->where('type', MovementType::RETURN->value)->count());
    }

    protected function syncReturns(ChannelConnection $connection): ?array
    {
        Redis::connection()->flushdb();

        return app(IngestReturns::class)->forConnection($connection->fresh());
    }

    protected function syncQuestions(ChannelConnection $connection): ?array
    {
        Redis::connection()->flushdb();

        return app(IngestQuestions::class)->forConnection($connection->fresh());
    }

    protected function allow(ChannelConnection $connection, OutboundWrite $write, bool $enabled = true): void
    {
        TenantContext::runAsSystem(fn () => DB::table('channel_connections')->where('id', $connection->id)->update([$write->column() => $enabled]));
    }

    protected function timeout(): \Closure
    {
        return static fn () => throw new ConnectionException('cURL error 28: Operation timed out');
    }
}
