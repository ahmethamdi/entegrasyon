<script setup>
import { Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import PanelLayout from '../../Layouts/PanelLayout.vue';

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
    OVERSOLD: { text: 'FAZLA SATIŞ', class: 'bg-red-50 text-red-800 border-red-200' },
    PENDING: { text: 'BEKLİYOR', class: 'bg-amber-50 text-amber-900 border-amber-300' },
    APPLIED: { text: 'STOK DÜŞÜLDÜ', class: 'bg-emerald-50 text-emerald-800 border-emerald-200' },
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
        <div>
            <p class="font-mono text-[10px] uppercase tracking-widest text-stone-500">
                Siparişler
            </p>
            <h1 class="mt-1 text-2xl font-semibold tracking-tight text-stone-900">
                Kanal siparişleri
            </h1>
        </div>

        <!--
            EYLEM GEREKTİREN ÖZET ÜSTTE. Fazla satış gizlenmez (§17 · P0):
            satıcı gönderemeyeceği bir siparişi kabul ettiğini burada görür.
        -->
        <div class="mt-6 grid gap-4 sm:grid-cols-3">
            <div class="rounded border border-stone-200 bg-white p-4">
                <p class="text-xs text-stone-500">Sipariş</p>
                <p class="mt-1 text-2xl font-semibold text-stone-900">{{ summary.orderCount }}</p>
            </div>

            <div
                class="rounded border p-4"
                :class="summary.oversoldOrderCount > 0
                    ? 'border-red-200 bg-red-50'
                    : 'border-stone-200 bg-white'"
            >
                <p
                    class="text-xs"
                    :class="summary.oversoldOrderCount > 0 ? 'text-red-800' : 'text-stone-500'"
                >
                    Fazla satış içeren
                </p>
                <p
                    class="mt-1 text-2xl font-semibold"
                    :class="summary.oversoldOrderCount > 0 ? 'text-red-900' : 'text-stone-900'"
                >
                    {{ summary.oversoldOrderCount }}
                </p>
            </div>

            <!--
                EŞLEŞMEMİŞ SKU AYRI UYARIDIR: o satırın stoğu HİÇ düşülmedi.
                Fazla satışta stok eksi görünür ve fark edilir; burada tablo
                "her şey yolunda" der ve sessiz kalırsa stok kalıcı olarak
                fazla gösterilir.
            -->
            <div
                class="rounded border p-4"
                :class="summary.unmatchedOrderCount > 0
                    ? 'border-amber-300 bg-amber-50'
                    : 'border-stone-200 bg-white'"
            >
                <p
                    class="text-xs"
                    :class="summary.unmatchedOrderCount > 0 ? 'text-amber-900' : 'text-stone-500'"
                >
                    Eşleşmemiş SKU içeren
                </p>
                <p
                    class="mt-1 text-2xl font-semibold"
                    :class="summary.unmatchedOrderCount > 0 ? 'text-amber-900' : 'text-stone-900'"
                >
                    {{ summary.unmatchedOrderCount }}
                </p>
            </div>
        </div>

        <!-- filtreler -->
        <div class="mt-8 flex flex-wrap items-center gap-3">
            <div class="flex rounded border border-stone-300 bg-white p-0.5">
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
                    Eşleşmemiş SKU
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
                    class="w-full min-w-0 rounded border border-stone-300 px-3 py-1.5 text-sm focus:border-stone-500 focus:outline-none sm:w-64"
                >
                <button
                    type="submit"
                    class="shrink-0 rounded border border-stone-300 px-3 py-1.5 text-sm text-stone-700 transition hover:bg-stone-100"
                >
                    Ara
                </button>
            </form>
        </div>

        <div v-if="!rows.length" class="mt-8 rounded border border-dashed border-stone-300 p-10 text-center">
            <p class="text-sm text-stone-600">Bu ölçütlerle sipariş bulunamadı.</p>
        </div>

        <!-- liste -->
        <!-- Asgari genişlik sütunların dar ekranda sıkışmasını önler; kutu kayar. -->
        <div v-else class="mt-6 overflow-x-auto rounded border border-stone-200 bg-white">
            <table class="w-full min-w-3xl text-sm">
                <thead class="border-b border-stone-200 bg-stone-50 text-left">
                    <tr class="font-mono text-[10px] uppercase tracking-wider text-stone-500">
                        <th class="px-4 py-3">Sipariş</th>
                        <th class="px-4 py-3">Kanal</th>
                        <th class="px-4 py-3 text-right">Kalem</th>
                        <th class="px-4 py-3 text-right">Tutar</th>
                        <th class="px-4 py-3">Stok</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>

                <tbody>
                    <tr
                        v-for="row in rows"
                        :key="row.id"
                        class="border-b border-stone-100"
                        :class="row.hasOversold ? 'bg-red-50/60' : (row.hasUnmatched ? 'bg-amber-50/50' : '')"
                    >
                        <td class="px-4 py-3">
                            <p class="font-mono text-xs text-stone-900">
                                {{ row.externalNumber ?? '—' }}
                            </p>
                            <p class="mt-0.5 text-xs text-stone-500">{{ placedAt(row) }}</p>
                        </td>

                        <td class="px-4 py-3">
                            <p class="text-xs text-stone-700">{{ row.channel.label ?? '—' }}</p>
                            <p class="mt-0.5 font-mono text-[11px] text-stone-500">
                                {{ row.channel.type ?? '—' }}
                            </p>
                        </td>

                        <td class="px-4 py-3 text-right font-mono text-xs tabular-nums text-stone-700">
                            {{ row.itemCount }}
                            <span class="text-stone-400">/ {{ row.lineCount }}</span>
                        </td>

                        <td class="px-4 py-3 text-right font-mono text-xs tabular-nums text-stone-900">
                            {{ row.grandTotal }} {{ row.currency }}
                        </td>

                        <!--
                            FAZLA SATIŞ VE EŞLEŞMEMİŞ SKU AYRI AYRI YAZILIR.
                            Rozet en acil olanı gösterir ama ikisi de varsa
                            ikisi de söylenir: farklı sorunlar, farklı çözümler.
                        -->
                        <td class="px-4 py-3">
                            <span
                                class="rounded border px-2 py-0.5 font-mono text-[10px] tracking-wider"
                                :class="badges[row.stockBadge]?.class"
                            >
                                {{ badges[row.stockBadge]?.text ?? row.stockBadge }}
                            </span>

                            <p v-if="row.hasOversold" class="mt-0.5 text-[11px] text-red-700">
                                {{ row.oversoldLineCount }} kalem stoksuz satıldı
                            </p>
                            <p v-if="row.hasUnmatched" class="mt-0.5 text-[11px] text-amber-800">
                                {{ row.unmatchedLineCount }} kalem eşleşmedi · stok düşülmedi
                            </p>
                        </td>

                        <td class="px-4 py-3 text-right">
                            <Link
                                :href="`/orders/${row.id}`"
                                class="rounded border border-stone-300 px-3 py-1.5 text-xs text-stone-700 transition hover:bg-stone-100"
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
