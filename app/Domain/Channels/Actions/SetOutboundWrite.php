<?php

declare(strict_types=1);

namespace App\Domain\Channels\Actions;

use App\Domain\Channels\Enums\OutboundWrite;
use App\Domain\Channels\Exceptions\OutboundWriteLocked;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Sync\Actions\RequestResync;
use App\Domain\Sync\Models\Listing;

/**
 * Bağlantının bir yazma iznini açar ya da kapatır — ADR-010.
 *
 * İZİN YALNIZ BU AÇIK EYLEMLE DEĞİŞİR: kimlik doğrulaması, sağlık kontrolü
 * ya da bağlantı kurulumu hiçbir izni açmaz.
 *
 * CANLI DOĞRULAMASI BİTMEMİŞ KANAL (`outbound_write_locked_channels`,
 * bugün Hepsiburada ve N11) için AÇMA reddedilir. Kapatma her zaman serbesttir.
 *
 * KAPATMA geçmişi SİLMEZ: listing kimlikleri, yaşam döngüsü ve senkron
 * durumu yerinde kalır; kuyruktaki işler çalıştıkları anda kapıya takılır.
 *
 * STOK/FİYAT AÇILINCA canlı satırlar yeniden senkronlanır: izin kapalıyken
 * fan-out operasyon açmamıştı, kanal eski değerde kalmış olabilir. Ürün
 * yayını açılınca hiçbir şey otomatik GİTMEZ — yayın satıcının ürün başına
 * açık eylemidir.
 */
final class SetOutboundWrite
{
    public function __construct(
        private readonly RequestResync $resync,
    ) {}

    public function run(ChannelConnection $connection, OutboundWrite $write, bool $enabled): void
    {
        if ($enabled && self::isLocked($connection)) {
            throw new OutboundWriteLocked($write, $connection->channel_type_code);
        }

        $column = $write->column();
        $wasEnabled = $connection->{$column} === true;

        $connection->forceFill([$column => $enabled])->save();

        if (! $enabled || $wasEnabled || $write === OutboundWrite::PRODUCT_PUBLISH) {
            return;
        }

        Listing::query()
            ->where('channel_connection_id', $connection->id)
            ->live()
            ->chunkById(200, function ($listings) use ($write): void {
                foreach ($listings as $listing) {
                    $this->resync->run($listing, $write->domain(), RequestResync::REASON_OUTBOUND_WRITE_ENABLED);
                }
            });
    }

    /** Kanal türü canlı doğrulama kapısında mı (izin açılamaz). */
    public static function isLocked(ChannelConnection $connection): bool
    {
        return in_array(
            $connection->channel_type_code,
            (array) config('entegrasyon.outbound_write_locked_channels', []),
            true,
        );
    }
}
