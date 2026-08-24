<script setup>
import { router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import PageHeader from '../../Components/PageHeader.vue';
import PanelLayout from '../../Layouts/PanelLayout.vue';

const props = defineProps({
    plans: { type: Array, default: () => [] },
    current: { type: Object, default: () => ({}) },
    usage: { type: Object, default: () => ({}) },
    paymentsEnabled: { type: Boolean, default: false },
});

const page = usePage();

const errors = computed(() => page.props.errors ?? {});

const statusLabels = {
    active: 'aktif',
    trialing: 'deneme',
    past_due: 'ödeme bekliyor',
    cancelled: 'iptal edildi',
    expired: 'süresi doldu',
};

/*
 * SINIRSIZ `null` TAŞINIR, sıfır DEĞİL. Sıfır gösterilseydi sınırsız
 * plan en kısıtlı plan gibi görünürdü.
 */
function limitText(limit) {
    return limit === null || limit === undefined ? 'sınırsız' : limit.toLocaleString('tr-TR');
}

/* Kullanım oranı — sınırsızda çubuk gösterilmez. */
function ratio(row) {
    if (row.limit === null || row.limit === undefined || row.limit === 0) {
        return null;
    }

    return Math.min(100, Math.round((row.current / row.limit) * 100));
}

function money(value, currency) {
    return `${Number(value).toLocaleString('tr-TR', { minimumFractionDigits: 2 })} ${currency}`;
}

function formatDate(iso) {
    if (!iso) return '—';

    return new Intl.DateTimeFormat('tr-TR', {
        day: '2-digit', month: 'long', year: 'numeric',
    }).format(new Date(iso));
}

/*
 * Ödeme oturumu SUNUCUDA açılır ve ardından Stripe'a YÖNLENDİRİLİR;
 * arada ağ gecikmesi vardır. Bekleme durumu gösterilmezse düğme
 * tepkisiz görünür ve kullanıcı tekrar basar — her basış YENİ bir
 * checkout oturumu yaratır. Yönlendirme başladıktan sonra da düğme
 * kilitli kalmalıdır; bu yüzden `onFinish` ile SIFIRLANMAZ.
 */
const buying = ref(null);

function buy(planCode) {
    if (buying.value !== null) return;

    buying.value = planCode;

    router.post('/billing/checkout', { plan_code: planCode }, {
        onError: () => {
            buying.value = null;
        },
    });
}

const usageRows = computed(() => Object.entries(props.usage).map(([key, row]) => ({ key, ...row })));
</script>

<template>
    <PanelLayout>
        <PageHeader section="Abonelik" title="Plan ve kullanım" />

        <!-- Ödeme altyapısı yoksa SÖYLENİR: sessizce başarısız olan bir
             düğme, sebebi hiç anlaşılmayan bir hatadır. -->
        <div
            v-if="!paymentsEnabled"
            class="mt-6 rounded-lg border border-amber-200 bg-amber-50 p-4"
        >
            <p class="text-sm font-medium text-amber-900">
                Ödeme altyapısı henüz yapılandırılmadı.
            </p>
            <p class="mt-1 text-xs leading-relaxed text-amber-800">
                Planlar görüntülenebilir ama satın alma yapılamaz.
                Yönetici <code class="font-mono">STRIPE_SECRET</code> tanımladığında
                bu bölüm etkinleşir.
            </p>
        </div>

        <p v-if="errors.plan_code" class="mt-6 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800">
            {{ errors.plan_code }}
        </p>

        <!-- Mevcut plan -->
        <section class="mt-8">
            <h2 class="text-sm font-semibold text-stone-900">Mevcut planın</h2>

            <div class="mt-3 rounded-lg border border-stone-200 bg-white p-4">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <p class="text-lg font-medium text-stone-900">
                        {{ current.planName ?? '—' }}
                    </p>
                    <span
                        v-if="current.status"
                        class="rounded px-2 py-0.5 font-mono text-[10px] uppercase tracking-wider"
                        :class="current.status === 'active' || current.status === 'trialing'
                            ? 'bg-emerald-100 text-emerald-800'
                            : 'bg-red-100 text-red-800'"
                    >
                        {{ statusLabels[current.status] ?? current.status }}
                    </span>
                    <span v-else class="font-mono text-[10px] uppercase tracking-wider text-stone-500">
                        abonelik yok
                    </span>
                </div>

                <p v-if="current.currentPeriodEnd" class="mt-2 text-xs text-stone-600">
                    Yenileme tarihi: {{ formatDate(current.currentPeriodEnd) }}
                </p>
            </div>
        </section>

        <!-- Kullanım — DEĞER VE LİMİT BİRLİKTE -->
        <section class="mt-8">
            <h2 class="text-sm font-semibold text-stone-900">Kullanımın</h2>

            <dl class="mt-3 grid gap-px overflow-hidden rounded-lg border border-stone-200 bg-stone-200 sm:grid-cols-2">
                <div v-for="row in usageRows" :key="row.key" class="bg-white p-4">
                    <dt class="font-mono text-[10px] uppercase tracking-wider text-stone-500">
                        {{ row.label }}
                    </dt>
                    <dd class="mt-1 text-xl font-medium tabular-nums text-stone-900">
                        {{ row.current.toLocaleString('tr-TR') }}
                        <span class="text-sm font-normal text-stone-500">
                            / {{ limitText(row.limit) }}
                        </span>
                    </dd>

                    <div v-if="ratio(row) !== null" class="mt-2 h-1.5 overflow-hidden rounded bg-stone-100">
                        <div
                            class="h-full rounded transition-all"
                            :class="ratio(row) >= 100 ? 'bg-red-500' : ratio(row) >= 80 ? 'bg-amber-500' : 'bg-emerald-500'"
                            :style="{ width: `${ratio(row)}%` }"
                        />
                    </div>
                </div>
            </dl>
        </section>

        <!-- Planlar -->
        <section class="mt-8">
            <h2 class="text-sm font-semibold text-stone-900">Planlar</h2>

            <div class="mt-3 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div
                    v-for="plan in plans"
                    :key="plan.code"
                    class="flex flex-col rounded border bg-white p-4"
                    :class="plan.code === current.planCode
                        ? 'border-stone-900 ring-1 ring-stone-900'
                        : 'border-stone-200'"
                >
                    <p class="text-sm font-semibold text-stone-900">{{ plan.name }}</p>

                    <p class="mt-2 text-xl font-medium tabular-nums text-stone-900">
                        {{ money(plan.price, plan.currency) }}
                        <span class="text-xs font-normal text-stone-500">/ ay</span>
                    </p>

                    <ul class="mt-3 flex-1 space-y-1 text-xs text-stone-600">
                        <li>{{ limitText(plan.limits.products) }} ürün</li>
                        <li>{{ limitText(plan.limits.channels) }} kanal</li>
                    </ul>

                    <p
                        v-if="plan.code === current.planCode"
                        class="mt-4 rounded bg-stone-100 py-2 text-center text-xs font-medium text-stone-600"
                    >
                        Mevcut planın
                    </p>
                    <button
                        v-else-if="Number(plan.price) > 0"
                        type="button"
                        class="mt-4 rounded-md bg-stone-900 py-2 text-xs font-medium text-white transition hover:bg-stone-700 disabled:cursor-not-allowed disabled:bg-stone-300"
                        :disabled="!paymentsEnabled || buying !== null"
                        @click="buy(plan.code)"
                    >
                        {{ buying === plan.code ? 'Yönlendiriliyor…' : 'Bu plana geç' }}
                    </button>
                    <p v-else class="mt-4 py-2 text-center text-xs text-stone-500">
                        Ücretsiz
                    </p>
                </div>
            </div>
        </section>
    </PanelLayout>
</template>
