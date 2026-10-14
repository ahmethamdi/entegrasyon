<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Catalog\Models\ChannelPriceRule;
use App\Domain\Suppliers\Actions\StartSupplierSync;
use App\Domain\Suppliers\Enums\RunStatus;
use App\Domain\Suppliers\Enums\SupplierReason;
use App\Domain\Suppliers\Models\SupplierRecord;
use App\Domain\Suppliers\Models\SupplierRunChange;
use App\Domain\Suppliers\Models\SupplierSource;
use App\Domain\Suppliers\Models\SupplierSyncRun;
use App\Domain\Suppliers\Support\FeedMapping;
use App\Domain\Suppliers\Support\FeedStructure;
use App\Domain\Suppliers\Support\SupplierFeedException;
use App\Domain\Suppliers\Support\SupplierFeedFetcher;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Tedarikçi XML kaynakları (S5, ADR-012).
 *
 * AKIŞ: adres → örnek oku (yapı keşfi) → kayıt düğümü + alan seç →
 * politika → ÖNİZLE (hiçbir şey yazmaz, beklenen değişiklikleri sayar) →
 * UYGULA → saatlik güncellemeyi AÇ (yalnız başarılı bir uygulamadan sonra).
 *
 * Kimlikler string alınır ve kiracı scope'u altında `findOrFail` yapılır:
 * başka kiracının ve olmayan kaynağın cevabı AYNI 404.
 *
 * Örnek okuma panel isteğinin içindedir ama SENKRONİZASYON DEĞİLDİR:
 * yalnız ilk 2 MB, 20 sn, yalnız yapı. Tur her zaman kuyrukta koşar.
 */
final class SupplierSourceController extends Controller
{
    private const SAMPLE_TTL_SECONDS = 3600;

    public function index(): InertiaResponse
    {
        $sources = SupplierSource::query()->orderBy('name')->get();

        return Inertia::render('Suppliers/Index', [
            'sources' => $sources->map(fn (SupplierSource $s) => $this->presentSource($s))->values(),
        ]);
    }

    public function create(): InertiaResponse
    {
        return Inertia::render('Suppliers/Create');
    }

