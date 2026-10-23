<script setup>
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import PageHeader from '../../Components/PageHeader.vue';
import WriteGuard from '../../Components/WriteGuard.vue';
import PanelLayout from '../../Layouts/PanelLayout.vue';
import { intlLocale, k, useI18n } from '../../lib/i18n';

const props = defineProps({
    source: { type: Object, required: true },
    sample: { type: Object, default: null },
    runs: { type: Array, default: () => [] },
    roundings: { type: Array, default: () => [] },
    fields: { type: Array, default: () => [] },
});

const { t } = useI18n();
const page = usePage();
const flashSuccess = computed(() => page.props.flash?.success);
const errors = computed(() => page.props.errors ?? {});

const form = useForm({
    name: props.source.name,
    feed_url: '',
    record_path: props.source.recordPath ?? props.sample?.record_path ?? '',
    mapping: {
        variant_path: props.source.mapping.variant_path ?? '',
        fields: { ...props.source.mapping.fields },
        options: [0, 1, 2].map((i) => ({ name: props.source.mapping.options[i]?.name ?? '', path: props.source.mapping.options[i]?.path ?? '' })),
    },
    number_format: props.source.numberFormat,
    currency: props.source.currency,
    stock_policy: props.source.stockPolicy,
    price_policy: props.source.pricePolicy,
    markup_percent: props.source.markupPercent,
    markup_amount: props.source.markupAmount,
    rounding: props.source.rounding,
    create_products: props.source.createProducts,
});

// Alanların kullanıcıya görünen adları — eşleme anahtarları sabit.
const fieldLabels = {
    key: k('Kimlik (zorunlu)'),
    group_key: k('Ana ürün kodu (varyant grubu)'),
    sku: k('Stok kodu (SKU)'),
    barcode: k('Barkod'),
    name: k('Ürün adı'),
    description: k('Açıklama'),
    brand: k('Marka'),
    category: k('Kategori'),
    images: k('Görseller'),
    stock: k('Stok'),
    cost: k('Alış fiyatı'),
    price: k('Satış fiyatı'),
    currency: k('Para birimi'),
};

const fieldHelp = {
    key: k('Tedarikçinin her ürüne verdiği değişmeyen kod. Sıra numarası değil.'),
    group_key: k('Bedenleri/renkleri aynı ürün altında toplamak için. Akışta yoksa boş bırak — isme bakarak gruplamayız.'),
};

const sampleFields = computed(() => props.sample?.fields ?? []);

function samplesFor(path) {
    return sampleFields.value.find((f) => f.path === path)?.samples ?? [];
}

const sampleForm = useForm({ record_path: '', variant_path: '' });

function readSample(recordPath = null) {
    sampleForm.record_path = recordPath ?? '';
    sampleForm.variant_path = form.mapping.variant_path ?? '';
    sampleForm.post(`/suppliers/${props.source.id}/sample`, {
        preserveScroll: true,
        onSuccess: () => {
            if (props.sample?.record_path) {
                form.record_path = props.sample.record_path;
            }

            if (!props.source.mapping.fields.key && props.sample?.number_format_hint) {
                form.number_format = props.sample.number_format_hint;
            }
        },
    });
}

function chooseRecordPath(path) {
    form.record_path = path;
    readSample(path);
}

function save() {
    form.put(`/suppliers/${props.source.id}`, { preserveScroll: true });
}

function post(action, confirmText = null) {
    if (confirmText && !window.confirm(confirmText)) {
        return;
    }

    router.post(`/suppliers/${props.source.id}/${action}`, {}, { preserveScroll: true });
}

function toggleActive(active) {
    router.put(`/suppliers/${props.source.id}/active`, { active }, { preserveScroll: true });
}

function destroy() {
    if (window.confirm(t('Kaynak, bağları ve tur geçmişi silinsin mi? Ürünler ve stok geçmişi kalır.'))) {
        router.delete(`/suppliers/${props.source.id}`);
    }
}

// MARKUP örneği — sunucudaki PriceRuleCalculator::costMarkup ile aynı kuruş hesabı.
const sampleCost = ref('100');

function minor(value) {
    const number = Number(String(value).replace(',', '.'));
    return Number.isFinite(number) ? Math.round(number * 100) : 0;
}

function ceilTo(value, ending) {
    const candidate = Math.trunc(value / 100) * 100 + ending;
    return candidate >= value ? candidate : candidate + 100;
}

