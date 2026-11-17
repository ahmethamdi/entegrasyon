<script setup>
import { Link, router, useForm } from '@inertiajs/vue3';
import { computed, onMounted, ref, watch } from 'vue';
import PanelLayout from '../../Layouts/PanelLayout.vue';
import { brandIcon, channelName, money, orderState, toneClass } from '../../lib/format.js';
import { intlLocale, k, useI18n } from '../../lib/i18n';
import { usePermissions } from '../../lib/permissions.js';

/*
 * ERP LİSTE GÖRÜNÜMÜ (17 Kas 2026 kullanıcı kararı — tuval "Yön 1 · Yoğun
 * liste"): tam genişlik, sıkı satır, sabit başlık, sekme sayaçları, koyu
 * toplu işlem şeridi. Özet kartları sekme sayaçlarına taşındı; fazla satış
 * ve tanınmayan ürün sayısı GİZLENMEZ (sekmede renkli sayaç + satır tonu).
 */
const props = defineProps({
    rows: { type: Array, default: () => [] },
    summary: { type: Object, default: () => ({}) },
    filters: { type: Object, default: () => ({}) },
});

const { t } = useI18n();
// Toplu işlem (kargo, etiket, fatura) depo işidir; salt okunur üye seçim kutusu görmez.
const { can } = usePermissions();

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

const tabs = computed(() => [
    { key: 'all', label: k('Tümü'), count: props.summary.orderCount, tone: 'muted' },
    { key: 'awaiting_shipment', label: k('Kargo bekleyen'), count: props.summary.awaitingCount, tone: 'muted' },
    { key: 'oversold', label: k('Fazla satış'), count: props.summary.oversoldOrderCount, tone: 'error' },
    { key: 'unmatched', label: k('Tanınmayan ürün'), count: props.summary.unmatchedOrderCount, tone: 'warning' },
]);

function countClass(tab) {
    if (tab.tone === 'error' && tab.count > 0) return 'bg-red-100 text-red-800';
    if (tab.tone === 'warning' && tab.count > 0) return 'bg-amber-100 text-amber-900';
    return 'bg-stone-100 text-stone-700';
}

/**
 * Stok rozetleri. Sıra önemlidir: FAZLA SATIŞ eşleşmemişten ÖNCE gelir,
 * çünkü fazla satış satılmış ve stoğu eksiye düşmüş bir kalemdir — kargo
 * çıkışı gerçekten tehlikededir. Eşleşmemiş satır henüz stoğa dokunmamıştır.
 */
const badges = {
    OVERSOLD: { text: k('Fazla satış'), class: 'bg-red-50 text-red-800 border-red-200' },
    PENDING: { text: k('Stok düşülmedi'), class: 'bg-amber-50 text-amber-900 border-amber-200' },
    APPLIED: { text: k('Stok düştü'), class: 'bg-stone-50 text-stone-600 border-stone-200' },
};

const invoiceLabels = {
    issued: { text: k('Kesildi'), class: 'text-stone-600' },
    pending: { text: k('Bekliyor'), class: 'font-medium text-amber-800' },
    issuing: { text: k('Bekliyor'), class: 'font-medium text-amber-800' },
    failed: { text: k('Hata'), class: 'font-medium text-red-700' },
};

/*
 * YOĞUNLUK — görünüm tercihi, tarayıcıda. Sıkı (varsayılan) ERP satırı;
 * rahat, dokunmatik ekranda depo kullanımı için.
 */
const dense = ref(true);

onMounted(() => {
    try {
        dense.value = window.localStorage.getItem('34p:siparis-yogunluk') !== 'rahat';
    } catch {
        dense.value = true;
    }
});

function toggleDensity() {
    dense.value = !dense.value;
    try {
        window.localStorage.setItem('34p:siparis-yogunluk', dense.value ? 'siki' : 'rahat');
    } catch {
        /* Gizli pencere: tercih yalnız bu sayfada geçerli. */
    }
}

const cell = computed(() => (dense.value ? 'px-3 py-1.5' : 'px-3 py-3'));

