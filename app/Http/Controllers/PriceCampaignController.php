<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Catalog\Actions\CreatePriceCampaign;
use App\Domain\Catalog\Actions\PushCampaignPrices;
use App\Domain\Catalog\Models\PriceCampaign;
use App\Domain\Catalog\Models\Product;
use App\Domain\Channels\Contracts\SupportsPricing;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Registry\AdapterRegistry;
use App\Domain\Identity\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Throwable;

/**
 * Kampanyalar modülü: liste, oluşturma, iptal.
 *
 * Tarihler KİRACININ SAAT DİLİMİNDE girilir ve gösterilir (varsayılan
 * Europe/Istanbul), UTC saklanır. UTC sorulsaydı "Cuma 00:00" kampanyası
 * Türkiye'de 03:00'te başlardı.
 */
final class PriceCampaignController extends Controller
{
    public function __construct(private readonly AdapterRegistry $registry) {}

    public function index(): InertiaResponse
    {
        $timezone = $this->timezone();

        $campaigns = PriceCampaign::query()
            ->withCount(['variants', 'connections'])
            ->orderByDesc('starts_at')
            ->limit(200)
            ->get();

        return Inertia::render('Campaigns/Index', [
            'campaigns' => $campaigns->map(fn (PriceCampaign $c): array => [
                'id' => $c->id,
                'name' => $c->name,
                'discountType' => $c->discount_type,
                'discountValue' => (string) $c->discount_value,
                'startsAt' => $c->starts_at->setTimezone($timezone)->format('d.m.Y H:i'),
                'endsAt' => $c->ends_at->setTimezone($timezone)->format('d.m.Y H:i'),
                'status' => $c->status(),
                'variantCount' => $c->variants_count,
                'connectionCount' => $c->connections_count,
            ])->all(),
        ]);
    }

    public function create(): InertiaResponse
    {
        $timezone = $this->timezone();

        return Inertia::render('Campaigns/Create', [
            'connections' => $this->pricingConnections()->map(fn (ChannelConnection $c): array => [
                'id' => $c->id,
                'label' => $c->label,
                'channel' => $c->channelType?->name ?? $c->channel_type_code,
            ])->values()->all(),
            'timezone' => $timezone,
            // Varsayılan: şimdi → 7 gün sonra, kiracının saatinde.
            'defaultStartsAt' => now($timezone)->format('Y-m-d\TH:i'),
            'defaultEndsAt' => now($timezone)->addDays(7)->format('Y-m-d\TH:i'),
        ]);
    }

