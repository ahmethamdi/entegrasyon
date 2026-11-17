<script setup>
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';
import PanelLayout from '../../Layouts/PanelLayout.vue';
import { brandIcon, channelName, money } from '../../lib/format.js';
import { k, useI18n } from '../../lib/i18n';
import { usePermissions } from '../../lib/permissions.js';

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
/*
 * S7 · depo üyesi stoğu yalnız GÖRÜR: sayım, ekleme, içe aktarma ve ürün
 * ayrıntısı (katalog ekranları) ona kapalı. Bağlantı yerine düz metin
 * basılır — tıklanıp 403 görülmesin.
 */
const { can } = usePermissions();
const productLink = computed(() => (can('catalog.view') ? Link : 'span'));
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
/*
 * Etiket: logo + kanal adı ("Trendyol"). Aynı kanaldan iki mağaza bağlıysa
 * kanal adı ikisini ayırmaz → o zaman bağlantının kendi adı yazılır.
 * Tam ad her durumda `title`'da durur.
 */
const codeCounts = computed(() => {
    const seen = new Map();
    for (const row of props.rows ?? []) {
        for (const c of row.channels ?? []) {
            if (!seen.has(c.code)) seen.set(c.code, new Set());
            seen.get(c.code).add(c.id);
        }
    }
    return seen;
});

function chipName(c) {
    return (codeCounts.value.get(c.code)?.size ?? 0) > 1 ? c.label : channelName(c.code);
}

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

// ── satır içi düzenleme (ERP liste görünümü, 17 Kas 2026) ───────────
/*
 * Fiyat, alış ve rafta sayım hücreleri yalnız TEK SEÇENEKLİ, set olmayan
 * üründe düzenlenir (`countVariantId` dolu). Değişiklikler birikir, tek
 * "Kaydet ve gönder" ile `ProductInlineController`'a gider — o da ürün
 * ekranının kullandığı aynı action'ları çağırır.
 *
 * SAYIM, EKLEME DEĞİL: satıcı "rafta kaç tane var" yazar, fark sistemde
 * hesaplanır (`AdjustStock::setTo`). Rafta değeri VARSAYILAN depodur.
 */
const edits = ref({});
const editable = (row) => Boolean(row.countVariantId) && !row.isBundle && can('catalog.manage');

function original(row, field) {
    // Türkçe yazım: ondalık virgül (kaydederken `num` noktaya çevirir).
    if (field === 'price') return row.price == null ? '' : String(row.price).replace('.', ',');
    if (field === 'cost') return row.countCost == null ? '' : String(row.countCost).replace('.', ',');
    return String(row.countOnHand ?? 0);
}

function valueOf(row, field) {
    const key = `${row.countVariantId}:${field}`;
    return key in edits.value ? edits.value[key] : String(original(row, field));
}

function isEdited(row, field) {
    return `${row.countVariantId}:${field}` in edits.value;
}

function setEdit(row, field, value) {
    const key = `${row.countVariantId}:${field}`;
    const next = { ...edits.value };
    if (String(value).trim() === String(original(row, field))) {
        delete next[key];
    } else {
        next[key] = value;
    }
    edits.value = next;
}

const dirtyCount = computed(() => Object.keys(edits.value).length);

const saveForm = useForm({ changes: [] });

const num = (v) => Number(String(v).replace(/\s/g, '').replace(',', '.'));

function saveEdits() {
    const byVariant = {};
    for (const [key, value] of Object.entries(edits.value)) {
        const [variantId, field] = key.split(':');
        byVariant[variantId] ??= { variant_id: variantId };
        if (field === 'stock') byVariant[variantId].stock = Math.max(0, Math.round(num(value)));
        else if (field === 'cost' && String(value).trim() === '') byVariant[variantId].cost = null;
        else byVariant[variantId][field] = num(value);
    }
    saveForm.changes = Object.values(byVariant);
    saveForm.post('/products/inline', {
        preserveScroll: true,
        onSuccess: () => { edits.value = {}; },
    });
}

