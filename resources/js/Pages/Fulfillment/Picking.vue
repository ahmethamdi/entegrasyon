<script setup>
import { Link } from '@inertiajs/vue3';
import { useI18n } from '../../lib/i18n';

defineProps({
    batch: { type: Object, required: true },
    summary: { type: Array, default: () => [] },
    orders: { type: Array, default: () => [] },
    totalUnits: { type: Number, default: 0 },
});

const { t } = useI18n();

function printPage() {
    window.print();
}
</script>

<!--
    YAZDIRMA SAYFASI (A4). Panel çerçevesi yok; tarayıcının "Yazdır / PDF
    olarak kaydet"i doğrudan depo kâğıdı verir. Her satırda VARYANT
    (seçenekler + SKU + barkod) yazılır — ürün adı tek başına yetmez.
-->
<template>
    <div class="mx-auto max-w-4xl bg-white p-6 text-sm text-stone-900 print:p-0">
        <div class="flex items-center justify-between gap-4 print:hidden">
            <Link :href="`/fulfillment/batches/${batch.id}`" class="text-stone-700 underline">{{ t('Geri') }}</Link>
            <button type="button" class="rounded-md bg-stone-900 px-3 py-1.5 text-white" @click="printPage">{{ t('Yazdır') }}</button>
        </div>

        <h1 class="mt-4 text-lg font-semibold">{{ t('Toplama listesi') }}</h1>
        <p class="text-stone-600">{{ t(':orders sipariş · :units adet', { orders: orders.length, units: totalUnits }) }}</p>

        <p v-if="!orders.length" class="mt-6 text-stone-600">{{ t('Seçimde toplanacak (kargo bekleyen) sipariş yok.') }}</p>

        <template v-else>
            <h2 class="mt-6 font-medium">{{ t('A · Toplama özeti (varyant başına)') }}</h2>
            <table class="mt-2 w-full border-collapse">
                <thead>
                    <tr class="border-b border-stone-400 text-left">
                        <th class="py-1.5 pr-2">{{ t('Ürün') }}</th>
                        <th class="py-1.5 pr-2">{{ t('Seçenekler') }}</th>
                        <th class="py-1.5 pr-2">{{ t('SKU') }}</th>
                        <th class="py-1.5 pr-2">{{ t('Barkod') }}</th>
                        <th class="py-1.5 pr-2 text-right">{{ t('Adet') }}</th>
                        <th class="py-1.5 text-right">{{ t('Sipariş') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in summary" :key="row.variantId ?? row.sku" class="border-b border-stone-200 align-top">
                        <td class="py-1.5 pr-2">
                            {{ row.product }}
                            <span v-if="row.reason === 'VARIANT_UNMATCHED'" class="block text-xs text-amber-800">{{ t('Katalogda eşlenmemiş — kanal SKU\'su') }}</span>
                        </td>
                        <td class="py-1.5 pr-2">{{ row.options ?? '—' }}</td>
                        <td class="py-1.5 pr-2 font-mono">{{ row.sku ?? '—' }}</td>
                        <td class="py-1.5 pr-2 font-mono">{{ row.barcode ?? '—' }}</td>
                        <td class="py-1.5 pr-2 text-right text-base font-semibold tabular-nums">{{ row.quantity }}</td>
                        <td class="py-1.5 text-right tabular-nums">{{ row.orders }}</td>
                    </tr>
                </tbody>
            </table>

            <h2 class="mt-8 font-medium print:break-before-page">{{ t('B · Paketleme dökümü (sipariş başına)') }}</h2>
            <section v-for="order in orders" :key="order.orderId" class="mt-3 break-inside-avoid border border-stone-300 p-3">
                <p class="font-medium">
                    {{ t('Sipariş') }} {{ order.number ?? '—' }} · {{ t('Paket') }} {{ order.package }}
                    <span class="font-normal text-stone-600">· {{ t(':units adet', { units: order.units }) }}</span>
                </p>
                <table class="mt-1 w-full border-collapse">
                    <tbody>
                        <tr v-for="(line, i) in order.lines" :key="i" class="border-t border-stone-200">
                            <td class="w-6 py-1">☐</td>
                            <td class="py-1 pr-2">{{ line.product }}<span v-if="line.options" class="text-stone-600"> — {{ line.options }}</span></td>
                            <td class="py-1 pr-2 font-mono">{{ line.sku ?? '—' }}</td>
                            <td class="py-1 pr-2 font-mono">{{ line.barcode ?? '—' }}</td>
                            <td class="py-1 text-right font-semibold tabular-nums">× {{ line.quantity }}</td>
                        </tr>
                    </tbody>
                </table>
            </section>
        </template>
    </div>
</template>
