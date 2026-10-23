<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Catalog\Actions\SetChannelPriceRule;
use App\Domain\Catalog\Models\ChannelPriceRule;
use App\Domain\Catalog\Support\Fx\FxRates;
use App\Domain\Catalog\Support\PriceRuleCalculator;
use App\Domain\Channels\Contracts\SupportsPricing;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Registry\AdapterRegistry;
use App\Domain\Sync\Support\ChannelCurrency;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Bağlantının fiyat kuralı ekranı: kanal farkı, yuvarlama, zarar koruması.
 *
 * Fiyat göndermeyen kanalda (yetenek yok) ekran 404: kural kaydedilir ama
 * hiçbir yere gitmezdi.
 */
final class ChannelPricingController extends Controller
{
    public function __construct(private readonly AdapterRegistry $registry) {}

    public function edit(string $connection, FxRates $rates): InertiaResponse
    {
        $model = $this->resolve($connection);
        $rule = ChannelPriceRule::query()->where('channel_connection_id', $model->id)->first();

        // Kanalın bildirdiği birim (Etsy mağaza birimi, eBay pazarı…); Shopify
        // ve Woo bildirmez, satıcı seçer. Okuma hatası ekranı düşürmez.
        $channelCurrency = ChannelCurrency::of($this->registry->for($model));
        $fxCurrency = $rule?->fx_currency ?? ($channelCurrency !== null && $channelCurrency !== 'TRY' ? $channelCurrency : null);

        $quotes = [];
        foreach (ChannelPriceRule::FX_CURRENCIES as $code) {
            $quote = $rates->quote($code);
            $quotes[$code] = $quote === null ? null : ['rate' => $quote->rate, 'date' => $quote->anchorDate->format('Y-m-d')];
        }

        return Inertia::render('Channels/Pricing', [
            'connection' => [
                'id' => $model->id,
                'label' => $model->label,
                'channel' => $model->channelType?->name ?? $model->channel_type_code,
            ],
            'rule' => [
                'markupPercent' => $rule === null ? '0' : self::trim((string) $rule->markup_percent),
                'markupAmount' => $rule === null ? '0' : self::trim((string) $rule->markup_amount),
                'rounding' => $rule?->rounding ?? ChannelPriceRule::ROUNDING_NONE,
                'minMarginPercent' => $rule?->min_margin_percent === null ? null : self::trim((string) $rule->min_margin_percent),
                'fxEnabled' => (bool) ($rule?->fx_enabled ?? false),
                'fxCurrency' => $fxCurrency,
                'fxMarkupPercent' => $rule === null ? '0' : self::trim((string) $rule->fx_markup_percent),
                'fxRounding' => $rule?->fx_rounding ?? ChannelPriceRule::ROUNDING_NONE,
                'fxMinPrice' => $rule?->fx_min_price === null ? null : self::trim((string) $rule->fx_min_price),
            ],
            // Örnek hesap ekranda canlı yapılır; sunucuyla aynı sonucu
            // vermesi için yuvarlama seçenekleri buradan gider.
            'roundings' => ChannelPriceRule::ROUNDINGS,
            'fxRoundings' => ChannelPriceRule::FX_ROUNDINGS,
            'fxCurrencies' => ChannelPriceRule::FX_CURRENCIES,
            'channelCurrency' => $channelCurrency,
            // Birim başına fiyatlama kuru; null = kur yok ya da bayat (fiyat gitmez).
            'fxQuotes' => $quotes,
        ]);
    }

