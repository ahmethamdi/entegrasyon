<script setup>
import { Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import PageHeader from '../../Components/PageHeader.vue';
import StatCard from '../../Components/StatCard.vue';
import PanelLayout from '../../Layouts/PanelLayout.vue';
import { channelName, money, orderState, toneClass } from '../../lib/format.js';

const props = defineProps({
    rows: { type: Array, default: () => [] },
    summary: { type: Object, default: () => ({}) },
    filters: { type: Object, default: () => ({}) },
});

const search = ref(props.filters.search ?? '');

function applyFilter(filter) {
    router.get('/orders', { filter, search: search.value || undefined }, {
        preserveState: true,
        preserveScroll: true,
    });
}

function submitSearch() {
    applyFilter(props.filters.filter);
}

/**
 * Stok rozetleri. Sıra önemlidir: FAZLA SATIŞ eşleşmemişten ÖNCE gelir,
 * çünkü fazla satış satılmış ve stoğu eksiye düşmüş bir kalemdir — kargo
 * çıkışı gerçekten tehlikededir. Eşleşmemiş satır henüz stoğa dokunmamıştır.
 */
const badges = {
    OVERSOLD: { text: 'Fazla satış', class: 'bg-red-50 text-red-800 border-red-200' },
    PENDING: { text: 'Stok düşülmedi', class: 'bg-amber-50 text-amber-900 border-amber-200' },
    APPLIED: { text: 'Stok düştü', class: 'bg-stone-50 text-stone-600 border-stone-200' },
};

function placedAt(row) {
    if (!row.placedAt) return '—';
    return new Date(row.placedAt).toLocaleString('tr-TR', {
        day: '2-digit', month: '2-digit', year: 'numeric',
        hour: '2-digit', minute: '2-digit',
    });
}
</script>

<template>
    <PanelLayout>
        <PageHeader section="Siparişler" title="Tüm kanallardan siparişler" />

        <!--
            EYLEM GEREKTİREN ÖZET ÜSTTE. Fazla satış gizlenmez (§17 · P0):
            satıcı gönderemeyeceği bir siparişi kabul ettiğini burada görür.
        -->
        <div class="mt-6 grid gap-3 sm:grid-cols-3">
            <StatCard label="Toplam sipariş" :value="summary.orderCount" />

            <StatCard
                label="Fazla satış içeren"
                :value="summary.oversoldOrderCount"
                :tone="summary.oversoldOrderCount > 0 ? 'error' : 'neutral'"
            />

            <!--
                EŞLEŞMEMİŞ SKU AYRI UYARIDIR: o satırın stoğu HİÇ düşülmedi.
                Fazla satışta stok eksi görünür ve fark edilir; burada tablo
                "her şey yolunda" der ve sessiz kalırsa stok kalıcı olarak
                fazla gösterilir. Bu yüzden tonu UYARI (amber), hata değil.
            -->
            <StatCard
                label="Tanınmayan ürün içeren"
                :value="summary.unmatchedOrderCount"
                :tone="summary.unmatchedOrderCount > 0 ? 'warning' : 'neutral'"
            />
        </div>

        <!-- filtreler -->
        <div class="mt-8 flex flex-wrap items-center gap-3">
            <div class="flex flex-wrap rounded-md border border-stone-300 bg-white p-0.5">
                <button
                    type="button"
                    class="rounded px-3 py-1.5 text-sm transition"
                    :class="filters.filter === 'all'
                        ? 'bg-stone-900 text-white'
                        : 'text-stone-700 hover:bg-stone-100'"
                    @click="applyFilter('all')"
                >
                    Tümü
                </button>
                <button
                    type="button"
                    class="rounded px-3 py-1.5 text-sm transition"
                    :class="filters.filter === 'awaiting_shipment'
                        ? 'bg-stone-900 text-white'
                        : 'text-stone-700 hover:bg-stone-100'"
                    @click="applyFilter('awaiting_shipment')"
                >
                    Kargo bekleyen
                </button>
                <button
                    type="button"
                    class="rounded px-3 py-1.5 text-sm transition"
                    :class="filters.filter === 'oversold'
                        ? 'bg-stone-900 text-white'
                        : 'text-stone-700 hover:bg-stone-100'"
                    @click="applyFilter('oversold')"
                >
                    Fazla satış
                </button>
                <button
                    type="button"
                    class="rounded px-3 py-1.5 text-sm transition"
                    :class="filters.filter === 'unmatched'
                        ? 'bg-stone-900 text-white'
                        : 'text-stone-700 hover:bg-stone-100'"
                    @click="applyFilter('unmatched')"
                >
                    Tanınmayan ürün
                </button>
            </div>

            <!--
                Arama kutusu dar ekranda ESNER (`w-full` + `min-w-0`), geniş
                ekranda sabit genişliğe döner. Sabit `w-64` tek başına
                bırakılsaydı 320px'lik telefonda kutu + düğme satıra sığmaz
                ve sayfayı yatay kaydırırdı (gerçek tarayıcıda ölçüldü).
            -->
            <form class="flex w-full items-center gap-2 sm:w-auto" @submit.prevent="submitSearch">
                <input
                    v-model="search"
                    type="search"
                    placeholder="Sipariş no veya SKU ara"
                    class="w-full min-w-0 rounded-md border border-stone-300 px-3 py-1.5 text-sm focus:border-ring focus:outline-2 focus:outline-offset-0 focus:outline-ring sm:w-64"
                >
                <button
                    type="submit"
                    class="shrink-0 rounded-md border border-stone-300 px-3 py-1.5 text-sm text-stone-700 transition hover:bg-stone-100"
                >
                    Ara
                </button>
            </form>
        </div>

        <div v-if="!rows.length" class="mt-8 rounded-lg border border-dashed border-stone-300 p-10 text-center">
            <p class="text-sm text-stone-600">Bu ölçütlerle sipariş bulunamadı.</p>
        </div>

        <!-- liste -->
        <!-- Asgari genişlik sütunların dar ekranda sıkışmasını önler; kutu kayar. -->
        <div v-else class="mt-6 overflow-x-auto rounded-lg border border-stone-200 bg-white">
            <table class="w-full min-w-3xl text-sm">
                <thead class="border-b border-stone-200 bg-stone-50 text-left">
                    <tr>
                        <th class="px-4 py-2.5 text-xs font-medium text-stone-600">Sipariş</th>
                        <th class="px-4 py-2.5 text-xs font-medium text-stone-600">Kanal</th>
                        <th class="px-4 py-2.5 text-xs font-medium text-stone-600">Durum</th>
                        <th class="px-4 py-2.5 text-right text-xs font-medium text-stone-600">Adet</th>
                        <th class="px-4 py-2.5 text-right text-xs font-medium text-stone-600">Tutar</th>
                        <th class="px-4 py-2.5 text-xs font-medium text-stone-600">Stok</th>
                        <th class="px-4 py-2.5 text-xs font-medium text-stone-600"></th>
                    </tr>
                </thead>

                <tbody>
                    <tr
                        v-for="row in rows"
                        :key="row.id"
                        class="border-b border-stone-100"
                        :class="row.hasOversold ? 'bg-red-50/60 hover:bg-red-100/60' : (row.hasUnmatched ? 'bg-amber-50/50 hover:bg-amber-100/50' : 'hover:bg-stone-50')"
                    >
                        <td class="px-4 py-3">
                            <Link :href="`/orders/${row.id}`" class="font-medium text-stone-900 hover:underline">
                                {{ row.externalNumber ?? '—' }}
                            </Link>
                            <p class="mt-0.5 text-xs text-stone-500">{{ placedAt(row) }}</p>
                        </td>

                        <td class="px-4 py-3">
                            <p class="text-stone-900">{{ channelName(row.channel.type) }}</p>
                            <p class="mt-0.5 text-xs text-stone-500">{{ row.channel.label ?? '' }}</p>
                        </td>

                        <td class="px-4 py-3">
                            <span
                                class="rounded-full border px-2.5 py-0.5 text-xs font-medium"
                                :class="toneClass[orderState(row.status, row.hasShipment).tone]"
                            >
                                {{ $t(orderState(row.status, row.hasShipment).text) }}
                            </span>
                        </td>

                        <td class="px-4 py-3 text-right tabular-nums text-stone-700">
                            {{ row.itemCount }}
                        </td>

                        <td class="px-4 py-3 text-right tabular-nums text-stone-900">
                            {{ money(row.grandTotal, row.currency) }}
                        </td>

                        <!--
                            FAZLA SATIŞ VE EŞLEŞMEMİŞ SKU AYRI AYRI YAZILIR.
                            Rozet en acil olanı gösterir ama ikisi de varsa
                            ikisi de söylenir: farklı sorunlar, farklı çözümler.
                        -->
                        <td class="px-4 py-3">
                            <span
                                class="rounded-full border px-2.5 py-0.5 text-xs font-medium"
                                :class="badges[row.stockBadge]?.class"
                            >
                                {{ badges[row.stockBadge]?.text ?? row.stockBadge }}
                            </span>

                            <p v-if="row.hasOversold" class="mt-0.5 text-[11px] text-red-700">
                                {{ row.oversoldLineCount }} kalem stoksuz satıldı
                            </p>
                            <p v-if="row.hasUnmatched" class="mt-0.5 text-[11px] text-amber-800">
                                {{ row.unmatchedLineCount }} ürün kataloğunda yok · stok düşülmedi
                            </p>
                        </td>

                        <td class="px-4 py-3 text-right">
                            <Link
                                :href="`/orders/${row.id}`"
                                class="rounded-md border border-stone-300 px-3 py-1.5 text-xs text-stone-700 transition hover:bg-stone-100"
                            >
                                Ayrıntı
                            </Link>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </PanelLayout>
</template>
