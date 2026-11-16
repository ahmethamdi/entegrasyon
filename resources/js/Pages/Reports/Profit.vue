<script setup>
import { Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import PageHeader from '../../Components/PageHeader.vue';
import PanelLayout from '../../Layouts/PanelLayout.vue';
import { brandIcon, channelName, money } from '../../lib/format.js';
import { k, useI18n } from '../../lib/i18n';

const props = defineProps({
    filters: { type: Object, default: () => ({}) },
    report: { type: Object, default: () => ({ currencies: {}, gaps: {} }) },
    maxOrders: { type: Number, default: 5000 },
});

const { t } = useI18n();
const from = ref(props.filters.from);
const to = ref(props.filters.to);

function apply() {
    router.get('/reports/profit', { from: from.value, to: to.value }, { preserveState: true });
}

function preset(kind) {
    const end = new Date();
    const start = new Date();
    if (kind === '7') start.setDate(end.getDate() - 6);
    if (kind === '30') start.setDate(end.getDate() - 29);
    if (kind === 'month') start.setDate(1);
    const iso = (d) => new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 10);
    from.value = iso(start);
    to.value = iso(end);
    apply();
}

function num(value) {
    const n = Number(value);
    return Number.isFinite(n) ? n : 0;
}

/* Para birimi — en çok siparişli önde; birden fazlaysa seçilir, toplanmaz. */
const currencyKeys = computed(() => Object.keys(props.report.currencies ?? {}));
const currency = ref(currencyKeys.value[0] ?? 'TRY');
const bucket = computed(() => props.report.currencies?.[currency.value] ?? null);

const stepLabels = {
    gross: k('Brüt satış'),
    cancel: k('İptal'),
    refund: k('İade'),
    commission: k('Komisyon'),
    shipping: k('Kargo'),
    other: k('Diğer kesinti'),
    cost: k('Ürün maliyeti'),
};
const stepHints = {
    gross: k('Sipariş satırlarının satış anındaki tutarı'),
    cancel: k('İptal edilen ve parası iade edilmeyen adet'),
    refund: k('Müşteriye geri ödenen'),
    commission: k('Beklenen pazaryeri komisyonu'),
    shipping: k('Beklenen kargo kesintisi'),
    other: k('Hizmet bedeli, stopaj ve benzeri'),
    cost: k('Satış anındaki alış maliyeti'),
};

/* Huni: her basamağın bilinen toplamı + kapsamı. Çubuk brüte göre. */
const funnel = computed(() => {
    const f = bucket.value?.funnel;
    if (!f) return [];
    const gross = Math.max(1, Math.abs(num(f.gross.amount)));
    return Object.keys(stepLabels).map((key) => {
        const step = f[key];
        const coverage = f.orders > 0 ? Math.round((step.known / f.orders) * 100) : 0;
        return {
            key,
            label: stepLabels[key],
            hint: stepHints[key],
            amount: step.amount,
            known: step.known,
            coverage,
            width: Math.min(100, Math.round((Math.abs(num(step.amount)) / gross) * 1000) / 10),
        };
    });
});

const summary = computed(() => {
    const f = bucket.value?.funnel;
    if (!f) return null;
    const sale = num(f.gross.amount) + num(f.cancel.amount) + num(f.refund.amount);
    const contribution = f.contribution === null ? null : num(f.contribution);
    return {
        orders: f.orders,
        complete: f.complete,
        incomplete: f.orders - f.complete,
        sale,
        contribution,
        partial: f.partial,
        coverage: f.orders > 0 ? Math.round((f.complete / f.orders) * 100) : 0,
    };
});

/* Kırılım sekmesi. */
const dimension = ref('channels');
const dimensions = [
    ['channels', k('Kanal')],
    ['categories', k('Kategori')],
    ['brands', k('Marka')],
];
const groups = computed(() => bucket.value?.[dimension.value] ?? []);

