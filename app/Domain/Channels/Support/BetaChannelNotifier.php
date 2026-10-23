<?php

declare(strict_types=1);

namespace App\Domain\Channels\Support;

use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Models\ChannelType;
use App\Domain\Identity\Models\Tenant;
use App\Support\Observability\OpsNotifier;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;

/**
 * Beta kanal bildirimleri — 34Devs'e (kullanıcı kararı 21 Eki 2026).
 *
 * Beta kanal = gerçek hesapta doğrulanmamış ama satışa açık. İlk müşteriyle
 * birlikte doğrulanacağı için ekip iki anı KAÇIRMAMALI: yeni bağlantı ve o
 * bağlantının ilk ciddi hatası (kalıcı hata, kimlik hatası, ölen sipariş).
 *
 * ⚠️ BİLDİRİM ASLA ASIL İŞİ DÜŞÜRMEZ. ntfy ya da posta hatası yutulur ve
 * yalnız loglanır — bildirim yüzünden sipariş/stok işi başarısız sayılsaydı
 * beta kanal tam da izlenmek istendiği anda bozulurdu.
 *
 * ⚠️ KİŞİSEL VERİ GİTMEZ: yalnız kanal, bağlantı adı, kiracı adı, hata
 * sınıfı ve maskelenmiş hata özetinin ilk 200 karakteri.
 */
final class BetaChannelNotifier
{
    /** Hata bildirimi bağlantı başına bu süre içinde en çok bir kez. */
    private const FAILURE_THROTTLE_SECONDS = 3600;

    public function connected(ChannelConnection $connection): void
    {
        $type = $this->betaType($connection);

        if ($type === null) {
            return;
        }

        $this->send(
            title: "Beta kanal bağlandı: {$type->name}",
            body: $this->describe($connection, $type).' — ilk kurulumu satıcıyla birlikte kontrol edin.',
            tags: 'new',
        );
    }

    public function failed(ChannelConnection $connection, string $kind, string $detail = ''): void
    {
        $type = $this->betaType($connection);

        if ($type === null) {
            return;
        }

        // Cache::add yalnız anahtar YOKSA yazar — saatlik kısma atomik.
        if (! Cache::add("beta-channel-failure:{$connection->id}", 1, self::FAILURE_THROTTLE_SECONDS)) {
            return;
        }

        $summary = trim(mb_substr($detail, 0, 200));

        $this->send(
            title: "Beta kanal hatası: {$type->name} ({$kind})",
            body: $this->describe($connection, $type).($summary !== '' ? " — {$summary}" : ''),
            tags: 'warning',
        );
    }

    private function betaType(ChannelConnection $connection): ?ChannelType
    {
        $type = TenantContext::runAsSystem(
            fn (): ?ChannelType => ChannelType::query()->find($connection->channel_type_code),
        );

        return $type?->is_beta === true ? $type : null;
    }

    private function describe(ChannelConnection $connection, ChannelType $type): string
    {
        $tenant = TenantContext::runAsSystem(
            fn (): ?string => Tenant::query()->whereKey($connection->tenant_id)->value('name'),
        );

        return sprintf(
            '%s · bağlantı "%s" (%s) · kiracı %s',
            $type->name,
            (string) $connection->label,
            (string) $connection->external_account_id,
            $tenant ?? (string) $connection->tenant_id,
        );
    }

    /** Gönderim `OpsNotifier`'da (S7: izleme turu da aynı yolu kullanır). */
    private function send(string $title, string $body, string $tags): void
    {
        app(OpsNotifier::class)->send($title, $body, $tags);
    }
}