    public function store(Request $request, SupplierFeedFetcher $fetcher): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'feed_url' => ['required', 'string', 'max:2000'],
        ]);

        $host = $this->acceptableUrl($fetcher, $validated['feed_url']);

        $source = SupplierSource::query()->create([
            'name' => $validated['name'],
            'feed_url' => trim($validated['feed_url']),
            'feed_host' => $host,
        ]);

        return redirect()->route('suppliers.edit', $source->id)
            ->with('success', __('Kaynak eklendi. Şimdi akışı oku ve alanları seç.'));
    }

    public function edit(string $source): InertiaResponse
    {
        $model = $this->resolve($source);
        $runs = $model->runs()->latest('created_at')->limit(10)->get();

        return Inertia::render('Suppliers/Edit', [
            'source' => $this->presentSource($model) + [
                'recordPath' => $model->record_path,
                'mapping' => [
                    'variant_path' => $model->mapping['variant_path'] ?? '',
                    'fields' => array_merge(array_fill_keys(FeedMapping::FIELDS, ''), (array) ($model->mapping['fields'] ?? [])),
                    'options' => array_values((array) ($model->mapping['options'] ?? [])),
                ],
                'numberFormat' => $model->number_format,
                'currency' => $model->currency,
                'stockPolicy' => $model->stock_policy,
                'pricePolicy' => $model->price_policy,
                'markupPercent' => (string) $model->markup_percent,
                'markupAmount' => (string) $model->markup_amount,
                'rounding' => $model->rounding,
                'createProducts' => $model->create_products,
                'mappingProblems' => FeedMapping::forSource($model)->problems($model),
                'recordCount' => SupplierRecord::query()->where('supplier_source_id', $model->id)->count(),
            ],
            'sample' => Cache::get($this->sampleKey($model)),
            'runs' => $runs->map(fn (SupplierSyncRun $r) => $this->presentRun($r))->values(),
            'roundings' => ChannelPriceRule::ROUNDINGS,
            'fields' => FeedMapping::FIELDS,
        ]);
    }

    public function update(Request $request, string $source, SupplierFeedFetcher $fetcher): RedirectResponse
    {
        $model = $this->resolve($source);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'feed_url' => ['nullable', 'string', 'max:2000'],
            'record_path' => ['nullable', 'string', 'max:255'],
            'mapping.variant_path' => ['nullable', 'string', 'max:255'],
            'mapping.fields' => ['array'],
            'mapping.fields.*' => ['nullable', 'string', 'max:255'],
            'mapping.options' => ['array', 'max:'.FeedMapping::MAX_OPTIONS],
            'mapping.options.*.name' => ['nullable', 'string', 'max:60'],
            'mapping.options.*.path' => ['nullable', 'string', 'max:255'],
            'number_format' => ['required', Rule::in([SupplierSource::NUMBER_DOT, SupplierSource::NUMBER_COMMA])],
            'currency' => ['required', 'string', 'size:3', 'alpha'],
            'stock_policy' => ['required', Rule::in([SupplierSource::STOCK_INFORMATIONAL, SupplierSource::STOCK_AUTHORITATIVE])],
            'price_policy' => ['required', Rule::in([SupplierSource::PRICE_NONE, SupplierSource::PRICE_FEED, SupplierSource::PRICE_COST_MARKUP])],
            'markup_percent' => ['required', 'numeric', 'min:0', 'max:1000'],
            'markup_amount' => ['required', 'numeric', 'min:0', 'max:100000'],
            'rounding' => ['required', Rule::in(ChannelPriceRule::ROUNDINGS)],
            'create_products' => ['boolean'],
        ]);

        $fields = array_intersect_key((array) ($validated['mapping']['fields'] ?? []), array_flip(FeedMapping::FIELDS));
        $mapping = [
            'variant_path' => trim((string) ($validated['mapping']['variant_path'] ?? ''), '/ '),
            'fields' => array_filter(array_map(fn ($v) => trim((string) $v, '/ '), $fields), fn ($v) => $v !== ''),
            'options' => array_values(array_filter(
                (array) ($validated['mapping']['options'] ?? []),
                fn ($o) => trim((string) ($o['name'] ?? '')) !== '' && trim((string) ($o['path'] ?? '')) !== '',
            )),
        ];

        $attributes = [
            'name' => $validated['name'],
            'record_path' => trim((string) ($validated['record_path'] ?? ''), '/ ') ?: null,
            'mapping' => $mapping,
            'number_format' => $validated['number_format'],
            'currency' => strtoupper($validated['currency']),
            'stock_policy' => $validated['stock_policy'],
            'price_policy' => $validated['price_policy'],
            'markup_percent' => $validated['markup_percent'],
            'markup_amount' => $validated['markup_amount'],
            'rounding' => $validated['rounding'],
            'create_products' => (bool) ($validated['create_products'] ?? false),
        ];

        if (($validated['feed_url'] ?? '') !== '') {
            $attributes['feed_url'] = trim($validated['feed_url']);
            $attributes['feed_host'] = $this->acceptableUrl($fetcher, $validated['feed_url']);
        }

        $model->fill($attributes);

        // Eşleme, adres ya da politika değiştiyse saatlik güncelleme KAPANIR:
        // yeni ayarla önce önizleme + uygulama görülmeli.
        $semantic = ['feed_url', 'record_path', 'mapping', 'number_format', 'currency', 'stock_policy', 'price_policy', 'markup_percent', 'markup_amount', 'rounding', 'create_products'];
        $deactivated = $model->is_active && $model->isDirty($semantic);

        if ($deactivated) {
            $model->is_active = false;
            $model->next_run_at = null;
        }

        $model->save();

        return back()->with('success', $deactivated
            ? __('Kaydedildi. Ayarlar değiştiği için saatlik güncelleme durduruldu; önizleyip uyguladıktan sonra yeniden aç.')
            : __('Kaydedildi.'));
    }

    public function sample(Request $request, string $source, SupplierFeedFetcher $fetcher, FeedStructure $structure): RedirectResponse
    {
        $model = $this->resolve($source);
        $validated = $request->validate([
            'record_path' => ['nullable', 'string', 'max:255'],
            'variant_path' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $feed = $fetcher->fetch($model->feed_url, sample: true);
        } catch (SupplierFeedException $e) {
            throw ValidationException::withMessages(['sample' => $e->reason->label().' — '.mb_substr($e->getMessage(), 0, 300)]);
        }

        try {
            $result = $structure->describe(
                $feed->path,
                ($validated['record_path'] ?? null) ?: null,
                ($validated['variant_path'] ?? null) ?: null,
            );
        } finally {
            $feed->discard();
        }

        $result['truncated'] = $feed->truncated;
        $result['bytes'] = $feed->bytes;
        $result['variant_path'] = ($validated['variant_path'] ?? null) ?: null;
        Cache::put($this->sampleKey($model), $result, self::SAMPLE_TTL_SECONDS);

        if ($result['record_path'] === null) {
            throw ValidationException::withMessages(['sample' => __('Akışta tekrarlanan bir ürün düğümü bulunamadı.')]);
        }

        return back();
    }

    public function preview(string $source, StartSupplierSync $start): RedirectResponse
    {
        return $this->startRun($source, $start, SupplierSyncRun::MODE_PREVIEW);
    }

    public function apply(string $source, StartSupplierSync $start): RedirectResponse
    {
        return $this->startRun($source, $start, SupplierSyncRun::MODE_APPLY);
    }

    public function activate(Request $request, string $source): RedirectResponse
    {
        $model = $this->resolve($source);
        $active = $request->boolean('active');

        if ($active) {
            // Saatlik güncelleme yalnız TAM başarılı bir uygulamadan sonra:
            // satıcı önce ne olacağını görmüş ve onaylamış olmalı.
            $lastApply = $model->runs()->where('mode', SupplierSyncRun::MODE_APPLY)->latest('created_at')->first();

            if ($lastApply === null || $lastApply->status !== RunStatus::COMPLETE || $model->last_succeeded_at === null) {
                throw ValidationException::withMessages(['active' => __('Saatlik güncellemeyi açmadan önce akışı başarıyla uygula.')]);
            }
        }

        $model->forceFill([
            'is_active' => $active,
            'next_run_at' => $active ? now()->addMinutes(SupplierSource::INTERVAL_MINUTES) : null,
        ])->save();

        return back()->with('success', $active ? __('Saatlik güncelleme açıldı.') : __('Saatlik güncelleme durduruldu.'));
    }

    public function run(Request $request, string $source, string $run): InertiaResponse
    {
        $model = $this->resolve($source);
        $runModel = SupplierSyncRun::query()->where('supplier_source_id', $model->id)->findOrFail($run);
        $kind = (string) $request->query('kind', '');

        $changes = SupplierRunChange::query()
            ->where('supplier_sync_run_id', $runModel->id)
            ->when($kind !== '', fn ($q) => $q->where('kind', $kind))
            ->orderBy('id')
            ->paginate(100)
            ->withQueryString();

        $kinds = SupplierRunChange::query()
            ->where('supplier_sync_run_id', $runModel->id)
            ->selectRaw('kind, count(*) as total')
            ->groupBy('kind')
            ->pluck('total', 'kind');

        return Inertia::render('Suppliers/Run', [
            'source' => ['id' => $model->id, 'name' => $model->name],
            'run' => $this->presentRun($runModel),
            'kind' => $kind,
            'kinds' => $kinds,
            'changes' => $changes->through(fn (SupplierRunChange $c) => [
                'id' => $c->id,
                'kind' => $c->kind,
                'sourceKey' => $c->source_key,
                'before' => $c->before,
                'after' => $c->after,
                'reasonCode' => $c->reason_code,
                'reason' => $c->reason_code !== null ? SupplierReason::tryFrom($c->reason_code)?->label() : null,
            ]),
        ]);
    }

    public function destroy(string $source): RedirectResponse
    {
        $model = $this->resolve($source);

        if ($model->sync_state === SupplierSource::STATE_RUNNING) {
            throw ValidationException::withMessages(['source' => SupplierReason::SUPPLIER_SYNC_ALREADY_RUNNING->label()]);
        }

        // Ürünler, varyantlar ve stok hareketleri KALIR; yalnız kaynak, bağlar
        // ve tur geçmişi silinir.
        $model->delete();

        return redirect()->route('suppliers.index')->with('success', __('Kaynak silindi. Ürünlerin ve stok geçmişin olduğu gibi duruyor.'));
    }

    // ---------------------------------------------------------------- iç

    private function startRun(string $source, StartSupplierSync $start, string $mode): RedirectResponse
    {
        $model = $this->resolve($source);

        $problems = FeedMapping::forSource($model)->problems($model);

        if ($problems !== []) {
            throw ValidationException::withMessages(['mapping' => SupplierReason::SUPPLIER_MAPPING_INVALID->label().': '.implode(', ', $problems)]);
        }

        try {
            $run = $start->run($model, $mode, SupplierSyncRun::TRIGGER_MANUAL);
        } catch (SupplierFeedException $e) {
            throw ValidationException::withMessages(['run' => $e->reason->label()]);
        }

        return redirect()->route('suppliers.runs.show', [$model->id, $run->id]);
    }

    private function acceptableUrl(SupplierFeedFetcher $fetcher, string $url): string
    {
        try {
            return $fetcher->assertAcceptableUrl($url);
        } catch (SupplierFeedException $e) {
            throw ValidationException::withMessages(['feed_url' => $e->reason->label().': '.$e->getMessage()]);
        }
    }

    private function resolve(string $id): SupplierSource
    {
        return SupplierSource::query()->findOrFail($id);
    }

    private function sampleKey(SupplierSource $source): string
    {
        return 'supplier-sample:'.TenantContext::idOrFail().':'.$source->id;
    }

    /**
     * @return array<string, mixed>
     */
    private function presentSource(SupplierSource $source): array
    {
        return [
            'id' => $source->id,
            'name' => $source->name,
            'host' => $source->feed_host,
            'isActive' => $source->is_active,
            'running' => $source->sync_state === SupplierSource::STATE_RUNNING,
            'lastStatus' => $source->last_status,
            'lastReason' => $source->last_reason_code !== null ? SupplierReason::tryFrom($source->last_reason_code)?->label() : null,
            'lastAttemptedAt' => $source->last_attempted_at?->toIso8601String(),
            'lastSucceededAt' => $source->last_succeeded_at?->toIso8601String(),
            'lastDurationMs' => $source->last_duration_ms,
            'nextRunAt' => $source->next_run_at?->toIso8601String(),
            'stockPolicy' => $source->stock_policy,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentRun(SupplierSyncRun $run): array
    {
        return [
            'id' => $run->id,
            'mode' => $run->mode,
            'trigger' => $run->trigger,
            'status' => $run->status->value,
            'reasonCode' => $run->reason_code,
            'reason' => $run->reason_code !== null ? SupplierReason::tryFrom($run->reason_code)?->label() : null,
            'errorDetail' => $run->error_detail,
            'startedAt' => $run->started_at?->toIso8601String(),
            'finishedAt' => $run->finished_at?->toIso8601String(),
            'durationMs' => $run->duration_ms,
            'bytes' => $run->bytes,
            'recordsSeen' => $run->records_seen,
            'counts' => array_merge(array_fill_keys(SupplierSyncRun::COUNT_KEYS, 0), (array) $run->counts),
        ];
    }
}