function groupName(group) {
    if (dimension.value === 'channels') return group.label || channelName(group.channel);
    if (group.kind === 'unmatched') return t('Kataloğa bağlanmamış ürün');
    if (group.kind === 'none') return dimension.value === 'brands' ? t('Markasız') : t('Kategorisiz');
    return group.label;
}

function known(value, count, total) {
    if (count === 0) return '—';
    return count === total ? money(value, currency.value) : `${money(value, currency.value)}*`;
}

const gaps = computed(() => {
    const g = props.report.gaps ?? {};
    const items = [];
    if (g.variantsWithoutCost > 0) items.push({ key: 'cost', count: g.variantsWithoutCost, title: k('üründe alış maliyeti girilmemiş'), hint: k('Maliyet girilince yeni siparişlerde katkı hesaplanır. Geçmiş siparişler satış anındaki maliyetle kalır.'), href: '/products/costs?filter=missing', action: k('Maliyet gir') });
    if (g.ordersWithoutCommission > 0) items.push({ key: 'commission', count: g.ordersWithoutCommission, title: k('siparişte beklenen komisyon yok'), hint: k('Kanalın komisyon yüzdesini gir; yeni siparişlerde ve son 90 günde kesintisi olmayan siparişlerde beklenen komisyon yazılır.'), href: '/settings/charge-rules', action: k('Kesinti varsayılanı gir') });
    if (g.ordersWithoutShipping > 0) items.push({ key: 'shipping', count: g.ordersWithoutShipping, title: k('siparişte beklenen kargo kesintisi yok'), hint: k('Kargo kesintisi bilinmeyen sipariş katkıya girmez. Sipariş başı kargo ücretini gir.'), href: '/settings/charge-rules', action: k('Kesinti varsayılanı gir') });
    if (g.unmatchedLines > 0) items.push({ key: 'unmatched', count: g.unmatchedLines, title: k('sipariş satırı kataloğa bağlı değil'), hint: k('Stok kodunu (SKU) kataloğundaki ürünle aynı yap; kategori ve marka o zaman görünür.'), href: '/orders?filter=unmatched', action: k('Siparişlere git') });
    return items;
});
</script>