    public function update(Request $request, string $connection, SetChannelPriceRule $setRule): RedirectResponse
    {
        $model = $this->resolve($connection);

        $validated = $request->validate([
            'markup_percent' => ['required', 'numeric', 'min:-90', 'max:500'],
            'markup_amount' => ['required', 'numeric', 'min:-100000', 'max:100000'],
            'rounding' => ['required', Rule::in(ChannelPriceRule::ROUNDINGS)],
            'min_margin_percent' => ['nullable', 'numeric', 'min:-100', 'max:1000'],
            'fx_enabled' => ['sometimes', 'boolean'],
            'fx_currency' => ['nullable', 'required_if_accepted:fx_enabled', Rule::in(ChannelPriceRule::FX_CURRENCIES)],
            // Kur payı negatif olamaz: zarar koruması çeviriden önceki TL
            // fiyatına bakar ve çevirinin fiyatı düşürmediğine güvenir.
            'fx_markup_percent' => ['sometimes', 'numeric', 'min:0', 'max:500'],
            'fx_rounding' => ['sometimes', Rule::in(ChannelPriceRule::FX_ROUNDINGS)],
            'fx_min_price' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
        ]);

        $values = [
            'markup_percent' => (string) $validated['markup_percent'],
            'markup_amount' => (string) $validated['markup_amount'],
            'rounding' => $validated['rounding'],
            'min_margin_percent' => $validated['min_margin_percent'] === null ? null : (string) $validated['min_margin_percent'],
        ];

        if (array_key_exists('fx_enabled', $validated)) {
            $values += [
                'fx_enabled' => (bool) $validated['fx_enabled'],
                'fx_currency' => $validated['fx_currency'] ?? null,
                'fx_markup_percent' => (string) ($validated['fx_markup_percent'] ?? '0'),
                'fx_rounding' => $validated['fx_rounding'] ?? ChannelPriceRule::ROUNDING_NONE,
                'fx_min_price' => isset($validated['fx_min_price']) ? (string) $validated['fx_min_price'] : null,
            ];
        }

        $count = $setRule->run($model, $values, $request->user()?->id);

        return redirect()->route('channels.index')->with(
            'success',
            $count > 0
                ? trans_choice(':label fiyat kuralı kaydedildi; :count ilanın fiyatı yeniden gönderiliyor.|:label fiyat kuralı kaydedildi; :count ilanın fiyatı yeniden gönderiliyor.', $count, ['label' => $model->label, 'count' => $count])
                : __(':label fiyat kuralı kaydedildi.', ['label' => $model->label]),
        );
    }

    /**
     * Kartta görünen kısa özet ("+%15 · ,90 · zarar koruması %10"); kural
     * yoksa ya da hiçbir şey yapmıyorsa null.
     */
    public static function summary(?ChannelPriceRule $rule): ?string
    {
        if ($rule === null) {
            return null;
        }

        $parts = [];

        if ((float) $rule->markup_percent !== 0.0) {
            $parts[] = ((float) $rule->markup_percent > 0 ? '+' : '').'%'.self::trim((string) $rule->markup_percent);
        }

        if ((float) $rule->markup_amount !== 0.0) {
            $parts[] = ((float) $rule->markup_amount > 0 ? '+' : '').self::trim((string) $rule->markup_amount);
        }

        $parts[] = match ($rule->rounding) {
            ChannelPriceRule::ROUNDING_WHOLE => __('tam sayıya yuvarla'),
            ChannelPriceRule::ROUNDING_X90 => __(',90 ile bitir'),
            ChannelPriceRule::ROUNDING_X99 => __(',99 ile bitir'),
            default => null,
        };

        if ($rule->min_margin_percent !== null) {
            $parts[] = __('zarar koruması %:margin', ['margin' => self::trim((string) $rule->min_margin_percent)]);
        }

        if ($rule->convertsCurrency()) {
            $parts[] = (float) $rule->fx_markup_percent !== 0.0
                ? __(':currency kuru +%:percent', ['currency' => (string) $rule->fx_currency, 'percent' => self::trim((string) $rule->fx_markup_percent)])
                : __(':currency kuru', ['currency' => (string) $rule->fx_currency]);
        }

        $parts = array_values(array_filter($parts));

        return $parts === [] ? null : implode(' · ', $parts);
    }

    private function resolve(string $connection): ChannelConnection
    {
        // Başka kiracının bağlantısı 404 (global scope).
        $model = ChannelConnection::query()
            ->with('channelType:code,name,adapter_class')
            ->findOrFail($connection);

        abort_unless($this->registry->for($model) instanceof SupportsPricing, 404);

        return $model;
    }

    /** "15.00" → "15", "2.50" → "2.5". */
    private static function trim(string $decimal): string
    {
        return PriceRuleCalculator::toMinor($decimal) % 100 === 0
            ? (string) intdiv(PriceRuleCalculator::toMinor($decimal), 100)
            : rtrim(rtrim($decimal, '0'), '.');
    }
}
