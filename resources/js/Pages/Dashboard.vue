<script setup>
import PanelLayout from '../Layouts/PanelLayout.vue';

const props = defineProps({
    tenant: { type: Object, default: () => ({}) },
    connections: { type: Array, default: () => [] },
    syncHealth: { type: Object, default: () => ({}) },
    oversold: { type: Array, default: () => [] },
    recentOperations: { type: Array, default: () => [] },
});

const statusLabels = {
    pending: 'bekliyor',
    retrying: 'yeniden deniyor',
    completed: 'tamamlandı',
    superseded: 'geçersiz kılındı',
    dead: 'başarısız',
};

const errorLabels = {
    RATE_LIMITED: 'hız sınırı',
    SERVER_ERROR: 'sunucu hatası',
    TIMEOUT: 'zaman aşımı',
    NETWORK: 'ağ hatası',
    CONFLICT: 'çakışma',
    VALIDATION: 'doğrulama',
    AUTHENTICATION: 'kimlik doğrulama',
    NOT_FOUND: 'bulunamadı',
};

function formatTime(iso) {
    if (!iso) return '—';

    return new Intl.DateTimeFormat('tr-TR', {
        day: '2-digit',
        month: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
    }).format(new Date(iso));
}
</script>

<template>
    <PanelLayout>
        <h1 class="text-2xl font-semibold tracking-tight text-stone-900">
            Senkron durumu
        </h1>

        <!-- Sağlık özeti -->
        <dl class="mt-6 grid grid-cols-2 gap-px overflow-hidden rounded border border-stone-200 bg-stone-200 sm:grid-cols-4">
            <div class="bg-white p-4">
                <dt class="font-mono text-[10px] uppercase tracking-wider text-stone-500">
                    Senkron
                </dt>
                <dd class="mt-1 text-2xl font-medium tabular-nums text-stone-900">
                    {{ syncHealth.synced ?? 0 }}
                </dd>
            </div>
            <div class="bg-white p-4">
                <dt class="font-mono text-[10px] uppercase tracking-wider text-stone-500">
                    Bekleyen
                </dt>
                <dd class="mt-1 text-2xl font-medium tabular-nums text-stone-900">
                    {{ syncHealth.dirty ?? 0 }}
                </dd>
            </div>
            <div class="bg-white p-4">
                <dt class="font-mono text-[10px] uppercase tracking-wider text-stone-500">
                    Geçici hata
                </dt>
                <dd
                    class="mt-1 text-2xl font-medium tabular-nums"
                    :class="(syncHealth.errorTransient ?? 0) > 0 ? 'text-amber-700' : 'text-stone-900'"
                >
                    {{ syncHealth.errorTransient ?? 0 }}
                </dd>
            </div>
            <div class="bg-white p-4">
                <dt class="font-mono text-[10px] uppercase tracking-wider text-stone-500">
                    Kalıcı hata
                </dt>
                <dd
                    class="mt-1 text-2xl font-medium tabular-nums"
                    :class="(syncHealth.errorPermanent ?? 0) > 0 ? 'text-red-700' : 'text-stone-900'"
                >
                    {{ syncHealth.errorPermanent ?? 0 }}
                </dd>
            </div>
        </dl>

        <!--
            FAZLA SATIŞ — negatif available kullanıcıya ANLATILIR (§17 · P0).
            Eksik miktar gizlenirse satıcı stoğunun neden tutmadığını anlamaz.
        -->
        <section v-if="oversold.length" class="mt-10">
            <h2 class="text-sm font-semibold text-red-800">
                Fazla satış · {{ oversold.length }} varyant
            </h2>
            <p class="mt-1 text-sm text-stone-600">
                Bu varyantlar stokta olandan fazla satıldı. Kanala sıfır gönderiliyor,
                ama gerçek açık aşağıda.
            </p>

            <div class="mt-3 overflow-x-auto rounded border border-red-200">
                <table class="w-full text-sm">
                    <thead class="bg-red-50 text-left">
                        <tr>
                            <th class="px-4 py-2 font-medium text-red-900">SKU</th>
                            <th class="px-4 py-2 text-right font-medium text-red-900">Bakiye</th>
                            <th class="px-4 py-2 text-right font-medium text-red-900">Eksik</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-red-100 bg-white">
                        <tr v-for="row in oversold" :key="row.sku">
                            <td class="px-4 py-2 font-mono text-xs text-stone-900">{{ row.sku }}</td>
                            <td class="px-4 py-2 text-right tabular-nums text-red-700">
                                {{ row.available }}
                            </td>
                            <td class="px-4 py-2 text-right tabular-nums font-medium text-red-800">
                                {{ row.shortfall }} adet
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- Kanal bağlantıları -->
        <section class="mt-10">
            <h2 class="text-sm font-semibold text-stone-900">Kanallar</h2>

            <p v-if="!connections.length" class="mt-2 text-sm text-stone-500">
                Henüz bağlı kanal yok.
            </p>

            <ul v-else class="mt-3 divide-y divide-stone-200 rounded border border-stone-200 bg-white">
                <li
                    v-for="connection in connections"
                    :key="connection.id"
                    class="flex items-center justify-between px-4 py-3"
                >
                    <div>
                        <p class="text-sm font-medium text-stone-900">{{ connection.channel }}</p>
                        <p class="text-xs text-stone-500">{{ connection.label }}</p>
                    </div>
                    <span
                        class="rounded-full px-2 py-0.5 text-xs font-medium"
                        :class="connection.health === 'healthy'
                            ? 'bg-emerald-50 text-emerald-800'
                            : 'bg-amber-50 text-amber-800'"
                    >
                        {{ connection.health === 'healthy' ? 'sağlıklı' : connection.health }}
                    </span>
                </li>
            </ul>
        </section>

        <!-- Son operasyonlar: "ne oldu" sorusunun cevabı -->
        <section class="mt-10">
            <h2 class="text-sm font-semibold text-stone-900">Son senkron işlemleri</h2>

            <p v-if="!recentOperations.length" class="mt-2 text-sm text-stone-500">
                Henüz senkron işlemi yok.
            </p>

            <!-- Asgari genişlik sütunların dar ekranda sıkışmasını önler; kutu kayar. -->
            <div v-else class="mt-3 overflow-x-auto rounded border border-stone-200">
                <table class="w-full min-w-3xl text-sm">
                    <thead class="bg-stone-50 text-left">
                        <tr>
                            <th class="px-4 py-2 font-medium text-stone-700">Kanal</th>
                            <th class="px-4 py-2 font-medium text-stone-700">İşlem</th>
                            <th class="px-4 py-2 text-right font-medium text-stone-700">Sürüm</th>
                            <th class="px-4 py-2 text-right font-medium text-stone-700">Deneme</th>
                            <th class="px-4 py-2 font-medium text-stone-700">Durum</th>
                            <th class="px-4 py-2 font-medium text-stone-700">Zaman</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100 bg-white">
                        <tr v-for="op in recentOperations" :key="op.id">
                            <td class="px-4 py-2 text-stone-900">{{ op.channel }}</td>
                            <td class="px-4 py-2 font-mono text-xs text-stone-600">{{ op.type }}</td>
                            <td class="px-4 py-2 text-right tabular-nums text-stone-600">{{ op.version }}</td>
                            <td class="px-4 py-2 text-right tabular-nums text-stone-600">{{ op.attempts }}</td>
                            <td class="px-4 py-2">
                                <span
                                    class="rounded px-1.5 py-0.5 text-xs font-medium"
                                    :class="op.isFailed
                                        ? 'bg-red-50 text-red-800'
                                        : 'bg-stone-100 text-stone-700'"
                                >
                                    {{ statusLabels[op.status] ?? op.status }}
                                </span>
                                <span v-if="op.errorClass" class="ml-1 text-xs text-red-700">
                                    {{ errorLabels[op.errorClass] ?? op.errorClass }}
                                </span>
                            </td>
                            <td class="px-4 py-2 text-xs tabular-nums text-stone-500">
                                {{ formatTime(op.createdAt) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </PanelLayout>
</template>
