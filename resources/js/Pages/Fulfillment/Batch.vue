<script setup>
import { Link, router } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted } from 'vue';
import PageHeader from '../../Components/PageHeader.vue';
import PanelLayout from '../../Layouts/PanelLayout.vue';
import { k, useI18n } from '../../lib/i18n';
import { usePermissions } from '../../lib/permissions.js';

const props = defineProps({
    batch: { type: Object, required: true },
    items: { type: Array, default: () => [] },
    counts: { type: Object, default: () => ({}) },
    readyLabels: { type: Number, default: 0 },
});

const { t } = useI18n();
// S7 · etiket ve toplama listesi depo işidir (rota ORDERS_WORK).
const { can } = usePermissions();

const titles = {
    labels: k('Kargo etiketleri'),
    picking: k('Toplama listesi'),
    invoice_documents: k('Fatura belgeleri'),
    issue_invoices: k('Fatura kesimi'),
};

/* Her kalem dört sonuçtan birinde — hiçbiri sessizce atlanmaz. */
const outcomes = {
    succeeded: { text: k('Başarılı'), class: 'bg-emerald-50 text-emerald-800 border-emerald-200' },
    in_progress: { text: k('Sürüyor'), class: 'bg-sky-50 text-sky-800 border-sky-200' },
    action_required: { text: k('İşlem gerekli'), class: 'bg-amber-50 text-amber-900 border-amber-200' },
    failed: { text: k('Başarısız'), class: 'bg-red-50 text-red-800 border-red-200' },
};

/* Kuyruktaki kalemler bitene kadar sonuç kendiliğinden yenilenir. */
let timer = null;
onMounted(() => {
    timer = setInterval(() => {
        if ((props.counts.in_progress ?? 0) > 0) router.reload({ only: ['items', 'counts', 'readyLabels'] });
    }, 5000);
});
onBeforeUnmount(() => clearInterval(timer));
</script>

<template>
    <PanelLayout>
        <PageHeader :section="t('Siparişler')" :title="t(titles[batch.action] ?? batch.action)" />

        <p class="mt-4 text-sm text-stone-700">
            {{ t(':total sipariş seçildi', { total: batch.total }) }} ·
            <template v-for="(o, key) in outcomes" :key="key">
                {{ t(o.text) }} {{ counts[key] ?? 0 }}<span v-if="key !== 'failed'"> · </span>
            </template>
        </p>

        <div class="mt-4 flex flex-wrap gap-2">
            <a
                v-if="batch.action === 'labels' && readyLabels > 0 && can('orders.work')"
                :href="`/fulfillment/batches/${batch.id}/labels.zpl`"
                class="rounded-md bg-stone-900 px-3 py-1.5 text-sm text-white"
            >
                {{ t('Hazır etiketleri indir (ZPL, :count)', { count: readyLabels }) }}
            </a>
            <Link
                v-if="(batch.action === 'picking' || batch.action === 'labels') && can('orders.work')"
                :href="`/fulfillment/batches/${batch.id}/picking`"
                class="rounded-md border border-stone-300 px-3 py-1.5 text-sm text-stone-800 hover:bg-stone-100"
            >
                {{ t('Toplama listesini aç') }}
            </Link>
            <Link href="/orders" class="rounded-md border border-stone-300 px-3 py-1.5 text-sm text-stone-800 hover:bg-stone-100">
                {{ t('Siparişlere dön') }}
            </Link>
        </div>

        <div class="relative mt-6 overflow-x-auto rounded-lg border border-stone-200 bg-white">
            <table class="w-full min-w-2xl text-sm">
                <thead class="border-b border-stone-200 bg-stone-50 text-left">
                    <tr>
                        <th class="px-4 py-2.5 text-xs font-medium text-stone-600">{{ t('Sipariş') }}</th>
                        <th class="px-4 py-2.5 text-xs font-medium text-stone-600">{{ t('Sonuç') }}</th>
                        <th class="px-4 py-2.5 text-xs font-medium text-stone-600">{{ t('Açıklama') }}</th>
                        <th class="px-4 py-2.5 text-xs font-medium text-stone-600"><span class="sr-only">{{ t('İşlem') }}</span></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="item in items" :key="item.orderId" class="border-b border-stone-100">
                        <td class="px-4 py-3">
                            <Link :href="`/orders/${item.orderId}`" class="font-medium text-stone-900 hover:underline">{{ item.number ?? '—' }}</Link>
                            <p class="mt-0.5 text-xs text-stone-500">{{ t('Paket') }} {{ item.package }}</p>
                        </td>
                        <td class="px-4 py-3">
                            <span class="whitespace-nowrap rounded-full border px-2.5 py-0.5 text-xs font-medium" :class="outcomes[item.outcome]?.class">
                                {{ t(outcomes[item.outcome]?.text ?? item.outcome) }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-stone-700">
                            {{ item.reason ?? '' }}
                            <span v-if="item.cargoProvider" class="block text-xs text-stone-500">{{ item.cargoProvider }}</span>
                            <span v-if="item.reasonCode" class="block font-mono text-[11px] text-stone-400">{{ item.reasonCode }}</span>
                        </td>
                        <td class="px-4 py-3 text-right">
                            <a
                                v-if="item.downloadable"
                                :href="`/orders/${item.orderId}/invoice/pdf`"
                                class="rounded-md border border-stone-300 px-3 py-1.5 text-xs text-stone-700 hover:bg-stone-100"
                            >
                                {{ t('Fatura PDF') }}
                            </a>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </PanelLayout>
</template>
