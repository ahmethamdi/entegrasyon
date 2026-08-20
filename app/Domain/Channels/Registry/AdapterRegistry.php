<?php

declare(strict_types=1);

namespace App\Domain\Channels\Registry;

use App\Domain\Channels\Contracts\ChannelAdapter;
use App\Domain\Channels\Contracts\SupportsApprovalWorkflow;
use App\Domain\Channels\Contracts\SupportsCatalog;
use App\Domain\Channels\Contracts\SupportsCatalogImport;
use App\Domain\Channels\Contracts\SupportsFulfillment;
use App\Domain\Channels\Contracts\SupportsInventory;
use App\Domain\Channels\Contracts\SupportsOrders;
use App\Domain\Channels\Contracts\SupportsPricing;
use App\Domain\Channels\Contracts\SupportsTaxonomy;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Support\ChannelHttpClient;
use App\Domain\Channels\Support\CredentialVault;
use App\Support\Logging\PayloadRedactor;
use RuntimeException;

/**
 * Adapter üretici — paylaşımsız yaşam döngüsü.
 *
 * Mimari Karar Dokümanı v2.2 · §7 · Registry, §1 · Karar 20, §17 · P0.
 *
 * DEĞİŞMEZ KURAL — HER ÇAĞRIDA YENİ ÖRNEK:
 *   Container'a singleton olarak bağlanmaz; önbellek TUTULMAZ.
 *
 *   Gerekçe bir güvenlik gerekçesidir: adapter bağlantı taşır. Paylaşılan bir
 *   örnek, aynı worker sürecinde kiracı A'nın bağlantısını ve kimlik
 *   bilgilerini kiracı B'nin işinde kullanır. Kuyruk worker'ları uzun
 *   ömürlüdür; bu sızıntı üretimde ve sessizce ortaya çıkar.
 *
 *   Bu sınıfa "performans için" önbellek eklenmesi teklif edilirse cevap
 *   hayırdır: adapter yaratmak ucuzdur, sızıntı pahalıdır.
 *
 * Yetenekler tip sisteminden okunur (`instanceof Supports*`); panelde
 * "if type === 'trendyol'" bloğu YAZILMAZ.
 */
final class AdapterRegistry
{
    /**
     * Bağlantı için YENİ bir adapter örneği üretir.
     *
     * @throws RuntimeException Adapter sınıfı yoksa veya sözleşmeyi uygulamıyorsa
     */
    public function for(ChannelConnection $connection): ChannelAdapter
    {
        $class = $connection->channelType?->adapter_class;

        if ($class === null || $class === '') {
            throw new RuntimeException(
                "Bağlantı {$connection->id} için adapter sınıfı tanımlı değil ".
                "(channel_type: {$connection->channel_type_code})."
            );
        }

        if (! class_exists($class)) {
            throw new RuntimeException(
                "Adapter sınıfı bulunamadı: {$class} ".
                "(channel_type: {$connection->channel_type_code})."
            );
        }

        if (! is_subclass_of($class, ChannelAdapter::class)) {
            throw new RuntimeException(
                "Adapter sınıfı {$class} ChannelAdapter sözleşmesini uygulamıyor."
            );
        }

        // app() ile çözülmez, doğrudan new: container adapter'ı çözerse
        // ileride biri singleton bağlayabilir ve kural sessizce delinir.
        return new $class(
            connection: $connection,
            client: $this->clientFor($connection),
        );
    }

    /**
     * Panele gönderilecek yetenek haritası.
     *
     * Vue tarafı `v-if="connection.capabilities.taxonomy"` yazar; kanal adı
     * kontrol etmez. Yeni kanal eklendiğinde panel kodu değişmez.
     *
     * @return array<string, bool>
     */
    public function capabilitiesFor(ChannelConnection $connection): array
    {
        return $this->capabilitiesOf($this->for($connection));
    }

    /** @return array<string, bool> */
    public function capabilitiesOf(ChannelAdapter $adapter): array
    {
        return [
            'catalog' => $adapter instanceof SupportsCatalog,
            // `catalog`'tan AYRI anahtar: ürün GÖNDERMEK ile kanaldan ürün
            // ÇEKMEK farklı yeteneklerdir ve bir kanal yalnızca birini
            // destekleyebilir. Tek anahtara bağlansaydı panel, içe aktarmayı
            // desteklemeyen kanalda da düğmeyi gösterirdi.
            'catalog_import' => $adapter instanceof SupportsCatalogImport,
            'inventory' => $adapter instanceof SupportsInventory,
            'pricing' => $adapter instanceof SupportsPricing,
            'orders' => $adapter instanceof SupportsOrders,
            'taxonomy' => $adapter instanceof SupportsTaxonomy,
            'approval' => $adapter instanceof SupportsApprovalWorkflow,
            'fulfillment' => $adapter instanceof SupportsFulfillment,
        ];
    }

    /**
     * Adapter'ın kullanacağı HTTP istemcisi — bağlantıya özgü, YENİ örnek.
     *
     * İstemci bağlantıyı ve onun kimlik bilgilerini taşır; adapter gibi o da
     * paylaşılamaz. Aynı gerekçe: paylaşılan bir istemci kiracı A'nın
     * kimlik bilgisiyle kiracı B'nin isteğini imzalardı.
     *
     * ChannelRateLimiter henüz yazılmadı (Redis kova); yazıldığında dördüncü
     * bağımlılık olarak buraya eklenecek ve bu sınıf dışında değişiklik
     * gerekmeyecek.
     */
    private function clientFor(ChannelConnection $connection): ChannelHttpClient
    {
        return new ChannelHttpClient(
            connection: $connection,
            vault: app(CredentialVault::class),
            redactor: app(PayloadRedactor::class),
        );
    }
}