    public function store(Request $request, CreatePriceCampaign $create): RedirectResponse
    {
        $connectionIds = $this->pricingConnections()->pluck('id')->all();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'discount_type' => ['required', Rule::in([PriceCampaign::TYPE_PERCENT, PriceCampaign::TYPE_AMOUNT])],
            'discount_value' => ['required', 'numeric', 'gt:0', 'max:9999999'],
            'starts_at' => ['required', 'date_format:Y-m-d\TH:i'],
            'ends_at' => ['required', 'date_format:Y-m-d\TH:i'],
            'show_compare_at' => ['boolean'],
            'product_ids' => ['required', 'array', 'min:1', 'max:5000'],
            'product_ids.*' => ['string'],
            // Yalnız bu kiracının, fiyat gönderen bağlantıları.
            'connection_ids' => ['required', 'array', 'min:1'],
            'connection_ids.*' => ['string', Rule::in($connectionIds)],
        ]);

        if ($validated['discount_type'] === PriceCampaign::TYPE_PERCENT && (float) $validated['discount_value'] >= 100) {
            throw ValidationException::withMessages(['discount_value' => __('Yüzde indirim 100\'den küçük olmalı.')]);
        }

        $timezone = $this->timezone();
        $startsAt = Carbon::createFromFormat('Y-m-d\TH:i', $validated['starts_at'], $timezone)->utc();
        $endsAt = Carbon::createFromFormat('Y-m-d\TH:i', $validated['ends_at'], $timezone)->utc();

        if ($endsAt->lte($startsAt)) {
            throw ValidationException::withMessages(['ends_at' => __('Bitiş başlangıçtan sonra olmalı.')]);
        }

        if ($endsAt->lte(now())) {
            throw ValidationException::withMessages(['ends_at' => __('Bitiş geçmişte olamaz.')]);
        }

        // Ürünler bu kiracıya ait olmalı (global scope); yabancı kimlikler düşer.
        $productIds = Product::query()->whereIn('id', $validated['product_ids'])->pluck('id')->all();

        if ($productIds === []) {
            throw ValidationException::withMessages(['product_ids' => __('En az bir ürün seç.')]);
        }

        $campaign = $create->run(
            tenantId: TenantContext::idOrFail(),
            name: $validated['name'],
            discountType: $validated['discount_type'],
            discountValue: (string) $validated['discount_value'],
            startsAt: $startsAt,
            endsAt: $endsAt,
            showCompareAt: (bool) ($validated['show_compare_at'] ?? true),
            productIds: $productIds,
            connectionIds: $validated['connection_ids'],
            userId: $request->user()?->id,
        );

        return redirect('/campaigns')->with('success', $campaign->status() === PriceCampaign::STATUS_ACTIVE
            ? __(':name başladı; fiyatlar kanallara gönderiliyor.', ['name' => $campaign->name])
            : __(':name planlandı; başlangıçta fiyatlar kendiliğinden gönderilecek.', ['name' => $campaign->name]));
    }

    public function cancel(Request $request, string $campaign, PushCampaignPrices $push): RedirectResponse
    {
        $model = PriceCampaign::query()->findOrFail($campaign);

        if (in_array($model->status(), [PriceCampaign::STATUS_ENDED, PriceCampaign::STATUS_CANCELLED], true)) {
            return back()->with('success', __(':name zaten bitmiş.', ['name' => $model->name]));
        }

        $push->cancel($model, $request->user()?->id);

        return back()->with('success', __(':name iptal edildi; fiyatlar normale dönüyor.', ['name' => $model->name]));
    }

    /** Ürün arama — başlık, SKU ya da barkod; en çok 30 sonuç. */
    public function products(Request $request): JsonResponse
    {
        $term = trim((string) $request->query('q', ''));

        $query = Product::query()
            ->select(['products.id', 'products.title'])
            ->selectSub(
                DB::table('variants')->whereColumn('variants.product_id', 'products.id')->selectRaw('min(sku)'),
                'sku',
            )
            ->orderBy('products.title')
            ->limit(30);

        if ($term !== '') {
            $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $term).'%';
            $query->where(fn ($q) => $q->where('products.title', 'ilike', $like)
                ->orWhereExists(fn ($sub) => $sub->from('variants')
                    ->whereColumn('variants.product_id', 'products.id')
                    ->where(fn ($v) => $v->where('variants.sku', 'ilike', $like)->orWhere('variants.barcode', 'ilike', $like))));
        }

        return response()->json($query->get()->map(fn ($p): array => [
            'id' => $p->id,
            'title' => $p->title,
            'sku' => $p->sku,
        ])->all());
    }

    /** Bu kiracının fiyat gönderebilen bağlantıları. */
    private function pricingConnections()
    {
        return ChannelConnection::query()
            ->with('channelType:code,name,adapter_class')
            ->orderBy('created_at')
            ->get()
            ->filter(function (ChannelConnection $connection): bool {
                try {
                    return $this->registry->for($connection) instanceof SupportsPricing;
                } catch (Throwable) {
                    return false;
                }
            });
    }

    private function timezone(): string
    {
        $timezone = Tenant::query()->whereKey(TenantContext::idOrFail())->value('timezone');

        return is_string($timezone) && $timezone !== '' ? $timezone : 'Europe/Istanbul';
    }
}
