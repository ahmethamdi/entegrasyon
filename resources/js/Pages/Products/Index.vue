<script setup>
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import PanelLayout from '../../Layouts/PanelLayout.vue';

const props = defineProps({
    rows: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
});

const page = usePage();
const flashSuccess = computed(() => page.props.flash?.success);

const search = ref(props.filters.search ?? '');

function submitSearch() {
    router.get('/products', { search: search.value || undefined }, {
        preserveState: true,
        preserveScroll: true,
    });
}

const statusLabels = {
    active: 'Yayında',
    draft: 'Taslak',
    archived: 'Arşiv',
};

function statusClass(status) {
    if (status === 'active') return 'bg-emerald-50 text-emerald-800 border-emerald-200';
    if (status === 'draft') return 'bg-stone-50 text-stone-600 border-stone-200';
    return 'bg-stone-100 text-stone-500 border-stone-200';
}
</script>

<template>
    <PanelLayout>
        <div class="flex items-end justify-between">
            <div>
                <p class="font-mono text-[10px] uppercase tracking-widest text-stone-500">
                    Katalog
                </p>
                <h1 class="mt-1 text-2xl font-semibold tracking-tight text-stone-900">
                    Ürünler
                </h1>
            </div>

            <Link
                href="/products/create"
                class="rounded bg-stone-900 px-4 py-2 text-sm font-medium text-white transition hover:bg-stone-800"
            >
                Ürün ekle
            </Link>
        </div>

        <div
            v-if="flashSuccess"
            class="mt-6 rounded border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900"
        >
            {{ flashSuccess }}
        </div>

        <form class="mt-8 flex items-center gap-2" @submit.prevent="submitSearch">
            <input
                v-model="search"
                type="search"
                placeholder="SKU veya başlık ara"
                class="w-72 rounded border border-stone-300 px-3 py-1.5 text-sm focus:border-stone-500 focus:outline-none"
            >
            <button
                type="submit"
                class="rounded border border-stone-300 px-3 py-1.5 text-sm text-stone-700 transition hover:bg-stone-100"
            >
                Ara
            </button>
        </form>

        <div v-if="!rows.length" class="mt-8 rounded border border-dashed border-stone-300 p-10 text-center">
            <p class="text-sm text-stone-600">
                Henüz ürün yok. Senkron için önce katalog gerekiyor.
            </p>
            <Link href="/products/create" class="mt-3 inline-block text-sm font-medium text-stone-900 underline">
                İlk ürünü ekle
            </Link>
        </div>

        <div v-else class="mt-6 overflow-x-auto rounded border border-stone-200 bg-white">
            <table class="w-full text-sm">
                <thead class="border-b border-stone-200 bg-stone-50 text-left">
                    <tr class="font-mono text-[10px] uppercase tracking-wider text-stone-500">
                        <th class="px-4 py-3">Ürün</th>
                        <th class="px-4 py-3">Durum</th>
                        <th class="px-4 py-3 text-right">Varyant</th>
                        <th class="px-4 py-3 text-right">Toplam stok</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>

                <tbody>
                    <tr
                        v-for="row in rows"
                        :key="row.id"
                        class="border-b border-stone-100"
                        :class="row.hasOversold ? 'bg-red-50/60' : ''"
                    >
                        <td class="px-4 py-3">
                            <p class="text-sm text-stone-900">{{ row.title }}</p>
                            <p class="mt-0.5 font-mono text-xs text-stone-500">{{ row.sku }}</p>
                        </td>

                        <td class="px-4 py-3">
                            <span
                                class="rounded border px-2 py-0.5 font-mono text-[10px] tracking-wider"
                                :class="statusClass(row.status)"
                            >
                                {{ statusLabels[row.status] ?? row.status }}
                            </span>
                        </td>

                        <td class="px-4 py-3 text-right font-mono text-xs tabular-nums text-stone-700">
                            {{ row.variantCount }}
                        </td>

                        <!--
                            KIRPMA YOK (§17 · P0): negatif toplam olduğu gibi
                            gösterilir ve fazla satış işaretlenir. Uyarıyı
                            yalnızca stok ekranına saklamak, ürüne bakan
                            kullanıcıyı eksikten habersiz bırakırdı.
                        -->
                        <td class="px-4 py-3 text-right">
                            <span
                                class="font-mono text-xs font-semibold tabular-nums"
                                :class="row.hasOversold ? 'text-red-800' : 'text-stone-900'"
                            >
                                {{ row.totalOnHand }}
                            </span>
                            <p v-if="row.hasOversold" class="mt-0.5 text-[11px] text-red-700">
                                fazla satış var
                            </p>
                        </td>

                        <td class="px-4 py-3 text-right">
                            <div class="flex justify-end gap-2">
                                <Link
                                    :href="`/products/${row.id}/channels`"
                                    class="rounded border border-stone-300 px-3 py-1.5 text-xs text-stone-700 transition hover:bg-stone-100"
                                >
                                    Kanallar
                                </Link>
                                <Link
                                    :href="`/products/${row.id}/edit`"
                                    class="rounded border border-stone-300 px-3 py-1.5 text-xs text-stone-700 transition hover:bg-stone-100"
                                >
                                    Düzenle
                                </Link>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </PanelLayout>
</template>
