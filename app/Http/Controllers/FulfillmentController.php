<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Fulfillment\Actions\StartFulfillmentBatch;
use App\Domain\Fulfillment\Exceptions\SelectionInvalid;
use App\Domain\Fulfillment\Models\FulfillmentBatch;
use App\Domain\Fulfillment\Models\ShippingLabel;
use App\Domain\Fulfillment\Support\BatchResults;
use App\Domain\Fulfillment\Support\FulfillmentPolicy;
use App\Domain\Fulfillment\Support\LabelDocuments;
use App\Domain\Fulfillment\Support\PickingList;
use App\Domain\Orders\Models\Order;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * S2 · Depo akışı — toplu etiket, fatura belgesi, toplama listesi.
 *
 * Kimlikler kontrolcüde kiracı scope'u altında çözülür (bkz.
 * `OrderController::show`): başka kiracının toplu işlemi 404 verir.
 */
final class FulfillmentController extends Controller
{
    public function store(Request $request, StartFulfillmentBatch $start): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', 'string', Rule::in(FulfillmentBatch::ACTIONS)],
            'order_ids' => ['required', 'array', 'min:1', 'max:'.StartFulfillmentBatch::MAX_ORDERS],
            'order_ids.*' => ['required', 'string', 'max:64'],
            'idempotency_key' => ['required', 'string', 'min:16', 'max:64'],
        ]);

        try {
            $batch = $start->run($data['action'], $data['order_ids'], $data['idempotency_key'], $request->user()?->id);
        } catch (SelectionInvalid) {
            // Başka kiracının siparişi de, olmayan sipariş de AYNI cevabı alır.
            return back()->withErrors(['bulk' => __('Seçimde işlenemeyen sipariş var; listeyi yenileyip yeniden seçin.')]);
        }

        return redirect()->route('fulfillment.batches.show', $batch->id);
    }

    public function show(string $batch, BatchResults $results): InertiaResponse
    {
        $batch = FulfillmentBatch::query()->findOrFail($batch);
        $resolved = $results->for($batch);

        return Inertia::render('Fulfillment/Batch', [
            'batch' => [
                'id' => $batch->id,
                'action' => $batch->action,
                'total' => $batch->total,
                'createdAt' => $batch->created_at?->toIso8601String(),
            ],
            'items' => $resolved['items'],
            'counts' => $resolved['counts'],
            'readyLabels' => $batch->action === FulfillmentBatch::ACTION_LABELS ? $this->readyLabels($batch)->count() : 0,
        ]);
    }

    /** Yazdırılabilir toplama listesi — yalnız toplanabilir (kargo bekleyen) siparişler. */
    public function picking(string $batch, PickingList $picking): InertiaResponse
    {
        $batch = FulfillmentBatch::query()->findOrFail($batch);
        $orders = Order::query()
            ->whereIn('id', $batch->items()->select('order_id'))
            ->get()
            ->filter(fn (Order $o): bool => FulfillmentPolicy::allows($o, FulfillmentPolicy::PICK))
            ->values();

        return Inertia::render('Fulfillment/Picking', [
            'batch' => ['id' => $batch->id, 'createdAt' => $batch->created_at?->toIso8601String()],
            ...$picking->build($orders),
        ]);
    }

    /** Hazır etiketler — termal yazıcı için birleşik ZPL (asıllar dokunulmadan). */
    public function labelsZpl(string $batch, LabelDocuments $documents): Response
    {
        $batch = FulfillmentBatch::query()->findOrFail($batch);
        $zpl = $documents->zplBundle($this->readyLabels($batch));

        if ($zpl === '') {
            return back()->withErrors(['bulk' => __('İndirilecek hazır ZPL etiketi yok.')]);
        }

        return response($zpl, 200, [
            'Content-Type' => 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="etiketler-'.substr($batch->id, -8).'.zpl"',
        ]);
    }

    /** @return Collection<int, ShippingLabel> */
    private function readyLabels(FulfillmentBatch $batch): Collection
    {
        return ShippingLabel::query()
            ->whereIn('order_id', $batch->items()->select('order_id'))
            ->where('status', ShippingLabel::STATUS_READY)
            ->with('order:id,state')
            ->orderBy('created_at')
            ->get()
            ->filter(fn (ShippingLabel $l): bool => $l->order !== null && FulfillmentPolicy::allows($l->order, FulfillmentPolicy::DOWNLOAD_LABEL))
            ->values();
    }
}