const markupResult = computed(() => {
    let value = minor(sampleCost.value);
    value = Math.trunc((value * (10000 + minor(form.markup_percent)) + 5000) / 10000);
    value += minor(form.markup_amount);
    value = Math.max(value, 0);
    if (form.rounding === 'whole') value = ceilTo(value, 0);
    if (form.rounding === 'x90') value = ceilTo(value, 90);
    if (form.rounding === 'x99') value = ceilTo(value, 99);
    return (value / 100).toFixed(2).replace('.', ',');
});

const roundingLabels = {
    none: k('Yuvarlama yok'),
    whole: k('Tam sayıya yuvarla (229,89 → 230,00)'),
    x90: k(',90 ile bitir (229,89 → 229,90)'),
    x99: k(',99 ile bitir (229,89 → 229,99)'),
};

const statuses = {
    running: k('Çalışıyor'),
    complete: k('Tamamlandı'),
    partial: k('Kısmen'),
    fetch_failed: k('İndirilemedi'),
    parse_failed: k('XML okunamadı'),
    validation_failed: k('Eşleme hatalı'),
    failed: k('Yarıda kaldı'),
};

const modes = { preview: k('Önizleme'), apply: k('Uygulama') };

function when(iso) {
    return iso ? new Date(iso).toLocaleString(intlLocale(), { dateStyle: 'short', timeStyle: 'short' }) : '—';
}

const canRun = computed(() => props.source.mappingProblems.length === 0 && !props.source.running);
</script>

