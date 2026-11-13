<script setup>
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import PanelLayout from '../Layouts/PanelLayout.vue';
import { money, orderState } from '../lib/format.js';
import { intlLocale, k, useI18n } from '../lib/i18n';

const props = defineProps({
    tenant: { type: Object, default: () => ({}) },
    connections: { type: Array, default: () => [] },
    syncHealth: { type: Object, default: () => ({}) },
    oversold: { type: Array, default: () => [] },
    recentOperations: { type: Array, default: () => [] },
    todos: { type: Array, default: () => [] },
    today: { type: Object, default: () => ({ orderCount: 0, revenue: [] }) },
    stock: { type: Object, default: () => ({ low: 0, out: 0, threshold: 5 }) },
    recentOrders: { type: Array, default: () => [] },
    lastSyncAt: { type: String, default: null },
});

const page = usePage();
const can = (perm) => (page.props.permissions ?? []).includes(perm);

/*
 * DÜNE GÖRE DEĞİŞİM yalnız TEK para birimi varken gösterilir: TL ile
 * Euro'nun toplam yüzdesi anlamsızdır. Dün aynı saate kadar satış yoksa
 * yüzde de yoktur (sıfıra bölme "∞" yazmaz, susar).
 */
const revenueDelta = computed(() => {
    const rows = props.today?.revenue ?? [];
    if (rows.length !== 1) return null;
    const before = Number(props.today?.yesterdayRevenue?.[rows[0].currency] ?? 0);
    if (!before) return null;
    return Math.round(((Number(rows[0].total) - before) / before) * 100);
});

/* "Trendyol 82 · Hepsiburada 31" — en çok üç kanal, kalanı "+2". */
const channelSplit = computed(() => {
    const rows = props.today?.byChannel ?? [];
    const head = rows.slice(0, 3).map((r) => `${r.channel} ${r.count}`);
    return rows.length > 3 ? `${head.join(' · ')} · +${rows.length - 3}` : head.join(' · ');
});

const connectedCount = computed(() => props.connections.filter((c) => c.health !== 'unhealthy').length);

/* "2 dk önce" — göreli zaman, tarayıcı dilinde. */
function ago(iso) {
    if (!iso) return null;
    const minutes = Math.round((Date.now() - new Date(iso).getTime()) / 60000);
    const rtf = new Intl.RelativeTimeFormat(intlLocale(), { numeric: 'auto' });
    if (minutes < 60) return rtf.format(-Math.max(minutes, 0), 'minute');
    if (minutes < 1440) return rtf.format(-Math.round(minutes / 60), 'hour');
    return rtf.format(-Math.round(minutes / 1440), 'day');
}

/*
 * ARAMA ÜRÜNLERE GİDER. Genel bir arama ucu yok; ürün araması SKU ve
 * varyant SKU'sunu da tarar — satıcının en sık aradığı şey budur.
 */
const query = ref('');
function search() {
    const value = query.value.trim();
    router.get('/products', value ? { search: value } : {});
}

/* Kanal satırı: kopuk > uyarı > eşitleniyor > eşit. */
function channelStatus(connection) {
    if (connection.health === 'unhealthy') return { text: t('Bağlantı koptu'), class: 'text-red-700' };
    if (connection.issues > 0) return { text: t(':count uyarı', { count: connection.issues }), class: 'text-amber-800' };
    if (connection.pending > 0) return { text: t('Eşitleniyor'), class: 'text-stone-600' };
    if (connection.health === 'healthy') return { text: t('Eşit'), class: 'text-emerald-700' };
    return { text: t('Kontrol ediliyor'), class: 'text-stone-600' };
}

const stateTone = {
    todo: 'bg-amber-100 text-amber-900',
    done: 'bg-emerald-100 text-emerald-800',
    muted: 'bg-stone-100 text-stone-700',
};

