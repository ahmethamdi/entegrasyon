<script setup>
import { Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import PageHeader from '../../Components/PageHeader.vue';
import PanelLayout from '../../Layouts/PanelLayout.vue';
import { money } from '../../lib/format.js';
import { useI18n } from '../../lib/i18n';
import { usePermissions } from '../../lib/permissions.js';

const props = defineProps({
    variants: { type: Object, required: true },
    filters: { type: Object, default: () => ({ filter: 'missing', search: '' }) },
    counts: { type: Object, default: () => ({ missing: 0, all: 0 }) },
});

const { t } = useI18n();
const { can } = usePermissions();
const editable = computed(() => can('catalog.manage'));
const search = ref(props.filters.search ?? '');

/* Girilen değerler — yalnız DEĞİŞEN satırlar gönderilir. */
const values = ref({});
function reset() {
    values.value = Object.fromEntries(props.variants.data.map((v) => [v.id, v.cost ?? '']));
}
reset();
watch(() => props.variants.data, reset);

const dirty = computed(() => props.variants.data.filter((v) => String(values.value[v.id] ?? '') !== String(v.cost ?? '')));
const invalid = computed(() => dirty.value.some((v) => {
    const raw = String(values.value[v.id] ?? '').replace(',', '.').trim();
    return raw !== '' && (!Number.isFinite(Number(raw)) || Number(raw) < 0);
}));

const form = useForm({ costs: [] });
function save() {
    form.costs = dirty.value.map((v) => {
        const raw = String(values.value[v.id] ?? '').replace(',', '.').trim();
        return { id: v.id, cost: raw === '' ? null : raw };
    });
    form.post('/products/costs', { preserveScroll: true });
}

function go(params) {
    router.get('/products/costs', { filter: props.filters.filter, search: search.value, ...params }, { preserveState: true, replace: true });
}

/* Marj ipucu: girilen maliyetle satış fiyatı arasındaki pay (KDV dahil, kesintiler hariç). */
function margin(v) {
    const cost = Number(String(values.value[v.id] ?? '').replace(',', '.'));
    const price = Number(v.price);
    if (!String(values.value[v.id] ?? '').trim() || !Number.isFinite(cost) || !(price > 0)) return null;
    return Math.round(((price - cost) / price) * 100);
}
</script>

<template>
    <PanelLayout>
        <PageHeader :section="t('Ürünler')" :title="t('Alış maliyetleri')" />

        <p class="mt-1 max-w-3xl text-[13px] leading-relaxed text-stone-600">
            {{ t('Ürünün sana kaça mal olduğunu (KDV dahil) gir. Kâr paneli ve zarar koruması bu rakamı kullanır; kanala gönderilmez. Yeni siparişler girilen maliyetle hesaplanır, geçmiş siparişler satış anındaki maliyetle kalır.') }}
        </p>

        <div class="mt-5 flex flex-wrap items-center gap-2">
            <div class="flex rounded-[10px] border border-stone-200 bg-white p-0.5 text-[13px]" role="tablist">
                <button type="button" role="tab" :aria-selected="filters.filter === 'missing'" class="whitespace-nowrap rounded-lg px-3 py-1.5 font-medium" :class="filters.filter === 'missing' ? 'bg-stone-900 text-white' : 'text-stone-700 hover:bg-stone-100'" @click="go({ filter: 'missing', page: 1 })">
                    {{ t('Maliyeti boş') }} <span class="tabular-nums opacity-70">{{ counts.missing }}</span>
                </button>
                <button type="button" role="tab" :aria-selected="filters.filter === 'all'" class="whitespace-nowrap rounded-lg px-3 py-1.5 font-medium" :class="filters.filter === 'all' ? 'bg-stone-900 text-white' : 'text-stone-700 hover:bg-stone-100'" @click="go({ filter: 'all', page: 1 })">
                    {{ t('Tümü') }} <span class="tabular-nums opacity-70">{{ counts.all }}</span>
                </button>
            </div>
            <form class="flex gap-2" @submit.prevent="go({ page: 1 })">
                <label class="sr-only" for="cost-search">{{ t('Ürün adı ya da SKU ara') }}</label>
                <input id="cost-search" v-model="search" type="search" :placeholder="t('Ürün adı ya da SKU ara')" class="h-9 w-56 rounded-[10px] border border-stone-300 px-3 text-sm" />
                <button type="submit" class="h-9 rounded-[10px] border border-stone-300 px-3 text-sm font-medium text-stone-700 hover:bg-stone-100">{{ t('Ara') }}</button>
            </form>
        </div>

        <section class="mt-4 rounded-[14px] border border-stone-200 bg-white">
            <div v-if="variants.data.length === 0" class="px-5 py-10 text-center">
                <p class="text-sm text-stone-700">{{ filters.filter === 'missing' && !filters.search ? t('Bütün ürünlerin maliyeti girilmiş.') : t('Eşleşen ürün yok.') }}</p>
                <Link href="/reports/profit" class="mt-2 inline-block text-[13px] font-semibold text-brand-700">{{ t('Kâr paneli') }} →</Link>
            </div>

            <div v-else class="overflow-x-auto px-5 pb-2">
                <table class="w-full min-w-[640px] text-left text-[13px]">
                    <thead>
                        <tr class="border-b border-stone-200 text-stone-600">
                            <th scope="col" class="py-2.5 pr-3 font-normal">{{ t('Ürün') }}</th>
                            <th scope="col" class="py-2.5 pr-3 font-normal">SKU</th>
                            <th scope="col" class="py-2.5 pr-3 text-right font-normal">{{ t('Satış fiyatı') }}</th>
                            <th scope="col" class="py-2.5 pr-3 text-right font-normal">{{ t('Alış maliyeti') }}</th>
                            <th scope="col" class="py-2.5 text-right font-normal">{{ t('Brüt pay') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="v in variants.data" :key="v.id" class="border-b border-stone-100 last:border-0">
                            <td class="py-2.5 pr-3">
                                <Link :href="`/products/${v.productId}/edit`" class="font-medium text-stone-900 underline-offset-4 hover:underline">{{ v.title }}</Link>
                                <div class="text-xs text-stone-500">
                                    <template v-if="v.options">{{ v.options }}</template>
                                    <template v-if="v.bundle"><template v-if="v.options"> · </template>{{ t('Set') }}</template>
                                    <template v-if="v.brand"><template v-if="v.options || v.bundle"> · </template>{{ v.brand }}</template>
                                </div>
                            </td>
                            <td class="py-2.5 pr-3 text-stone-700">{{ v.sku }}</td>
                            <td class="py-2.5 pr-3 text-right tabular-nums text-stone-700">{{ money(v.price, v.currency) }}</td>
                            <td class="py-2.5 pr-3 text-right">
                                <label class="sr-only" :for="`cost-${v.id}`">{{ t(':sku alış maliyeti', { sku: v.sku }) }}</label>
                                <input
                                    :id="`cost-${v.id}`"
                                    v-model="values[v.id]"
                                    type="text"
                                    inputmode="decimal"
                                    :disabled="!editable"
                                    placeholder="0,00"
                                    class="h-9 w-28 rounded-[10px] border border-stone-300 px-3 text-right text-sm tabular-nums disabled:bg-stone-50"
                                    :class="String(values[v.id] ?? '') !== String(v.cost ?? '') ? 'border-brand-500 ring-1 ring-brand-500' : ''"
                                />
                            </td>
                            <td class="py-2.5 text-right tabular-nums" :class="margin(v) === null ? 'text-stone-400' : margin(v) < 0 ? 'font-medium text-brand-700' : 'text-stone-600'">
                                {{ margin(v) === null ? '—' : `%${margin(v)}` }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-if="variants.last_page > 1" class="flex items-center justify-between border-t border-stone-100 px-5 py-3 text-[13px] text-stone-600">
                <span>{{ t(':from–:to / :total', { from: variants.from, to: variants.to, total: variants.total }) }}</span>
                <span class="flex gap-2">
                    <button type="button" class="rounded-lg border border-stone-300 px-3 py-1 disabled:opacity-40" :disabled="!variants.prev_page_url || dirty.length > 0" @click="go({ page: variants.current_page - 1 })">{{ t('Önceki') }}</button>
                    <button type="button" class="rounded-lg border border-stone-300 px-3 py-1 disabled:opacity-40" :disabled="!variants.next_page_url || dirty.length > 0" @click="go({ page: variants.current_page + 1 })">{{ t('Sonraki') }}</button>
                </span>
            </div>
        </section>

        <p class="mt-2 text-xs text-stone-500">{{ t('Brüt pay = (satış fiyatı − maliyet) / satış fiyatı. Komisyon ve kargo hariçtir; asıl katkı kâr panelindedir.') }}</p>

        <!-- kaydet çubuğu -->
        <div v-if="editable && dirty.length" class="sticky bottom-4 mt-4 flex flex-wrap items-center justify-between gap-3 rounded-[14px] border border-stone-300 bg-white px-5 py-3">
            <span class="text-sm text-stone-700">
                {{ t(':count satır değişti', { count: dirty.length }) }}
                <span v-if="invalid" class="ml-2 font-medium text-brand-700">{{ t('Geçersiz tutar var: yalnız sayı girin.') }}</span>
            </span>
            <span class="flex gap-2">
                <button type="button" class="h-9 rounded-[10px] border border-stone-300 px-4 text-sm font-medium text-stone-700 hover:bg-stone-100" @click="reset">{{ t('Vazgeç') }}</button>
                <button type="button" class="h-9 rounded-[10px] bg-stone-900 px-4 text-sm font-semibold text-white hover:bg-stone-700 disabled:opacity-50" :disabled="invalid || form.processing" @click="save">{{ t('Kaydet') }}</button>
            </span>
        </div>
    </PanelLayout>
</template>
