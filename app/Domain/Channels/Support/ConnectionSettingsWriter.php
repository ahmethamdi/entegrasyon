<?php

declare(strict_types=1);

namespace App\Domain\Channels\Support;

use App\Domain\Channels\Models\ChannelConnection;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Bağlantı `settings` jsonb'una ANAHTAR DÜZEYİNDE yazar.
 *
 * Yoklama/mutabakat imleçleri önceden tüm `settings` dizisini yeniden
 * yazıyordu: tur sürerken panelden kaydedilen bir ayar (depo seçimi, KDV
 * varsayılanı) tur sonunda ESKİ kopyayla ezilirdi. Burada yalnız verilen
 * anahtarlar değişir — `||` birleştirme ve `-` silme tek UPDATE'te,
 * veritabanında yapılır.
 */
final class ConnectionSettingsWriter
{
    /**
     * @param  array<string, mixed>  $values
     * @param  list<string>  $forget
     */
    public static function merge(ChannelConnection $connection, array $values, array $forget = []): void
    {
        $patch = json_encode($values === [] ? new \stdClass : $values, JSON_THROW_ON_ERROR);

        TenantContext::runAsSystem(function () use ($connection, $patch, $forget): void {
            $expression = "COALESCE(settings, '{}'::jsonb)";

            foreach ($forget as $_) {
                $expression = "({$expression} - ?::text)";
            }

            DB::update(
                "UPDATE channel_connections SET settings = {$expression} || ?::jsonb, updated_at = now() WHERE id = ?",
                [...$forget, $patch, $connection->id],
            );
        });

        // Bellekteki kopya da güncellenir — aynı tur içinde okuyan bulsun.
        $settings = $connection->settings ?? [];

        foreach ($forget as $key) {
            unset($settings[$key]);
        }

        $connection->setRawAttributes(
            [...$connection->getAttributes(), 'settings' => json_encode([...$settings, ...$values], JSON_THROW_ON_ERROR)],
            sync: true,
        );
    }
}
