<script setup>
import { Link } from '@inertiajs/vue3';
import PageHeader from '../../Components/PageHeader.vue';
import PanelLayout from '../../Layouts/PanelLayout.vue';

defineProps({
    order: { type: Object, required: true },
});

const lineBadges = {
    OVERSOLD: { text: 'FAZLA SATIŞ', class: 'bg-red-50 text-red-800 border-red-200' },
    PENDING: { text: 'BEKLİYOR', class: 'bg-sky-50 text-sky-800 border-sky-200' },
    APPLIED: { text: 'STOK DÜŞÜLDÜ', class: 'bg-emerald-50 text-emerald-800 border-emerald-200' },
};

/**
 * Olay etiketleri. OVERSELL_DETECTED bizim ürettiğimiz DENETİM olayıdır;
 * kanaldan gelmez ve `external_ref` taşımaz.
 */
const eventLabels = {
    created: 'Sipariş alındı',
    updated: 'Güncellendi',
    cancelled: 'İptal edildi',
    returned: 'İade edildi',
    fulfilled: 'Kargolandı',
    OVERSELL_DETECTED: 'Fazla satış tespit edildi',
};

function stamp(value) {
    if (!value) return '—';
    return new Date(value).toLocaleString('tr-TR', {
        day: '2-digit', month: '2-digit', year: 'numeric',
        hour: '2-digit', minute: '2-digit',
    });
}
</script>

<template>
    <PanelLayout>
        <PageHeader section="Sipariş" :title="order.externalNumber ?? order.externalId">
            <template #actions>
                <Link
                    href="/orders"
                    class="rounded-md border border-stone-300 px-3 py-1.5 text-sm text-stone-700 transition hover:bg-stone-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
                >
                    Listeye dön
                </Link>
            </template>

            <template #toolbar>
                <p class="text-sm text-stone-600">
                    {{ order.channel.label ?? '—' }}
                    <span class="font-mono text-xs text-stone-500">({{ order.channel.type }})</span>
                    · {{ stamp(order.placedAt) }}
                </p>
            </template>
        </PageHeader>

        <!-- tutarlar -->
        <div class="mt-6 grid gap-4 sm:grid-cols-4">
            <div class="rounded-lg border border-stone-200 bg-white p-4">
                <p class="text-xs text-stone-500">Ara toplam</p>
                <p class="mt-1 font-mono text-sm tabular-nums text-stone-900">
                    {{ order.subtotal }} {{ order.currency }}
                </p>
            </div>
            <div class="rounded-lg border border-stone-200 bg-white p-4">
                <p class="text-xs text-stone-500">Kargo</p>
                <p class="mt-1 font-mono text-sm tabular-nums text-stone-900">
                    {{ order.shippingTotal }} {{ order.currency }}
                </p>
            </div>
            <div class="rounded-lg border border-stone-200 bg-white p-4">
                <p class="text-xs text-stone-500">Vergi</p>
                <p class="mt-1 font-mono text-sm tabular-nums text-stone-900">
                    {{ order.taxTotal }} {{ order.currency }}
                </p>
            </div>
            <div class="rounded-lg border border-stone-200 bg-white p-4">
                <p class="text-xs text-stone-500">Genel toplam</p>
                <p class="mt-1 font-mono text-sm font-semibold tabular-nums text-stone-900">
                    {{ order.grandTotal }} {{ order.currency }}
                </p>
            </div>
        </div>

        <!-- satırlar -->
        <h2 class="mt-10 text-sm font-semibold text-stone-900">Kalemler</h2>

        <!-- Asgari genişlik sütunların dar ekranda sıkışmasını önler; kutu kayar. -->
        <div class="mt-3 overflow-x-auto rounded-lg border border-stone-200 bg-white">
            <table class="w-full min-w-2xl text-sm">
                <thead class="border-b border-stone-200 bg-stone-50 text-left">
                    <tr>
                        <th class="px-4 py-2.5 font-mono text-[10px] font-medium uppercase tracking-wider text-stone-600">Ürün</th>
                        <th class="px-4 py-2.5 text-right font-mono text-[10px] font-medium uppercase tracking-wider text-stone-600">Adet</th>
                        <th class="px-4 py-2.5 text-right font-mono text-[10px] font-medium uppercase tracking-wider text-stone-600">İptal / İade</th>
                        <th class="px-4 py-2.5 text-right font-mono text-[10px] font-medium uppercase tracking-wider text-stone-600">Tutar</th>
                        <th class="px-4 py-2.5 font-mono text-[10px] font-medium uppercase tracking-wider text-stone-600">Stok</th>
                    </tr>
                </thead>

                <tbody>
                    <tr
                        v-for="line in order.lines"
                        :key="line.id"
                        class="border-b border-stone-100"
                        :class="line.isOversold ? 'bg-red-50/60 hover:bg-red-100/60' : (!line.isMatched ? 'bg-amber-50/50 hover:bg-amber-100/50' : 'hover:bg-stone-50')"
                    >
                        <td class="px-4 py-3">
                            <p class="text-xs text-stone-900">{{ line.title }}</p>
                            <p class="mt-0.5 font-mono text-[11px] text-stone-500">{{ line.sku }}</p>
                        </td>

                        <td class="px-4 py-3 text-right font-mono text-xs tabular-nums text-stone-700">
                            {{ line.quantity }}
                        </td>

                        <td class="px-4 py-3 text-right font-mono text-xs tabular-nums text-stone-700">
                            {{ line.quantityCancelled }} / {{ line.quantityReturned }}
                        </td>

                        <td class="px-4 py-3 text-right font-mono text-xs tabular-nums text-stone-900">
                            {{ line.lineTotal }} {{ order.currency }}
                        </td>

                        <td class="px-4 py-3">
                            <span
                                class="rounded border px-2 py-0.5 font-mono text-[10px] tracking-wider"
                                :class="lineBadges[line.stockStatus]?.class"
                            >
                                {{ lineBadges[line.stockStatus]?.text ?? line.stockStatus }}
                            </span>

                            <!--
                                EŞLEŞMEMİŞ SKU: sipariş KAYBEDİLMEDİ ama stok
                                düşülmedi. Satıcı eşleştirmeyi yapana kadar
                                bakiye olduğundan fazla görünür.
                            -->
                            <p v-if="!line.isMatched" class="mt-0.5 text-[11px] text-amber-800">
                                Kataloğunuzda eşleşen ürün yok · stok düşülmedi
                            </p>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- olay geçmişi -->
        <h2 class="mt-10 text-sm font-semibold text-stone-900">Geçmiş</h2>

        <div class="mt-3 rounded-lg border border-stone-200 bg-white">
            <ul>
                <li
                    v-for="event in order.events"
                    :key="event.id"
                    class="flex items-center justify-between border-b border-stone-100 px-4 py-3 last:border-b-0"
                >
                    <div>
                        <p
                            class="text-xs"
                            :class="event.type === 'OVERSELL_DETECTED'
                                ? 'font-medium text-red-800'
                                : 'text-stone-900'"
                        >
                            {{ eventLabels[event.type] ?? event.type }}
                            <span v-if="event.quantity" class="font-mono text-stone-500">
                                · {{ event.quantity }} adet
                            </span>
                        </p>
                        <p class="mt-0.5 font-mono text-[11px] text-stone-500">{{ event.source }}</p>
                    </div>

                    <p class="font-mono text-[11px] text-stone-500">{{ stamp(event.occurredAt) }}</p>
                </li>
            </ul>
        </div>
    </PanelLayout>
</template>
