<script setup>
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import PageHeader from '../../Components/PageHeader.vue';
import StatCard from '../../Components/StatCard.vue';
import PanelLayout from '../../Layouts/PanelLayout.vue';
import { money } from '../../lib/format.js';
import { intlLocale, useI18n } from '../../lib/i18n';

const props = defineProps({
    stats: { type: Object, required: true },
    // Çerezsiz ziyaret sayacı (VisitStats) — görüntüleme, gün başına tekil ziyaretçi.
    visits: { type: Object, required: true },
});

const { t } = useI18n();

/*
 * HUNİ: kayıt → doğrulanmış → kanal bağlamış → ücretli. "Kaç kişi kayıt
 * oldu" sorusunun dürüst cevabı tek sayı değil; kayıt olup kanal
 * bağlamayan hesap ürünü kullanmıyordur.
 */
const funnel = computed(() => {
    const f = props.stats.funnel;
    const pct = (n) => (f.signups > 0 ? Math.round((n / f.signups) * 100) : 0);

    return [
        { label: t('Kayıt'), value: f.signups, pct: 100 },
        { label: t('E-postasını doğrulayan'), value: f.verified, pct: pct(f.verified) },
        { label: t('Kanal bağlayan'), value: f.withChannel, pct: pct(f.withChannel) },
        { label: t('Ücretli planda'), value: f.paying, pct: pct(f.paying) },
    ];
});

/* Son 30 gün çubukları — en yüksek gün tam boy. */
const maxDaily = computed(() => Math.max(1, ...props.stats.dailySignups.map((d) => d.count)));
const dayLabel = (iso) => new Date(iso).toLocaleDateString(intlLocale(), { day: 'numeric', month: 'short' });

/* Ziyaret çubukları: görüntülemeye göre ölçeklenir, tekil ziyaretçi ipucunda. */
const maxViews = computed(() => Math.max(1, ...props.visits.daily.map((d) => d.views)));

const revenue = computed(() => Object.entries(props.stats.revenue ?? {}));
</script>

