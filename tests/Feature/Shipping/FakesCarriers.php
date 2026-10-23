<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use App\Domain\Shipping\Models\CarrierAccount;
use App\Domain\Shipping\Support\ShipmentRecipient;
use App\Domain\Shipping\Support\ShipmentRequest;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;

/**
 * Kargo firmalarının sahte SOAP cevapları.
 *
 * Gövdeler `tests/Fixtures/shipping/` altında ve firmaların HERKESE AÇIK
 * TEST SUNUCUSUNDAN 23 Eki 2026'da alınan gerçek cevaplardır (anahtar
 * `__KEY__`, gönderici adı anonim). Kimlik bilgisi içermezler.
 *
 * Cevap işlem adına göre seçilir (`createShipment`, `SetOrder` …); aynı
 * işleme sırayla birden çok cevap verilebilir, son cevap tekrar eder.
 * Kanal (Shopify/Woo) istekleri `$channelResponder`'a düşer.
 */
trait FakesCarriers
{
    /** @var array<string, list<string|\Closure>> işlem → sıradaki fixture adları */
    protected array $carrierReplies = [];

    /** @var list<array{operation: string, body: string, url: string, action: string}> */
    protected array $carrierCalls = [];

    /** @var (\Closure(HttpRequest): mixed)|null */
    protected ?\Closure $channelResponder = null;

    protected bool $carriersFaked = false;

    protected const OPERATIONS = ['createShipment', 'queryShipment', 'cancelShipment', 'SetOrder', 'GetArasBarcode', 'CancelDispatch'];

    protected function replyWith(string $operation, string|\Closure ...$fixtures): void
    {
        $this->carrierReplies[$operation] = $fixtures;
        $this->fakeCarriers();
    }

    protected function fakeCarriers(): void
    {
        if ($this->carriersFaked) {
            return;
        }

        $this->carriersFaked = true;

        Http::fake(function (HttpRequest $request) {
            $body = $request->body();

            foreach (self::OPERATIONS as $operation) {
                if (! str_contains($body, '<ship:'.$operation.' ') && ! str_contains($body, '<'.$operation.' xmlns')) {
                    continue;
                }

                $this->carrierCalls[] = [
                    'operation' => $operation,
                    'body' => $body,
                    'url' => $request->url(),
                    'action' => (string) ($request->header('SOAPAction')[0] ?? ''),
                ];

                $queue = $this->carrierReplies[$operation] ?? [];
                $next = count($queue) > 1 ? array_shift($this->carrierReplies[$operation]) : ($queue[0] ?? null);

                if ($next instanceof \Closure) {
                    return $next($request);
                }

                if ($next === null) {
                    return Http::response('beklenmeyen kargo işlemi '.$operation, 599);
                }

                return Http::response($this->fixture($next, $this->keyIn($body)), 200, ['Content-Type' => 'text/xml; charset=utf-8']);
            }

            if ($this->channelResponder !== null) {
                return ($this->channelResponder)($request);
            }

            return Http::response(['message' => 'beklenmeyen istek '.$request->url()], 599);
        });
    }

    /** Fixture gövdesi; `__KEY__` istekteki anahtarla değişir. */
    protected function fixture(string $name, string $key = 'ANAHTAR'): string
    {
        $xml = (string) file_get_contents(base_path('tests/Fixtures/shipping/'.$name.'.xml'));

        return str_replace('__KEY__', $key, $xml);
    }

    /** @return list<string> */
    protected function callsTo(string $operation): array
    {
        return array_values(array_map(
            static fn (array $c): string => $c['body'],
            array_filter($this->carrierCalls, static fn (array $c): bool => $c['operation'] === $operation),
        ));
    }

    protected function carrierAccount(string $provider, string $environment = CarrierAccount::ENV_TEST): CarrierAccount
    {
        return new CarrierAccount([
            'provider' => $provider,
            'environment' => $environment,
            'credentials' => ['username' => 'satici-ws', 'password' => 'gizli-ws-sifre'],
            'status' => CarrierAccount::STATUS_CONNECTED,
        ]);
    }

    protected function shipmentRequest(string $key = '34PTESTANAHTAR01', array $recipient = []): ShipmentRequest
    {
        return new ShipmentRequest(
            cargoKey: $key,
            recipient: new ShipmentRecipient(...[
                'name' => 'Ayşe Yılmaz',
                'address' => 'Caferağa Mah. Moda Cad. No:12 D:3',
                'city' => 'İstanbul',
                'district' => 'Kadıköy',
                'phone' => '+90 532 111 22 33',
                'email' => 'ayse@example.com',
                ...$recipient,
            ]),
            orderNumber: '#1001',
        );
    }

    private function keyIn(string $body): string
    {
        foreach (['cargoKey', 'IntegrationCode', 'integrationCode', 'keys', 'cargoKeys'] as $field) {
            if (preg_match('#<'.$field.'>([^<]+)</'.$field.'>#', $body, $m) === 1) {
                return $m[1];
            }
        }

        return 'ANAHTAR';
    }
}
