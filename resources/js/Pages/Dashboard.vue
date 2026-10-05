<script setup>
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import PanelLayout from '../Layouts/PanelLayout.vue';
import { money } from '../lib/format.js';
import { intlLocale, k, useI18n } from '../lib/i18n';

const props = defineProps({
    tenant: { type: Object, default: () => ({}) },
    connections: { type: Array, default: () => [] },
    syncHealth: { type: Object, default: () => ({}) },
    oversold: { type: Array, default: () => [] },
    recentOperations: { type: Array, default: () => [] },
    todos: { type: Array, default: () => [] },
    today: { type: Object, default: () => ({ orderCount: 0, revenue: [] }) },
});

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
const toneClass = {
    urgent: 'before:bg-red-500',
    action: 'before:bg-amber-400',
};

/* Kanal sağlığı satıcının diliyle. */
const health = {
    healthy: { text: k('Bağlı'), class: 'bg-emerald-50 text-emerald-800 border-emerald-200' },
    unhealthy: { text: k('Bağlantı koptu'), class: 'bg-red-50 text-red-800 border-red-200' },
    unknown: { text: k('Kontrol ediliyor'), class: 'bg-stone-50 text-stone-600 border-stone-200' },
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
</script>

<template>
    <PanelLayout>
        <p class="text-sm capitalize text-stone-500">{{ today }}</p>
        <h1 class="mt-1 text-2xl font-semibold tracking-tight text-stone-900">
            {{ tenant?.name ? t('Merhaba, :name', { name: tenant.name }) : t('Merhaba') }}
        </h1>

        <!-- bugün -->
        <div class="mt-6 grid gap-4 sm:grid-cols-2">
            <Link
                href="/orders"
                class="rounded-xl border border-stone-200 bg-white p-5 transition hover:border-stone-300 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
            >
                <p class="text-sm text-stone-600">{{ t('Bugünkü siparişler') }}</p>
                <p class="mt-1 text-3xl font-semibold tabular-nums text-stone-900">{{ props.today?.orderCount ?? 0 }}</p>
            </Link>
            <div class="rounded-xl border border-stone-200 bg-white p-5">
                <p class="text-sm text-stone-600">{{ t('Bugünkü satış') }}</p>
                <p class="mt-1 text-3xl font-semibold tabular-nums text-stone-900">{{ revenueText }}</p>
            </div>
        </div>

        <!-- yapılacaklar -->
        <section class="mt-10" aria-labelledby="todo-heading">
            <h2 id="todo-heading" class="text-lg font-semibold text-stone-900">{{ t('Yapman gerekenler') }}</h2>

            <div
                v-if="!todos.length"
                class="mt-3 rounded-xl border border-emerald-200 bg-emerald-50 px-5 py-4"
            >
                <p class="font-medium text-emerald-900">{{ t('Her şey yolunda.') }}</p>
                <p class="mt-0.5 text-sm text-emerald-800">{{ t('Şu an ilgilenmen gereken bir şey yok.') }}</p>
            </div>

            <ul v-else class="mt-3 space-y-2">
                <li v-for="todo in todos" :key="todo.key">
                    <Link
                        :href="todo.href"
                        class="relative flex items-center gap-4 overflow-hidden rounded-xl border border-stone-200 bg-white py-4 pl-6 pr-4 transition before:absolute before:inset-y-0 before:left-0 before:w-1 hover:border-stone-300 hover:bg-stone-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                        :class="toneClass[todo.tone]"
                    >
                        <span class="min-w-10 text-2xl font-semibold tabular-nums text-stone-900">{{ todo.count }}</span>
                        <span class="min-w-0 flex-1">
                            <span class="block font-medium text-stone-900">{{ todo.title }}</span>
                            <span class="block text-sm text-stone-600">{{ todo.hint }}</span>
                        </span>
                        <span class="shrink-0 text-sm font-medium text-stone-700" aria-hidden="true">{{ t('Git') }} →</span>
                    </Link>
                </li>
            </ul>
        </section>

        <!-- kanallar -->
        <section class="mt-10" aria-labelledby="channels-heading">
            <div class="flex items-center justify-between">
                <h2 id="channels-heading" class="text-lg font-semibold text-stone-900">{{ t('Kanalların') }}</h2>
                <Link href="/channels/create" class="text-sm font-medium text-stone-700 underline underline-offset-4 hover:text-stone-900">
                    {{ t('Kanal ekle') }}
                </Link>
            </div>

            <div v-if="!connections.length" class="mt-3 rounded-xl border border-dashed border-stone-300 px-5 py-6 text-center">
                <p class="text-stone-700">{{ t('Henüz bağlı kanalın yok.') }}</p>
                <Link
                    href="/channels/create"
                    class="mt-3 inline-block rounded-md bg-stone-900 px-4 py-2 text-sm font-medium text-white transition hover:bg-stone-700"
                >
                    {{ t('İlk kanalını bağla') }}
                </Link>
            </div>

            <ul v-else class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <li
                    v-for="connection in connections"
                    :key="connection.id"
                    class="flex items-center justify-between gap-3 rounded-xl border border-stone-200 bg-white px-4 py-3"
                >
                    <div class="min-w-0">
                        <p class="font-medium text-stone-900">{{ connection.channel }}</p>
                        <p class="truncate text-sm text-stone-500">{{ connection.label }}</p>
                    </div>
                    <span
                        class="shrink-0 rounded-full border px-2.5 py-0.5 text-xs font-medium"
                        :class="(health[connection.health] ?? health.unknown).class"
                    >
                        {{ t((health[connection.health] ?? health.unknown).text) }}
                    </span>
                </li>
            </ul>
        </section>

        <!--
            TEKNİK AYRINTILAR — kapalı başlar. Satıcının günlük işi değil;
            destek görüşmesinde "son gönderimler ne oldu" sorusunun cevabı.
        -->
        <details class="group mt-12 rounded-xl border border-stone-200 bg-white">
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