<template>
    <PanelLayout>
        <PageHeader :section="t('Yönetim')" :title="t('Platform özeti')" :description="t('Tüm müşteriler üzerinden kayıt, kullanım ve gelir.')">
            <template #actions>
                <div class="flex gap-2">
                    <Link href="/admin/tenants" class="rounded-md border border-stone-300 px-4 py-2 text-sm text-stone-700 transition hover:bg-stone-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring">{{ t('Müşteriler') }}</Link>
                    <Link href="/admin/plans" class="rounded-md border border-stone-300 px-4 py-2 text-sm text-stone-700 transition hover:bg-stone-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring">{{ t('Planlar') }}</Link>
                    <Link href="/admin/certification" class="rounded-md border border-stone-300 px-4 py-2 text-sm text-stone-700 transition hover:bg-stone-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring">{{ t('Kanal sertifikasyonu') }}</Link>
                    <Link href="/admin/contact-messages" class="rounded-md border border-stone-300 px-4 py-2 text-sm text-stone-700 transition hover:bg-stone-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring">{{ t('İletişim mesajları') }}</Link>
                </div>
            </template>
        </PageHeader>

        <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <StatCard :label="t('Son 7 günde kayıt')" :value="stats.signups7" />
            <StatCard :label="t('Son 30 günde kayıt')" :value="stats.signups30" />
            <StatCard :label="t('Son 7 günde giriş yapan')" :value="stats.activeUsers7" />
            <StatCard :label="t('Son 30 günde sipariş')" :value="stats.orders30" />
        </div>

        <!--
            ZİYARET: çerezsiz, IP saklamayan günlük toplam. Tekil ziyaretçi GÜN
            BAŞINADIR — yedi günlük sayı, üç gün gelen kişiyi üç kez sayar;
            bunu açıkça yazmazsak rakam olduğundan büyük okunur.
        -->
        <section class="mt-6 rounded-lg border border-stone-200 bg-white p-5">
            <h2 class="text-sm font-semibold text-stone-900">{{ t('Ziyaret · son 7 / 30 gün') }}</h2>
            <div class="mt-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
                <StatCard :label="t('Görüntüleme · 7 gün')" :value="visits.views7" />
                <StatCard :label="t('Görüntüleme · 30 gün')" :value="visits.views30" />
                <StatCard :label="t('Tekil ziyaretçi · 7 gün')" :value="visits.visitors7" />
                <StatCard :label="t('Tekil ziyaretçi · 30 gün')" :value="visits.visitors30" />
            </div>

            <div class="mt-5 flex h-32 items-end gap-1" role="img" :aria-label="t('Günlük görüntüleme · son 30 gün')">
                <div
                    v-for="d in visits.daily"
                    :key="d.date"
                    class="group relative flex-1 rounded-t bg-stone-300 hover:bg-stone-700"
                    :style="{ height: Math.max(2, (d.views / maxViews) * 100) + '%' }"
                    :title="`${dayLabel(d.date)}: ${t(':views görüntüleme, :visitors ziyaretçi', { views: d.views, visitors: d.visitors })}`"
                />
            </div>
            <div class="mt-1 flex justify-between text-xs text-stone-500">
                <span>{{ dayLabel(visits.daily[0]?.date) }}</span>
                <span>{{ dayLabel(visits.daily[visits.daily.length - 1]?.date) }}</span>
            </div>

            <div class="mt-5 grid gap-6 lg:grid-cols-2">
                <div>
                    <h3 class="text-xs font-semibold uppercase tracking-wide text-stone-500">{{ t('En çok bakılan sayfalar · 30 gün') }}</h3>
                    <p v-if="!visits.topPages.length" class="mt-2 text-sm text-stone-500">{{ t('Henüz ziyaret yok.') }}</p>
                    <ul v-else class="mt-2 divide-y divide-stone-100 text-sm">
                        <li v-for="p in visits.topPages" :key="p.path" class="flex justify-between gap-3 py-1.5">
                            <span class="truncate font-mono text-xs text-stone-700" :title="p.path">{{ p.path }}</span>
                            <b class="tabular-nums text-stone-900">{{ p.views }}</b>
                        </li>
                    </ul>
                </div>
                <div>
                    <h3 class="text-xs font-semibold uppercase tracking-wide text-stone-500">{{ t('Kaynak siteler · 30 gün') }}</h3>
                    <p v-if="!visits.topReferrers.length" class="mt-2 text-sm text-stone-500">{{ t('Dış kaynaktan gelen ziyaret yok.') }}</p>
                    <ul v-else class="mt-2 divide-y divide-stone-100 text-sm">
                        <li v-for="r in visits.topReferrers" :key="r.host" class="flex justify-between gap-3 py-1.5">
                            <span class="truncate text-stone-700">{{ r.host }}</span>
                            <b class="tabular-nums text-stone-900">{{ r.views }}</b>
                        </li>
                    </ul>
                </div>
            </div>

            <p class="mt-4 text-xs text-stone-500">{{ t('Çerezsiz sayılır, IP saklanmaz. Tekil ziyaretçi gün başınadır: iki gün gelen kişi iki kez sayılır. Botlar ve yönetici hesabı sayılmaz.') }}</p>
        </section>

        <section class="mt-6 rounded-lg border border-stone-200 bg-white p-5">
            <h2 class="text-sm font-semibold text-stone-900">{{ t('Kayıttan ödemeye') }}</h2>
            <ul class="mt-4 space-y-3">
                <li v-for="step in funnel" :key="step.label">
                    <div class="flex items-baseline justify-between text-sm">
                        <span class="text-stone-700">{{ step.label }}</span>
                        <span class="tabular-nums text-stone-900"><b>{{ step.value }}</b> <span class="text-stone-500">· %{{ step.pct }}</span></span>
                    </div>
                    <div class="mt-1 h-2 rounded bg-stone-100">
                        <div class="h-2 rounded bg-stone-800" :style="{ width: step.pct + '%' }" />
                    </div>
                </li>
            </ul>
        </section>

        <section class="mt-6 rounded-lg border border-stone-200 bg-white p-5">
            <h2 class="text-sm font-semibold text-stone-900">{{ t('Günlük kayıt · son 30 gün') }}</h2>
            <div class="mt-4 flex h-32 items-end gap-1" role="img" :aria-label="t('Günlük kayıt · son 30 gün')">
                <div
                    v-for="d in stats.dailySignups"
                    :key="d.date"
                    class="group relative flex-1 rounded-t bg-stone-300 hover:bg-stone-700"
                    :style="{ height: Math.max(2, (d.count / maxDaily) * 100) + '%' }"
                    :title="`${dayLabel(d.date)}: ${d.count}`"
                />
            </div>
            <div class="mt-1 flex justify-between text-xs text-stone-500">
                <span>{{ dayLabel(stats.dailySignups[0]?.date) }}</span>
                <span>{{ dayLabel(stats.dailySignups[stats.dailySignups.length - 1]?.date) }}</span>
            </div>
        </section>

        <div class="mt-6 grid gap-6 lg:grid-cols-3">
            <section class="rounded-lg border border-stone-200 bg-white p-5">
                <h2 class="text-sm font-semibold text-stone-900">{{ t('Aylık tekrarlayan gelir') }}</h2>
                <p v-if="!revenue.length" class="mt-3 text-sm text-stone-500">{{ t('Henüz ücretli abonelik yok.') }}</p>
                <ul v-else class="mt-3 space-y-1">
                    <li v-for="[cur, amount] in revenue" :key="cur" class="text-lg font-semibold tabular-nums text-stone-900">{{ money(amount, cur) }}</li>
                </ul>
                <p class="mt-2 text-xs text-stone-500">{{ t('Para birimleri ayrı gösterilir, toplanmaz.') }}</p>
            </section>

            <section class="rounded-lg border border-stone-200 bg-white p-5">
                <h2 class="text-sm font-semibold text-stone-900">{{ t('Kanallar') }}</h2>
                <p v-if="!stats.channels.length" class="mt-3 text-sm text-stone-500">{{ t('Henüz bağlı kanal yok.') }}</p>
                <ul v-else class="mt-3 divide-y divide-stone-100 text-sm">
                    <li v-for="c in stats.channels" :key="c.code" class="flex justify-between py-1.5">
                        <span class="text-stone-700">{{ c.name }}</span>
                        <span class="tabular-nums">
                            <b class="text-stone-900">{{ c.total }}</b>
                            <span v-if="c.unhealthy" class="ml-2 text-red-700">{{ t(':count sorunlu', { count: c.unhealthy }) }}</span>
                        </span>
                    </li>
                </ul>
            </section>

            <section class="rounded-lg border border-stone-200 bg-white p-5">
                <h2 class="text-sm font-semibold text-stone-900">{{ t('Planlar') }}</h2>
                <ul class="mt-3 divide-y divide-stone-100 text-sm">
                    <li v-for="p in stats.plans" :key="p.code" class="flex justify-between py-1.5">
                        <span class="text-stone-700">{{ p.name }} <span v-if="!p.isPublic" class="ml-1 rounded bg-amber-100 px-1.5 py-0.5 text-xs text-amber-900">{{ t('Özel') }}</span></span>
                        <b class="tabular-nums text-stone-900">{{ p.tenants }}</b>
                    </li>
                </ul>
                <p class="mt-2 text-xs text-stone-500">{{ t(':count müşteri hesabı', { count: stats.tenants }) }}</p>
            </section>
        </div>
    </PanelLayout>
</template>
