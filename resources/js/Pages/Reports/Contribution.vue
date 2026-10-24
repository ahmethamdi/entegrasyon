<script setup>
import { Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import PageHeader from '../../Components/PageHeader.vue';
import PanelLayout from '../../Layouts/PanelLayout.vue';
import { channelName, money } from '../../lib/format.js';
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
</script>

<template>
    <PanelLayout>
        <PageHeader :section="t('Raporlar')" :title="t('Katkı (KDV dahil)')" />

        <p class="mt-2 max-w-3xl text-sm text-stone-600">
            {{ t('Bu rapor muhasebe kârı değildir: tutarlar KDV dahildir, maliyet satış anındaki KDV dahil alış maliyetidir. Beklenen, sipariş anındaki bilgilerden; gerçekleşen, yalnız doğrulanmış pazaryeri hakediş kayıtlarından hesaplanır. Eksik olan katkı son rakam olarak gösterilmez.') }}
        </p>

        <form class="mt-4 flex flex-wrap items-end gap-3" @submit.prevent="apply">
            <label class="text-sm text-stone-700">
                {{ t('Başlangıç') }}
                <input v-model="from" type="date" class="mt-1 block rounded border-stone-300 text-sm" />
            </label>
            <label class="text-sm text-stone-700">
                {{ t('Bitiş') }}
                <input v-model="to" type="date" class="mt-1 block rounded border-stone-300 text-sm" />
            </label>
            <button type="submit" class="rounded bg-stone-900 px-3 py-2 text-sm text-white">{{ t('Göster') }}</button>
        </form>

        <section v-for="(basisTotals, basis) in totals" :key="basis" class="mt-6">
            <h2 class="text-sm font-medium text-stone-900">
                {{ basis === 'expected' ? t('Dönem toplamı — beklenen') : t('Dönem toplamı — gerçekleşen') }}
            </h2>
            <p v-if="Object.keys(basisTotals).length === 0" class="mt-1 text-sm text-stone-500">{{ t('Bu dönemde sipariş yok.') }}</p>
            <div v-for="(bucket, currency) in basisTotals" :key="currency" class="relative mt-2 overflow-x-auto rounded border border-stone-200 bg-white p-3 text-sm">
                <div class="flex flex-wrap gap-x-6 gap-y-1">
                    <span v-for="[key, label] in components" :key="key">
                        {{ t(label) }}: <strong>{{ money(bucket[key] ?? '0.00', currency) }}</strong>
                        <span v-if="bucket.unknown && bucket.unknown[key] > 0" class="text-amber-800">
                            ({{ t(':count siparişte bilinmiyor', { count: bucket.unknown[key] }) }})
                        </span>
                    </span>
                </div>
                <p class="mt-2">
                    {{ t('Katkı (yalnız tamam siparişler)') }}:
                    <strong v-if="bucket.contribution !== null">{{ money(bucket.contribution, currency) }}</strong>
                    <template v-else>
                        <strong>—</strong>
                        <span class="text-amber-800">({{ basis === 'settled' ? t('Bu dönemde hakedişi tamamlanmış sipariş yok.') : t('Bu dönemde katkısı tam hesaplanabilen sipariş yok.') }})</span>
                    </template>
                    · {{ t('Tamam') }} {{ bucket.complete ?? 0 }}
                    · {{ t('Eksik') }} {{ bucket.incomplete ?? 0 }}
                    · {{ t('Hakediş yok') }} {{ bucket.notAvailable ?? 0 }}
                </p>
            </div>
        </section>

        <p v-if="truncated" class="mt-4 text-sm text-amber-800">{{ t('Dönemde 200\'den fazla sipariş var; en yeni 200 gösteriliyor ve toplamlar onlardan.') }}</p>

        <div class="relative mt-6 overflow-x-auto rounded border border-stone-200 bg-white">
            <table class="min-w-full text-sm">
                <thead class="bg-stone-50 text-left text-xs text-stone-600">
                    <tr>
                        <th class="px-3 py-2">{{ t('Sipariş') }}</th>
                        <th class="px-3 py-2">{{ t('Taban') }}</th>
                        <th v-for="[key, label] in components" :key="key" class="px-3 py-2 text-right">{{ t(label) }}</th>
                        <th class="px-3 py-2 text-right">{{ t('Katkı') }}</th>
                        <th class="px-3 py-2">{{ t('Durum') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <template v-for="row in rows" :key="row.id">
                        <tr v-for="(view, basis) in { expected: row.expected, settled: row.settled }" :key="row.id + basis" class="border-t border-stone-100 align-top">
                            <td v-if="basis === 'expected'" rowspan="2" class="px-3 py-2">
                                <Link :href="`/reports/contribution/${row.id}`" class="font-medium text-stone-900 underline">{{ row.number }}</Link>
                                <div class="text-xs text-stone-500">{{ channelName(row.channel) }} · {{ date(row.placedAt) }}</div>
                            </td>
                            <td class="px-3 py-2 text-xs text-stone-600">{{ basis === 'expected' ? t('Beklenen') : t('Gerçekleşen') }}</td>
                            <td v-for="[key] in components" :key="key" class="px-3 py-2 text-right tabular-nums" :class="view[key] === null ? 'text-amber-800' : ''">
                                {{ view.status === 'not_available' ? '—' : cell(view, key) }}
                            </td>
                            <td class="px-3 py-2 text-right tabular-nums">
                                <strong v-if="view.contribution !== null">{{ money(view.contribution, view.currency) }}</strong>
                                <span v-else-if="view.status === 'incomplete'" class="text-amber-800">
                                    {{ t('eksik') }}<br /><span class="text-xs">{{ t('bilinenler: :amount', { amount: money(view.partial, view.currency) }) }}</span>
                                </span>
                                <span v-else>—</span>
                            </td>
                            <td class="px-3 py-2">
                                <span class="inline-block rounded border px-2 py-0.5 text-xs" :class="statusClass[view.status]">{{ t(statusText[view.status]) }}</span>
                            </td>
                        </tr>
                    </template>
                    <tr v-if="rows.length === 0">
                        <td colspan="10" class="px-3 py-6 text-center text-stone-500">{{ t('Bu dönemde sipariş yok.') }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <section class="mt-8">
            <h2 class="text-sm font-medium text-stone-900">{{ t('Eşleşmeyen hakediş işlemleri') }} ({{ unmatched.count }})</h2>
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
