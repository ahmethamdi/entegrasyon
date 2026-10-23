<script setup>
import { computed, ref } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import PageHeader from '../../Components/PageHeader.vue';
import WriteGuard from '../../Components/WriteGuard.vue';
import PanelLayout from '../../Layouts/PanelLayout.vue';
import { useI18n } from '../../lib/i18n';

const { t } = useI18n();

const props = defineProps({
    connection: { type: Object, required: true },
    rule: { type: Object, required: true },
    roundings: { type: Array, default: () => [] },
    fxRoundings: { type: Array, default: () => [] },
    fxCurrencies: { type: Array, default: () => [] },
    channelCurrency: { type: String, default: null },
    fxQuotes: { type: Object, default: () => ({}) },
});

const form = useForm({
    markup_percent: props.rule.markupPercent,
    markup_amount: props.rule.markupAmount,
    rounding: props.rule.rounding,
    min_margin_percent: props.rule.minMarginPercent ?? '',
    fx_enabled: props.rule.fxEnabled ?? false,
    fx_currency: props.rule.fxCurrency ?? '',
    fx_markup_percent: props.rule.fxMarkupPercent ?? '0',
    fx_rounding: props.rule.fxRounding ?? 'none',
    fx_min_price: props.rule.fxMinPrice ?? '',
});

const fxRoundingLabels = {
    none: 'Yuvarlama yok',
    whole: 'Tam sayıya yuvarla (12,31 → 13,00)',
    half: 'Bir üst ,00 ya da ,50 (12,31 → 12,50)',
    x49: ',49 ile bitir (12,31 → 12,49)',
    x90: ',90 ile bitir (12,31 → 12,90)',
    x99: ',99 ile bitir (12,31 → 12,99)',
};

const fxQuote = computed(() => (form.fx_currency ? props.fxQuotes[form.fx_currency] ?? null : null));

// Kanal birimi biliniyorsa ve seçilen hedef farklıysa uyar: fiyat yine gitmez.
const fxCurrencyMismatch = computed(() => form.fx_enabled && props.channelCurrency && form.fx_currency && props.channelCurrency !== form.fx_currency && props.channelCurrency !== 'TRY');

const guard = ref(props.rule.minMarginPercent !== null);

const roundingLabels = {
    none: 'Yuvarlama yok',
    whole: 'Tam sayıya yuvarla (229,89 → 230,00)',
    x90: ',90 ile bitir (229,89 → 229,90)',
    x99: ',99 ile bitir (229,89 → 229,99)',
};

// ÖRNEK HESAP — sunucudaki `PriceRuleCalculator` ile AYNI kuruş hesabı.
// Float ile yapılsaydı ekranda 229,88, kanalda 229,89 görünebilirdi.
const sample = ref('100');
const sampleCost = ref('');

function minor(value) {
    const number = Number(String(value).replace(',', '.'));
    return Number.isFinite(number) ? Math.round(number * 100) : 0;
}

function ceilTo(value, ending) {
    const candidate = Math.trunc(value / 100) * 100 + ending;
    return candidate >= value ? candidate : candidate + 100;
}

function roundUp(value, rounding) {
    if (rounding === 'whole') return ceilTo(value, 0);
    if (rounding === 'half') return Math.trunc((value + 49) / 50) * 50;
    if (rounding === 'x49') return ceilTo(value, 49);
    if (rounding === 'x90') return ceilTo(value, 90);
    if (rounding === 'x99') return ceilTo(value, 99);
    return value;
}

function apply(price) {
    let value = minor(price);
    const basisPoints = minor(form.markup_percent);
    value = Math.trunc((value * (10000 + basisPoints) + 5000) / 10000);
    value += minor(form.markup_amount);
    value = Math.max(value, 0);

    return roundUp(value, form.rounding);
}

// `FxConverter` ile aynı sıra: kur (kuruşa yarım yukarı) → kur payı → yuvarlama.
function convert(valueMinor) {
    if (!fxQuote.value) return null;
    let value = Math.round(valueMinor / Number(fxQuote.value.rate));
    const basisPoints = minor(form.fx_markup_percent);
    value = Math.trunc((value * (10000 + basisPoints) + 5000) / 10000);

    return roundUp(Math.max(value, 0), form.fx_rounding);
}

const sampleConverted = computed(() => (form.fx_enabled && form.fx_currency ? convert(apply(sample.value)) : null));

