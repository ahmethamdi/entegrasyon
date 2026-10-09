<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Catalog\Actions\SetChannelPriceRule;
use App\Domain\Catalog\Models\ChannelPriceRule;
use App\Domain\Catalog\Support\PriceRuleCalculator;
use App\Domain\Channels\Contracts\SupportsPricing;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Registry\AdapterRegistry;
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

    public function edit(string $connection): InertiaResponse
    {
        $model = $this->resolve($connection);
        $rule = ChannelPriceRule::query()->where('channel_connection_id', $model->id)->first();

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
            ],
            // Örnek hesap ekranda canlı yapılır; sunucuyla aynı sonucu
            // vermesi için yuvarlama seçenekleri buradan gider.
            'roundings' => ChannelPriceRule::ROUNDINGS,
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
        ]);

        $count = $setRule->run($model, [
            'markup_percent' => (string) $validated['markup_percent'],
            'markup_amount' => (string) $validated['markup_amount'],
            'rounding' => $validated['rounding'],
            'min_margin_percent' => $validated['min_margin_percent'] === null ? null : (string) $validated['min_margin_percent'],
        ], $request->user()?->id);

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
