<?php

declare(strict_types=1);

namespace App\Domain\Finance\Support;

use App\Domain\Finance\Models\ChannelChargeRule;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Support\Collection;

/**
 * Bağlantının kesinti varsayılanları (komisyon, kargo, paketleme) — iş/istek
 * ömrü boyunca önbellekli. Zarar koruması her listing için sorar; Trendyol
 * yükünde 1000 satır = 1000 sorgu olmasın.
 *
 * ⚠️ `scoped` bağlanır (ChannelPriceRules ile aynı gerekçe): uzun yaşayan
 * Horizon işçisinde eski kesinti, satıcı değiştirdikten sonra da kullanılırdı.
 *
 * ⚠️ Sistem bağlamında okunur: tarama/mutabakat `runAsSystem()` altında
 * koşar. Bağlantı kimliği benzersizdir, kiracı sızıntısı yoktur.
 */
final class ChannelChargeRules
{
    /** @var array<string, Collection<string, ChannelChargeRule>> */
    private array $rules = [];

    /**
     * Listing için geçerli kesinti: kategori istisnası varsa onun komisyonu,
     * yoksa bağlantı varsayılanı. Kargo + paketleme yalnız varsayılandan.
     *
     * Kural yoksa sıfır döner (eski davranış): bilinmeyen kesinti tabanı
     * yükseltmez — yükseltseydi satıcı kural girmeden ilanları dururdu.
     *
     * Kategori yalnız bağlantının kategori istisnası varsa çözülür (çoğu
     * bağlantıda yok → ek sorgu yok).
     *
     * @param  Closure(): ?string  $category
     * @return array{commission: string, perOrder: string}
     */
    public function forListing(string $connectionId, Closure $category): array
    {
        $rules = $this->forConnection($connectionId);
        $default = $rules->get(ChannelChargeRule::DEFAULT_CATEGORY);

        $categoryId = $rules->count() > ($default === null ? 0 : 1) ? $category() : null;

        $commission = ($categoryId !== null && $categoryId !== '' ? $rules->get($categoryId)?->commission_percent : null)
            ?? $default?->commission_percent;

        return [
            'commission' => $commission === null ? '0' : (string) $commission,
            'perOrder' => Money::add((string) ($default?->shipping_fee ?? '0'), (string) ($default?->packaging_fee ?? '0')),
        ];
    }

    /** @return Collection<string, ChannelChargeRule> */
    private function forConnection(string $connectionId): Collection
    {
        if (! array_key_exists($connectionId, $this->rules)) {
            $this->rules[$connectionId] = TenantContext::runAsSystem(
                fn (): Collection => ChannelChargeRule::query()
                    ->where('channel_connection_id', $connectionId)
                    ->get()
                    ->keyBy('internal_category_id'),
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