<template>
    <PanelLayout>
        <PageHeader
            :section="t('Tedarikçi XML')"
            :title="source.name"
            :description="source.host"
        />
        <WriteGuard permission="catalog.manage">

            <p v-if="flashSuccess" class="mt-6 rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">{{ flashSuccess }}</p>
            <p v-for="key in ['sample', 'mapping', 'run', 'active', 'source']" v-show="errors[key]" :key="key" class="mt-4 rounded-md border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-900">{{ errors[key] }}</p>

            <!-- 1 · Akışı oku -->
            <section class="mt-8 max-w-5xl rounded-lg border border-stone-200 p-5">
                <h2 class="text-base font-semibold text-stone-900">{{ t('1. Akışı oku') }}</h2>
                <p class="mt-1 text-sm text-stone-600">{{ t('Akışın ilk 2 MB\'ı okunur, yalnız yapısı çıkarılır. Hiçbir ürün değişmez.') }}</p>
                <button type="button" :disabled="sampleForm.processing" class="mt-3 rounded-md border border-stone-300 px-4 py-2 text-sm hover:bg-stone-50 disabled:opacity-50" @click="readSample(form.record_path || null)">
                    {{ sampleForm.processing ? t('Okunuyor…') : t('Akışı oku') }}
                </button>

                <div v-if="sample" class="mt-4 space-y-3 text-sm">
                    <p class="text-stone-700">
                        {{ t(':count ürün düğümü bulundu', { count: sample.record_count }) }}
                        <span v-if="sample.truncated" class="text-stone-500">· {{ t('örnek kesik, gerçek sayı daha fazla olabilir') }}</span>
                    </p>
                    <label class="block">
                        <span class="block text-xs text-stone-600">{{ t('Ürün düğümü') }}</span>
                        <select :value="form.record_path" class="mt-1 rounded-md border border-stone-300 px-3 py-2 font-mono text-sm" @change="chooseRecordPath($event.target.value)">
                            <option v-for="candidate in sample.record_paths" :key="candidate.path" :value="candidate.path">{{ candidate.path }} ({{ candidate.count }})</option>
                        </select>
                    </label>
                    <label v-if="sample.variant_paths.length" class="block">
                        <span class="block text-xs text-stone-600">{{ t('Varyant düğümü (ürünün içinde tekrar eden)') }}</span>
                        <select v-model="form.mapping.variant_path" class="mt-1 rounded-md border border-stone-300 px-3 py-2 font-mono text-sm" @change="readSample(form.record_path)">
                            <option value="">{{ t('Yok — her düğüm tek ürün') }}</option>
                            <option v-for="candidate in sample.variant_paths" :key="candidate.path" :value="candidate.path">{{ candidate.path }} ({{ candidate.count }})</option>
                        </select>
                    </label>
                </div>
            </section>

            <form class="mt-6 max-w-5xl space-y-6" @submit.prevent="save">
                <!-- 2 · Alanlar -->
                <section class="rounded-lg border border-stone-200 p-5">
                    <h2 class="text-base font-semibold text-stone-900">{{ t('2. Alanları eşle') }}</h2>
                    <p v-if="!sample" class="mt-1 text-sm text-stone-600">{{ t('Önce akışı oku; alanlar akışın gerçek yapısından seçilir.') }}</p>

                    <div class="mt-4 grid gap-4 md:grid-cols-2">
                        <label v-for="field in fields" :key="field" class="block">
                            <span class="block text-xs font-medium text-stone-700">{{ t(fieldLabels[field]) }}</span>
                            <select v-model="form.mapping.fields[field]" class="mt-1 w-full rounded-md border border-stone-300 px-3 py-2 font-mono text-xs">
                                <option value="">—</option>
                                <option v-if="form.mapping.fields[field] && !sampleFields.some((f) => f.path === form.mapping.fields[field])" :value="form.mapping.fields[field]">{{ form.mapping.fields[field] }}</option>
                                <option v-for="option in sampleFields" :key="option.path" :value="option.path">{{ option.path }}{{ option.samples.length ? ' — ' + option.samples.join(' | ') : '' }}</option>
                            </select>
                            <span v-if="fieldHelp[field]" class="mt-1 block text-xs text-stone-500">{{ t(fieldHelp[field]) }}</span>
                            <span v-if="form.mapping.fields[field] && samplesFor(form.mapping.fields[field]).length" class="mt-1 block truncate text-xs text-stone-500">{{ t('Örnek') }}: {{ samplesFor(form.mapping.fields[field]).join(' · ') }}</span>
                        </label>
                    </div>

                    <h3 class="mt-6 text-sm font-medium text-stone-900">{{ t('Seçenekler (beden, renk)') }}</h3>
                    <div v-for="(option, index) in form.mapping.options" :key="index" class="mt-2 flex flex-wrap gap-2">
                        <input v-model="option.name" type="text" :placeholder="t('Seçenek adı')" maxlength="60" class="w-40 rounded-md border border-stone-300 px-3 py-2 text-sm">
                        <select v-model="option.path" class="min-w-64 rounded-md border border-stone-300 px-3 py-2 font-mono text-xs">
                            <option value="">—</option>
                            <option v-if="option.path && !sampleFields.some((f) => f.path === option.path)" :value="option.path">{{ option.path }}</option>
                            <option v-for="candidate in sampleFields" :key="candidate.path" :value="candidate.path">{{ candidate.path }}{{ candidate.samples.length ? ' — ' + candidate.samples.join(' | ') : '' }}</option>
                        </select>
                    </div>

                    <fieldset class="mt-6">
                        <legend class="text-sm font-medium text-stone-900">{{ t('Sayı biçimi') }}</legend>
                        <p class="mt-0.5 text-xs text-stone-500">{{ t('"12.500" bir akışta on iki bin beş yüz, ötekinde on iki buçuktur. Tahmin etmiyoruz, sen seç.') }}</p>
                        <p v-if="sample?.number_format_hint" class="mt-1 text-xs text-stone-600">{{ t('Örnekte görünen: :format', { format: sample.number_format_hint === 'comma' ? '1.234,56' : '1,234.56' }) }}</p>
                        <label class="mt-2 flex items-center gap-2 text-sm"><input v-model="form.number_format" type="radio" value="comma"> 1.234,56</label>
                        <label class="mt-1 flex items-center gap-2 text-sm"><input v-model="form.number_format" type="radio" value="dot"> 1,234.56</label>
                    </fieldset>

                    <label class="mt-4 block w-40">
                        <span class="block text-xs text-stone-600">{{ t('Akışın para birimi (alan yoksa)') }}</span>
                        <input v-model="form.currency" type="text" maxlength="3" class="mt-1 w-full rounded-md border border-stone-300 px-3 py-2 text-sm uppercase">
                    </label>
                </section>

                <!-- 3 · Politikalar -->
                <section class="rounded-lg border border-stone-200 p-5">
                    <h2 class="text-base font-semibold text-stone-900">{{ t('3. Stok ve fiyat') }}</h2>

                    <fieldset class="mt-4">
                        <legend class="text-sm font-medium text-stone-900">{{ t('Tedarikçinin stoğu') }}</legend>
                        <label class="mt-2 flex items-start gap-2 text-sm">
                            <input v-model="form.stock_policy" type="radio" value="informational" class="mt-1">
                            <span><strong>{{ t('Yalnız bilgi') }}</strong> — {{ t('Tedarikçinin stoğu kaydedilir, benim stoğuma dokunulmaz. Ürünü kendi depomdan satıyorum.') }}</span>
                        </label>
                        <label class="mt-2 flex items-start gap-2 text-sm">
                            <input v-model="form.stock_policy" type="radio" value="authoritative" class="mt-1">
                            <span><strong>{{ t('Stoğum tedarikçininki') }}</strong> — {{ t('Tedarikçi stoğu değiştirdiğinde benim stoğum o sayıya ayarlanır ve kanallara gider. Akışta görünmeyen ürünün stoğu sıfırlanmaz.') }}</span>
                        </label>
                    </fieldset>

                    <fieldset class="mt-6">
                        <legend class="text-sm font-medium text-stone-900">{{ t('Satış fiyatı') }}</legend>
                        <label class="mt-2 flex items-center gap-2 text-sm"><input v-model="form.price_policy" type="radio" value="none"> {{ t('Fiyatıma dokunma') }}</label>
                        <label class="mt-1 flex items-center gap-2 text-sm"><input v-model="form.price_policy" type="radio" value="feed_price"> {{ t('Akıştaki satış fiyatını kullan') }}</label>
                        <label class="mt-1 flex items-center gap-2 text-sm"><input v-model="form.price_policy" type="radio" value="cost_markup"> {{ t('Alış fiyatının üzerine kâr ekle') }}</label>

                        <div v-if="form.price_policy === 'cost_markup'" class="mt-3 grid gap-4 pl-6 md:grid-cols-[1fr_14rem]">
                            <div class="space-y-3">
                                <div class="flex flex-wrap gap-4">
                                    <label class="block">
                                        <span class="block text-xs text-stone-600">{{ t('Alış fiyatının üzerine %') }}</span>
                                        <input v-model="form.markup_percent" type="number" step="0.01" min="0" max="1000" class="mt-1 w-28 rounded-md border border-stone-300 px-3 py-2 text-sm">
                                    </label>
                                    <label class="block">
                                        <span class="block text-xs text-stone-600">{{ t('Sabit tutar (yüzdeden sonra eklenir)') }}</span>
                                        <input v-model="form.markup_amount" type="number" step="0.01" min="0" class="mt-1 w-32 rounded-md border border-stone-300 px-3 py-2 text-sm">
                                    </label>
                                </div>
                                <p class="text-xs text-stone-500">{{ t('Satış fiyatı = alış × (1 + yüzde) + tutar. Alış fiyatı yoksa ya da 0 ise fiyat değişmez.') }}</p>
                                <div class="space-y-1">
                                    <label v-for="option in roundings" :key="option" class="flex items-center gap-2 text-sm">
                                        <input v-model="form.rounding" type="radio" :value="option"> {{ t(roundingLabels[option] ?? option) }}
                                    </label>
                                </div>
                            </div>
                            <aside class="h-fit rounded-md border border-stone-200 bg-stone-50 p-3">
                                <label class="block text-xs text-stone-600">{{ t('Alış fiyatı') }}
                                    <input v-model="sampleCost" type="number" step="0.01" min="0" class="mt-1 w-full rounded-md border border-stone-300 px-2 py-1 text-sm">
                                </label>
                                <p class="mt-2 text-xs text-stone-600">{{ t('Satış fiyatı') }}</p>
                                <p class="text-xl font-semibold tabular-nums">{{ markupResult }}</p>
                            </aside>
                        </div>
                        <p class="mt-2 text-xs text-stone-500">{{ t('Fiyat yalnız tedarikçinin değeri değiştiğinde güncellenir; panelden elle değiştirdiğin fiyat her saat ezilmez. Kanala gönderim, kanalın fiyat izni açıksa olur.') }}</p>
                    </fieldset>

                    <label class="mt-6 flex items-start gap-2 text-sm">
                        <input v-model="form.create_products" type="checkbox" class="mt-1">
                        <span>{{ t('Eşleşmeyen ürünleri yeni ürün olarak aç (plan ürün sınırına sayılır)') }}</span>
                    </label>
                    <p class="mt-1 pl-6 text-xs text-stone-500">{{ t('Eşleşme: önce stok kodu, sonra barkod. İkisi farklı ürünü gösterirse ürün açılmaz, sana sorulur.') }}</p>
                </section>

                <div class="flex items-center gap-3">
                    <label class="block">
                        <span class="sr-only">{{ t('Tedarikçi adı') }}</span>
                        <input v-model="form.name" type="text" maxlength="120" class="rounded-md border border-stone-300 px-3 py-2 text-sm">
                    </label>
                    <button type="submit" :disabled="form.processing" class="rounded-md bg-stone-900 px-4 py-2 text-sm font-medium text-white hover:bg-stone-700 disabled:opacity-50">
                        {{ form.processing ? t('Kaydediliyor…') : t('Kaydet') }}
                    </button>
                </div>
                <p v-for="(message, key) in form.errors" :key="key" class="text-sm text-red-700">{{ message }}</p>
            </form>

            <!-- 4 · Önizle / uygula / saatlik -->
            <section class="mt-8 max-w-5xl rounded-lg border border-stone-200 p-5">
                <h2 class="text-base font-semibold text-stone-900">{{ t('4. Önizle ve uygula') }}</h2>
                <p v-if="source.mappingProblems.length" class="mt-2 text-sm text-amber-900">{{ t('Eksik eşleme: :fields', { fields: source.mappingProblems.join(', ') }) }}</p>
                <div class="mt-3 flex flex-wrap gap-3">
                    <button type="button" :disabled="!canRun" class="rounded-md border border-stone-300 px-4 py-2 text-sm hover:bg-stone-50 disabled:opacity-50" @click="post('preview')">
                        {{ t('Önizle (hiçbir şey değişmez)') }}
                    </button>
                    <button type="button" :disabled="!canRun" class="rounded-md bg-stone-900 px-4 py-2 text-sm font-medium text-white hover:bg-stone-700 disabled:opacity-50" @click="post('apply', t('Akış şimdi uygulansın mı? Önce önizlemeyi gördüğünden emin ol.'))">
                        {{ t('Uygula') }}
                    </button>
                    <button v-if="!source.isActive" type="button" class="rounded-md border border-emerald-600 px-4 py-2 text-sm text-emerald-800 hover:bg-emerald-50" @click="toggleActive(true)">
                        {{ t('Saatlik güncellemeyi aç') }}
                    </button>
                    <button v-else type="button" class="rounded-md border border-stone-300 px-4 py-2 text-sm hover:bg-stone-50" @click="toggleActive(false)">
                        {{ t('Saatlik güncellemeyi durdur') }}
                    </button>
                </div>
                <p class="mt-2 text-xs text-stone-500">
                    {{ source.isActive ? t('Sonraki güncelleme: :at', { at: when(source.nextRunAt) }) : t('Saatlik güncelleme kapalı. Başarılı bir uygulamadan sonra açabilirsin.') }}
                </p>

                <table v-if="runs.length" class="mt-5 min-w-full divide-y divide-stone-200 text-sm">
                    <thead class="text-left text-xs text-stone-600">
                        <tr>
                            <th class="py-2 pr-4 font-medium">{{ t('Zaman') }}</th>
                            <th class="py-2 pr-4 font-medium">{{ t('Tür') }}</th>
                            <th class="py-2 pr-4 font-medium">{{ t('Sonuç') }}</th>
                            <th class="py-2 pr-4 font-medium">{{ t('Kayıt') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100">
                        <tr v-for="run in runs" :key="run.id">
                            <td class="py-2 pr-4 tabular-nums"><Link :href="`/suppliers/${source.id}/runs/${run.id}`" class="underline">{{ when(run.startedAt) }}</Link></td>
                            <td class="py-2 pr-4">{{ t(modes[run.mode] ?? run.mode) }}<span v-if="run.trigger === 'schedule'" class="text-stone-500"> · {{ t('saatlik') }}</span></td>
                            <td class="py-2 pr-4">{{ t(statuses[run.status] ?? run.status) }}<span v-if="run.reason" class="text-stone-500"> · {{ run.reason }}</span></td>
                            <td class="py-2 pr-4 tabular-nums">{{ run.recordsSeen }}</td>
                        </tr>
                    </tbody>
                </table>
            </section>

            <div class="mt-8">
                <button type="button" class="text-sm text-red-700 underline" @click="destroy">{{ t('Kaynağı sil') }}</button>
            </div>
        </WriteGuard>
    </PanelLayout>
</template>
