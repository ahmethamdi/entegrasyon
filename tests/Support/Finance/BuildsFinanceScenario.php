<?php

declare(strict_types=1);

namespace Tests\Support\Finance;

use App\Domain\Channels\Adapters\Trendyol\TrendyolAdapter;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Models\ChannelType;
use App\Domain\Channels\Support\CredentialVault;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\OrderLine;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * S1 hakediş testleri için ortak kurulum: Trendyol bağlantısı, siparişler
 * ve canlı yanıttan türetilmiş (anonim) cari hesap ekstresi sayfaları.
 */
trait BuildsFinanceScenario
{
    protected const SELLER = '999001';

    /** Son kurulan kiracının sahibi (ekran testleri için). */
    protected ?User $owner = null;

    /** @return array{0: Tenant, 1: ChannelConnection} */
    protected function trendyolTenant(string $seller = self::SELLER, string $connectedAt = '2026-10-10 00:00:00'): array
    {
        $this->owner = User::factory()->create();
        $tenant = (new CreateTenant)->run(name: 'Hakediş '.uniqid(), owner: $this->owner);

        TenantContext::runAsSystem(fn () => ChannelType::query()->updateOrCreate(['code' => 'trendyol'], [
            'name' => 'Trendyol', 'kind' => 'marketplace', 'adapter_class' => TrendyolAdapter::class,
            'supports_webhooks' => false, 'is_active' => true,
        ]));

        $connection = TenantContext::runFor($tenant->id, function () use ($seller, $connectedAt): ChannelConnection {
            $connection = ChannelConnection::factory()->create([
                'channel_type_code' => 'trendyol',
                'external_account_id' => $seller.'-'.uniqid(),
                'status' => 'active',
                'connected_at' => $connectedAt,
                'settings' => [TrendyolAdapter::SELLER_ID_KEY => $seller],
            ]);
            app(CredentialVault::class)->store($connection, ['api_key' => 'k', 'api_secret' => 's']);

            return $connection->fresh(['channelType']);
        });

        return [$tenant, $connection];
    }

    /**
     * Satış anı görüntüsüyle bir Trendyol siparişi (paket = external_id).
     *
     * @param  list<array{sku?: string, qty?: int, net?: ?string, cost?: ?string, cancelled?: int, returned?: int}>  $lines
     */
    protected function trendyolOrder(Tenant $tenant, ChannelConnection $connection, string $package, string $number, array $lines): Order
    {
        return TenantContext::runFor($tenant->id, function () use ($tenant, $connection, $package, $number, $lines): Order {
            $order = Order::query()->forceCreate([
                'tenant_id' => $tenant->id,
                'channel_connection_id' => $connection->id,
                'external_id' => $package,
                'external_number' => $number,
                'status' => 'Delivered',
                'state' => 'delivered',
                'currency' => 'TRY',
                'placed_at' => '2026-10-05 10:00:00',
            ]);

            foreach (array_values($lines) as $i => $line) {
                OrderLine::query()->forceCreate([
                    'tenant_id' => $tenant->id,
                    'order_id' => $order->id,
                    'external_line_id' => (string) ($i + 1),
                    'sku' => $line['sku'] ?? 'SKU-'.($i + 1),
                    'title' => 'Ürün',
                    'quantity' => $line['qty'] ?? 1,
                    'quantity_cancelled' => $line['cancelled'] ?? 0,
                    'quantity_returned' => $line['returned'] ?? 0,
                    'net_line_total' => $line['net'] ?? null,
                    'gross_line_total' => $line['net'] ?? null,
                    'amounts_include_vat' => true,
                    'unit_cost' => $line['cost'] ?? null,
                    'cost_currency' => isset($line['cost']) ? 'TRY' : null,
                    'cost_captured_at' => isset($line['cost']) ? '2026-10-05 10:00:00' : null,
                    'financials_captured_at' => '2026-10-05 10:00:00',
                ]);
            }

            return $order;
        });
    }

    /** Canlı yanıttan (11 Eki 2026) anonimleştirilmiş sayfa. */
    protected function livePage(string $source): array
    {
        return json_decode((string) file_get_contents(base_path("tests/Fixtures/trendyol/finance/{$source}-canli-2026-10-11.json")), true);
    }

    /** Canlı satış satırının değiştirilmiş kopyası. */
    protected function saleRow(array $overrides = []): array
    {
        return [...$this->livePage('settlements')['content'][0], ...$overrides];
    }

    /** @param list<array<string, mixed>> $rows */
    protected function page(array $rows, int $page = 0, int $totalPages = 1): array
    {
        return ['page' => $page, 'size' => 500, 'totalPages' => $totalPages, 'totalElements' => count($rows), 'content' => $rows];
    }

    /**
     * Uç noktaya göre yanıt. `$settlements`/`$others`: Request → yanıt gövdesi
     * ya da düz gövde (her pencere/sayfa aynı).
     */
    protected function fakeFinance(array|\Closure $settlements, array|\Closure $others = []): void
    {
        $others = $others === [] ? $this->page([]) : $others;

        Http::fake(function (Request $request) use ($settlements, $others) {
            $url = $request->url();
            $body = match (true) {
                str_contains($url, '/finance/che/sellers/') && str_contains($url, '/settlements') => $settlements,
                str_contains($url, '/finance/che/sellers/') && str_contains($url, '/otherfinancials') => $others,
                default => null,
            };

            if ($body === null) {
                return Http::response(['message' => 'beklenmeyen istek '.$url], 599);
            }

            $body = $body instanceof \Closure ? $body($request) : $body;

            return is_int($body) ? Http::response(['errors' => 'down'], $body) : Http::response($body, 200);
        });
    }
}