/*
 * TOPLU DEPO İŞLEMİ (S2). Seçim yalnız bu sayfadaki satırlardır; sunucu
 * kimlikleri kiracı içinde yeniden çözer. Form tek kullanımlık anahtar
 * taşır: çift tıklama ya da yeniden gönderim AYNI toplu işi açar.
 */
const selected = ref([]);
const allSelected = computed(() => props.rows.length > 0 && selected.value.length === props.rows.length);
const newKey = () => (globalThis.crypto?.randomUUID?.() ?? `${Date.now()}-${Math.random().toString(16).slice(2)}`);
const bulk = useForm({ action: '', order_ids: [], idempotency_key: newKey() });

watch(selected, () => { bulk.idempotency_key = newKey(); });

function toggleAll() {
    selected.value = allSelected.value ? [] : props.rows.map((r) => r.id);
}

function runBulk(action) {
    if (bulk.processing || selected.value.length === 0) return;
    bulk.action = action;
    bulk.order_ids = [...selected.value];
    bulk.post('/orders/bulk', { preserveScroll: true });
}

const bulkActions = [
    ['labels', k('Kargo etiketi al')],
    ['picking', k('Toplama listesi')],
    ['invoice_documents', k('Fatura belgeleri')],
    ['issue_invoices', k('Fatura kes')],
];

/* Para birimi başına toplam — farklı para birimleri toplanmaz. */
function totals(rows) {
    const byCurrency = {};
    for (const row of rows) {
        const cur = row.currency ?? 'TRY';
        byCurrency[cur] = (byCurrency[cur] ?? 0) + Number(row.grandTotal ?? 0);
    }
    return Object.entries(byCurrency).map(([cur, sum]) => money(sum, cur)).join(' + ') || money(0, 'TRY');
}

const visibleTotal = computed(() => totals(props.rows));
const selectedTotal = computed(() => totals(props.rows.filter((r) => selected.value.includes(r.id))));

function placedAt(row) {
    if (!row.placedAt) return '—';
    return new Date(row.placedAt).toLocaleString(intlLocale(), {
        day: '2-digit', month: '2-digit',
        hour: '2-digit', minute: '2-digit',
    });
}

function rowTone(row) {
    if (selected.value.includes(row.id)) return 'bg-brand-50';
    if (row.hasOversold) return 'bg-red-50/60 hover:bg-red-100/60';
    if (row.hasUnmatched) return 'bg-amber-50/50 hover:bg-amber-100/50';
    return 'hover:bg-stone-50';
}
</script>

