<?php

declare(strict_types=1);

namespace App\Domain\Channels\Actions;

use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Registry\AdapterRegistry;
use App\Domain\Sync\Support\ChannelErrorText;
use Throwable;

/**
 * Bağlantının sağlığını ölçer ve durumu yazar.
 *
 * Mimari Karar Dokümanı v2.2 · §7 · healthCheck(), §13 · faz 1.4.
 *
 * SORUMLULUK SINIRI: adapter ölçer, bu action YAZAR. Adapter yan etkisizdir
 * ve veritabanına dokunmaz (§7); `health_status` ve `status` geçişleri
 * çekirdeğin kararıdır.
 *
 * DURUM GEÇİŞİ İKİ YÖNLÜDÜR:
 *   sağlıklı  → status = active   (pending veya sağlıksızdan iyileşme)
 *   sağlıksız → status = pending  (aktifse geri alınır)
 *
 *   Sağlıksız bağlantıyı `active` bırakmak, kuyruğa iş atılmasına ve her
 *   birinin kalıcı hataya düşmesine yol açardı. `pending`'e çekmek akışı
 *   durdurur ve panelde görünür kılar.
 *
 * BAĞLANTI SİLİNMEZ, İŞARETLENİR: listing'ler ve sipariş geçmişi ona bağlıdır.
 *
 * İSTİSNA DIŞARI SIZMAZ: adapter `healthCheck()` içinde yakalar ama registry
 * adapter'ı kuramazsa (adapter sınıfı yok, kimlik bilgisi bozuk) burada
 * patlar. Sağlık kontrolü bir teşhis aracıdır; kendisi 500 vermemelidir.
 */
final class CheckChannelHealth
{
    public function __construct(
        private readonly AdapterRegistry $registry,
        private readonly ChannelErrorText $errorText,
    ) {}

    public function run(ChannelConnection $connection): ChannelConnection
    {
        try {
            // Her çağrıda YENİ adapter örneği (§7 · P0 güvenlik).
            $result = $this->registry->for($connection)->healthCheck();

            $healthy = $result->healthy;
            $message = $result->message;
        } catch (Throwable $e) {
            // Adapter kurulamadı: bu da sağlıksızlıktır, 500 değil.
            $healthy = false;
            $message = $e->getMessage();
        }

        if ($healthy) {
            $connection->forceFill([
                'health_status' => 'healthy',
                'status' => 'active',
                'last_healthy_at' => now(),
                // Eski hata temizlenir: kullanıcı düzeldiğini görmeli.
                'last_error' => null,
                'connected_at' => $connection->connected_at ?? now(),
            ])->save();

            return $connection;
        }

        $connection->forceFill([
            'health_status' => 'unhealthy',
            // Aktifse geri çekilir: sağlıksız kanala iş atılmaz.
            'status' => 'pending',
            // MASKELENİR (§11): hata metni kanalın yanıt gövdesini taşır ve
            // o gövde kimlik bilgisini yansıtabilir. Bu kolon panele
            // olduğu gibi gider — maskelenmezse sır tarayıcıda görünür.
            'last_error' => $this->errorText->redact($connection, $message),
        ])->save();

        return $connection;
    }
}
