<script setup>
import { computed, ref } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import PageHeader from '../../Components/PageHeader.vue';
import PanelLayout from '../../Layouts/PanelLayout.vue';
import { useI18n } from '../../lib/i18n';

const { t } = useI18n();

const props = defineProps({
    connection: { type: Object, required: true },
    rule: { type: Object, required: true },
    roundings: { type: Array, default: () => [] },
});

const form = useForm({
    markup_percent: props.rule.markupPercent,
    markup_amount: props.rule.markupAmount,
    rounding: props.rule.rounding,
    min_margin_percent: props.rule.minMarginPercent ?? '',
});

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

function apply(price) {
    let value = minor(price);
    const basisPoints = minor(form.markup_percent);
    value = Math.trunc((value * (10000 + basisPoints) + 5000) / 10000);
    value += minor(form.markup_amount);
    value = Math.max(value, 0);

    if (form.rounding === 'whole') value = ceilTo(value, 0);
    if (form.rounding === 'x90') value = ceilTo(value, 90);
    if (form.rounding === 'x99') value = ceilTo(value, 99);

    return value;
}

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
        .transform((data) => ({ ...data, min_margin_percent: guard.value ? (data.min_margin_percent === '' ? 0 : data.min_margin_percent) : null }))
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
                <p class="text-2xl font-semibold tabular-nums text-stone-900">{{ sampleResult }}</p>
                <p v-if="sampleBlocked" class="mt-2 rounded bg-red-50 px-2 py-1 text-xs text-red-900">
                    {{ t('Taban :floor — bu fiyat gönderilmez.', { floor: format(sampleFloor) }) }}
                </p>
                <p v-else-if="sampleFloor !== null" class="mt-2 text-xs text-emerald-800">
                    {{ t('Taban :floor — gönderilir.', { floor: format(sampleFloor) }) }}
                </p>
            </aside>
        </form>
    </PanelLayout>
</template>
