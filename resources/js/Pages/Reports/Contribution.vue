<script setup>
import { Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import PageHeader from '../../Components/PageHeader.vue';
import PanelLayout from '../../Layouts/PanelLayout.vue';
import { brandIcon, channelName, money } from '../../lib/format.js';
import { intlLocale, k, useI18n } from '../../lib/i18n';

const props = defineProps({
    rows: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
    totals: { type: Object, default: () => ({}) },
    unmatched: { type: Object, default: () => ({ count: 0, rows: [] }) },
    truncated: { type: Boolean, default: false },
});

const { t } = useI18n();
const from = ref(props.filters.from);
const to = ref(props.filters.to);

function apply() {
    router.get('/reports/contribution', { from: from.value, to: to.value }, { preserveState: true });
}

/*
 * DURUM ETİKETİ — eksik katkı SON RAKAM gibi gösterilmez (S1 kararı 10).
 */
const statusText = {
    complete: k('Tamam'),
    incomplete: k('Eksik'),
    not_available: k('Hakediş yok'),
};
const statusClass = {
    complete: 'bg-emerald-50 text-emerald-800 border-emerald-200',
    incomplete: 'bg-amber-50 text-amber-900 border-amber-200',
    not_available: 'bg-stone-50 text-stone-600 border-stone-200',
};

function date(value) {
    if (!value) return '—';
    return new Date(value).toLocaleDateString(intlLocale(), { day: '2-digit', month: '2-digit', year: 'numeric' });
}

function cell(view, key) {
    return view[key] === null ? t('bilinmiyor') : money(view[key], view.currency);
}

const components = [
    ['sale', k('Satış')],
    ['refund', k('İade')],
    ['commission', k('Komisyon')],
    ['shipping', k('Kargo')],
    ['other', k('Diğer')],
    ['cost', k('Maliyet')],
];

/* Yön A (13 Kas 2026): kartlar + dağılım çubuğu + tek tabanlı tablo. */
const basis = ref('expected');

function num(value) {
    const n = Number(value);
    return Number.isFinite(n) ? n : 0;
}

/* Kartlar beklenen tabandan, ilk para biriminden (genelde TRY). */
const summary = computed(() => {
    const buckets = props.totals?.[basis.value] ?? {};
    const currency = Object.keys(buckets)[0] ?? 'TRY';
    const b = buckets[currency] ?? null;
    if (!b) return null;
    const sale = num(b.sale) + num(b.refund);
    const fees = num(b.commission) + num(b.shipping) + num(b.other);
    const cost = num(b.cost);
    const contribution = b.contribution === null ? null : num(b.contribution);
    return {
        currency, sale, fees, cost, contribution,
        commission: num(b.commission), shipping: num(b.shipping), other: num(b.other),
        margin: contribution !== null && sale > 0 ? Math.round((contribution / sale) * 1000) / 10 : null,
        complete: b.complete ?? 0, incomplete: b.incomplete ?? 0, notAvailable: b.notAvailable ?? 0,
        unknownCost: b.unknown?.cost ?? 0,
    };
});

/* Satışın nereye gittiği — kesinti ve maliyet negatif gelir, payı mutlak değerden. */
const split = computed(() => {
    const s = summary.value;
    if (!s || s.sale <= 0) return [];
    const parts = [
        { key: 'commission', label: k('Komisyon'), value: Math.abs(s.commission), class: 'bg-brand-500' },
        { key: 'shipping', label: k('Kargo'), value: Math.abs(s.shipping), class: 'bg-amber-400' },
        { key: 'other', label: k('Diğer kesinti'), value: Math.abs(s.other), class: 'bg-stone-400' },
        { key: 'cost', label: k('Maliyet'), value: Math.abs(s.cost), class: 'bg-stone-700' },
    ];
    const used = parts.reduce((a, p) => a + p.value, 0);
    // Katkı kartla AYNI sayı (yalnız tamam siparişler); maliyeti bilinmeyen
    // siparişin payı ayrı gri dilimdir — çubuk kartla çelişmesin.
    const contribution = Math.max(0, s.contribution ?? 0);
    parts.push({ key: 'left', label: k('Katkı'), value: contribution, class: 'bg-emerald-600' });
    parts.push({ key: 'unknown', label: k('Hesaplanamayan'), value: Math.max(0, s.sale - used - contribution), class: 'bg-stone-200' });
    return parts.filter((p) => p.value > 0).map((p) => ({ ...p, pct: Math.round((p.value / s.sale) * 1000) / 10 }));
});

function rowMargin(view) {
    if (view.contribution === null || view.sale === null || num(view.sale) <= 0) return null;
    return Math.round((num(view.contribution) / num(view.sale)) * 1000) / 10;
}

/* Hızlı dönem seçimi. */
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

const settledEmpty = computed(() => props.rows.every((r) => r.settled.status === 'not_available'));
</script>

<template>
    <PanelLayout>
        <div class="flex flex-wrap items-end justify-between gap-4">
            <PageHeader :section="t('Raporlar')" :title="t('Katkı (KDV dahil)')" />
        </div>

        <p class="mt-1 max-w-3xl text-[13px] leading-relaxed text-stone-600">
            {{ t('Bu rapor muhasebe kârı değildir: tutarlar KDV dahildir, maliyet satış anındaki KDV dahil alış maliyetidir. Beklenen, sipariş anındaki bilgilerden; gerçekleşen, yalnız doğrulanmış pazaryeri hakediş kayıtlarından hesaplanır. Eksik olan katkı son rakam olarak gösterilmez.') }}
        </p>

        <!-- dönem -->
        <form class="mt-5 flex flex-wrap items-end gap-2" @submit.prevent="apply">
            <div class="flex rounded-[10px] border border-stone-300 bg-white p-0.5 text-[13px]" role="group" :aria-label="t('Hızlı dönem')">
                <button type="button" class="rounded-lg px-3 py-1.5 font-medium text-stone-700 hover:bg-stone-100" @click="preset('7')">{{ t('Son 7 gün') }}</button>
                <button type="button" class="rounded-lg px-3 py-1.5 font-medium text-stone-700 hover:bg-stone-100" @click="preset('30')">{{ t('Son 30 gün') }}</button>
                <button type="button" class="rounded-lg px-3 py-1.5 font-medium text-stone-700 hover:bg-stone-100" @click="preset('month')">{{ t('Bu ay') }}</button>
            </div>
            <label class="text-[13px] text-stone-600">
                <span class="sr-only">{{ t('Başlangıç') }}</span>
                <input v-model="from" type="date" class="h-9 rounded-[10px] border-stone-300 text-sm" />
            </label>
            <span class="pb-2 text-stone-400" aria-hidden="true">–</span>
            <label class="text-[13px] text-stone-600">
                <span class="sr-only">{{ t('Bitiş') }}</span>
                <input v-model="to" type="date" class="h-9 rounded-[10px] border-stone-300 text-sm" />
            </label>
            <button type="submit" class="h-9 rounded-[10px] bg-stone-900 px-4 text-sm font-semibold text-white hover:bg-stone-700">{{ t('Göster') }}</button>
        </form>

        <!-- taban -->
        <div class="mt-6 flex rounded-[10px] border border-stone-200 bg-white p-0.5 text-sm sm:inline-flex" role="tablist">
            <button type="button" role="tab" :aria-selected="basis === 'expected'" class="flex-1 whitespace-nowrap rounded-lg px-4 py-2 font-medium" :class="basis === 'expected' ? 'bg-stone-900 text-white' : 'text-stone-700 hover:bg-stone-100'" @click="basis = 'expected'">{{ t('Beklenen') }}</button>
            <button type="button" role="tab" :aria-selected="basis === 'settled'" class="flex-1 whitespace-nowrap rounded-lg px-4 py-2 font-medium" :class="basis === 'settled' ? 'bg-stone-900 text-white' : 'text-stone-700 hover:bg-stone-100'" @click="basis = 'settled'">{{ t('Gerçekleşen (hakediş)') }}</button>
        </div>

        <!-- kartlar -->
        <div v-if="summary" class="mt-4 grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-4">
            <div class="flex flex-col gap-1.5 rounded-[14px] border border-stone-200 bg-white p-4 sm:p-5">
                <span class="text-[13px] text-stone-600">{{ t('Satış') }}</span>
                <span class="text-xl font-bold tracking-tight tabular-nums text-stone-900 sm:text-[26px]">{{ money(summary.sale, summary.currency) }}</span>
                <span class="text-[13px] text-stone-600">{{ t(':count sipariş', { count: summary.complete + summary.incomplete + summary.notAvailable }) }}</span>
            </div>
            <div class="flex flex-col gap-1.5 rounded-[14px] border border-stone-200 bg-white p-4 sm:p-5">
                <span class="text-[13px] text-stone-600">{{ t('Kesintiler') }}</span>
                <span class="text-xl font-bold tracking-tight tabular-nums text-stone-900 sm:text-[26px]">{{ money(summary.fees, summary.currency) }}</span>
                <span class="text-[13px] text-stone-600">{{ t('Komisyon :c · kargo :s', { c: money(summary.commission, summary.currency), s: money(summary.shipping, summary.currency) }) }}</span>
            </div>
            <div class="flex flex-col gap-1.5 rounded-[14px] border border-stone-200 bg-white p-4 sm:p-5">
                <span class="text-[13px] text-stone-600">{{ t('Maliyet') }}</span>
                <span class="text-xl font-bold tracking-tight tabular-nums text-stone-900 sm:text-[26px]">{{ money(summary.cost, summary.currency) }}</span>
                <span class="text-[13px]" :class="summary.unknownCost ? 'font-medium text-amber-800' : 'text-stone-600'">
                    {{ summary.unknownCost ? t(':count siparişte bilinmiyor', { count: summary.unknownCost }) : t('Satış anındaki alış maliyeti') }}
                </span>
            </div>
            <div class="flex flex-col gap-1.5 rounded-[14px] border p-4 sm:p-5" :class="summary.contribution === null ? 'border-stone-200 bg-white' : 'border-emerald-200 bg-emerald-50'">
                <span class="text-[13px]" :class="summary.contribution === null ? 'text-stone-600' : 'text-emerald-900'">{{ t('Katkı') }}</span>
                <span class="text-xl font-bold tracking-tight tabular-nums sm:text-[26px]" :class="summary.contribution === null ? 'text-stone-400' : 'text-emerald-900'">
                    {{ summary.contribution === null ? '—' : money(summary.contribution, summary.currency) }}
                </span>
                <span class="text-[13px]" :class="summary.contribution === null ? 'text-stone-600' : 'font-medium text-emerald-800'">
                    <template v-if="summary.margin !== null">{{ t('Marj %:pct', { pct: summary.margin }) }} · </template>{{ t('Tamam :a · eksik :b', { a: summary.complete, b: summary.incomplete }) }}
                </span>
            </div>
        </div>

        <!-- satışın nereye gittiği -->
        <section v-if="split.length" class="mt-4 rounded-[14px] border border-stone-200 bg-white p-5" aria-labelledby="split-heading">
            <h2 id="split-heading" class="text-sm font-semibold text-stone-900">{{ t('Satışın nereye gittiği') }}</h2>
            <div class="mt-3 flex h-3 overflow-hidden rounded-full bg-stone-100" role="img" :aria-label="split.map((p) => `${t(p.label)} %${p.pct}`).join(', ')">
                <span v-for="part in split" :key="part.key" :class="part.class" :style="{ width: part.pct + '%' }" />
            </div>
            <ul class="mt-3 flex flex-wrap gap-x-5 gap-y-1.5 text-[13px] text-stone-700">
                <li v-for="part in split" :key="part.key" class="flex items-center gap-1.5">
                    <span class="size-2.5 rounded-sm" :class="part.class" aria-hidden="true" />
                    {{ t(part.label) }} <span class="tabular-nums text-stone-500">%{{ part.pct }}</span>
                </li>
            </ul>
        </section>

        <p v-if="truncated" class="mt-4 text-sm text-amber-800">{{ t('Dönemde 200\'den fazla sipariş var; en yeni 200 gösteriliyor ve toplamlar onlardan.') }}</p>

        <!-- siparişler -->
        <section class="mt-4 rounded-[14px] border border-stone-200 bg-white" aria-labelledby="orders-heading">
            <h2 id="orders-heading" class="px-5 pt-5 text-sm font-semibold text-stone-900">{{ t('Sipariş bazında') }}</h2>

            <div v-if="basis === 'settled' && settledEmpty" class="px-5 pb-6 pt-3">
                <p class="text-sm text-stone-700">{{ t('Bu dönemde hakediş kaydı yok.') }}</p>
                <p class="mt-0.5 text-[13px] text-stone-600">{{ t('Pazaryeri ödeme dökümleri geldikçe gerçekleşen tutarlar burada siparişlerle eşleşir.') }}</p>
            </div>

            <div v-else class="overflow-x-auto px-5 pb-3">
                <table class="w-full min-w-[760px] text-left text-[13px]">
                    <thead>
                        <tr class="border-b border-stone-200 text-stone-600">
                            <th scope="col" class="py-2.5 pr-3 font-normal">{{ t('Sipariş') }}</th>
                            <th v-for="[key, label] in components" :key="key" scope="col" class="py-2.5 pr-3 text-right font-normal">{{ t(label) }}</th>
                            <th scope="col" class="py-2.5 pr-3 text-right font-normal">{{ t('Katkı') }}</th>
                            <th scope="col" class="py-2.5 text-right font-normal">{{ t('Durum') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in rows" :key="row.id" class="border-b border-stone-100 last:border-0">
                            <td class="py-3 pr-3">
                                <Link :href="`/reports/contribution/${row.id}`" class="font-medium text-stone-900 underline-offset-4 hover:underline">{{ row.number }}</Link>
                                <div class="mt-0.5 flex items-center gap-1.5 text-xs text-stone-500">
                                    <img v-if="brandIcon(row.channel)" :src="brandIcon(row.channel)" alt="" class="size-3.5 rounded-sm object-contain">
                                    {{ channelName(row.channel) }} · {{ date(row.placedAt) }}
                                </div>
                            </td>
                            <td v-for="[key] in components" :key="key" class="py-3 pr-3 text-right tabular-nums" :class="row[basis][key] === null ? 'text-amber-800' : num(row[basis][key]) < 0 ? 'text-stone-600' : 'text-stone-900'">
                                {{ row[basis].status === 'not_available' ? '—' : cell(row[basis], key) }}
                            </td>
                            <td class="py-3 pr-3 text-right tabular-nums">
                                <template v-if="row[basis].contribution !== null">
                                    <strong class="text-stone-900">{{ money(row[basis].contribution, row[basis].currency) }}</strong>
                                    <div v-if="rowMargin(row[basis]) !== null" class="text-xs" :class="rowMargin(row[basis]) >= 0 ? 'text-emerald-700' : 'text-brand-700'">%{{ rowMargin(row[basis]) }}</div>
                                </template>
                                <span v-else-if="row[basis].status === 'incomplete'" class="text-amber-800">
                                    {{ t('eksik') }}<br /><span class="text-xs">{{ t('bilinenler: :amount', { amount: money(row[basis].partial, row[basis].currency) }) }}</span>
                                </span>
                                <span v-else class="text-stone-400">—</span>
                            </td>
                            <td class="py-3 text-right">
                                <span class="inline-block rounded-md border px-2 py-0.5 text-xs font-medium" :class="statusClass[row[basis].status]">{{ t(statusText[row[basis].status]) }}</span>
                            </td>
                        </tr>
                        <tr v-if="rows.length === 0">
                            <td colspan="10" class="py-6 text-center text-stone-500">{{ t('Bu dönemde sipariş yok.') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section v-if="unmatched.count > 0 || unmatched.unclassified > 0" class="mt-4 rounded-[14px] border border-stone-200 bg-white p-5">
            <h2 class="text-sm font-semibold text-stone-900">{{ t('Eşleşmeyen hakediş işlemleri') }} ({{ unmatched.count }})</h2>
            <p class="mt-1 text-sm text-stone-600">
                {{ t('Siparişe güvenle bağlanamayan pazaryeri işlemleri. Tahminle bağlanmaz ve katkıya girmez; sipariş sonradan gelirse otomatik yeniden denenir.') }}
                {{ t('Doğrulanmamış ya da tanınmayan türdeki işlem sayısı: :count', { count: unmatched.unclassified }) }}
            </p>
            <div v-if="unmatched.rows.length" class="relative mt-2 overflow-x-auto rounded border border-stone-200 bg-white">
                <table class="min-w-full text-sm">
                    <thead class="bg-stone-50 text-left text-xs text-stone-600">
                        <tr>
                            <th class="px-3 py-2">{{ t('Tarih') }}</th>
                            <th class="px-3 py-2">{{ t('Tür') }}</th>
                            <th class="px-3 py-2">{{ t('Sipariş / paket') }}</th>
                            <th class="px-3 py-2 text-right">{{ t('Alacak') }}</th>
                            <th class="px-3 py-2 text-right">{{ t('Borç') }}</th>
                            <th class="px-3 py-2 text-right">{{ t('Komisyon') }}</th>
                            <th class="px-3 py-2">{{ t('Neden') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="tx in unmatched.rows" :key="tx.id" class="border-t border-stone-100">
                            <td class="px-3 py-2">{{ date(tx.date) }}</td>
                            <td class="px-3 py-2">{{ tx.typeRaw }} <span class="text-xs text-stone-500">({{ tx.classification }})</span></td>
                            <td class="px-3 py-2">{{ tx.orderNumber ?? '—' }} / {{ tx.package ?? '—' }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ money(tx.credit, tx.currency) }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ money(tx.debt, tx.currency) }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ money(tx.commission, tx.currency) }}</td>
                            <td class="px-3 py-2 text-xs text-stone-600">{{ tx.matchReason }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </PanelLayout>
</template>
