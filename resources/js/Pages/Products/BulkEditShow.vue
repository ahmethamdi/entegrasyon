<script setup>
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted } from 'vue';
import PageHeader from '../../Components/PageHeader.vue';
import PanelLayout from '../../Layouts/PanelLayout.vue';
import { money } from '../../lib/format';
import { k, useI18n } from '../../lib/i18n';
import { usePermissions } from '../../lib/permissions.js';

const props = defineProps({
    bulkImport: { type: Object, required: true },
    status: { type: String, default: '' },
    rows: { type: Object, required: true },
});

const { t } = useI18n();
// S7 · Excel değişikliğini uygulamak yazma işidir; önizleme herkese açık.
const { can } = usePermissions();
const page = usePage();
const errors = computed(() => page.props.errors ?? {});
const flashSuccess = computed(() => page.props.flash?.success);

const rowStatuses = {
    change: { text: k('Değişecek'), class: 'text-sky-800' },
    unchanged: { text: k('Değişmeyecek'), class: 'text-stone-500' },
    invalid: { text: k('Hatalı'), class: 'text-red-800' },
    conflict: { text: k('Uygulanmayacak'), class: 'text-amber-900' },
    applied: { text: k('Uygulandı'), class: 'text-emerald-800' },
    failed: { text: k('Başarısız'), class: 'text-red-800' },
};

const filters = [
    ['', k('Hepsi')],
    ['change', k('Değişecek')],
    ['invalid', k('Hatalı')],
    ['conflict', k('Uygulanmayacak')],
    ['unchanged', k('Değişmeyecek')],
    ['applied', k('Uygulandı')],
];

const canApply = computed(() => props.bulkImport.status === 'parsed' && props.bulkImport.counts.change > 0);
const applying = computed(() => props.bulkImport.status === 'applying');

let timer = null;

onMounted(() => {
    if (applying.value) {
        timer = setInterval(() => router.reload({ preserveScroll: true, onFinish: () => {
            if (props.bulkImport.status !== 'applying') clearInterval(timer);
        } }), 3000);
    }
});

onUnmounted(() => clearInterval(timer));

function apply() {
    const text = t(':count satır uygulansın mı? Hatalı ve uygulanmayacak satırlar atlanır.', { count: props.bulkImport.counts.change });

    if (window.confirm(text)) {
        router.post(`/products/bulk-edit/${props.bulkImport.id}/apply`, {}, { preserveScroll: true });
    }
}

function filter(value) {
    router.get(`/products/bulk-edit/${props.bulkImport.id}`, value ? { status: value } : {}, { preserveScroll: true });
}

function stockCell(row) {
    if (row.stockNew === null) return '';
    return `${row.stockCurrent} → ${row.stockNew}`;
}

function priceCell(row) {
    if (row.priceNew === null) return '';
    return `${money(row.priceCurrent, row.currency)} → ${money(row.priceNew, row.currency)}`;
}
</script>

