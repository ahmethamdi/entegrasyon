<script setup>
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, nextTick, ref } from 'vue';
import PageHeader from '../../Components/PageHeader.vue';
import PanelLayout from '../../Layouts/PanelLayout.vue';
import { money } from '../../lib/format.js';
import { k, useI18n } from '../../lib/i18n';

/*
 * ÜRÜNLER + STOK — tek ekran (5 Ekim panel yenilemesi, kullanıcı kararı).
 *
 * Satıcı stoğu ürünün YANINDA görür ve sayar; ayrı "Stok" ekranı yok.
 * Kanalda aynı ürünün varyantı olan satırlar (`groupKey`) tek başlık
 * altında toplanır — gruplama YALNIZ EKRANDA, model değişmez.
 */
const props = defineProps({
    rows: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
    tabCounts: { type: Object, default: () => ({}) },
    pagination: { type: Object, default: () => ({ page: 1, lastPage: 1, total: 0 }) },
    lowStock: { type: Number, default: 5 },
});

const { t } = useI18n();
const page = usePage();
const flashSuccess = computed(() => page.props.flash?.success);

const search = ref(props.filters.search ?? '');

const tabs = [
    { key: 'all', label: k('Tümü') },
    { key: 'low', label: k('Stoğu az') },
    { key: 'out', label: k('Stoksuz') },
    { key: 'problem', label: k('Sorunlu') },
];

function go(params) {
    router.get('/products', {
        filter: props.filters.filter !== 'all' ? props.filters.filter : undefined,
        search: search.value || undefined,
        ...params,
    }, { preserveState: true, preserveScroll: true });
}

function applyFilter(filter) {
    go({ filter: filter === 'all' ? undefined : filter, page: undefined });
}

function submitSearch() {
    go({ page: undefined });
}

/*
 * Kanal çipi — renk TEK sinyal değil: her durumun kendi işareti ve
 * yazısı var (renk körlüğünde yeşil/kırmızı karışır).
 */
const chipStates = {
    live: { text: k('Satışta'), mark: '✓', class: 'border-emerald-200 bg-emerald-50 text-emerald-800' },
    pending: { text: k('Bekliyor'), mark: '…', class: 'border-sky-200 bg-sky-50 text-sky-800' },
    problem: { text: k('Sorun var'), mark: '!', class: 'border-red-200 bg-red-50 text-red-800' },
};

const statePriority = { problem: 3, pending: 2, live: 1 };

/*
 * Satırları gruplara çevir. Aynı `groupKey` sunucu sıralamasında (başlık,
 * SKU) bitişik düşer; tek üyeli grup düz satır olarak çizilir.
 */
const items = computed(() => {
    const out = [];
    const byKey = new Map();

    for (const row of props.rows) {
        if (row.groupKey && byKey.has(row.groupKey)) {
            byKey.get(row.groupKey).members.push(row);
            continue;
        }

        const item = { key: row.groupKey ?? row.id, members: [row] };
        out.push(item);
        if (row.groupKey) byKey.set(row.groupKey, item);
    }

    return out.map((item) => (item.members.length === 1
        ? { type: 'row', key: item.key, row: item.members[0] }
        : { type: 'group', key: item.key, ...summarize(item.members) }));
});

function summarize(members) {
    const prices = members.map((m) => Number(m.price)).filter((p) => !Number.isNaN(p));
    const channels = new Map();

    for (const m of members) {
        for (const c of m.channels) {
            const seen = channels.get(c.id);
            if (!seen || statePriority[c.state] > statePriority[seen.state]) channels.set(c.id, c);
        }
    }

    return {
        members,
        title: members[0].title,
        imageUrl: members.find((m) => m.imageUrl)?.imageUrl ?? null,
        currency: members[0].currency,
        minPrice: prices.length ? Math.min(...prices) : null,
        maxPrice: prices.length ? Math.max(...prices) : null,
        available: members.reduce((sum, m) => sum + m.available, 0),
        shortfall: members.reduce((sum, m) => sum + m.shortfall, 0),
        channels: [...channels.values()],
    };
}

/* Sorunlu ya da eksiği olan grup AÇIK başlar: satıcı içine bakmalı. */
const openGroups = ref(new Set());

function isOpen(group) {
    return openGroups.value.has(group.key)
        || group.shortfall > 0
        || group.channels.some((c) => c.state === 'problem');
}