<template>
    <PanelLayout>
        <PageHeader :section="t('Finans')" :title="t('Kâr paneli')" />

        <p class="mt-1 max-w-3xl text-[13px] leading-relaxed text-stone-600">
            {{ t('Satıştan katkıya kadar her kesinti tek ekranda. Tutarlar KDV dahildir ve muhasebe kârı değildir. Bilinmeyen kalem sıfır sayılmaz: her satırda kaç siparişte bilindiği yazar, katkı yalnız bütün kalemleri bilinen siparişlerden toplanır.') }}
        </p>

        <form class="mt-5 flex flex-wrap items-end gap-2" @submit.prevent="apply">
            <div class="flex rounded-[10px] border border-stone-300 bg-white p-0.5 text-[13px]" role="group" :aria-label="t('Hızlı dönem')">
                <button type="button" class="rounded-lg px-3 py-1.5 font-medium text-stone-700 hover:bg-stone-100" @click="preset('7')">{{ t('Son 7 gün') }}</button>
                <button type="button" class="rounded-lg px-3 py-1.5 font-medium text-stone-700 hover:bg-stone-100" @click="preset('30')">{{ t('Son 30 gün') }}</button>
                <button type="button" class="rounded-lg px-3 py-1.5 font-medium text-stone-700 hover:bg-stone-100" @click="preset('month')">{{ t('Bu ay') }}</button>
            </div>
            <label class="text-[13px] text-stone-600">
                <span class="sr-only">{{ t('Başlangıç') }}</span>
                <input v-model="from" type="date" class="h-9 rounded-[10px] border border-stone-300 px-3 text-sm" />
            </label>
            <span class="pb-2 text-stone-400" aria-hidden="true">–</span>
            <label class="text-[13px] text-stone-600">
                <span class="sr-only">{{ t('Bitiş') }}</span>
                <input v-model="to" type="date" class="h-9 rounded-[10px] border border-stone-300 px-3 text-sm" />
            </label>
            <button type="submit" class="h-9 rounded-[10px] bg-stone-900 px-4 text-sm font-semibold text-white hover:bg-stone-700">{{ t('Göster') }}</button>
            <label v-if="currencyKeys.length > 1" class="ml-auto text-[13px] text-stone-600">
                {{ t('Para birimi') }}
                <select v-model="currency" class="ml-1 h-9 rounded-[10px] border border-stone-300 px-3 text-sm">
                    <option v-for="c in currencyKeys" :key="c" :value="c">{{ c }}</option>
                </select>
            </label>
        </form>

        <p v-if="report.truncated" class="mt-4 text-sm text-amber-800">{{ t('Dönemde :max siparişten fazlası var; en yeni :max sipariş hesaplandı. Dönemi daraltın.', { max: maxOrders }) }}</p>

        <div v-if="!summary" class="mt-6 rounded-[14px] border border-stone-200 bg-white px-5 py-8 text-center">
            <p class="text-sm text-stone-700">{{ t('Bu dönemde sipariş yok.') }}</p>
        </div>

        <template v-else>
            <!-- kartlar -->
            <div class="mt-5 grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-4">
                <div class="flex flex-col gap-1.5 rounded-[14px] border border-stone-200 bg-white p-4 sm:p-5">
                    <span class="text-[13px] text-stone-600">{{ t('Net satış') }}</span>
                    <span class="text-xl font-bold tracking-tight tabular-nums text-stone-900 sm:text-[26px]">{{ money(summary.sale, currency) }}</span>
                    <span class="text-[13px] text-stone-600">{{ t(':count sipariş', { count: summary.orders }) }}</span>
                </div>
                <div class="flex flex-col gap-1.5 rounded-[14px] border p-4 sm:p-5" :class="summary.contribution === null ? 'border-stone-200 bg-white' : 'border-emerald-200 bg-emerald-50'">
                    <span class="text-[13px]" :class="summary.contribution === null ? 'text-stone-600' : 'text-emerald-900'">{{ t('Katkı') }}</span>
                    <span class="text-xl font-bold tracking-tight tabular-nums sm:text-[26px]" :class="summary.contribution === null ? 'text-stone-400' : 'text-emerald-900'">
                        {{ summary.contribution === null ? '—' : money(summary.contribution, currency) }}
                    </span>
                    <span class="text-[13px]" :class="summary.contribution === null ? 'text-stone-600' : 'text-emerald-800'">{{ t(':count tamam siparişten', { count: summary.complete }) }}</span>
                </div>
                <div class="flex flex-col gap-1.5 rounded-[14px] border border-stone-200 bg-white p-4 sm:p-5">
                    <span class="text-[13px] text-stone-600">{{ t('Hesaplanabilen') }}</span>
                    <span class="text-xl font-bold tracking-tight tabular-nums sm:text-[26px]" :class="summary.coverage < 100 ? 'text-amber-800' : 'text-stone-900'">%{{ summary.coverage }}</span>
                    <span class="text-[13px] text-stone-600">{{ t('Tamam :a · eksik :b', { a: summary.complete, b: summary.incomplete }) }}</span>
                </div>
                <div class="flex flex-col gap-1.5 rounded-[14px] border border-stone-200 bg-white p-4 sm:p-5">
                    <span class="text-[13px] text-stone-600">{{ t('Bilinenlerin toplamı') }}</span>
                    <span class="text-xl font-bold tracking-tight tabular-nums text-stone-500 sm:text-[26px]">{{ money(summary.partial, currency) }}</span>
                    <span class="text-[13px] text-stone-600">{{ t('Eksik kalemler hariç; katkı değildir') }}</span>
                </div>
            </div>

            <!-- huni -->
            <section class="mt-4 rounded-[14px] border border-stone-200 bg-white p-5" aria-labelledby="funnel-heading">
                <h2 id="funnel-heading" class="text-sm font-semibold text-stone-900">{{ t('Satıştan katkıya') }}</h2>
                <ol class="mt-4 space-y-3">
                    <li v-for="step in funnel" :key="step.key" class="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-4 gap-y-1 sm:grid-cols-[180px_minmax(0,1fr)_140px_120px]">
                        <div>
                            <div class="text-[13px] font-medium text-stone-900">{{ t(step.label) }}</div>
                            <div class="text-xs text-stone-500">{{ t(step.hint) }}</div>
                        </div>
                        <div class="order-3 col-span-2 h-2.5 overflow-hidden rounded-full bg-stone-100 sm:order-none sm:col-span-1">
                            <span class="block h-full rounded-full" :class="step.key === 'gross' ? 'bg-stone-800' : step.key === 'cost' ? 'bg-stone-500' : 'bg-brand-500'" :style="{ width: step.width + '%' }" />
                        </div>
                        <div class="text-right text-sm font-semibold tabular-nums" :class="num(step.amount) < 0 ? 'text-stone-700' : 'text-stone-900'">{{ money(step.amount, currency) }}</div>
                        <div class="hidden text-right text-xs sm:block" :class="step.coverage < 100 ? 'font-medium text-amber-800' : 'text-stone-500'">
                            {{ t('%:pct siparişte biliniyor', { pct: step.coverage }) }}
                        </div>
                        <div class="order-4 col-span-2 text-xs sm:hidden" :class="step.coverage < 100 ? 'font-medium text-amber-800' : 'text-stone-500'">
                            {{ t('%:pct siparişte biliniyor', { pct: step.coverage }) }}
                        </div>
                    </li>
                    <li class="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-4 border-t border-stone-200 pt-3 sm:grid-cols-[180px_minmax(0,1fr)_140px_120px]">
                        <div class="text-[13px] font-semibold text-stone-900">{{ t('Katkı') }}</div>
                        <div class="hidden sm:block" />
                        <div class="text-right text-sm font-bold tabular-nums" :class="summary.contribution === null ? 'text-stone-400' : 'text-emerald-800'">{{ summary.contribution === null ? '—' : money(summary.contribution, currency) }}</div>
                        <div class="hidden text-right text-xs text-stone-500 sm:block">{{ t(':count tamam siparişten', { count: summary.complete }) }}</div>
                    </li>
                </ol>
                <p class="mt-4 text-xs text-stone-500">{{ t('Basamaklar yalnız bilinen siparişlerin toplamıdır; kapsamı %100 olmayan basamak eksik olabilir. Katkı yalnız bütün kalemleri bilinen siparişlerden toplanır, bu yüzden basamakların farkına eşit olmayabilir.') }}</p>
            </section>

            <!-- eksik veri -->
            <section v-if="gaps.length" class="mt-4 rounded-[14px] border border-amber-200 bg-amber-50 p-5" aria-labelledby="gaps-heading">
                <h2 id="gaps-heading" class="text-sm font-semibold text-amber-950">{{ t('Katkıyı tamamlamak için') }}</h2>
                <ul class="mt-3 divide-y divide-amber-200">
                    <li v-for="gap in gaps" :key="gap.key" class="flex flex-wrap items-center justify-between gap-3 py-2.5">
                        <div>
                            <div class="text-sm text-amber-950"><strong class="tabular-nums">{{ gap.count }}</strong> {{ t(gap.title) }}</div>
                            <div class="text-xs text-amber-900">{{ t(gap.hint) }}</div>
                        </div>
                        <Link :href="gap.href" class="rounded-[10px] border border-amber-300 bg-white px-3 py-1.5 text-[13px] font-medium text-amber-950 hover:bg-amber-100">{{ t(gap.action) }}</Link>
                    </li>
                </ul>
            </section>

            <!-- kırılım -->
            <section class="mt-4 rounded-[14px] border border-stone-200 bg-white" aria-labelledby="split-heading">
                <div class="flex flex-wrap items-center justify-between gap-3 px-5 pt-5">
                    <h2 id="split-heading" class="text-sm font-semibold text-stone-900">{{ t('Kırılım') }}</h2>
                    <div class="flex rounded-[10px] border border-stone-200 bg-white p-0.5 text-[13px]" role="tablist">
                        <button v-for="[key, label] in dimensions" :key="key" type="button" role="tab" :aria-selected="dimension === key" class="whitespace-nowrap rounded-lg px-3 py-1.5 font-medium" :class="dimension === key ? 'bg-stone-900 text-white' : 'text-stone-700 hover:bg-stone-100'" @click="dimension = key">{{ t(label) }}</button>
                    </div>
                </div>
                <div class="overflow-x-auto px-5 pb-3">
                    <table class="mt-2 w-full min-w-[720px] text-left text-[13px]">
                        <thead>
                            <tr class="border-b border-stone-200 text-stone-600">
                                <th scope="col" class="py-2.5 pr-3 font-normal">{{ t(dimensions.find((d) => d[0] === dimension)[1]) }}</th>
                                <th scope="col" class="py-2.5 pr-3 text-right font-normal">{{ dimension === 'channels' ? t('Sipariş') : t('Satır') }}</th>
                                <th scope="col" class="py-2.5 pr-3 text-right font-normal">{{ t('Satış') }}</th>
                                <th scope="col" class="py-2.5 pr-3 text-right font-normal">{{ t('Kesintiler') }}</th>
                                <th scope="col" class="py-2.5 pr-3 text-right font-normal">{{ t('Maliyet') }}</th>
                                <th scope="col" class="py-2.5 pr-3 text-right font-normal">{{ t('Katkı') }}</th>
                                <th scope="col" class="py-2.5 text-right font-normal">{{ t('Hesaplanabilen') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="group in groups" :key="group.key" class="border-b border-stone-100 last:border-0">
                                <td class="py-3 pr-3">
                                    <span class="flex items-center gap-1.5 font-medium" :class="group.kind && group.kind !== 'value' ? 'text-stone-500' : 'text-stone-900'">
                                        <img v-if="dimension === 'channels' && brandIcon(group.channel)" :src="brandIcon(group.channel)" alt="" class="size-4 rounded-sm object-contain">
                                        {{ groupName(group) }}
                                    </span>
                                </td>
                                <td class="py-3 pr-3 text-right tabular-nums text-stone-700">{{ group.orders }}</td>
                                <td class="py-3 pr-3 text-right tabular-nums text-stone-900">{{ known(group.sale, group.saleKnown, group.orders) }}</td>
                                <td class="py-3 pr-3 text-right tabular-nums text-stone-600">{{ known(group.deductions, group.deductionsKnown, group.orders) }}</td>
                                <td class="py-3 pr-3 text-right tabular-nums text-stone-600">{{ known(group.cost, group.costKnown, group.orders) }}</td>
                                <td class="py-3 pr-3 text-right tabular-nums">
                                    <template v-if="group.contribution !== null">
                                        <strong class="text-stone-900">{{ money(group.contribution, currency) }}</strong>
                                        <div v-if="group.margin !== null" class="text-xs" :class="group.margin >= 0 ? 'text-emerald-700' : 'text-brand-700'">%{{ group.margin }}</div>
                                    </template>
                                    <span v-else class="text-stone-400">—</span>
                                </td>
                                <td class="py-3 text-right text-xs tabular-nums" :class="group.complete < group.orders ? 'font-medium text-amber-800' : 'text-stone-500'">{{ group.complete }} / {{ group.orders }}</td>
                            </tr>
                            <tr v-if="groups.length === 0">
                                <td colspan="7" class="py-6 text-center text-stone-500">{{ t('Bu dönemde sipariş yok.') }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p class="px-5 pb-5 text-xs text-stone-500">
                    {{ t('* İşaretli tutar yalnız bilinen kalemlerin toplamıdır. Katkı ve marj yalnız bütün kalemleri bilinen siparişlerden.') }}
                    <template v-if="dimension !== 'channels'"> {{ t('Kategori ve markada iade, komisyon ve kargo satırlara satış payıyla dağıtılır; kuruş yuvarlaması olabilir.') }}</template>
                </p>
            </section>
        </template>
    </PanelLayout>
</template>
