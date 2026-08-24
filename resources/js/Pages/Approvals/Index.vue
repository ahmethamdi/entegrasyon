<script setup>
import { Link, router } from '@inertiajs/vue3';
import { computed } from 'vue';
import PageHeader from '../../Components/PageHeader.vue';
import StatCard from '../../Components/StatCard.vue';
import PanelLayout from '../../Layouts/PanelLayout.vue';

const props = defineProps({
    rows: { type: Array, default: () => [] },
    summary: { type: Object, default: () => ({}) },
    connections: { type: Array, default: () => [] },
    hasApprovalChannels: { type: Boolean, default: false },
    filters: { type: Object, default: () => ({}) },
    lastCheckedAt: { type: String, default: null },
});

/**
 * Durum rozetleri — İKİ DURUM, İKİ FARKLI EYLEM.
 *
 * `rejected` KULLANICI MÜDAHALESİ bekler ve kendiliğinden düzelmez;
 * `pending_approval` normal bir kuyruk durumudur ve satıcının yapacağı
 * bir şey yoktur.
 *
 * Bekleyen rozeti SKY'dır, amber DEĞİL — panelin yerleşik kuralı:
 * "bekliyor" bir uyarı değil, başarı dışındaki EN SAKİN durumdur.
 */
const badges = {
    rejected: {
        text: 'REDDEDİLDİ',
        class: 'bg-red-50 text-red-900 border-red-300',
    },
    pending_approval: {
        text: 'ONAY BEKLİYOR',
        class: 'bg-sky-50 text-sky-800 border-sky-200',
    },
};

const connectionNames = computed(() => Object.fromEntries(
    props.connections.map((c) => [c.id, `${c.channel} · ${c.label}`]),
));

const lastCheckedText = computed(() => (
    props.lastCheckedAt ? new Date(props.lastCheckedAt).toLocaleString('tr-TR') : null
));

function badgeFor(status) {
    return badges[status] ?? { text: status, class: 'bg-stone-50 text-stone-600 border-stone-200' };
}

/**
 * Filtre değişikliği tek yerden gider — iki filtre AYNI sorguyu daraltır
 * ve ayrı ayrı gönderilselerdi biri diğerini sıfırlardı.
 */
function applyFilter(patch) {
    router.get('/approvals', {
        connection: props.filters.connection ?? undefined,
        status: props.filters.status ?? undefined,
        ...patch,
    }, {
        preserveState: true,
        preserveScroll: true,
    });
}
</script>