function format(value) {
    return (value / 100).toFixed(2).replace('.', ',');
}

const sampleResult = computed(() => format(apply(sample.value)));

const sampleFloor = computed(() => {
    if (!guard.value || sampleCost.value === '') return null;
    const basisPoints = minor(form.min_margin_percent || 0);
    return Math.trunc((minor(sampleCost.value) * (10000 + basisPoints) + 9999) / 10000);
});

const sampleBlocked = computed(() => sampleFloor.value !== null && apply(sample.value) < sampleFloor.value);

function submit() {
    form
        .transform((data) => ({
            ...data,
            min_margin_percent: guard.value ? (data.min_margin_percent === '' ? 0 : data.min_margin_percent) : null,
            fx_currency: data.fx_currency === '' ? null : data.fx_currency,
            fx_min_price: data.fx_min_price === '' ? null : data.fx_min_price,
        }))
        .put(`/channels/${props.connection.id}/pricing`, { preserveScroll: true });
}
</script>

<template>
    <PanelLayout>
        <PageHeader
            :section="t('Kanallarım')"
            :title="t(':label fiyat kuralı', { label: connection.label })"
            :description="t(':channel\'e giden her fiyata uygulanır. Ürüne bu kanal için elle fiyat girdiysen o fiyat olduğu gibi gider.', { channel: connection.channel })"
        />
        <WriteGuard permission="channels.manage">

            <form class="mt-8 grid max-w-4xl gap-8 lg:grid-cols-[1fr_18rem]" @submit.prevent="submit">
                <div class="space-y-6">
                    <fieldset>
                        <legend class="text-sm font-medium text-stone-900">{{ t('Kanal farkı') }}</legend>
                        <p class="mt-0.5 text-xs text-stone-500">{{ t('Komisyonu ve kargoyu fiyata yansıtmak için. İndirim için eksi yaz.') }}</p>

                        <div class="mt-3 flex flex-wrap gap-4">
                            <label class="block">
                                <span class="block text-xs text-stone-600">{{ t('Yüzde') }}</span>
                                <span class="mt-1 flex items-center rounded-md border border-stone-300 bg-white focus-within:border-ring">
                                    <span class="pl-3 text-sm text-stone-500">%</span>
                                    <input v-model="form.markup_percent" type="number" step="0.01" min="-90" max="500" inputmode="decimal" class="w-28 rounded-r-md border-0 px-2 py-2 text-sm focus:outline-none">
                                </span>
                            </label>
                            <label class="block">
                                <span class="block text-xs text-stone-600">{{ t('Sabit tutar (yüzdeden sonra eklenir)') }}</span>
                                <input v-model="form.markup_amount" type="number" step="0.01" inputmode="decimal" class="mt-1 w-32 rounded-md border border-stone-300 px-3 py-2 text-sm focus:border-ring focus:outline-none">
                            </label>
                        </div>
                        <p v-if="form.errors.markup_percent" class="mt-1 text-sm text-red-700">{{ form.errors.markup_percent }}</p>
                        <p v-if="form.errors.markup_amount" class="mt-1 text-sm text-red-700">{{ form.errors.markup_amount }}</p>
                    </fieldset>

                    <fieldset>
                        <legend class="text-sm font-medium text-stone-900">{{ t('Yuvarlama') }}</legend>
                        <p class="mt-0.5 text-xs text-stone-500">{{ t('Her zaman yukarı yuvarlar; kârından kırpmaz.') }}</p>
                        <div class="mt-3 space-y-2">
                            <label v-for="option in roundings" :key="option" class="flex items-center gap-2 text-sm text-stone-700">
                                <input v-model="form.rounding" type="radio" :value="option" class="text-stone-900">
                                {{ t(roundingLabels[option] ?? option) }}
                            </label>
                        </div>
                    </fieldset>

                    <fieldset>
                        <legend class="text-sm font-medium text-stone-900">{{ t('Zarar koruması') }}</legend>
                        <label class="mt-2 flex items-start gap-2 text-sm text-stone-700">
                            <input v-model="guard" type="checkbox" class="mt-0.5">
                            <span>{{ t('Alış maliyetinin altına düşen fiyatı bu kanala gönderme') }}</span>
                        </label>
                        <div v-if="guard" class="mt-3 pl-6">
                            <label class="block">
                                <span class="block text-xs text-stone-600">{{ t('En az kâr') }}</span>
                                <span class="mt-1 flex w-36 items-center rounded-md border border-stone-300 bg-white focus-within:border-ring">
                                    <span class="pl-3 text-sm text-stone-500">%</span>
                                    <input v-model="form.min_margin_percent" type="number" step="0.01" min="-100" inputmode="decimal" placeholder="0" class="w-24 rounded-r-md border-0 px-2 py-2 text-sm focus:outline-none">
                                </span>
                            </label>
                            <p class="mt-1 text-xs text-stone-500">{{ t('Maliyeti ürün düzenleme ekranından girersin. Maliyeti girilmemiş ürün denetlenmez. Durdurulan fiyat kanala gitmez, nedeni ürünün Kanallar sayfasında yazar.') }}</p>
                            <p v-if="form.errors.min_margin_percent" class="mt-1 text-sm text-red-700">{{ form.errors.min_margin_percent }}</p>
                        </div>
                    </fieldset>

                    <fieldset>
                        <legend class="text-sm font-medium text-stone-900">{{ t('Kur çevirisi') }}</legend>
                        <p class="mt-0.5 text-xs text-stone-500">{{ t('Ürün fiyatın TL ise ve bu kanal başka para biriminde satıyorsa fiyatı TCMB döviz alış kuruyla çevirir. Sıra: yukarıdaki kanal farkı ve yuvarlama, sonra kur, sonra kur payı, en son kur yuvarlaması.') }}</p>
                        <label class="mt-2 flex items-start gap-2 text-sm text-stone-700">
                            <input v-model="form.fx_enabled" type="checkbox" class="mt-0.5">
                            <span>{{ t('Fiyatı kanalın para birimine çevir') }}</span>
                        </label>
                        <div v-if="form.fx_enabled" class="mt-3 space-y-4 pl-6">
                            <label class="block">
                                <span class="block text-xs text-stone-600">{{ t('Kanalın para birimi') }}</span>
                                <select v-model="form.fx_currency" class="mt-1 w-40 rounded-md border border-stone-300 px-3 py-2 text-sm focus:border-ring focus:outline-none">
                                    <option value="">{{ t('Seç') }}</option>
                                    <option v-for="code in fxCurrencies" :key="code" :value="code">{{ code }}</option>
                                </select>
                            </label>
                            <p v-if="form.errors.fx_currency" class="text-sm text-red-700">{{ form.errors.fx_currency }}</p>
                            <p v-if="fxCurrencyMismatch" class="rounded bg-amber-50 px-2 py-1 text-xs text-amber-900">
                                {{ t('Kanal fiyatları :channel kabul ediyor; :chosen seçersen fiyat gönderilmez.', { channel: channelCurrency, chosen: form.fx_currency }) }}
                            </p>
                            <p v-if="form.fx_currency && fxQuote" class="text-xs text-stone-600">
                                {{ t('Fiyatlama kuru: 1 :currency = :rate TL (TCMB, :date). Kur %0,5\'ten fazla değişince fiyatlar kendiliğinden yeniden gönderilir.', { currency: form.fx_currency, rate: Number(fxQuote.rate).toFixed(4).replace('.', ','), date: fxQuote.date.split('-').reverse().join('.') }) }}
                            </p>
                            <p v-else-if="form.fx_currency" class="rounded bg-amber-50 px-2 py-1 text-xs text-amber-900">
                                {{ t(':currency için güncel kur yok; kur gelene kadar bu kanala fiyat gönderilmez.', { currency: form.fx_currency }) }}
                            </p>

                            <label class="block">
                                <span class="block text-xs text-stone-600">{{ t('Kur payı (komisyon, yurt dışı kargo)') }}</span>
                                <span class="mt-1 flex w-36 items-center rounded-md border border-stone-300 bg-white focus-within:border-ring">
                                    <span class="pl-3 text-sm text-stone-500">%</span>
                                    <input v-model="form.fx_markup_percent" type="number" step="0.01" min="0" max="500" inputmode="decimal" class="w-24 rounded-r-md border-0 px-2 py-2 text-sm focus:outline-none">
                                </span>
                            </label>
                            <p v-if="form.errors.fx_markup_percent" class="text-sm text-red-700">{{ form.errors.fx_markup_percent }}</p>

                            <div>
                                <span class="block text-xs text-stone-600">{{ t('Kur yuvarlaması (kanalın para biriminde, her zaman yukarı)') }}</span>
                                <div class="mt-2 space-y-2">
                                    <label v-for="option in fxRoundings" :key="option" class="flex items-center gap-2 text-sm text-stone-700">
                                        <input v-model="form.fx_rounding" type="radio" :value="option" class="text-stone-900">
                                        {{ t(fxRoundingLabels[option] ?? option) }}
                                    </label>
                                </div>
                            </div>

                            <label class="block">
                                <span class="block text-xs text-stone-600">{{ t('En düşük fiyat (isteğe bağlı, kanalın para biriminde)') }}</span>
                                <input v-model="form.fx_min_price" type="number" step="0.01" min="0" inputmode="decimal" class="mt-1 w-36 rounded-md border border-stone-300 px-3 py-2 text-sm focus:border-ring focus:outline-none">
                                <span class="mt-1 block text-xs text-stone-500">{{ t('Çevrilmiş fiyat bunun altındaysa kanala gönderilmez.') }}</span>
                            </label>
                            <p v-if="form.errors.fx_min_price" class="text-sm text-red-700">{{ form.errors.fx_min_price }}</p>
                        </div>
                    </fieldset>

                    <div class="flex items-center gap-3">
                        <button
                            type="submit"
                            :disabled="form.processing"
                            class="rounded-md bg-stone-900 px-4 py-2 text-sm font-medium text-white transition hover:bg-stone-700 disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            {{ form.processing ? t('Kaydediliyor…') : t('Kaydet') }}
                        </button>
                        <Link href="/channels" class="text-sm text-stone-600 underline">{{ t('Vazgeç') }}</Link>
                    </div>
                </div>

                <aside class="h-fit rounded-lg border border-stone-200 bg-stone-50 p-4">
                    <h2 class="text-sm font-medium text-stone-900">{{ t('Örnek hesap') }}</h2>
                    <label class="mt-3 block">
                        <span class="block text-xs text-stone-600">{{ t('Ürün fiyatı') }}</span>
                        <input v-model="sample" type="number" step="0.01" min="0" inputmode="decimal" class="mt-1 w-full rounded-md border border-stone-300 px-3 py-2 text-sm focus:border-ring focus:outline-none">
                    </label>
                    <label v-if="guard" class="mt-3 block">
                        <span class="block text-xs text-stone-600">{{ t('Alış maliyeti') }}</span>
                        <input v-model="sampleCost" type="number" step="0.01" min="0" inputmode="decimal" class="mt-1 w-full rounded-md border border-stone-300 px-3 py-2 text-sm focus:border-ring focus:outline-none">
                    </label>
                    <p class="mt-4 text-xs text-stone-600">{{ t(':channel\'e giden fiyat', { channel: connection.channel }) }}</p>
                    <p class="text-2xl font-semibold tabular-nums text-stone-900">{{ sampleResult }}<span v-if="form.fx_enabled && form.fx_currency" class="ml-1 text-sm font-normal text-stone-500">TL</span></p>
                    <template v-if="form.fx_enabled && form.fx_currency">
                        <p class="mt-3 text-xs text-stone-600">{{ t('Kanala giden (:currency)', { currency: form.fx_currency }) }}</p>
                        <p v-if="sampleConverted !== null" class="text-2xl font-semibold tabular-nums text-stone-900">{{ format(sampleConverted) }} <span class="text-sm font-normal text-stone-500">{{ form.fx_currency }}</span></p>
                        <p v-else class="text-sm text-amber-900">{{ t('Kur yok — gönderilmez') }}</p>
                        <p v-if="sampleConverted !== null && form.fx_min_price !== '' && sampleConverted < minor(form.fx_min_price)" class="mt-2 rounded bg-red-50 px-2 py-1 text-xs text-red-900">
                            {{ t('En düşük fiyatın altında — gönderilmez.') }}
                        </p>
                    </template>
                    <p v-if="sampleBlocked" class="mt-2 rounded bg-red-50 px-2 py-1 text-xs text-red-900">
                        {{ t('Taban :floor — bu fiyat gönderilmez.', { floor: format(sampleFloor) }) }}
                    </p>
                    <p v-else-if="sampleFloor !== null" class="mt-2 text-xs text-emerald-800">
                        {{ t('Taban :floor — gönderilir.', { floor: format(sampleFloor) }) }}
                    </p>
                </aside>
            </form>
        </WriteGuard>
    </PanelLayout>
</template>
