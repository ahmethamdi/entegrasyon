<script setup>
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import PanelLayout from '../../Layouts/PanelLayout.vue';

const props = defineProps({
    connections: { type: Array, default: () => [] },
});

const page = usePage();

const flashSuccess = computed(() => page.props.flash?.success);
const flashWarning = computed(() => page.props.flash?.warning);

/**
 * Sağlıksız bağlantı üstte: kullanıcının ilgilenmesi gereken satır o.
 */
const sorted = computed(() =>
    [...props.connections].sort((a, b) => {
        const rank = (c) => (c.health === 'healthy' ? 1 : 0);
        return rank(a) - rank(b);
    }),
);

const healthLabels = {
    healthy: 'Cevap veriyor',
    unhealthy: 'Cevap vermiyor',
    unknown: 'Denenmedi',
};

function healthClass(health) {
    if (health === 'healthy') return 'bg-emerald-50 text-emerald-800 border-emerald-200';
    if (health === 'unhealthy') return 'bg-red-50 text-red-800 border-red-200';
    return 'bg-stone-50 text-stone-600 border-stone-200';
}

/** Yetenekler tip sisteminden gelir; kanal adı kontrol edilmez. */
const capabilityLabels = {
    catalog: 'Ürün',
    inventory: 'Stok',
    pricing: 'Fiyat',
    orders: 'Sipariş',
    taxonomy: 'Kategori',
    approval: 'Onay',
    fulfillment: 'Kargo',
};

function activeCapabilities(capabilities) {
    return Object.entries(capabilities ?? {})
        .filter(([, enabled]) => enabled)
        .map(([key]) => capabilityLabels[key] ?? key);
}

/*
 * Sağlık kontrolü bir AĞ ÇAĞRISIDIR ve saniyeler sürebilir (kanal
 * yavaşsa daha da uzun). Bekleme durumu gösterilmezse düğme tıklamaya
 * tepkisiz görünür ve kullanıcı tekrar tekrar basar; her basış kanala
 * YENİ bir istek gönderir ve hız sınırı kotasını boşa harcar.
 */
const checking = ref(null);

function recheck(id) {
    if (checking.value !== null) return;

    checking.value = id;

    router.post(`/channels/${id}/health`, {}, {
        preserveScroll: true,
        onFinish: () => {
            checking.value = null;
        },
    });
}

function formatDate(iso) {
    if (!iso) return '—';
    return new Date(iso).toLocaleString('tr-TR');
}
</script>

<template>
    <PanelLayout>
        <div class="flex items-end justify-between">
            <div>
                <p class="font-mono text-[10px] uppercase tracking-widest text-stone-500">
                    Kanallar
                </p>
                <h1 class="mt-1 text-2xl font-semibold tracking-tight text-stone-900">
                    Bağlı mağazalar
                </h1>
            </div>

            <Link
                href="/channels/create"
                class="rounded bg-stone-900 px-4 py-2 text-sm font-medium text-white transition hover:bg-stone-800"
            >
                Mağaza bağla
            </Link>
        </div>

        <div
            v-if="flashSuccess"
            class="mt-6 rounded border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900"
        >
            {{ flashSuccess }}
        </div>

        <div
            v-if="flashWarning"
            class="mt-6 rounded border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900"
        >
            {{ flashWarning }}
        </div>

        <div v-if="!sorted.length" class="mt-10 rounded border border-dashed border-stone-300 p-10 text-center">
            <p class="text-sm text-stone-600">
                Henüz bağlı mağaza yok. Senkron için en az bir kanal gerekiyor.
            </p>
            <Link href="/channels/create" class="mt-3 inline-block text-sm font-medium text-stone-900 underline">
                İlk mağazayı bağla
            </Link>
        </div>

        <div v-else class="mt-6 space-y-4">
            <article
                v-for="connection in sorted"
                :key="connection.id"
                class="rounded border border-stone-200 bg-white p-5"
            >
                <div class="flex items-start justify-between gap-4">
                    <div class="min-w-0">
                        <div class="flex items-center gap-2">
                            <h2 class="truncate text-sm font-medium text-stone-900">
                                {{ connection.label }}
                            </h2>
                            <span
                                class="rounded border px-2 py-0.5 font-mono text-[10px] uppercase tracking-wider"
                                :class="healthClass(connection.health)"
                            >
                                {{ healthLabels[connection.health] ?? connection.health }}
                            </span>
                        </div>

                        <p class="mt-1 truncate font-mono text-xs text-stone-500">
                            {{ connection.channel }} · {{ connection.account }}
                        </p>
                    </div>

                    <button
                        type="button"
                        class="shrink-0 rounded border border-stone-300 px-3 py-1.5 text-sm text-stone-700 transition hover:bg-stone-100 disabled:cursor-not-allowed disabled:opacity-50"
                        :disabled="checking !== null"
                        @click="recheck(connection.id)"
                    >
                        {{ checking === connection.id ? 'Kontrol ediliyor…' : 'Tekrar dene' }}
                    </button>
                </div>

                <!--
                    Sağlıksızlık gerekçesi GİZLENMEZ: kullanıcı anahtarı mı
                    yanlış girdi, mağaza mı kapalı — ancak bu metni görerek
                    ayırt edebilir.
                -->
                <p
                    v-if="connection.lastError"
                    class="mt-3 rounded bg-red-50 px-3 py-2 font-mono text-xs text-red-900"
                >
                    {{ connection.lastError }}
                </p>

                <dl class="mt-4 grid grid-cols-2 gap-4 border-t border-stone-100 pt-4 text-xs sm:grid-cols-3">
                    <div>
                        <dt class="text-stone-500">Durum</dt>
                        <dd class="mt-0.5 font-medium text-stone-900">{{ connection.status }}</dd>
                    </div>
                    <div>
                        <dt class="text-stone-500">Son sağlıklı</dt>
                        <dd class="mt-0.5 text-stone-700">{{ formatDate(connection.lastHealthyAt) }}</dd>
                    </div>
                    <div>
                        <dt class="text-stone-500">Yetenekler</dt>
                        <dd class="mt-0.5 text-stone-700">
                            {{ activeCapabilities(connection.capabilities).join(' · ') || '—' }}
                        </dd>
                    </div>
                </dl>
            </article>
        </div>
    </PanelLayout>
</template>