<template>
    <PanelLayout wide>
        <!-- başlık + arama -->
        <div class="flex flex-wrap items-end gap-3">
            <div class="min-w-0 flex-1">
                <p class="text-xs text-stone-600">{{ t('Siparişler') }}</p>
                <h1 class="text-xl font-bold tracking-tight text-stone-900">{{ t('Tüm kanallardan siparişler') }}</h1>
            </div>
            <!--
                Arama kutusu dar ekranda ESNER (`w-full` + `min-w-0`), geniş
                ekranda sabit genişliğe döner. Sabit genişlik tek başına
                bırakılsaydı 320px'lik telefonda kutu + düğme satıra sığmaz
                ve sayfayı yatay kaydırırdı (gerçek tarayıcıda ölçüldü).
            -->
            <form class="flex w-full items-center gap-2 sm:w-auto" role="search" @submit.prevent="submitSearch">
                <input
                    v-model="search"
                    type="search"
                    :placeholder="t('Sipariş no veya SKU ara')"
                    :aria-label="t('Sipariş ara')"
                    class="h-9 w-full min-w-0 rounded-[10px] border border-stone-300 bg-white px-3 text-sm focus:border-ring focus:outline-2 focus:outline-offset-0 focus:outline-ring sm:w-80"
                >
                <button
                    type="submit"
                    class="h-9 shrink-0 rounded-[10px] border border-stone-300 bg-white px-3 text-sm text-stone-700 transition hover:bg-stone-100"
                >
                    {{ t('Ara') }}
                </button>
            </form>
        </div>

        <div class="relative mt-4 overflow-hidden rounded-[14px] border border-stone-200 bg-white">
            <!-- sekmeler + yoğunluk -->
            <div class="flex flex-wrap items-center gap-x-1 border-b border-stone-200 px-3">
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
                    <span class="ml-1 rounded-full px-1.5 py-px text-[11px] font-semibold tabular-nums" :class="countClass(tab)">{{ tab.count ?? 0 }}</span>
                </button>
                <span class="flex-1" />
                <button
                    type="button"
                    class="my-1.5 h-8 rounded-lg border border-stone-300 px-2.5 text-xs text-stone-700 transition hover:bg-stone-100"
                    @click="toggleDensity"
                >
                    {{ dense ? t('Yoğunluk: Sıkı') : t('Yoğunluk: Rahat') }}
                </button>
            </div>

            <!-- toplu işlem şeridi -->
            <div
                v-if="selected.length && can('orders.work')"
                class="flex flex-wrap items-center gap-2 border-b border-brand-200 bg-brand-50 px-4 py-2 text-stone-900"
                role="region"
                :aria-label="t('Toplu işlem')"
            >
                <strong class="text-sm">{{ t(':count sipariş seçildi', { count: selected.length }) }}</strong>
                <span class="text-sm text-stone-600">· {{ selectedTotal }}</span>
                <span class="flex-1" />
                <button
                    v-for="([action, label], index) in bulkActions"
                    :key="action"
                    type="button"
                    class="h-8 rounded-lg px-3 text-sm transition disabled:opacity-50"
                    :class="index === 0 ? 'bg-brand-600 font-semibold text-white hover:bg-brand-700' : 'border border-stone-300 bg-white text-stone-800 hover:bg-stone-100'"
                    :disabled="bulk.processing"
                    @click="runBulk(action)"
                >
                    {{ t(label) }}
                </button>
                <button type="button" class="h-8 rounded-lg px-2 text-sm text-stone-600 hover:text-stone-900" @click="selected = []">
                    {{ t('Seçimi kaldır') }}
                </button>
                <p v-if="bulk.errors.bulk || bulk.errors.order_ids" class="w-full text-sm text-red-700">
                    {{ bulk.errors.bulk ?? bulk.errors.order_ids }}
                </p>
            </div>

            <div v-if="!rows.length" class="p-10 text-center">
                <p class="text-sm text-stone-600">{{ t('Bu ölçütlerle sipariş bulunamadı.') }}</p>
            </div>

            <!--
                `relative` ŞART: tablodaki `sr-only` etiketler `absolute`tur ve
                kap konumlandırılmamışsa kaydırma kutusu onları KIRPMAZ — 390
                px'te sayfa 759 px'e taşıyordu (24 Eki 2026'da ölçüldü).
                Başlık satırı kutunun içinde YAPIŞKANDIR: uzun listede sütun
                adları kaybolmaz.
            -->
            <div v-else class="relative max-h-[calc(100vh-230px)] min-h-[320px] overflow-auto">
                <table class="w-full min-w-[1100px] border-separate border-spacing-0 text-[13px]">
                    <thead class="text-left">
                        <tr class="[&>th]:sticky [&>th]:top-0 [&>th]:z-10 [&>th]:border-b [&>th]:border-stone-200 [&>th]:bg-stone-100 [&>th]:px-3 [&>th]:py-2 [&>th]:text-[11px] [&>th]:font-semibold [&>th]:uppercase [&>th]:tracking-wide [&>th]:text-stone-600 [&>th]:whitespace-nowrap">
                            <th v-if="can('orders.work')" class="w-9">
                                <input type="checkbox" :checked="allSelected" :aria-label="t('Tümünü seç')" @change="toggleAll">
                            </th>
                            <th>{{ t('Sipariş') }}</th>
                            <th>{{ t('Kanal') }}</th>
                            <th>{{ t('Tarih') }}</th>
                            <th>{{ t('Ürünler') }}</th>
                            <th class="text-right">{{ t('Adet') }}</th>
                            <th class="text-right">{{ t('Tutar') }}</th>
                            <th>{{ t('Durum') }}</th>
                            <th>{{ t('Kargo / takip') }}</th>
                            <th>{{ t('Fatura') }}</th>
                            <th>{{ t('Stok') }}</th>
                        </tr>
                    </thead>

                    <tbody>
                        <tr v-for="row in rows" :key="row.id" :class="rowTone(row)" class="[&>td]:border-b [&>td]:border-stone-100 [&>td]:whitespace-nowrap">
                            <td v-if="can('orders.work')" :class="cell">
                                <input v-model="selected" type="checkbox" :value="row.id" :aria-label="t('Siparişi seç')">
                            </td>

                            <td :class="cell">
                                <Link :href="`/orders/${row.id}`" class="font-mono text-[12.5px] font-medium text-brand-700 hover:underline">
                                    {{ row.externalNumber ?? '—' }}
                                </Link>
                            </td>

                            <td :class="cell">
                                <span class="flex items-center gap-1.5 text-stone-900" :title="row.channel.label ?? undefined">
                                    <img v-if="brandIcon(row.channel.type)" :src="brandIcon(row.channel.type)" alt="" class="size-4 rounded-sm object-contain">
                                    {{ channelName(row.channel.type) }}
                                </span>
                            </td>

                            <td :class="cell" class="tabular-nums text-stone-600">{{ placedAt(row) }}</td>

                            <td :class="cell" class="max-w-[240px] truncate text-stone-800" :title="row.firstTitle ?? undefined">
                                {{ row.firstTitle ?? '—' }}
                                <span v-if="row.lineCount > 1" class="text-xs text-stone-500">{{ t('+:count kalem', { count: row.lineCount - 1 }) }}</span>
                            </td>

                            <td :class="cell" class="text-right tabular-nums text-stone-700">{{ row.itemCount }}</td>

                            <td :class="cell" class="text-right font-medium tabular-nums text-stone-900">{{ money(row.grandTotal, row.currency) }}</td>

                            <td :class="cell">
                                <span
                                    class="rounded-full border px-2 py-px text-xs font-medium"
                                    :class="toneClass[orderState(row.status, row.hasShipment, row.state).tone]"
                                >
                                    {{ $t(orderState(row.status, row.hasShipment, row.state).text) }}
                                </span>
                            </td>

                            <td :class="cell" class="text-stone-700">
                                <template v-if="row.tracking">
                                    <span v-if="row.tracking.carrier" class="text-xs text-stone-500">{{ row.tracking.carrier }} · </span>
                                    <span class="font-mono text-xs">{{ row.tracking.number }}</span>
                                </template>
                                <span v-else class="text-stone-400">—</span>
                            </td>

                            <td :class="cell" class="text-xs">
                                <span :class="invoiceLabels[row.invoiceStatus]?.class ?? 'text-stone-400'">
                                    {{ invoiceLabels[row.invoiceStatus] ? t(invoiceLabels[row.invoiceStatus].text) : '—' }}
                                </span>
                            </td>

                            <!--
                                FAZLA SATIŞ VE EŞLEŞMEMİŞ SKU AYRI AYRI YAZILIR.
                                Rozet en acil olanı gösterir; ayrıntı fareyle
                                üzerine gelince (title) ve satır tonunda.
                            -->
                            <td :class="cell">
                                <span
                                    class="rounded-full border px-2 py-px text-xs font-medium"
                                    :class="badges[row.stockBadge]?.class"
                                    :title="[
                                        row.hasOversold ? t(':count kalem stoksuz satıldı', { count: row.oversoldLineCount }) : '',
                                        row.hasUnmatched ? t(':count ürün kataloğunda yok · stok düşülmedi', { count: row.unmatchedLineCount }) : '',
                                    ].filter(Boolean).join(' · ') || undefined"
                                >
                                    {{ badges[row.stockBadge] ? t(badges[row.stockBadge].text) : row.stockBadge }}
                                </span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- alt bilgi: görünen toplam -->
            <div v-if="rows.length" class="flex flex-wrap items-center gap-x-4 gap-y-1 border-t border-stone-200 px-4 py-2 text-xs text-stone-600">
                <span>{{ t(':count sipariş gösteriliyor (en yeni 50)', { count: rows.length }) }}</span>
                <span>{{ t('Görünen toplam') }} <strong class="tabular-nums text-stone-900">{{ visibleTotal }}</strong></span>
            </div>
        </div>
    </PanelLayout>
</template>
