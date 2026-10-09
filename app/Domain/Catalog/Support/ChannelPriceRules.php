<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Support;

use App\Domain\Catalog\Models\ChannelPriceRule;
use App\Support\Tenancy\TenantContext;

/**
 * Bağlantının fiyat kuralı — iş/istek ömrü boyunca önbellekli.
 *
 * `Listing::effectivePrice()` her listing için çağrılır (Trendyol yükünde
 * 1000 satır); her seferinde sorgu atılsaydı yük 1000 sorgu olurdu.
 *
 * ⚠️ `scoped` BAĞLANIR, `singleton` DEĞİL. Horizon işçisi uzun yaşar;
 * singleton önbelleği satıcının kuralı değiştirmesinden sonra da eski kuralı
 * döndürür ve kanala ESKİ fiyat giderdi. Scoped örnek her kuyruk işinde ve
 * her istekte sıfırlanır.
 *
 * ⚠️ SİSTEM BAĞLAMINDA OKUNUR. Mutabakat ve tarama `runAsSystem()` altında
 * koşar; kapsanmış sorgu orada istisna fırlatırdı. Bağlantı kimliği
 * benzersizdir, kiracı sızıntısı yoktur.
 */
final class ChannelPriceRules
{
    /** @var array<string, ChannelPriceRule|null> */
    private array $rules = [];

    public function forConnection(string $connectionId): ?ChannelPriceRule
    {
        if (! array_key_exists($connectionId, $this->rules)) {
            $this->rules[$connectionId] = TenantContext::runAsSystem(
                fn (): ?ChannelPriceRule => ChannelPriceRule::query()
                    ->where('channel_connection_id', $connectionId)
                    ->first(),
            );
        }

        return $this->rules[$connectionId];
    }

    /** Kural yazıldıktan sonra aynı istekte eski değer okunmasın. */
    public function forget(string $connectionId): void
    {
        unset($this->rules[$connectionId]);
    }
}