/*
 * ANA EKRAN SATICIYA NE YAPACAĞINI SÖYLER, SAYI EZBERLETMEZ.
 *
 * Eski ekran "Senkron durumu" başlığıyla dört teknik sayı gösteriyordu
 * (senkron / bekleyen / geçici hata / kalıcı hata). Satıcı bunlara bakıp
 * ne yapacağını bilemez. Yeni sıra: bugün ne oldu → ne yapmalıyım →
 * kanallarım ayakta mı. Teknik sayılar silinmedi, en alttaki kapalı
 * "Teknik ayrıntılar"a indi: destek görüşmesinde hâlâ gerekli.
 */

const { t } = useI18n();

const today = new Intl.DateTimeFormat(intlLocale(), { weekday: 'long', day: 'numeric', month: 'long' }).format(new Date());

const revenueText = computed(() => {
    const rows = props.today?.revenue ?? [];
    if (!rows.length) return money(0, 'TRY');
    return rows.map((row) => money(row.total, row.currency)).join(' + ');
});

/*
 * Ton = aciliyet. `urgent` kırmızı çizgi (söz verilmiş ve tutulamıyor ya da
 * bir şey kanala gitmiyor), `action` amber (yapılacak iş, arıza değil).
 */
/* Düğme işi söyler ("Git" değil): satıcı tıklamadan ne yapacağını bilir. */
const todoAction = {
    oversold: k('Stoğu düzelt'),
    awaiting_shipment: k('Kargola'),
    shipment_failed: k('Düzelt'),
    unanswered_questions: k('Cevapla'),
    returns_awaiting_decision: k('Karar ver'),
    unmatched: k('Eşleştir'),
    rejected: k('İncele'),
    price_conflict: k('Karar ver'),
    failed_sync: k('Tekrar dene'),
    broken_channel: k('Yenile'),
};

const toneClass = {
    urgent: 'bg-brand-600',
    action: 'bg-amber-500',
};

const statusLabels = {
    pending: k('bekliyor'),
    retrying: k('yeniden deniyor'),
    completed: k('tamamlandı'),
    superseded: k('yenisi geldi'),
    dead: k('başarısız'),
};

function formatTime(iso) {
    if (!iso) return '—';

    return new Intl.DateTimeFormat(intlLocale(), {
        day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit',
    }).format(new Date(iso));
}