function margin(row) {
    const price = num(valueOf(row, 'price'));
    const cost = num(valueOf(row, 'cost'));
    if (!price || Number.isNaN(price) || valueOf(row, 'cost') === '' || Number.isNaN(cost)) return null;
    return Math.round(((price - cost) / price) * 100);
}

/*
 * YOĞUNLUK — görünüm tercihi, tarayıcıda (siparişlerle ortak anahtar değil:
 * depo ekranında rahat, sipariş ekranında sıkı isteyen olabilir).
 */
const dense = ref(true);

onMounted(() => {
    try {
        dense.value = window.localStorage.getItem('34p:urun-yogunluk') !== 'rahat';
    } catch {
        dense.value = true;
    }
});

function toggleDensity() {
    dense.value = !dense.value;
    try {
        window.localStorage.setItem('34p:urun-yogunluk', dense.value ? 'siki' : 'rahat');
    } catch {
        /* Gizli pencere: tercih yalnız bu sayfada geçerli. */
    }
}

const cell = computed(() => (dense.value ? 'px-3 py-1.5' : 'px-3 py-3'));
</script>


<template>
    <PanelLayout wide>
        <!-- başlık + eylemler -->
        <div class="flex flex-wrap items-end gap-3">
            <div class="min-w-0 flex-1">
                <p class="text-xs text-stone-600">{{ t('Mağazam') }}</p>
                <h1 class="text-xl font-bold tracking-tight text-stone-900">{{ t('Ürünler') }}</h1>
            </div>
            <div v-if="can('catalog.manage')" class="flex flex-wrap gap-2">
                <!--
                    Toplu içe aktarma ürün ekleme AKIŞININ YANINDA durur:
                    satıcı 500 ürünü tek tek giremeyeceğini tam burada görür.
                -->
                <Link href="/products/import" class="h-9 rounded-[10px] border border-stone-300 bg-white px-3 text-sm leading-9 font-medium text-stone-700 transition hover:bg-stone-100">{{ t('Toplu içe aktar') }}</Link>
                <Link href="/products/bulk-edit" class="h-9 rounded-[10px] border border-stone-300 bg-white px-3 text-sm leading-9 font-medium text-stone-700 transition hover:bg-stone-100">{{ t('Excel ile fiyat/stok') }}</Link>
                <Link href="/products/costs" class="h-9 rounded-[10px] border border-stone-300 bg-white px-3 text-sm leading-9 font-medium text-stone-700 transition hover:bg-stone-100">{{ t('Alış maliyetleri') }}</Link>
                <Link href="/products/bundles/create" class="h-9 rounded-[10px] border border-stone-300 bg-white px-3 text-sm leading-9 font-medium text-stone-700 transition hover:bg-stone-100">{{ t('Set oluştur') }}</Link>
                <Link href="/products/create" class="h-9 rounded-[10px] bg-brand-600 px-4 text-sm leading-9 font-semibold text-white transition hover:bg-brand-700">{{ t('Ürün ekle') }}</Link>
            </div>
        </div>

        <div
            v-if="flashSuccess"
            class="mt-4 rounded-[14px] border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900"
            role="status"
        >
            {{ flashSuccess }}
        </div>

        <div class="relative mt-4 overflow-hidden rounded-[14px] border border-stone-200 bg-white">
            <!-- sekmeler + arama + yoğunluk -->
            <div class="flex flex-wrap items-center gap-x-1 gap-y-2 border-b border-stone-200 px-3">
                <button
                    v-for="tab in tabs"
                    :key="tab.key"
                    type="button"
                    class="-mb-px whitespace-nowrap border-b-2 px-2.5 py-2.5 text-sm transition"
                    :class="filters.filter === tab.key ? 'border-brand-600 font-semibold text-stone-900' : 'border-transparent font-medium text-stone-600 hover:text-stone-900'"
                    :aria-pressed="filters.filter === tab.key"
                    @click="applyFilter(tab.key)"
                >
                    {{ t(tab.label) }}
                    <span
                        class="ml-1 rounded-full px-1.5 py-px text-[11px] font-semibold tabular-nums"
                        :class="(tab.key === 'problem' || tab.key === 'out') && tabCounts[tab.key] > 0 ? 'bg-red-100 text-red-800' : 'bg-stone-100 text-stone-700'"
                    >{{ tabCounts[tab.key] ?? 0 }}</span>
                </button>
                <span class="flex-1" />
                <form class="my-1.5 flex min-w-0 items-center gap-2" role="search" @submit.prevent="submitSearch">
                    <input
                        v-model="search"
                        type="search"
                        :placeholder="t('Ürün adı veya stok kodu')"
                        :aria-label="t('Ürün ara')"
                        class="h-8 w-full min-w-0 rounded-lg border border-stone-300 px-3 text-sm focus:border-ring focus:outline-2 focus:outline-offset-0 focus:outline-ring sm:w-64"
                    >
                    <button type="submit" class="h-8 shrink-0 rounded-lg border border-stone-300 px-3 text-sm text-stone-700 transition hover:bg-stone-100">{{ t('Ara') }}</button>
                </form>
                <button
                    type="button"
                    class="my-1.5 h-8 rounded-lg border border-stone-300 px-2.5 text-xs text-stone-700 transition hover:bg-stone-100"
                    @click="toggleDensity"
                >
                    {{ dense ? t('Yoğunluk: Sıkı') : t('Yoğunluk: Rahat') }}
                </button>
            </div>

            <!-- kaydedilmemiş değişiklikler -->
            <div
                v-if="dirtyCount"
                class="flex flex-wrap items-center gap-2 border-b border-amber-200 bg-amber-50 px-4 py-2"
                role="region"
                :aria-label="t('Kaydedilmemiş değişiklikler')"
            >
                <strong class="text-sm text-amber-900">{{ t(':count hücre değişti', { count: dirtyCount }) }}</strong>
                <span class="text-sm text-amber-800">· {{ t('kaydedince stok ve fiyat gönderimi açık kanallara gider') }}</span>
                <span class="flex-1" />
                <button type="button" class="h-8 rounded-lg border border-stone-300 bg-white px-3 text-sm text-stone-700 hover:bg-stone-100" @click="edits = {}">{{ t('Vazgeç') }}</button>
                <button
                    type="button"
                    class="h-8 rounded-lg bg-brand-600 px-3 text-sm font-semibold text-white hover:bg-brand-700 disabled:opacity-50"
                    :disabled="saveForm.processing"
                    @click="saveEdits"
                >
                    {{ t('Kaydet ve gönder') }}
                </button>
                <p v-if="Object.keys(saveForm.errors).length" class="w-full text-sm text-red-700">{{ Object.values(saveForm.errors)[0] }}</p>
            </div>

            <!-- Boş durumlar: hiç ürün yok ≠ bu filtrede ürün yok. -->
            <div v-if="!rows.length" class="p-10 text-center">
                <template v-if="tabCounts.all === 0 && !filters.search">
                    <p class="text-sm text-stone-600">{{ t('Henüz ürün yok. Mağazandan çekebilir ya da elle ekleyebilirsin.') }}</p>
                    <Link v-if="can('catalog.manage')" href="/products/import" class="mt-3 inline-block text-sm font-medium text-stone-900 underline">
                        {{ t('Ürünleri içe aktar') }}
                    </Link>
                </template>
                <p v-else class="text-sm text-stone-600">{{ t('Bu filtrede ürün yok.') }}</p>
            </div>

            <!--
                `relative` ŞART: `sr-only` etiketler `absolute`tur; kap
                konumlandırılmamışsa kaydırma kutusu onları kırpmaz.
            -->
            <div v-else class="relative max-h-[calc(100vh-230px)] min-h-[320px] overflow-auto">
                <table class="w-full min-w-[1180px] border-separate border-spacing-0 text-[13px]">
                    <thead class="text-left">
                        <tr class="[&>th]:sticky [&>th]:top-0 [&>th]:z-10 [&>th]:border-b [&>th]:border-stone-200 [&>th]:bg-stone-100 [&>th]:px-3 [&>th]:py-2 [&>th]:text-[11px] [&>th]:font-semibold [&>th]:uppercase [&>th]:tracking-wide [&>th]:text-stone-600 [&>th]:whitespace-nowrap">
                            <th>{{ t('Ürün') }}</th>
                            <th>{{ t('Stok kodu') }}</th>
                            <th class="text-right">{{ t('Satış fiyatı') }}</th>
                            <th class="text-right">{{ t('Alış') }}</th>
                            <th class="text-right">{{ t('Marj') }}</th>
                            <th class="text-right">{{ t('Rafta') }}</th>
                            <th class="text-right">{{ t('Satılabilir') }}</th>
                            <th>{{ t('Kanallar') }}</th>
                            <th><span class="sr-only">{{ t('İşlem') }}</span></th>
                        </tr>
                    </thead>

                    <tbody>
                        <template v-for="item in items" :key="item.key">
                            <!-- ── varyant grubu: başlık satırı ── -->
                            <tr v-if="item.type === 'group'" class="cursor-pointer bg-stone-50/70 hover:bg-stone-100/70 [&>td]:border-b [&>td]:border-stone-100" @click="toggle(item)">
                                <td :class="cell" colspan="2">
                                    <button type="button" class="flex items-center gap-2 text-left" :aria-expanded="isOpen(item)">
                                        <span class="w-3 text-stone-500 transition" :class="isOpen(item) ? 'rotate-90' : ''" aria-hidden="true">›</span>
                                        <span class="size-7 shrink-0 overflow-hidden rounded-md border border-stone-200 bg-stone-100">
                                            <img v-if="item.imageUrl" :src="item.imageUrl" alt="" class="h-full w-full object-cover" loading="lazy">
                                        </span>
                                        <span class="truncate font-semibold text-stone-900">{{ item.title }}</span>
                                        <span class="text-xs text-stone-500">{{ t(':count seçenek', { count: item.members.length }) }}</span>
                                    </button>
                                </td>
                                <td :class="cell" class="text-right tabular-nums text-stone-700">{{ priceText(item.minPrice, item.maxPrice, item.currency) }}</td>
                                <td :class="cell" />
                                <td :class="cell" />
                                <td :class="cell" />
                                <td :class="[cell, stockTone(item.available)]" class="text-right font-mono font-semibold tabular-nums">{{ item.available }}</td>
                                <td :class="cell">
                                    <span class="flex flex-wrap gap-1">
                                        <span
                                            v-for="c in item.channels"
                                            :key="c.id"
                                            class="inline-flex items-center gap-1 rounded-full border px-1.5 py-px text-[11px] font-medium"
                                            :class="chipStates[c.state].class"
                                            :title="`${c.label}: ${t(chipStates[c.state].text)}`"
                                        >
                                            <img v-if="brandIcon(c.code)" :src="brandIcon(c.code)" alt="" class="size-3.5 rounded-sm object-contain">
                                            <span aria-hidden="true">{{ chipStates[c.state].mark }}</span>
                                            <span class="sr-only">{{ chipName(c) }} — {{ t(chipStates[c.state].text) }}</span>
                                        </span>
                                    </span>
                                </td>
                                <td :class="cell" />
                            </tr>

                            <!-- ── ürün satırları (tek ürün ya da açık grubun üyeleri) ── -->
                            <template v-if="item.type === 'row' || isOpen(item)">
                                <tr
                                    v-for="row in (item.type === 'row' ? [item.row] : item.members)"
                                    :key="row.id"
                                    class="[&>td]:border-b [&>td]:border-stone-100"
                                    :class="row.shortfall ? 'bg-red-50/50' : 'hover:bg-stone-50'"
                                >
                                    <td :class="cell" class="max-w-[320px]">
                                        <span class="flex items-center gap-2" :class="item.type === 'group' ? 'pl-5' : ''">
                                            <span v-if="item.type === 'row'" class="size-7 shrink-0 overflow-hidden rounded-md border border-stone-200 bg-stone-100">
                                                <img v-if="row.imageUrl" :src="row.imageUrl" alt="" class="h-full w-full object-cover" loading="lazy">
                                            </span>
                                            <component :is="productLink" :href="`/products/${row.id}/edit`" class="truncate text-stone-900 underline-offset-2 hover:underline" :title="row.title">
                                                {{ row.title }}
                                            </component>
                                            <span v-if="row.status !== 'active'" class="shrink-0 rounded-full border border-stone-200 px-1.5 py-px text-[11px] text-stone-600">
                                                {{ row.status === 'draft' ? t('Taslak') : t('Arşiv') }}
                                            </span>
                                            <!-- SET: stok bileşenlerden türetilir, sayımı yapılmaz. -->
                                            <span v-if="row.isBundle" class="shrink-0 rounded-full border border-sky-200 bg-sky-50 px-1.5 py-px text-[11px] font-medium text-sky-800" :title="row.limitingSku ? t('Stoğu sınırlayan: :sku', { sku: row.limitingSku }) : undefined">
                                                {{ t('Set') }}
                                            </span>
                                        </span>
                                    </td>
                                    <td :class="cell" class="font-mono text-xs whitespace-nowrap text-stone-700">{{ row.sku }}</td>

                                    <td :class="dense ? 'px-1.5 py-1' : 'px-1.5 py-2'" class="text-right">
                                        <input
                                            v-if="editable(row) && row.maxPrice === null"
                                            :value="valueOf(row, 'price')"
                                            inputmode="decimal"
                                            :aria-label="t(':sku satış fiyatı', { sku: row.sku })"
                                            class="h-7 w-24 rounded-md border px-2 text-right tabular-nums focus:outline-2 focus:outline-offset-0 focus:outline-ring"
                                            :class="isEdited(row, 'price') ? 'border-amber-400 bg-amber-50' : 'border-transparent bg-transparent hover:border-stone-300 hover:bg-white'"
                                            @input="setEdit(row, 'price', $event.target.value)"
                                        >
                                        <span v-else class="px-1.5 tabular-nums text-stone-700">{{ priceText(row.price, row.maxPrice, row.currency) }}</span>
                                    </td>

                                    <td :class="dense ? 'px-1.5 py-1' : 'px-1.5 py-2'" class="text-right">
                                        <input
                                            v-if="editable(row)"
                                            :value="valueOf(row, 'cost')"
                                            inputmode="decimal"
                                            :placeholder="t('gir')"
                                            :aria-label="t(':sku alış maliyeti', { sku: row.sku })"
                                            class="h-7 w-24 rounded-md border px-2 text-right tabular-nums placeholder:text-stone-400 focus:outline-2 focus:outline-offset-0 focus:outline-ring"
                                            :class="isEdited(row, 'cost') ? 'border-amber-400 bg-amber-50' : 'border-transparent bg-transparent hover:border-stone-300 hover:bg-white'"
                                            @input="setEdit(row, 'cost', $event.target.value)"
                                        >
                                        <span v-else class="px-1.5 text-stone-400">—</span>
                                    </td>

                                    <td :class="cell" class="text-right tabular-nums">
                                        <span v-if="margin(row) !== null" :class="margin(row) < 20 ? 'font-semibold text-red-700' : 'text-stone-700'">%{{ margin(row) }}</span>
                                        <span v-else class="text-stone-400">—</span>
                                    </td>

                                    <td :class="dense ? 'px-1.5 py-1' : 'px-1.5 py-2'" class="text-right">
                                        <input
                                            v-if="editable(row)"
                                            :value="valueOf(row, 'stock')"
                                            inputmode="numeric"
                                            :aria-label="t(':sku stoğunu say', { sku: row.sku })"
                                            :title="t('Rafta kaç adet var? (varsayılan depo)')"
                                            class="h-7 w-20 rounded-md border px-2 text-right font-mono tabular-nums focus:outline-2 focus:outline-offset-0 focus:outline-ring"
                                            :class="isEdited(row, 'stock') ? 'border-amber-400 bg-amber-50' : 'border-transparent bg-transparent hover:border-stone-300 hover:bg-white'"
                                            @input="setEdit(row, 'stock', $event.target.value)"
                                        >
                                        <span v-else class="px-1.5 text-stone-400">—</span>
                                    </td>

                                    <!-- KIRPMA YOK (§17 · P0): eksik açıkça yazılır. -->
                                    <td :class="cell" class="text-right whitespace-nowrap">
                                        <span class="font-mono font-semibold tabular-nums" :class="stockTone(row.available)">{{ row.available }}</span>
                                        <span v-if="row.shortfall" class="block text-[11px] text-red-700">{{ t(':count adet eksik', { count: row.shortfall }) }}</span>
                                    </td>

                                    <td :class="cell">
                                        <span class="flex flex-nowrap gap-1">
                                            <component
                                                :is="productLink"
                                                v-for="c in row.channels"
                                                :key="c.id"
                                                :href="`/products/${row.id}/channels`"
                                                class="inline-flex items-center gap-1 rounded-full border px-1.5 py-px text-[11px] font-medium transition hover:brightness-95"
                                                :class="chipStates[c.state].class"
                                                :title="`${c.label}: ${t(chipStates[c.state].text)}${c.stockSync ? '' : ' · ' + t('stok gönderimi kapalı')}`"
                                            >
                                                <img v-if="brandIcon(c.code)" :src="brandIcon(c.code)" alt="" class="size-3.5 rounded-sm object-contain">
                                                <span aria-hidden="true">{{ chipStates[c.state].mark }}</span>
                                                {{ chipName(c) }}
                                                <span class="sr-only">— {{ t(chipStates[c.state].text) }}</span>
                                            </component>
                                            <Link
                                                v-if="!row.channels.length && can('catalog.manage')"
                                                :href="`/products/${row.id}/channels`"
                                                class="rounded-full border border-dashed border-stone-300 px-2 py-px text-[11px] text-stone-600 transition hover:bg-stone-100"
                                            >
                                                {{ t('Kanala gönder') }}
                                            </Link>
                                        </span>
                                    </td>

                                    <td :class="cell" class="text-right">
                                        <Link v-if="can('catalog.view')" :href="`/products/${row.id}/edit`" class="text-xs text-stone-600 underline-offset-2 hover:underline">{{ t('Aç') }}</Link>
                                    </td>
                                </tr>
                            </template>
                        </template>
                    </tbody>
                </table>
            </div>

            <!-- alt bilgi + sayfalama -->
            <nav
                v-if="rows.length"
                class="flex flex-wrap items-center gap-3 border-t border-stone-200 px-4 py-2 text-xs text-stone-600"
                :aria-label="t('Sayfalar')"
            >
                <span>{{ t(':total ürün', { total: pagination.total }) }}</span>
                <span class="hidden sm:inline">{{ t('Fiyat, alış ve rafta hücrelerine tıklayıp yazın; yalnız tek seçenekli ürünlerde.') }}</span>
                <span class="flex-1" />
                <template v-if="pagination.lastPage > 1">
                    <button
                        type="button"
                        class="h-7 rounded-lg border border-stone-300 px-2.5 transition hover:bg-stone-100 disabled:opacity-40"
                        :disabled="pagination.page <= 1"
                        @click="go({ page: pagination.page - 1 })"
                    >
                        ← {{ t('Önceki') }}
                    </button>
                    <span class="tabular-nums">{{ t(':page / :last', { page: pagination.page, last: pagination.lastPage }) }}</span>
                    <button
                        type="button"
                        class="h-7 rounded-lg border border-stone-300 px-2.5 transition hover:bg-stone-100 disabled:opacity-40"
                        :disabled="pagination.page >= pagination.lastPage"
                        @click="go({ page: pagination.page + 1 })"
                    >
                        {{ t('Sonraki') }} →
                    </button>
                </template>
            </nav>
        </div>
    </PanelLayout>
</template>
