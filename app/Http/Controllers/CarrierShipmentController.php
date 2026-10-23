<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Fulfillment\Support\FulfillmentPolicy;
use App\Domain\Orders\Models\Order;
use App\Domain\Shipping\Actions\CancelCarrierShipment;
use App\Domain\Shipping\Actions\HandOverCarrierShipment;
use App\Domain\Shipping\Actions\RequestCarrierShipment;
use App\Domain\Shipping\Models\CarrierAccount;
use App\Domain\Shipping\Models\CarrierShipment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * S6 · Kargo II — siparişten satıcının kendi Yurtiçi/Aras sözleşmesiyle gönderi.
 *
 * Kimlikler kontrolcüde kiracı scope'u altında çözülür (`OrderController::show`
 * ile aynı gerekçe): başka kiracının siparişi 404 verir.
 */
final class CarrierShipmentController extends Controller
{
    public function store(Request $request, string $order, RequestCarrierShipment $requestShipment): RedirectResponse
    {
        $data = $request->validate([
            'carrier' => ['required', 'string', Rule::in(CarrierAccount::PROVIDERS)],
        ]);

        $order = Order::query()->findOrFail($order);
        $decision = $requestShipment->run($order, $data['carrier'], $request->user()?->id);

        if ($decision->reason !== null) {
            return back()->withErrors(['shipment' => $decision->reason->label()]);
        }

        return back()->with('success', __('Kargo gönderisi açılıyor.'));
    }

    public function cancel(string $order, CancelCarrierShipment $cancel): RedirectResponse
    {
        $shipment = CarrierShipment::query()->where('order_id', $order)->firstOrFail();

        if (! $cancel->run($shipment)) {
            return back()->withErrors(['shipment' => __('Bu gönderi iptal edilemez (kargoya verilmiş ya da zaten iptal ediliyor).')]);
        }

        return back()->with('success', __('Gönderi iptal ediliyor.'));
    }

    /** Satıcı paketi kuryeye verdi — takip numarası kanala bildirilir. */
    public function handOver(Request $request, string $order, HandOverCarrierShipment $handOver): RedirectResponse
    {
        $shipment = CarrierShipment::query()->where('order_id', $order)->firstOrFail();

        if (! $handOver->run($shipment, $request->user()?->id)) {
            return back()->withErrors(['shipment' => __('Takip numarası henüz yok; firma gönderiyi işleyince kendiliğinden bildirilecek.')]);
        }

        return back()->with('success', __('Kargo bildirimi kanala gönderiliyor.'));
    }

    /** Etiket: `zpl` (termal) ya da `pdf` (firma verdiyse). */
    public function label(string $order, string $format): Response
    {
        abort_unless(in_array($format, ['zpl', 'pdf'], true), 404);

        $shipment = CarrierShipment::query()->where('order_id', $order)->with('order:id,state')->firstOrFail();

        abort_unless(
            $shipment->order !== null
            && in_array($shipment->status, [CarrierShipment::STATUS_CREATED, CarrierShipment::STATUS_SHIPPED], true)
            && FulfillmentPolicy::allows($shipment->order, FulfillmentPolicy::DOWNLOAD_LABEL),
            404,
        );

        $name = 'kargo-'.$shipment->cargo_key;

        if ($format === 'zpl') {
            abort_if($shipment->label_zpl === null, 404);

            return response($shipment->label_zpl, 200, [
                'Content-Type' => 'application/octet-stream',
                'Content-Disposition' => 'attachment; filename="'.$name.'.zpl"',
            ]);
        }

        $pdf = $shipment->label_pdf === null ? false : base64_decode($shipment->label_pdf, true);
        abort_if($pdf === false || ! str_starts_with($pdf, '%PDF'), 404);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$name.'.pdf"',
        ]);
    }
}