<template>
    <PanelLayout>
        <PageHeader section="Katalog" title="Kanal onayları">
            <template #actions>
                <p v-if="lastCheckedText" class="text-xs text-stone-500">
                    Son kontrol: {{ lastCheckedText }}
                </p>
            </template>
        </PageHeader>

        <!--
            ONAY SÜRECİ OLAN KANAL YOKSA TABLO HİÇ GÖSTERİLMEZ.

            Boş tablo göstermek satıcıya "onay bekleyen ürünüm yok"
            dedirtirdi; doğru cevap "bu kanalda onay süreci yok"tur ve
            ikisi TAMAMEN FARKLI şeylerdir. WooCommerce'te ürün
            gönderilir gönderilmez yayına girer.
        -->
        <div
            v-if="!hasApprovalChannels"
            class="mt-6 rounded-lg border border-stone-200 bg-white px-4 py-12 text-center"
        >
            <p class="text-sm text-stone-600">
                Onay süreci olan bağlı kanalınız yok.
            </p>
            <p class="mt-1 text-xs text-stone-500">
                WooCommerce gibi mağaza yazılımlarında ürün gönderilir gönderilmez yayına girer;
                onay bekleme durumu yalnızca Trendyol gibi pazaryerlerinde vardır.
            </p>
            <Link
                href="/channels"
                class="mt-4 inline-block rounded-md border border-stone-300 bg-white px-3 py-1.5 text-sm text-stone-800 transition hover:bg-stone-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
            >
                Kanallara git
            </Link>
        </div>

        <template v-else>
            <!--
                İKİ SAYI, İKİ FARKLI EYLEM. Reddedilen müdahale ister,
                bekleyen yalnızca zaman ister. Tek sayıda birleştirilselerdi
                satıcı "sistem hallediyor" sanır ve tam olarak kendisini
                bekleyen satırı hiç görmezdi.
            -->
            <div class="mt-6 grid gap-3 sm:grid-cols-2">
                <StatCard
                    label="Reddedildi"
                    :value="summary.rejected ?? 0"
                    :tone="summary.rejected > 0 ? 'error' : 'neutral'"
                    :hint="summary.rejected > 0 ? 'Düzeltip yeniden gönderin' : null"
                />

                <!-- Bekleyen bir SORUN DEĞİL: nötr ton. -->
                <StatCard
                    label="Onay bekliyor"
                    :value="summary.pending ?? 0"
                    :hint="summary.pending > 0 ? 'Kanalın incelemesi sürüyor' : null"
                />
            </div>

            <div
                v-if="summary.rejected > 0"
                class="mt-6 rounded border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-900"
            >
                <span class="font-semibold">{{ summary.rejected }} ürün kanal tarafından reddedildi.</span>
                Bu satırlar kendiliğinden düzelmez — sebebi okuyup ürünü düzeltin ve
                kanala yeniden gönderin.
            </div>

            <!-- filtreler -->
            <div class="mt-8 flex flex-wrap items-center gap-3">
                <div class="flex rounded-md border border-stone-300 bg-white p-0.5">
                    <button
                        type="button"
                        class="rounded px-3 py-1.5 text-sm transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
                        :class="!filters.status ? 'bg-stone-900 text-white' : 'text-stone-700 hover:bg-stone-100'"
                        @click="applyFilter({ status: undefined })"
                    >
                        Hepsi
                    </button>
                    <button
                        type="button"
                        class="rounded px-3 py-1.5 text-sm transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
                        :class="filters.status === 'rejected' ? 'bg-stone-900 text-white' : 'text-stone-700 hover:bg-stone-100'"
                        @click="applyFilter({ status: 'rejected' })"
                    >
                        Reddedilenler
                    </button>
                    <button
                        type="button"
                        class="rounded px-3 py-1.5 text-sm transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
                        :class="filters.status === 'pending_approval' ? 'bg-stone-900 text-white' : 'text-stone-700 hover:bg-stone-100'"
                        @click="applyFilter({ status: 'pending_approval' })"
                    >
                        Bekleyenler
                    </button>
                </div>

                <!-- Tek kanal varsa seçim sormak gereksiz bir karar yüküdür. -->
                <select
                    v-if="connections.length > 1"
                    class="rounded-md border border-stone-300 bg-white px-3 py-1.5 text-sm text-stone-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
                    :value="filters.connection ?? ''"
                    @change="applyFilter({ connection: $event.target.value || undefined })"
                >
                    <option value="">Tüm kanallar</option>
                    <option v-for="c in connections" :key="c.id" :value="c.id">
                        {{ c.channel }} · {{ c.label }}
                    </option>
                </select>
            </div>

            <!--
                Asgari genişlik KIRPMAYI önler: `w-full` tablo dar ekranda
                sütunları sıkıştırır ve "Sebep" gibi metin taşıyan sütun
                bir şeride düşüp okunamaz hâle gelir. Genişlik verilince
                kutu KAYAR ve sayfa taşması ÜRETMEZ.
            -->
            <div class="mt-6 overflow-x-auto rounded-lg border border-stone-200 bg-white">
                <table class="w-full min-w-4xl text-sm">
                    <thead class="border-b border-stone-200 bg-stone-50 text-left">
                        <tr>
                            <th class="px-4 py-2.5 font-mono text-[10px] font-medium uppercase tracking-wider text-stone-600">Ürün</th>
                            <th class="px-4 py-2.5 font-mono text-[10px] font-medium uppercase tracking-wider text-stone-600">Kanal</th>
                            <th class="px-4 py-2.5 font-mono text-[10px] font-medium uppercase tracking-wider text-stone-600">Durum</th>
                            <th class="px-4 py-2.5 font-mono text-[10px] font-medium uppercase tracking-wider text-stone-600">Sebep</th>
                            <th class="px-4 py-2.5 font-mono text-[10px] font-medium uppercase tracking-wider text-stone-600">Son kontrol</th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-stone-100">
                        <!--
                            TONLU SATIRDA HOVER KENDİ AİLESİNDE KALIR:
                            `hover:bg-stone-50` kırmızı satırı griye yıkar
                            ve satıcı sinyalden şüphe eder.
                        -->
                        <tr
                            v-for="row in rows"
                            :key="row.id"
                            :class="row.status === 'rejected'
                                ? 'bg-red-50/40 hover:bg-red-50'
                                : 'hover:bg-stone-50'"
                        >
                            <td class="px-4 py-3">
                                <Link
                                    v-if="row.productId"
                                    :href="`/products/${row.productId}/channels`"
                                    class="text-stone-900 underline-offset-2 hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
                                >
                                    {{ row.title ?? '—' }}
                                </Link>
                                <span v-else class="text-stone-900">{{ row.title ?? '—' }}</span>
                                <!-- SKU bir KİMLİKTİR; kelime ortasından bölünürse okunmaz. -->
                                <p class="font-mono text-[11px] whitespace-nowrap text-stone-500">
                                    {{ row.sku ?? '—' }}
                                </p>
                            </td>

                            <td class="px-4 py-3 text-stone-600">
                                {{ connectionNames[row.connectionId] ?? '—' }}
                                <a
                                    v-if="row.externalUrl"
                                    :href="row.externalUrl"
                                    target="_blank"
                                    rel="noopener"
                                    class="block font-mono text-[11px] text-stone-500 underline-offset-2 hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
                                >
                                    #{{ row.externalId }}
                                </a>
                                <p v-else-if="row.externalId" class="font-mono text-[11px] text-stone-500">
                                    #{{ row.externalId }}
                                </p>
                            </td>

                            <td class="px-4 py-3">
                                <span
                                    class="inline-block rounded border px-2 py-0.5 text-[10px] font-medium tracking-wide"
                                    :class="badgeFor(row.status).class"
                                >
                                    {{ badgeFor(row.status).text }}
                                </span>
                            </td>

                            <!--
                                RED SEBEBİ ADIYLA GÖSTERİLİR: "reddedildi"
                                demek satıcıya ne yapacağını söylemez.
                                Bekleyen satırda sebep YOKTUR ve olmaması
                                doğrudur — kanal henüz bir şey söylemedi.
                            -->
                            <td class="px-4 py-3 text-stone-700">
                                <template v-if="row.reason">{{ row.reason }}</template>
                                <span v-else-if="row.status === 'pending_approval'" class="text-stone-400">
                                    Kanal henüz bir şey bildirmedi
                                </span>
                                <span v-else class="text-stone-400">—</span>
                            </td>

                            <td class="px-4 py-3 text-xs text-stone-500">
                                <!--
                                    HİÇ SORULMAMIŞ satır "—" değil AÇIKÇA
                                    söylenir: tire, satıcıya "kontrol
                                    edildi ama tarih yok" gibi okunurdu.
                                -->
                                {{ row.checkedAt
                                    ? new Date(row.checkedAt).toLocaleString('tr-TR')
                                    : 'Henüz sorulmadı' }}
                            </td>
                        </tr>

                        <tr v-if="rows.length === 0">
                            <td colspan="5" class="px-4 py-12 text-center">
                                <p class="text-sm text-stone-600">
                                    <template v-if="filters.status || filters.connection">
                                        Bu filtreye uyan ürün yok.
                                    </template>
                                    <template v-else>
                                        Onay bekleyen ya da reddedilen ürün yok — gönderdiğiniz her şey yayında.
                                    </template>
                                </p>
                                <p class="mt-1 text-xs text-stone-500">
                                    Onay durumu saatlik olarak kanaldan okunur.
                                </p>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </template>
    </PanelLayout>
</template>