function toggle(group) {
    const next = new Set(openGroups.value);
    next.has(group.key) ? next.delete(group.key) : next.add(group.key);
    openGroups.value = next;
}

function priceText(min, max, currency) {
    if (min === null || min === undefined) return '—';
    if (max !== null && max !== undefined && Number(max) !== Number(min)) {
        return `${money(min, currency)} – ${money(max, currency)}`;
    }
    return money(min, currency);
}

function stockTone(available) {
    if (available < 0) return 'text-red-800';
    if (available === 0) return 'text-stone-500';
    if (available <= props.lowStock) return 'text-amber-800';
    return 'text-stone-900';
}

// ── yerinde sayım ─────────────────────────────────────────────────────
/*
 * SAYIM, EKLEME DEĞİL: satıcı "rafta kaç tane var" yazar, fark sistemde
 * hesaplanır (`AdjustStock::setTo`). Eklenecek adedi sormak satıcıya
 * çıkarma yaptırırdı ve stok düşürmenin yolu yoktu.
 */
const counting = ref(null);
const countForm = useForm({ variant_id: '', target: 0 });

async function openCount(row) {
    counting.value = row.id;
    countForm.variant_id = row.countVariantId;
    countForm.target = Math.max(0, row.countOnHand ?? 0);
    countForm.clearErrors();
    await nextTick();
    document.getElementById(`count-${row.id}`)?.select();
}

function submitCount() {
    countForm.post('/inventory/adjust', {
        preserveScroll: true,
        onSuccess: () => { counting.value = null; },
    });
}
</script>

