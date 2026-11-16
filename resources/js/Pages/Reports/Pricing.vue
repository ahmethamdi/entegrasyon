<script setup>
import { Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import PageHeader from '../../Components/PageHeader.vue';
import PanelLayout from '../../Layouts/PanelLayout.vue';
import { brandIcon, channelName, money } from '../../lib/format.js';
import { k, useI18n } from '../../lib/i18n';

const props = defineProps({
    connections: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({ connection: '', target: '15' }) },
    report: { type: Object, default: null },
    maxListings: { type: Number, default: 2000 },
});

const { t } = useI18n();
const connection = ref(props.filters.connection);
const target = ref(props.filters.target);

function apply() {
    router.get('/reports/pricing', { connection: connection.value, target: String(target.value).replace(',', '.') }, { preserveState: true, replace: true });
}

const status = ref('attention');
const tabs = [
    ['attention', k('Dikkat isteyen')],
    ['loss', k('Zararına')],
    ['below_target', k('Hedefin altında')],
    ['unknown', k('Hesaplanamayan')],
    ['all', k('Tümü')],
];

const rows = computed(() => {
    const all = props.report?.rows ?? [];
    if (status.value === 'all') return all;
    if (status.value === 'attention') return all.filter((r) => r.status === 'loss' || r.status === 'below_target');
    return all.filter((r) => r.status === status.value);
});

const statusText = { loss: k('Zararına'), below_target: k('Hedef altı'), ok: k('Tamam'), unknown: k('Eksik') };
const statusClass = {
    loss: 'bg-brand-50 text-brand-800 border-brand-200',
    below_target: 'bg-amber-50 text-amber-900 border-amber-200',
    ok: 'bg-emerald-50 text-emerald-800 border-emerald-200',
    unknown: 'bg-stone-50 text-stone-600 border-stone-200',
};
const missingText = { cost: k('maliyet yok'), commission: k('komisyon yok'), price: k('fiyat yok') };

const ruleMissing = computed(() => props.report && props.report.rule.commissionPercent === null);
</script>

