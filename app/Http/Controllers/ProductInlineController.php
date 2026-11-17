<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Catalog\Actions\SetVariantCost;
use App\Domain\Catalog\Actions\SetVariantPrice;
use App\Domain\Catalog\Models\Variant;
use App\Domain\Inventory\Actions\AdjustStock;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Ürünler listesinde SATIR İÇİ düzenleme (ERP liste görünümü, 17 Kas 2026)
 * — fiyat, alış maliyeti ve rafta sayım tek "Kaydet ve gönder" ile.
 *
 * YENİ YAZMA YOLU YOK: her değişiklik ürün ekranının kullandığı AYNI
 * action'dan geçer — fiyat `SetVariantPrice` (değiştiyse fiyat olayı →
 * kanallara), maliyet `SetVariantCost`, stok `AdjustStock::setTo` (sayım:
 * "rafta X var", varsayılan depo; stok izni kapalı kanala gitmez). Böylece
 * kanala giden davranış listeden ve ekrandan aynıdır.
 *
 * Yalnız TEK VARYANTLI, set OLMAYAN ürünün varyantı düzenlenir: listede
 * çok varyantlı ürünün tek bir fiyatı/sayımı yoktur, setin stoğu
 * bileşenlerinden türetilir. Kimlikler kiracı scope'u altında çözülür;
 * başka kiracının varyantı sessizce bulunmaz.
 */
final class ProductInlineController extends Controller
{
    public const MAX_CHANGES = 200;

    public function store(
        Request $request,
        SetVariantPrice $setPrice,
        SetVariantCost $setCost,
        AdjustStock $adjustStock,
    ): RedirectResponse {
        $validated = $request->validate([
            'changes' => ['required', 'array', 'min:1', 'max:'.self::MAX_CHANGES],
            'changes.*.variant_id' => ['required', 'uuid'],
            'changes.*.price' => ['sometimes', 'required', 'numeric', 'min:0', 'max:9999999'],
            'changes.*.cost' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999999'],
            'changes.*.stock' => ['sometimes', 'required', 'integer', 'min:0', 'max:1000000'],
        ]);

        $changes = collect($validated['changes'])->keyBy('variant_id');
        $variants = Variant::query()
            ->whereIn('id', $changes->keys())
            ->where('kind', Variant::KIND_STANDARD)
            ->get()
            ->keyBy('id');

        // Tek varyantlı ürün kuralı: aynı ürünün başka standart varyantı varsa reddet.
        $multi = Variant::query()
            ->whereIn('product_id', $variants->pluck('product_id'))
            ->where('kind', Variant::KIND_STANDARD)
            ->selectRaw('product_id, count(*) AS n')
            ->groupBy('product_id')
            ->havingRaw('count(*) > 1')
            ->pluck('product_id')
            ->all();

        $unknown = $changes->keys()->diff($variants->keys());
        $blocked = $variants->filter(fn (Variant $v): bool => in_array($v->product_id, $multi, true));

        if ($unknown->isNotEmpty() || $blocked->isNotEmpty()) {
            throw ValidationException::withMessages([
                'changes' => __('Yalnız tek seçenekli ürünlerin fiyatı ve stoğu listeden düzenlenir; diğerlerini ürün ekranından düzenleyin.'),
            ]);
        }

        $warehouse = $request->attributes->get('tenant')?->defaultWarehouse();
        abort_if($warehouse === null, 409, __('Kiracının varsayılan deposu yok.'));

        $done = 0;

        foreach ($changes as $variantId => $change) {
            $variant = $variants[$variantId];

            if (array_key_exists('price', $change) && $setPrice->run($variant, (float) $change['price'])) {
                $done++;
            }

            if (array_key_exists('cost', $change)) {
                $cost = $change['cost'];

                if ($setCost->run($variant->fresh() ?? $variant, $cost === null || $cost === '' ? null : (string) $cost)) {
                    $done++;
                }
            }

            if (array_key_exists('stock', $change)) {
                $movement = $adjustStock->setTo(
                    warehouseId: $warehouse->id,
                    variantId: $variant->id,
                    target: (int) $change['stock'],
                    note: 'Ürün listesinden sayım',
                    actorId: $request->user()?->id,
                );
                $done += $movement !== null ? 1 : 0;
            }
        }

        return back()->with('success', $done === 0
            ? __('Değişiklik yok.')
            : __(':count değişiklik kaydedildi; fiyat ve stok bağlı kanallara gönderiliyor.', ['count' => $done]));
    }
}
