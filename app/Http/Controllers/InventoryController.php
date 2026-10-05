<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Catalog\Models\Variant;
use App\Domain\Inventory\Actions\AdjustStock;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Stok — 5 Ekim panel yenilemesinden beri AYRI EKRAN YOK.
 *
 * Kullanıcı kararı: stok ürün listesinden girilir (Ürünler ekranında
 * toplam stok, "stoğu az / stoksuz" filtreleri ve yerinde sayım). Eski
 * `/inventory` adresi yer imlerinde ve e-postalarda yaşadığı için 404 değil
 * yönlendirme verir; burada yalnız sayım/düzeltme uç noktası kalır.
 *
 * DEĞİŞMEZ KURAL — FAZLA SATIŞ GİZLENMEZ: Ürünler ekranında negatif bakiye
 * kırpılmadan gösterilir ve "stoksuz" filtresine girer.
 */
final class InventoryController extends Controller
{
    /** Eski stok ekranı → Ürünler. Fazla satış filtresi "stoksuz"a eşlenir. */
    public function index(Request $request): RedirectResponse
    {
        $query = array_filter([
            'filter' => $request->string('filter')->toString() === 'oversold' ? 'out' : null,
            'search' => $request->string('search')->trim()->toString() ?: null,
        ]);

        return redirect()->to('/products'.($query === [] ? '' : '?'.http_build_query($query)));
    }

    /**
     * Elle stok düzeltme — fazla satışın düzeltme yolu.
     *
     * Düzeltme LEDGER üzerinden geçer: `AdjustStock` bir MANUAL_ADJUSTMENT
     * hareketi yazar ve projeksiyon ondan türer.
     */
    public function adjust(Request $request, AdjustStock $adjustStock): RedirectResponse
    {
        $validated = $request->validate([
            'variant_id' => ['required', 'uuid'],
            // İki biçim: `target` = SAYIM ("rafta X var", eksiltebilir —
            // panelin asıl yolu); `quantity` = EKLE (eski biçim, pozitif).
            'target' => ['required_without:quantity', 'nullable', 'integer', 'min:0', 'max:1000000'],
            'quantity' => ['required_without:target', 'nullable', 'integer', 'min:1'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        // Kiracı scope'u altında aranır: başka kiracının varyantı 404.
        $variant = Variant::query()->findOrFail($validated['variant_id']);

        $warehouseId = $this->defaultWarehouseId($request);

        if (isset($validated['target'])) {
            $movement = $adjustStock->setTo(
                warehouseId: $warehouseId,
                variantId: $variant->id,
                target: (int) $validated['target'],
                note: $validated['note'] ?? null,
                actorId: $request->user()?->id,
            );

            return back()->with('success', $movement === null
                ? __('Stok zaten :count, değişiklik yok.', ['count' => $validated['target']])
                : __(':sku stoğu :count olarak kaydedildi.', ['sku' => $variant->sku, 'count' => $validated['target']]));
        }

        $adjustStock->run(
            warehouseId: $warehouseId,
            variantId: $variant->id,
            quantity: $validated['quantity'],
            note: $validated['note'] ?? null,
            actorId: $request->user()?->id,
        );

        return back()->with(
            'success',
            "{$variant->sku} için {$validated['quantity']} adet düzeltme kaydedildi.",
        );
    }

    /**
     * Kiracının varsayılan deposu.
     *
     * "En az bir varsayılan" DB kısıtıyla zorlanmaz; `CreateTenant` garanti
     * eder. Yoksa bu bir veri bütünlüğü hatasıdır ve sessizce geçilmemeli.
     */
    private function defaultWarehouseId(Request $request): string
    {
        $tenant = $request->attributes->get('tenant');

        $warehouse = $tenant?->defaultWarehouse();

        abort_if($warehouse === null, 409, 'Kiracının varsayılan deposu yok.');

        return $warehouse->id;
    }
}