/* Bugünse yalnız saat, değilse gün + saat. */
function orderTime(iso) {
    if (!iso) return '—';
    const d = new Date(iso);
    const sameDay = d.toDateString() === new Date().toDateString();
    return new Intl.DateTimeFormat(intlLocale(), sameDay
        ? { hour: '2-digit', minute: '2-digit' }
        : { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' }).format(d);
}
</script>

<template>
    <PanelLayout>
        <!-- üst satır: selam + durum · arama · ürün yükle -->
        <div class="flex flex-wrap items-center gap-4">
            <div class="min-w-0 flex-[1_1_18rem]">
                <h1 class="text-[26px] font-bold tracking-tight text-stone-900">
                    {{ tenant?.name ? t('Merhaba, :name', { name: tenant.name }) : t('Merhaba') }}
                </h1>
                <p class="mt-1 text-sm text-stone-600">
                    <span class="capitalize">{{ today }}</span>
                    <template v-if="connections.length"> · {{ t(':count kanal bağlı', { count: connectedCount }) }}</template>
                    <template v-if="lastSyncAt">, {{ t('son eşitleme :ago', { ago: ago(lastSyncAt) }) }}</template>
                </p>
            </div>

            <div class="flex w-full items-center gap-2 sm:w-auto sm:flex-[0_1_28rem] sm:gap-3">
            <form role="search" class="flex h-11 min-w-0 flex-1 items-center gap-2 rounded-[10px] border border-stone-300 bg-white px-3 focus-within:border-stone-500" @submit.prevent="search">
                <svg class="h-4 w-4 shrink-0 text-stone-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="7" /><path d="M20 20l-3.5-3.5" stroke-linecap="round" /></svg>
                <label for="panel-search" class="sr-only">{{ t('Ürün ara') }}</label>
                <input id="panel-search" v-model="query" type="search" :placeholder="t('Ürün adı ya da SKU ara')" class="w-full bg-transparent text-sm text-stone-900 placeholder:text-stone-500 focus:outline-none">
            </form>

            <Link
                v-if="can('catalog.view')"
                href="/products/import"
                class="inline-flex h-11 shrink-0 items-center rounded-[10px] bg-brand-600 px-4 sm:px-[18px] text-sm font-semibold text-white transition hover:bg-brand-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
            >
                {{ t('Ürün yükle') }}
            </Link>
            </div>
        </div>

        <!-- dört sayı: bugün ne oldu -->
        <div class="mt-7 grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-4">
            <div class="flex flex-col gap-2 rounded-[14px] border border-stone-200 bg-white p-4 sm:p-5">
                <span class="text-[13px] text-stone-600">{{ t('Bugünkü satış') }}</span>
                <span class="text-2xl font-bold tracking-tight tabular-nums text-stone-900 sm:text-[30px]">{{ revenueText }}</span>
                <span v-if="revenueDelta !== null" class="text-[13px] font-medium" :class="revenueDelta >= 0 ? 'text-emerald-700' : 'text-brand-700'">
                    {{ revenueDelta >= 0 ? '↑' : '↓' }} {{ t('%:pct dünden', { pct: Math.abs(revenueDelta) }) }}
                </span>
                <span v-else class="text-[13px] text-stone-600">{{ t('Dün bu saatte satış yoktu') }}</span>
            </div>

            <Link href="/orders" class="flex flex-col gap-2 rounded-[14px] border border-stone-200 bg-white p-4 transition hover:border-stone-300 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring sm:p-5">
                <span class="text-[13px] text-stone-600">{{ t('Bugünkü siparişler') }}</span>
                <span class="text-2xl font-bold tracking-tight tabular-nums text-stone-900 sm:text-[30px]">{{ props.today?.orderCount ?? 0 }}</span>
                <span class="truncate text-[13px] text-stone-600">{{ channelSplit || t('Bugün henüz sipariş yok') }}</span>
            </Link>

            <Link href="/orders?filter=awaiting_shipment" class="flex flex-col gap-2 rounded-[14px] border border-stone-200 bg-white p-4 transition hover:border-stone-300 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring sm:p-5">
                <span class="text-[13px] text-stone-600">{{ t('Gönderilmeyi bekleyen') }}</span>
                <span class="text-2xl font-bold tracking-tight tabular-nums text-stone-900 sm:text-[30px]">{{ props.today?.awaitingShipment ?? 0 }}</span>
                <span v-if="(props.today?.awaitingOverDay ?? 0) > 0" class="text-[13px] font-medium text-brand-700">
                    {{ t(':count tanesi 24 saatten eski', { count: props.today.awaitingOverDay }) }}
                </span>
                <span v-else class="text-[13px] text-stone-600">{{ t('Geciken paket yok') }}</span>
            </Link>

            <Link href="/products?filter=low" class="flex flex-col gap-2 rounded-[14px] border border-stone-200 bg-white p-4 transition hover:border-stone-300 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring sm:p-5">
                <span class="text-[13px] text-stone-600">{{ t('Stokta azalan') }}</span>
                <span class="text-2xl font-bold tracking-tight tabular-nums text-stone-900 sm:text-[30px]">{{ t(':count ürün', { count: stock.low }) }}</span>
                <span class="text-[13px]" :class="stock.out > 0 ? 'font-medium text-brand-700' : 'text-stone-600'">
                    {{ stock.out > 0 ? t(':count ürün tükendi', { count: stock.out }) : t(':n adet ve altı', { n: stock.threshold }) }}
                </span>
            </Link>
        </div>

        <div class="mt-4 grid gap-4 lg:grid-cols-3">
            <!-- yapılacaklar -->
            <section class="rounded-[14px] border border-stone-200 bg-white p-5 sm:p-[22px] lg:col-span-2" aria-labelledby="todo-heading">
                <h2 id="todo-heading" class="text-base font-semibold text-stone-900">{{ t('Yapman gerekenler') }}</h2>

                <div v-if="!todos.length" class="mt-3 border-t border-stone-100 pt-4">
                    <p class="text-sm font-medium text-emerald-800">{{ t('Her şey yolunda.') }}</p>
                    <p class="mt-0.5 text-[13px] text-stone-600">{{ t('Şu an ilgilenmen gereken bir şey yok.') }}</p>
                </div>

                <ul v-else class="mt-2">
                    <li v-for="todo in todos" :key="todo.key" class="flex items-center gap-3.5 border-t border-stone-100 py-3">
                        <span class="h-2 w-2 shrink-0 rounded-full" :class="toneClass[todo.tone]" aria-hidden="true" />
                        <span class="min-w-0 flex-1">
                            <span class="block text-sm font-medium text-stone-900">
                                <span class="tabular-nums">{{ todo.count }}</span> {{ todo.title }}
                                <span v-if="todo.tone === 'urgent'" class="sr-only">({{ t('acil') }})</span>
                            </span>
                            <span class="block text-[13px] text-stone-600">{{ todo.hint }}</span>
                        </span>
                        <Link
                            :href="todo.href"
                            class="shrink-0 rounded-lg border border-stone-300 px-3.5 py-2.5 text-[13px] font-semibold text-stone-900 transition hover:bg-stone-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                        >
                            {{ t(todoAction[todo.key] ?? 'Git') }}<span class="sr-only">: {{ todo.title }}</span>
                        </Link>
                    </li>
                </ul>
            </section>

            <!-- kanallar -->
            <section class="flex flex-col gap-3.5 rounded-[14px] border border-stone-200 bg-white p-5 sm:p-[22px]" aria-labelledby="channels-heading">
                <h2 id="channels-heading" class="text-base font-semibold text-stone-900">{{ t('Kanalların') }}</h2>

                <p v-if="!connections.length" class="text-sm text-stone-600">{{ t('Henüz bağlı kanalın yok.') }}</p>

                <ul v-else class="space-y-3">
                    <li v-for="connection in connections" :key="connection.id" class="flex items-center justify-between gap-3 text-sm">
                        <span class="min-w-0">
                            <span class="block truncate text-stone-900">{{ connection.channel }}</span>
                            <span v-if="connection.label && connection.label !== connection.channel" class="block truncate text-xs text-stone-500">{{ connection.label }}</span>
                        </span>
                        <span class="shrink-0 font-medium" :class="channelStatus(connection).class">
                            <span aria-hidden="true">●</span> {{ channelStatus(connection).text }}
                        </span>
                    </li>
                </ul>

                <Link v-if="can('channels.manage')" href="/channels/create" class="mt-auto pt-1 text-[13px] font-semibold text-brand-700 hover:text-brand-800">
                    + {{ connections.length ? t('Kanal ekle') : t('İlk kanalını bağla') }}
                </Link>
            </section>
        </div>

        <!-- son siparişler -->
        <section class="mt-4 rounded-[14px] border border-stone-200 bg-white p-5 sm:p-[22px]" aria-labelledby="recent-heading">
            <div class="flex items-center justify-between pb-2">
                <h2 id="recent-heading" class="text-base font-semibold text-stone-900">{{ t('Son siparişler') }}</h2>
                <Link href="/orders" class="text-[13px] font-medium text-brand-700 hover:text-brand-800">{{ t('Tüm siparişler') }}</Link>
            </div>

            <p v-if="!recentOrders.length" class="border-t border-stone-100 pt-4 text-sm text-stone-600">{{ t('Henüz sipariş yok.') }}</p>

            <div v-else class="overflow-x-auto">
                <table class="w-full min-w-[560px] text-left text-[13px]">
                    <thead>
                        <tr class="border-b border-stone-200 text-stone-600">
                            <th scope="col" class="py-2.5 pr-4 font-normal">{{ t('Sipariş') }}</th>
                            <th scope="col" class="py-2.5 pr-4 font-normal">{{ t('Kanal') }}</th>
                            <th scope="col" class="py-2.5 pr-4 text-right font-normal">{{ t('Tutar') }}</th>
                            <th scope="col" class="py-2.5 pr-4 font-normal">{{ t('Durum') }}</th>
                            <th scope="col" class="py-2.5 text-right font-normal">{{ t('Saat') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="order in recentOrders" :key="order.id" class="border-b border-stone-100 last:border-0">
                            <td class="py-3 pr-4 font-medium">
                                <Link :href="`/orders/${order.id}`" class="text-stone-900 underline-offset-4 hover:underline">{{ order.number }}</Link>
                            </td>
                            <td class="py-3 pr-4 text-stone-900">{{ order.channel }}</td>
                            <td class="py-3 pr-4 text-right tabular-nums text-stone-900">{{ money(order.grandTotal, order.currency) }}</td>
                            <td class="py-3 pr-4">
                                <span class="rounded-md px-2 py-0.5 font-medium" :class="stateTone[orderState(order.status, order.hasShipment, order.state).tone]">
                                    {{ t(orderState(order.status, order.hasShipment, order.state).text) }}
                                </span>
                            </td>
                            <td class="py-3 text-right tabular-nums text-stone-600">{{ orderTime(order.placedAt) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <!--
            TEKNİK AYRINTILAR — kapalı başlar. Satıcının günlük işi değil;
            destek görüşmesinde "son gönderimler ne oldu" sorusunun cevabı.
        -->
        <details class="group mt-8 rounded-[14px] border border-stone-200 bg-white">
            <summary class="cursor-pointer list-none px-5 py-3 text-sm font-medium text-stone-700 hover:text-stone-900">
                <span class="mr-1 inline-block transition group-open:rotate-90" aria-hidden="true">›</span>
                {{ t('Teknik ayrıntılar') }}
            </summary>

            <div class="border-t border-stone-200 px-5 py-4">
                <dl class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                    <div>
                        <dt class="text-xs text-stone-500">{{ t('Kanallarla aynı') }}</dt>
                        <dd class="text-lg font-medium tabular-nums text-stone-900">{{ syncHealth.synced ?? 0 }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-stone-500">{{ t('Gönderilmeyi bekleyen') }}</dt>
                        <dd class="text-lg font-medium tabular-nums text-stone-900">{{ syncHealth.dirty ?? 0 }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-stone-500">{{ t('Tekrar denenecek') }}</dt>
                        <dd class="text-lg font-medium tabular-nums text-stone-900">{{ syncHealth.errorTransient ?? 0 }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-stone-500">{{ t('Senin düzeltmen gereken') }}</dt>
                        <dd class="text-lg font-medium tabular-nums" :class="(syncHealth.errorPermanent ?? 0) > 0 ? 'text-red-700' : 'text-stone-900'">
                            {{ syncHealth.errorPermanent ?? 0 }}
                        </dd>
                    </div>
                </dl>

                <h3 class="mt-6 text-sm font-medium text-stone-900">{{ t('Son gönderimler') }}</h3>
                <p v-if="!recentOperations.length" class="mt-1 text-sm text-stone-500">{{ t('Henüz gönderim yok.') }}</p>
                <ul v-else class="mt-2 divide-y divide-stone-100 text-sm">
                    <li v-for="op in recentOperations" :key="op.id" class="flex flex-wrap items-center justify-between gap-2 py-2">
                        <span class="text-stone-900">{{ op.channel }}</span>
                        <span class="font-mono text-xs text-stone-500">{{ op.type }}</span>
                        <span :class="op.isFailed ? 'text-red-700' : 'text-stone-600'">{{ statusLabels[op.status] ? t(statusLabels[op.status]) : op.status }}</span>
                        <span class="text-xs tabular-nums text-stone-500">{{ formatTime(op.createdAt) }}</span>
                    </li>
                </ul>
            </div>
        </details>
    </PanelLayout>
</template>