<template>
    <PanelLayout>
        <PageHeader :section="t('Finans')" :title="t('Fiyat ve kâr')" />

        <p class="mt-1 max-w-3xl text-[13px] leading-relaxed text-stone-600">
            {{ t('Kanaldaki her ürünün bir adet satıldığında sana ne bıraktığı: fiyat − komisyon − kargo − paketleme − alış maliyeti (KDV dahil). Zararsız fiyat ve hedef kârın için önerilen fiyat yanında yazar. Bu ekran fiyatı değiştirmez.') }}
        </p>

        <div v-if="!connections.length" class="mt-6 rounded-[14px] border border-stone-200 bg-white px-5 py-8 text-center">
            <p class="text-sm text-stone-700">{{ t('Henüz bağlı kanalın yok.') }}</p>
        </div>

        <template v-else>
            <form class="mt-5 flex flex-wrap items-end gap-3" @submit.prevent="apply">
                <label class="text-[13px] text-stone-700">
                    {{ t('Kanal') }}
                    <select v-model="connection" class="mt-1 block h-9 rounded-[10px] border border-stone-300 px-3 text-sm" @change="apply">
                        <option v-for="c in connections" :key="c.id" :value="c.id">{{ c.label || channelName(c.channel) }} · {{ channelName(c.channel) }}</option>
                    </select>
                </label>
                <label class="text-[13px] text-stone-700">
                    {{ t('Hedef kâr (%)') }}
                    <input v-model="target" type="text" inputmode="decimal" class="mt-1 block h-9 w-24 rounded-[10px] border border-stone-300 px-3 text-right text-sm tabular-nums" />
                </label>
                <button type="submit" class="h-9 rounded-[10px] bg-stone-900 px-4 text-sm font-semibold text-white hover:bg-stone-700">{{ t('Hesapla') }}</button>
            </form>

            <template v-if="report">
                <!-- kesinti varsayılanı -->
                <div class="mt-4 flex flex-wrap items-center gap-x-5 gap-y-1 rounded-[14px] border px-5 py-3 text-[13px]" :class="ruleMissing ? 'border-amber-200 bg-amber-50 text-amber-950' : 'border-stone-200 bg-white text-stone-700'">
                    <template v-if="ruleMissing">
                        <span>{{ t('Bu kanalın komisyon yüzdesi girilmemiş; katkı hesaplanamıyor.') }}</span>
                        <Link href="/settings/charge-rules" class="ml-auto rounded-[10px] border border-amber-300 bg-white px-3 py-1.5 font-medium hover:bg-amber-100">{{ t('Kesinti varsayılanı gir') }}</Link>
                    </template>
                    <template v-else>
                        <span>{{ t('Komisyon') }} <strong class="tabular-nums">%{{ report.rule.commissionPercent }}</strong><template v-if="report.rule.categoryExceptions"> · {{ t(':count kategori istisnası', { count: report.rule.categoryExceptions }) }}</template></span>
                        <span>{{ t('Kargo') }} <strong class="tabular-nums">{{ report.rule.shippingFee === null ? '—' : money(report.rule.shippingFee) }}</strong></span>
                        <span>{{ t('Paketleme') }} <strong class="tabular-nums">{{ report.rule.packagingFee === null ? '—' : money(report.rule.packagingFee) }}</strong></span>
                        <Link href="/settings/charge-rules" class="ml-auto text-[13px] font-semibold text-brand-700">{{ t('Değiştir') }}</Link>
                    </template>
                </div>

                <!-- kartlar -->
                <div class="mt-4 grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-4">
                    <div class="flex flex-col gap-1.5 rounded-[14px] border border-stone-200 bg-white p-4 sm:p-5">
                        <span class="text-[13px] text-stone-600">{{ t('Canlı ilan') }}</span>
                        <span class="text-xl font-bold tabular-nums text-stone-900 sm:text-[26px]">{{ report.total }}</span>
                    </div>
                    <div class="flex flex-col gap-1.5 rounded-[14px] border p-4 sm:p-5" :class="report.counts.loss ? 'border-brand-200 bg-brand-50' : 'border-stone-200 bg-white'">
                        <span class="text-[13px]" :class="report.counts.loss ? 'text-brand-800' : 'text-stone-600'">{{ t('Zararına satılan') }}</span>
                        <span class="text-xl font-bold tabular-nums sm:text-[26px]" :class="report.counts.loss ? 'text-brand-800' : 'text-stone-900'">{{ report.counts.loss }}</span>
                    </div>
                    <div class="flex flex-col gap-1.5 rounded-[14px] border border-stone-200 bg-white p-4 sm:p-5">
                        <span class="text-[13px] text-stone-600">{{ t('Hedefin altında') }}</span>
                        <span class="text-xl font-bold tabular-nums sm:text-[26px]" :class="report.counts.below_target ? 'text-amber-800' : 'text-stone-900'">{{ report.counts.below_target }}</span>
                    </div>
                    <div class="flex flex-col gap-1.5 rounded-[14px] border border-stone-200 bg-white p-4 sm:p-5">
                        <span class="text-[13px] text-stone-600">{{ t('Hesaplanamayan') }}</span>
                        <span class="text-xl font-bold tabular-nums text-stone-500 sm:text-[26px]">{{ report.counts.unknown }}</span>
                    </div>
                </div>

                <p v-if="report.truncated" class="mt-3 text-sm text-amber-800">{{ t('Bu kanalda :max ilandan fazlası var; ilk :max ilan hesaplandı.', { max: maxListings }) }}</p>

                <section class="mt-4 rounded-[14px] border border-stone-200 bg-white">
                    <div class="flex flex-wrap gap-2 px-5 pt-5">
                        <div class="flex flex-wrap rounded-[10px] border border-stone-200 bg-white p-0.5 text-[13px]" role="tablist">
                            <button v-for="[key, label] in tabs" :key="key" type="button" role="tab" :aria-selected="status === key" class="whitespace-nowrap rounded-lg px-3 py-1.5 font-medium" :class="status === key ? 'bg-stone-900 text-white' : 'text-stone-700 hover:bg-stone-100'" @click="status = key">{{ t(label) }}</button>
                        </div>
                    </div>
                    <div class="overflow-x-auto px-5 pb-3">
                        <table class="mt-2 w-full min-w-[720px] text-left text-[13px]">
                            <thead>
                                <tr class="border-b border-stone-200 text-stone-600">
                                    <th scope="col" class="py-2.5 pr-2 font-normal">{{ t('Ürün') }}</th>
                                    <th scope="col" class="py-2.5 pr-2 text-right font-normal">{{ t('Kanal fiyatı') }}</th>
                                    <th scope="col" class="py-2.5 pr-2 text-right font-normal">{{ t('Maliyet') }}</th>
                                    <th scope="col" class="py-2.5 pr-2 text-right font-normal">{{ t('Komisyon') }}</th>
                                    <th scope="col" class="py-2.5 pr-2 text-right font-normal">{{ t('Adet başı katkı') }}</th>
                                    <th scope="col" class="py-2.5 pr-2 text-right font-normal">{{ t('Zararsız fiyat') }}</th>
                                    <th scope="col" class="py-2.5 pr-2 text-right font-normal">{{ t('Hedef için fiyat') }}</th>
                                    <th scope="col" class="py-2.5 text-right font-normal">{{ t('Durum') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="row in rows" :key="row.id" class="border-b border-stone-100 last:border-0">
                                    <td class="py-2.5 pr-3">
                                        <Link v-if="row.productId" :href="`/products/${row.productId}/edit`" class="font-medium text-stone-900 underline-offset-4 hover:underline">{{ row.title }}</Link>
                                        <span v-else class="text-stone-500">{{ row.title ?? '—' }}</span>
                                        <div class="text-xs text-stone-500">{{ row.sku }}</div>
                                    </td>
                                    <td class="py-2.5 pr-2 text-right tabular-nums text-stone-900">{{ money(row.price, row.currency) }}</td>
                                    <td class="py-2.5 pr-2 text-right tabular-nums text-stone-700">{{ row.cost === null ? '—' : money(row.cost, row.currency) }}</td>
                                    <td class="py-2.5 pr-2 text-right tabular-nums text-stone-700">{{ row.commissionPercent === null ? '—' : `%${row.commissionPercent}` }}</td>
                                    <td class="py-2.5 pr-2 text-right tabular-nums">
                                        <template v-if="row.contribution !== null">
                                            <strong :class="Number(row.contribution) < 0 ? 'text-brand-700' : 'text-stone-900'">{{ money(row.contribution, row.currency) }}</strong>
                                            <div v-if="row.margin !== null" class="text-xs" :class="row.margin < 0 ? 'text-brand-700' : 'text-stone-500'">%{{ row.margin }}</div>
                                        </template>
                                        <span v-else class="text-xs text-stone-500">{{ row.missing.map((m) => t(missingText[m])).join(', ') }}</span>
                                    </td>
                                    <td class="py-2.5 pr-2 text-right tabular-nums text-stone-700">{{ row.breakEven === null ? '—' : money(row.breakEven, row.currency) }}</td>
                                    <td class="py-2.5 pr-2 text-right tabular-nums font-medium text-stone-900">{{ row.target === null ? '—' : money(row.target, row.currency) }}</td>
                                    <td class="py-2.5 text-right">
                                        <span class="inline-block whitespace-nowrap rounded-md border px-2 py-0.5 text-xs font-medium" :class="statusClass[row.status]">{{ t(statusText[row.status]) }}</span>
                                    </td>
                                </tr>
                                <tr v-if="rows.length === 0">
                                    <td colspan="8" class="py-6 text-center text-stone-500">{{ status === 'attention' ? t('Zararına ya da hedefin altında ürün yok.') : t('Bu filtrede ürün yok.') }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <p class="px-5 pb-5 text-xs text-stone-500">{{ t('Kargo ve paketleme sipariş başıdır; burada siparişte tek adet varsayılır. Maliyet ürünün bugünkü alış maliyetidir; kanal fiyatı kampanya ve kanal fiyat kuralı uygulanmış hâlidir.') }}</p>
                </section>
            </template>
        </template>
    </PanelLayout>
</template>
