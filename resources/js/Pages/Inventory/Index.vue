<script setup>
import { router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import PanelLayout from '../../Layouts/PanelLayout.vue';

const props = defineProps({
    rows: { type: Array, default: () => [] },
    summary: { type: Object, default: () => ({}) },
    filters: { type: Object, default: () => ({}) },
});

const page = usePage();
const flashSuccess = computed(() => page.props.flash?.success);

const search = ref(props.filters.search ?? '');

function applyFilter(filter) {
    router.get('/inventory', { filter, search: search.value || undefined }, {
        preserveState: true,
        preserveScroll: true,
    });
}

function submitSearch() {
    applyFilter(props.filters.filter);
}

/**
 * Rozet etiketleri. Sıra önemlidir: kalıcı hata bekleyenden ÖNCE gelir,
 * çünkü kullanıcı müdahalesi bekler ve "bekliyor" demek satıcıyı
 * kendiliğinden düzelecek sanmaya iter.
 */
const badges = {
    error: { text: 'HATA', class: 'bg-red-50 text-red-800 border-red-200' },
    retrying: { text: 'YENİDEN DENENİYOR', class: 'bg-amber-50 text-amber-900 border-amber-300' },
    pending: { text: 'BEKLİYOR', class: 'bg-sky-50 text-sky-800 border-sky-200' },
    synced: { text: 'SENKRON', class: 'bg-emerald-50 text-emerald-800 border-emerald-200' },
    unlisted: { text: 'LİSTELENMEDİ', class: 'bg-stone-50 text-stone-600 border-stone-200' },
};

// ── düzeltme formu ────────────────────────────────────────────────────
const adjusting = ref(null);

const adjustForm = useForm({ variant_id: '', quantity: 1, note: '' });

function openAdjust(row) {
    adjusting.value = row.variantId;
    adjustForm.variant_id = row.variantId;
    // Fazla satışta eksik miktar hazır gelir: satıcının yapacağı en olası iş.
    adjustForm.quantity = row.shortfall > 0 ? row.shortfall : 1;
    adjustForm.note = '';
    adjustForm.clearErrors();
}

function submitAdjust() {
    adjustForm.post('/inventory/adjust', {
        preserveScroll: true,
        onSuccess: () => {
            adjusting.value = null;
            adjustForm.reset('note');
        },
    });
}

function money(row) {
    if (row.price === null || row.price === undefined) return '—';
    return `${row.price} ${row.currency ?? ''}`.trim();
}
</script>

<template>
    <PanelLayout>
        <div class="flex items-end justify-between">
            <div>
                <p class="font-mono text-[10px] uppercase tracking-widest text-stone-500">
                    Stok
                </p>
                <h1 class="mt-1 text-2xl font-semibold tracking-tight text-stone-900">
                    Ürün ve stok
                </h1>
            </div>
        </div>

        <div
            v-if="flashSuccess"
            class="mt-6 rounded border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900"
        >
            {{ flashSuccess }}
        </div>

        <!--
            FAZLA SATIŞ ÖZETİ ÜSTTE: satıcının önce görmesi gereken şey bu.
            Eksik miktar gizlenmez; kırpma yalnızca kanala giden yükte meşru.
        -->
        <div class="mt-6 grid gap-4 sm:grid-cols-3">
            <div class="rounded border border-stone-200 bg-white p-4">
                <p class="text-xs text-stone-500">Varyant</p>
                <p class="mt-1 text-2xl font-semibold text-stone-900">{{ summary.variantCount }}</p>
            </div>

            <div
                class="rounded border p-4"
                :class="summary.oversoldCount > 0
                    ? 'border-red-200 bg-red-50'
                    : 'border-stone-200 bg-white'"
            >
                <p class="text-xs" :class="summary.oversoldCount > 0 ? 'text-red-800' : 'text-stone-500'">
                    Fazla satılan
                </p>
                <p
                    class="mt-1 text-2xl font-semibold"
                    :class="summary.oversoldCount > 0 ? 'text-red-900' : 'text-stone-900'"
                >
                    {{ summary.oversoldCount }}
                </p>
            </div>

            <div
                class="rounded border p-4"
                :class="summary.totalShortfall > 0
                    ? 'border-red-200 bg-red-50'
                    : 'border-stone-200 bg-white'"
            >
                <p class="text-xs" :class="summary.totalShortfall > 0 ? 'text-red-800' : 'text-stone-500'">
                    Toplam eksik adet
                </p>
                <p
                    class="mt-1 text-2xl font-semibold"
                    :class="summary.totalShortfall > 0 ? 'text-red-900' : 'text-stone-900'"
                >
                    {{ summary.totalShortfall }}
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
                    Fazla satılan
                </button>
            </div>

            <form class="flex items-center gap-2" @submit.prevent="submitSearch">
                <input
                    v-model="search"
                    type="search"
                    placeholder="SKU ara"
                    class="w-56 rounded border border-stone-300 px-3 py-1.5 text-sm focus:border-stone-500 focus:outline-none"
                >
                <button
                    type="submit"
                    class="rounded border border-stone-300 px-3 py-1.5 text-sm text-stone-700 transition hover:bg-stone-100"
                >
                    Ara
                </button>
            </form>
        </div>

        <div v-if="!rows.length" class="mt-8 rounded border border-dashed border-stone-300 p-10 text-center">
            <p class="text-sm text-stone-600">Bu ölçütlerle varyant bulunamadı.</p>
        </div>

        <!-- liste -->
        <!-- Asgari genişlik sütunların dar ekranda sıkışmasını önler; kutu kayar. -->
        <div v-else class="mt-6 overflow-x-auto rounded border border-stone-200 bg-white">
            <table class="w-full min-w-3xl text-sm">
                <thead class="border-b border-stone-200 bg-stone-50 text-left">
                    <tr class="font-mono text-[10px] uppercase tracking-wider text-stone-500">
                        <th class="px-4 py-3">SKU</th>
                        <th class="px-4 py-3 text-right">Elde</th>
                        <th class="px-4 py-3 text-right">Rezerve</th>
                        <th class="px-4 py-3 text-right">Satılabilir</th>
                        <th class="px-4 py-3">Senkron</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>

                <tbody>
                    <template v-for="row in rows" :key="row.levelId">
                        <tr
                            class="border-b border-stone-100"
                            :class="row.isOversold ? 'bg-red-50/60' : ''"
                        >
                            <td class="px-4 py-3">
                                <!-- SKU bir KİMLİKTİR; kelime ortasından bölünürse okunmaz. -->
                                <p class="font-mono text-xs whitespace-nowrap text-stone-900">{{ row.sku }}</p>
                                <p class="mt-0.5 text-xs text-stone-500">
                                    {{ money(row) }} · {{ row.warehouse ?? '—' }}
                                </p>
                            </td>

                            <td class="px-4 py-3 text-right font-mono text-xs tabular-nums text-stone-700">
                                {{ row.onHand }}
                            </td>
                            <td class="px-4 py-3 text-right font-mono text-xs tabular-nums text-stone-700">
                                {{ row.reserved }}
                            </td>

                            <!--
                                NEGATİF DEĞER OLDUĞU GİBİ GÖSTERİLİR (§17 · P0).
                                Eksik miktar ayrıca yazılır: "kaç adet açıktasın".
                            -->
                            <td class="px-4 py-3 text-right">
                                <span
                                    class="font-mono text-xs font-semibold tabular-nums"
                                    :class="row.isOversold ? 'text-red-800' : 'text-stone-900'"
                                >
                                    {{ row.available }}
                                </span>
                                <p v-if="row.isOversold" class="mt-0.5 text-[11px] text-red-700">
                                    {{ row.shortfall }} adet eksik
                                </p>
                            </td>

                            <td class="px-4 py-3">
                                <span
                                    class="rounded border px-2 py-0.5 font-mono text-[10px] tracking-wider"
                                    :class="badges[row.sync.badge]?.class"
                                >
                                    {{ badges[row.sync.badge]?.text ?? row.sync.badge }}
                                </span>
                                <p v-if="row.sync.total" class="mt-0.5 text-[11px] text-stone-500">
                                    {{ row.sync.total }} kanal<span v-if="row.sync.dirty">
                                        · {{ row.sync.dirty }} bekliyor</span>
                                </p>
                            </td>

                            <td class="px-4 py-3 text-right">
                                <button
                                    type="button"
                                    class="rounded border border-stone-300 px-3 py-1.5 text-xs text-stone-700 transition hover:bg-stone-100"
                                    @click="openAdjust(row)"
                                >
                                    Düzelt
                                </button>
                            </td>
                        </tr>

                        <!--
                            DÜZELTME YOLU (§17 · P0). Fazla satışta eksik miktar
                            hazır gelir; düzeltme ledger'a MANUAL_ADJUSTMENT
                            hareketi olarak yazılır ve kanala geri gider.
                        -->
                        <tr v-if="adjusting === row.variantId" class="border-b border-stone-100 bg-stone-50">
                            <td colspan="6" class="px-4 py-4">
                                <form class="flex flex-wrap items-end gap-3" @submit.prevent="submitAdjust">
                                    <div>
                                        <label
                                            :for="`qty-${row.variantId}`"
                                            class="block text-xs font-medium text-stone-700"
                                        >
                                            Eklenecek adet
                                        </label>
                                        <input
                                            :id="`qty-${row.variantId}`"
                                            v-model.number="adjustForm.quantity"
                                            type="number"
                                            min="1"
                                            required
                                            class="mt-1 w-28 rounded border border-stone-300 px-3 py-1.5 text-sm focus:border-stone-500 focus:outline-none"
                                        >
                                    </div>

                                    <div class="min-w-64 flex-1">
                                        <label
                                            :for="`note-${row.variantId}`"
                                            class="block text-xs font-medium text-stone-700"
                                        >
                                            Not
                                        </label>
                                        <input
                                            :id="`note-${row.variantId}`"
                                            v-model="adjustForm.note"
                                            type="text"
                                            placeholder="Sayım farkı"
                                            class="mt-1 w-full rounded border border-stone-300 px-3 py-1.5 text-sm focus:border-stone-500 focus:outline-none"
                                        >
                                    </div>

                                    <button
                                        type="submit"
                                        :disabled="adjustForm.processing"
                                        class="rounded bg-stone-900 px-4 py-1.5 text-sm font-medium text-white transition hover:bg-stone-800 disabled:opacity-50"
                                    >
                                        Kaydet
                                    </button>
                                    <button
                                        type="button"
                                        class="text-sm text-stone-600 underline"
                                        @click="adjusting = null"
                                    >
                                        Vazgeç
                                    </button>

                                    <p class="w-full text-xs text-stone-500">
                                        Düzeltme stok defterine işlenir ve kanallara geri yazılır.
                                        Eksiltme için satış veya transfer hareketi kullanılır.
                                    </p>

                                    <p v-if="adjustForm.errors.quantity" class="w-full text-sm text-red-700">
                                        {{ adjustForm.errors.quantity }}
                                    </p>
                                </form>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
    </PanelLayout>
</template>