<template>
    <PanelLayout>
        <PageHeader
            :section="t('Excel ile toplu fiyat ve stok')"
            :title="bulkImport.filename"
            :description="bulkImport.status === 'parsed' ? t('Önizleme — henüz hiçbir şey değişmedi.') : null"
        />

        <p v-if="flashSuccess" class="mt-6 rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">{{ flashSuccess }}</p>
        <p v-if="errors.apply" class="mt-4 rounded-md border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-900">{{ errors.apply }}</p>
        <p v-if="bulkImport.status === 'rejected'" class="mt-6 rounded-md border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-900">
            {{ bulkImport.reason }} — {{ t('Şablonu yeniden indirip onun üzerinde düzenle.') }}
        </p>

        <dl v-else class="mt-6 grid max-w-4xl grid-cols-2 gap-3 sm:grid-cols-4">
            <div class="rounded-md border border-stone-200 p-3"><dt class="text-xs text-stone-600">{{ t('Stok değişecek') }}</dt><dd class="text-xl font-semibold tabular-nums">{{ bulkImport.counts.stock_changes }}</dd></div>
            <div class="rounded-md border border-stone-200 p-3"><dt class="text-xs text-stone-600">{{ t('Fiyat değişecek') }}</dt><dd class="text-xl font-semibold tabular-nums">{{ bulkImport.counts.price_changes }}</dd></div>
            <div class="rounded-md border border-stone-200 p-3"><dt class="text-xs text-stone-600">{{ t('Hatalı satır') }}</dt><dd class="text-xl font-semibold tabular-nums">{{ bulkImport.counts.invalid }}</dd></div>
            <div class="rounded-md border border-stone-200 p-3"><dt class="text-xs text-stone-600">{{ t('Uygulanmayacak') }}</dt><dd class="text-xl font-semibold tabular-nums">{{ bulkImport.counts.conflict }}</dd></div>
            <div v-if="bulkImport.status === 'applied'" class="rounded-md border border-emerald-200 p-3"><dt class="text-xs text-stone-600">{{ t('Uygulandı') }}</dt><dd class="text-xl font-semibold tabular-nums">{{ bulkImport.counts.applied }}</dd></div>
            <div v-if="bulkImport.counts.apply_conflicts" class="rounded-md border border-amber-300 p-3"><dt class="text-xs text-stone-600">{{ t('Önizlemeden sonra değişti') }}</dt><dd class="text-xl font-semibold tabular-nums">{{ bulkImport.counts.apply_conflicts }}</dd></div>
        </dl>

        <div v-if="bulkImport.status !== 'rejected'" class="mt-6 flex flex-wrap items-center gap-3">
            <button v-if="canApply && can('catalog.manage')" type="button" class="rounded-md bg-stone-900 px-4 py-2 text-sm font-medium text-white hover:bg-stone-700" @click="apply">
                {{ t(':count satırı uygula', { count: bulkImport.counts.change }) }}
            </button>
            <span v-if="applying" class="text-sm text-sky-800">{{ t('Uygulanıyor, sayfa kendiliğinden yenilenir…') }}</span>
            <Link href="/products/bulk-edit" class="text-sm underline">{{ t('Geri') }}</Link>
            <p class="w-full text-xs text-stone-500">{{ t('Stok ve fiyat önce 34Pazar\'da değişir; kanala yalnız o kanalın stok/fiyat izni açıksa gider.') }}</p>
        </div>

        <div v-if="bulkImport.status !== 'rejected'" class="mt-6 flex flex-wrap gap-2 text-sm">
            <button v-for="[value, label] in filters" :key="value" type="button" class="rounded-full border px-3 py-1" :class="status === value ? 'border-stone-900 bg-stone-900 text-white' : 'border-stone-300'" @click="filter(value)">
                {{ t(label) }}
            </button>
        </div>

        <div v-if="bulkImport.status !== 'rejected'" class="relative mt-4 overflow-x-auto rounded-lg border border-stone-200">
            <table class="min-w-full divide-y divide-stone-200 text-sm">
                <thead class="bg-stone-50 text-left text-xs text-stone-600">
                    <tr>
                        <th class="px-3 py-2 font-medium">{{ t('Satır') }}</th>
                        <th class="px-3 py-2 font-medium">SKU</th>
                        <th class="px-3 py-2 font-medium">{{ t('Ürün') }}</th>
                        <th class="px-3 py-2 font-medium">{{ t('Stok') }}</th>
                        <th class="px-3 py-2 font-medium">{{ t('Fiyat') }}</th>
                        <th class="px-3 py-2 font-medium">{{ t('Durum') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-stone-100 bg-white">
                    <tr v-for="row in rows.data" :key="row.id">
                        <td class="px-3 py-2 tabular-nums text-stone-500">{{ row.row }}</td>
                        <td class="px-3 py-2 font-mono text-xs">{{ row.sku ?? '—' }}</td>
                        <td class="px-3 py-2">{{ row.label ?? '' }}</td>
                        <td class="px-3 py-2 tabular-nums">
                            {{ stockCell(row) }}
                            <div v-if="row.stockNew !== null && row.status === 'change'" class="text-xs text-stone-500">
                                {{ row.stockChannels > 0 ? t(':count kanala gider', { count: row.stockChannels }) : t('kanala gitmez (izin kapalı ya da ilan yok)') }}
                            </div>
                        </td>
                        <td class="px-3 py-2 tabular-nums">
                            {{ priceCell(row) }}
                            <div v-if="row.priceNew !== null && row.status === 'change'" class="text-xs text-stone-500">
                                {{ row.priceChannels > 0 ? t(':count kanala gider', { count: row.priceChannels }) : t('kanala gitmez (izin kapalı ya da ilan yok)') }}
                            </div>
                        </td>
                        <td class="px-3 py-2">
                            <span :class="rowStatuses[row.status]?.class">{{ t(rowStatuses[row.status]?.text ?? row.status) }}</span>
                            <div v-if="row.reason" class="text-xs text-stone-600">{{ row.reason }}</div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-if="rows.links?.length > 3" class="mt-4 flex flex-wrap gap-1 text-sm">
            <template v-for="link in rows.links" :key="link.label">
                <Link v-if="link.url" :href="link.url" preserve-scroll class="rounded border px-2 py-1" :class="link.active ? 'border-stone-900' : 'border-stone-200'" v-html="link.label" />
            </template>
        </div>
    </PanelLayout>
</template>