<template>
    <PanelLayout>
        <PageHeader :section="t('Mağazam')" :title="t('Ürünler')">
            <template #actions>
                <!--
                    Toplu içe aktarma ürün ekleme AKIŞININ YANINDA durur:
                    satıcı 500 ürünü tek tek giremeyeceğini tam burada görür.
                -->
                <Link
                    href="/products/import"
                    class="rounded-md border border-stone-300 px-4 py-2 text-sm font-medium text-stone-700 transition hover:bg-stone-100"
                >
                    {{ t('Toplu içe aktar') }}
                </Link>
                <Link
                    href="/products/create"
                    class="rounded-md bg-stone-900 px-4 py-2 text-sm font-medium text-white transition hover:bg-stone-700"
                >
                    {{ t('Ürün ekle') }}
                </Link>
            </template>

            <template #toolbar>
                <div class="flex flex-wrap items-center gap-3">
                    <!-- Sekmeler sayılarıyla: satıcı tıklamadan "3 ürün stoksuz" görür. -->
                    <div class="-mx-1 flex max-w-full gap-1 overflow-x-auto px-1" role="group" :aria-label="t('Ürün filtresi')">
                        <button
                            v-for="tab in tabs"
                            :key="tab.key"
                            type="button"
                            class="flex shrink-0 items-center gap-1.5 rounded-full border px-3 py-1.5 text-sm transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                            :class="filters.filter === tab.key
                                ? 'border-stone-900 bg-stone-900 text-white'
                                : 'border-stone-300 bg-white text-stone-700 hover:bg-stone-100'"
                            :aria-pressed="filters.filter === tab.key"
                            @click="applyFilter(tab.key)"
                        >
                            {{ t(tab.label) }}
                            <span
                                class="rounded-full px-1.5 text-xs tabular-nums"
                                :class="filters.filter === tab.key
                                    ? 'bg-white/20'
                                    : (tab.key === 'problem' || tab.key === 'out') && tabCounts[tab.key] > 0
                                        ? 'bg-red-100 text-red-800'
                                        : 'bg-stone-100 text-stone-600'"
                            >{{ tabCounts[tab.key] ?? 0 }}</span>
                        </button>
                    </div>

                    <form class="flex min-w-0 flex-1 items-center gap-2 sm:flex-none" role="search" @submit.prevent="submitSearch">
                        <input
                            v-model="search"
                            type="search"
                            :placeholder="t('Ürün adı veya stok kodu')"
                            :aria-label="t('Ürün ara')"
                            class="w-full min-w-0 rounded-md border border-stone-300 px-3 py-1.5 text-sm focus:border-ring focus:outline-2 focus:outline-offset-0 focus:outline-ring sm:w-64"
                        >
                        <button
                            type="submit"
                            class="shrink-0 rounded-md border border-stone-300 px-3 py-1.5 text-sm text-stone-700 transition hover:bg-stone-100"
                        >
                            {{ t('Ara') }}
                        </button>
                    </form>
                </div>
            </template>
        </PageHeader>

        <div
            v-if="flashSuccess"
            class="mt-6 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900"
            role="status"
        >
            {{ flashSuccess }}
        </div>

        <!-- Boş durumlar: hiç ürün yok ≠ bu filtrede ürün yok. -->
        <div v-if="!rows.length" class="mt-8 rounded-lg border border-dashed border-stone-300 p-10 text-center">
            <template v-if="tabCounts.all === 0 && !filters.search">
                <p class="text-sm text-stone-600">{{ t('Henüz ürün yok. Mağazandan çekebilir ya da elle ekleyebilirsin.') }}</p>
                <Link href="/products/import" class="mt-3 inline-block text-sm font-medium text-stone-900 underline">
                    {{ t('Ürünleri içe aktar') }}
                </Link>
            </template>
            <p v-else class="text-sm text-stone-600">{{ t('Bu filtrede ürün yok.') }}</p>
        </div>

        <ul v-else class="mt-6 divide-y divide-stone-100 overflow-hidden rounded-lg border border-stone-200 bg-white">
            <template v-for="item in items" :key="item.key">
                <!-- ── varyant grubu ─────────────────────────────── -->
                <li v-if="item.type === 'group'">
                    <button
                        type="button"
                        class="flex w-full items-center gap-3 px-4 py-3 text-left transition hover:bg-stone-50 focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-ring"
                        :aria-expanded="isOpen(item)"
                        @click="toggle(item)"
                    >
                        <span class="h-12 w-12 shrink-0 overflow-hidden rounded-md border border-stone-200 bg-stone-100">
                            <img v-if="item.imageUrl" :src="item.imageUrl" alt="" class="h-full w-full object-cover" loading="lazy">
                            <svg v-else class="m-auto mt-3.5 h-5 w-5 text-stone-300" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M3 6l7-3 7 3-7 3z M3 6v8l7 3 7-3V6 M10 9v8" stroke-linecap="round" stroke-linejoin="round" /></svg>
                        </span>

                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-sm font-medium text-stone-900">{{ item.title }}</span>
                            <span class="mt-0.5 block text-xs text-stone-500">
                                {{ t(':count seçenek', { count: item.members.length }) }} · {{ priceText(item.minPrice, item.maxPrice, item.currency) }}
                            </span>
                            <span v-if="item.channels.length" class="mt-1.5 flex flex-wrap gap-1">
                                <span
                                    v-for="c in item.channels"
                                    :key="c.id"
                                    class="inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-[11px] font-medium"
                                    :class="chipStates[c.state].class"
                                    :title="`${c.label}: ${t(chipStates[c.state].text)}`"
                                >
                                    <span aria-hidden="true">{{ chipStates[c.state].mark }}</span>
                                    {{ c.label }}
                                    <span class="sr-only">— {{ t(chipStates[c.state].text) }}</span>
                                </span>
                            </span>
                        </span>

                        <span class="shrink-0 text-right">
                            <span class="block font-mono text-sm font-semibold tabular-nums" :class="stockTone(item.available)">
                                {{ item.available }}
                            </span>
                            <span class="block text-[11px] text-stone-500">{{ t('toplam stok') }}</span>
                        </span>

                        <span class="shrink-0 text-stone-400 transition" :class="isOpen(item) ? 'rotate-90' : ''" aria-hidden="true">›</span>
                    </button>

                    <ul v-if="isOpen(item)" class="border-t border-stone-100 bg-stone-50/60">
                        <li v-for="row in item.members" :key="row.id" class="border-b border-stone-100 last:border-b-0">
                            <div class="flex items-center gap-3 py-2.5 pl-8 pr-4 sm:pl-19">
                                <div class="min-w-0 flex-1">
                                    <p class="font-mono text-xs whitespace-nowrap text-stone-700">{{ row.sku }}</p>
                                    <p class="mt-0.5 text-xs text-stone-500">{{ priceText(row.price, row.maxPrice, row.currency) }}</p>
                                </div>
                                <div class="shrink-0 text-right">
                                    <button
                                        v-if="row.countVariantId"
                                        type="button"
                                        class="rounded-md border border-transparent px-2 py-1 font-mono text-sm font-semibold tabular-nums transition hover:border-stone-300 hover:bg-white focus-visible:outline-2 focus-visible:outline-ring"
                                        :class="stockTone(row.available)"
                                        :aria-label="t(':sku stoğunu say', { sku: row.sku })"
                                        @click="openCount(row)"
                                    >
                                        {{ row.available }}
                                    </button>
                                    <span v-else class="font-mono text-sm font-semibold tabular-nums" :class="stockTone(row.available)">{{ row.available }}</span>
                                    <p v-if="row.shortfall" class="text-[11px] text-red-700">{{ t(':count adet eksik', { count: row.shortfall }) }}</p>
                                </div>
                                <Link
                                    :href="`/products/${row.id}/edit`"
                                    class="shrink-0 text-xs text-stone-600 underline-offset-2 hover:underline"
                                >
                                    {{ t('Aç') }}
                                </Link>
                            </div>
                            <form
                                v-if="counting === row.id"
                                class="flex flex-wrap items-end gap-2 pb-3 pl-8 pr-4 sm:pl-19"
                                @submit.prevent="submitCount"
                            >
                                <label :for="`count-${row.id}`" class="w-full text-xs font-medium text-stone-700">
                                    {{ t('Rafta kaç adet var?') }}
                                </label>
                                <input
                                    :id="`count-${row.id}`"
                                    v-model.number="countForm.target"
                                    type="number"
                                    min="0"
                                    inputmode="numeric"
                                    required
                                    class="w-28 rounded-md border border-stone-300 px-3 py-1.5 text-sm focus:border-ring focus:outline-2 focus:outline-offset-0 focus:outline-ring"
                                >
                                <button
                                    type="submit"
                                    :disabled="countForm.processing"
                                    class="rounded-md bg-stone-900 px-4 py-1.5 text-sm font-medium text-white transition hover:bg-stone-700 disabled:opacity-50"
                                >
                                    {{ t('Kaydet') }}
                                </button>
                                <button type="button" class="px-2 py-1.5 text-sm text-stone-600 underline" @click="counting = null">
                                    {{ t('Vazgeç') }}
                                </button>
                                <p v-if="countForm.errors.target" class="w-full text-sm text-red-700">{{ countForm.errors.target }}</p>
                            </form>
                        </li>
                    </ul>
                </li>

                <!-- ── tek ürün ──────────────────────────────────── -->
                <li v-else :class="item.row.shortfall ? 'bg-red-50/50' : ''">
                    <div class="flex items-center gap-3 px-4 py-3">
                        <Link :href="`/products/${item.row.id}/edit`" class="h-12 w-12 shrink-0 overflow-hidden rounded-md border border-stone-200 bg-stone-100" tabindex="-1" aria-hidden="true">
                            <img v-if="item.row.imageUrl" :src="item.row.imageUrl" alt="" class="h-full w-full object-cover" loading="lazy">
                            <svg v-else class="m-auto mt-3.5 h-5 w-5 text-stone-300" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M3 6l7-3 7 3-7 3z M3 6v8l7 3 7-3V6 M10 9v8" stroke-linecap="round" stroke-linejoin="round" /></svg>
                        </Link>

                        <div class="min-w-0 flex-1">
                            <Link
                                :href="`/products/${item.row.id}/edit`"
                                class="block truncate text-sm font-medium text-stone-900 underline-offset-2 hover:underline"
                            >
                                {{ item.row.title }}
                            </Link>
                            <p class="mt-0.5 text-xs text-stone-500">
                                <span class="font-mono whitespace-nowrap">{{ item.row.sku }}</span>
                                · {{ priceText(item.row.price, item.row.maxPrice, item.row.currency) }}
                                <span v-if="item.row.status !== 'active'" class="ml-1 rounded-full border border-stone-200 px-1.5 py-px text-[11px] text-stone-600">
                                    {{ item.row.status === 'draft' ? t('Taslak') : t('Arşiv') }}
                                </span>
                            </p>
                            <div class="mt-1.5 flex flex-wrap gap-1">
                                <Link
                                    v-for="c in item.row.channels"
                                    :key="c.id"
                                    :href="`/products/${item.row.id}/channels`"
                                    class="inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-[11px] font-medium transition hover:brightness-95"
                                    :class="chipStates[c.state].class"
                                    :title="`${c.label}: ${t(chipStates[c.state].text)}`"
                                >
                                    <span aria-hidden="true">{{ chipStates[c.state].mark }}</span>
                                    {{ c.label }}
                                    <span class="sr-only">— {{ t(chipStates[c.state].text) }}</span>
                                </Link>
                                <Link
                                    v-if="!item.row.channels.length"
                                    :href="`/products/${item.row.id}/channels`"
                                    class="rounded-full border border-dashed border-stone-300 px-2 py-0.5 text-[11px] text-stone-600 transition hover:bg-stone-100"
                                >
                                    {{ t('Kanala gönder') }}
                                </Link>
                            </div>
                        </div>

                        <div class="shrink-0 text-right">
                            <button
                                v-if="item.row.countVariantId"
                                type="button"
                                class="rounded-md border border-stone-200 px-2.5 py-1 font-mono text-sm font-semibold tabular-nums transition hover:border-stone-400 hover:bg-white focus-visible:outline-2 focus-visible:outline-ring"
                                :class="stockTone(item.row.available)"
                                :aria-label="t(':sku stoğunu say', { sku: item.row.sku })"
                                @click="openCount(item.row)"
                            >
                                {{ item.row.available }}
                            </button>
                            <span v-else class="font-mono text-sm font-semibold tabular-nums" :class="stockTone(item.row.available)">
                                {{ item.row.available }}
                            </span>
                            <!-- KIRPMA YOK (§17 · P0): eksik açıkça yazılır. -->
                            <p v-if="item.row.shortfall" class="mt-0.5 text-[11px] text-red-700">
                                {{ t(':count adet eksik', { count: item.row.shortfall }) }}
                            </p>
                            <p v-else class="mt-0.5 text-[11px] text-stone-500">{{ t('stok') }}</p>
                        </div>
                    </div>

                    <form
                        v-if="counting === item.row.id"
                        class="flex flex-wrap items-end gap-2 border-t border-stone-100 bg-stone-50 px-4 py-3"
                        @submit.prevent="submitCount"
                    >
                        <label :for="`count-${item.row.id}`" class="w-full text-xs font-medium text-stone-700">
                            {{ t('Rafta kaç adet var?') }}
                        </label>
                        <input
                            :id="`count-${item.row.id}`"
                            v-model.number="countForm.target"
                            type="number"
                            min="0"
                            inputmode="numeric"
                            required
                            class="w-28 rounded-md border border-stone-300 px-3 py-1.5 text-sm focus:border-ring focus:outline-2 focus:outline-offset-0 focus:outline-ring"
                        >
                        <button
                            type="submit"
                            :disabled="countForm.processing"
                            class="rounded-md bg-stone-900 px-4 py-1.5 text-sm font-medium text-white transition hover:bg-stone-700 disabled:opacity-50"
                        >
                            {{ t('Kaydet') }}
                        </button>
                        <button type="button" class="px-2 py-1.5 text-sm text-stone-600 underline" @click="counting = null">
                            {{ t('Vazgeç') }}
                        </button>
                        <p class="w-full text-xs text-stone-500">{{ t('Fark kaydedilir ve bağlı kanallara gönderilir.') }}</p>
                        <p v-if="countForm.errors.target" class="w-full text-sm text-red-700">{{ countForm.errors.target }}</p>
                    </form>
                </li>
            </template>
        </ul>

        <nav
            v-if="pagination.lastPage > 1"
            class="mt-4 flex items-center justify-between text-sm text-stone-600"
            :aria-label="t('Sayfalar')"
        >
            <button
                type="button"
                class="rounded-md border border-stone-300 px-3 py-1.5 transition hover:bg-stone-100 disabled:opacity-40"
                :disabled="pagination.page <= 1"
                @click="go({ page: pagination.page - 1 })"
            >
                ← {{ t('Önceki') }}
            </button>
            <span class="tabular-nums">{{ t(':page / :last', { page: pagination.page, last: pagination.lastPage }) }}</span>
            <button
                type="button"
                class="rounded-md border border-stone-300 px-3 py-1.5 transition hover:bg-stone-100 disabled:opacity-40"
                :disabled="pagination.page >= pagination.lastPage"
                @click="go({ page: pagination.page + 1 })"
            >
                {{ t('Sonraki') }} →
            </button>
        </nav>
    </PanelLayout>
</template>
