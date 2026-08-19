<script setup>
import { router } from '@inertiajs/vue3';
import { computed } from 'vue';
import PanelLayout from '../../Layouts/PanelLayout.vue';

const props = defineProps({
    rows: { type: Array, default: () => [] },
    summary: { type: Object, default: () => ({}) },
    last_run: { type: Object, default: null },
    filters: { type: Object, default: () => ({}) },
});

function applyFilter(filter) {
    router.get('/reconciliation', { filter }, {
        preserveState: true,
        preserveScroll: true,
    });
}

/**
 * Durum rozetleri. Sıra ve renk ÖNEMLİDİR.
 *
 * MANUAL_REVIEW en ağırıdır: otomatik onarım orada DURDU (§10 · 3 tur
 * kuralı) ve satır kendiliğinden düzelmeyecek. Sıradan bir sürüklenmeyle
 * aynı renkte gösterilseydi satıcı "sistem hallediyor" sanır ve tam olarak
 * müdahale bekleyen satırı hiç görmezdi.
 *
 * REPAIR_QUEUED sakin bir renktedir: onarım YOLDA ve satıcının yapacağı
 * bir şey yok.
 */
const badges = {
    MANUAL_REVIEW: {
        text: 'ELLE İNCELEME',
        class: 'bg-red-50 text-red-900 border-red-300',
    },
    DRIFT_DETECTED: {
        text: 'SÜRÜKLENME',
        class: 'bg-amber-50 text-amber-900 border-amber-300',
    },
    REPAIR_QUEUED: {
        text: 'ONARILIYOR',
        class: 'bg-sky-50 text-sky-800 border-sky-200',
    },
    REMOTE_MISSING: {
        text: 'KANALDA YOK',
        class: 'bg-purple-50 text-purple-900 border-purple-200',
    },
    REMOTE_UNREACHABLE: {
        text: 'KANAL OKUNAMADI',
        class: 'bg-stone-100 text-stone-700 border-stone-300',
    },
    REPAIRED: {
        text: 'ONARILDI',
        class: 'bg-emerald-50 text-emerald-800 border-emerald-200',
    },
    MATCHED: {
        text: 'EŞLEŞTİ',
        class: 'bg-emerald-50 text-emerald-800 border-emerald-200',
    },
};

/** Aday seçim sebebi — "bu satıra neden bakıldı". */
const reasons = {
    recently_sold: 'Yeni satış',
    previous_error: 'Önceki hata',
    stale_sync: 'Bekleyen senkron',
    drift_detected: 'Doğrulama turu',
    sampled: 'Örneklem',
};

const scopes = {
    hot: 'Sıcak (5 dk)',
    warm: 'Ilık (saatlik)',
    cold: 'Soğuk (günlük)',
};

const lastRunText = computed(() => {
    if (!props.last_run) {
        return null;
    }

    const scope = scopes[props.last_run.scope] ?? props.last_run.scope;
    const when = props.last_run.finishedAt ?? props.last_run.startedAt;

    if (!when) {
        return scope;
    }

    return `${scope} · ${new Date(when).toLocaleString('tr-TR')}`;
});

function badgeFor(status) {
    return badges[status] ?? { text: status, class: 'bg-stone-50 text-stone-600 border-stone-200' };
}

function reasonFor(reason) {
    return reasons[reason] ?? reason;
}
</script>

<template>
    <PanelLayout>
        <div class="flex items-end justify-between">
            <div>
                <p class="font-mono text-[10px] uppercase tracking-widest text-stone-500">
                    Mutabakat
                </p>
                <h1 class="mt-1 text-2xl font-semibold tracking-tight text-stone-900">
                    Kanal sürüklenmesi
                </h1>
            </div>

            <p v-if="lastRunText" class="text-xs text-stone-500">
                Son tur: {{ lastRunText }}
            </p>
        </div>

        <!--
            ÜÇ SAYI, ÜÇ FARKLI EYLEM. Tek sayıda birleştirilselerdi satıcı
            hangi eylemin gerektiğini bilemezdi: elle inceleme müdahale
            ister, sürüklenme kendiliğinden onarılır, okunamayan kanal
            bağlantı sağlığına bakmayı gerektirir.
        -->
        <div class="mt-6 grid gap-4 sm:grid-cols-4">
            <div
                class="rounded border p-4"
                :class="summary.manual_review > 0
                    ? 'border-red-300 bg-red-50'
                    : 'border-stone-200 bg-white'"
            >
                <p class="text-xs" :class="summary.manual_review > 0 ? 'text-red-800' : 'text-stone-500'">
                    Elle inceleme
                </p>
                <p
                    class="mt-1 text-2xl font-semibold"
                    :class="summary.manual_review > 0 ? 'text-red-900' : 'text-stone-900'"
                >
                    {{ summary.manual_review ?? 0 }}
                </p>
            </div>

            <div
                class="rounded border p-4"
                :class="summary.drift > 0 ? 'border-amber-300 bg-amber-50' : 'border-stone-200 bg-white'"
            >
                <p class="text-xs" :class="summary.drift > 0 ? 'text-amber-900' : 'text-stone-500'">
                    Sürüklenme
                </p>
                <p
                    class="mt-1 text-2xl font-semibold"
                    :class="summary.drift > 0 ? 'text-amber-900' : 'text-stone-900'"
                >
                    {{ summary.drift ?? 0 }}
                </p>
            </div>

            <div class="rounded border border-stone-200 bg-white p-4">
                <p class="text-xs text-stone-500">Kanal okunamadı</p>
                <p class="mt-1 text-2xl font-semibold text-stone-900">
                    {{ summary.unreachable ?? 0 }}
                </p>
            </div>

            <div class="rounded border border-stone-200 bg-white p-4">
                <p class="text-xs text-stone-500">Onarıldı</p>
                <p class="mt-1 text-2xl font-semibold text-stone-900">
                    {{ summary.repaired ?? 0 }}
                </p>
            </div>
        </div>

        <!--
            ELLE İNCELEME UYARISI: bu satırlarda otomatik onarım DURDU ve
            kullanıcı müdahale etmezse hiçbir şey değişmeyecek.
        -->
        <div
            v-if="summary.manual_review > 0"
            class="mt-6 rounded border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-900"
        >
            <span class="font-semibold">{{ summary.manual_review }} ürün elle inceleme bekliyor.</span>
            Bu satırlarda kanal üç tur üst üste bizim gönderdiğimiz değeri uygulamadı;
            otomatik onarım durduruldu. Kanal panelinden ürünün stok yönetimi
            ayarını ve yetkileri kontrol edin.
        </div>

        <!-- filtreler -->
        <div class="mt-8 flex flex-wrap items-center gap-3">
            <div class="flex rounded border border-stone-300 bg-white p-0.5">
                <button
                    type="button"
                    class="rounded px-3 py-1.5 text-sm transition"
                    :class="filters.filter === 'open'
                        ? 'bg-stone-900 text-white'
                        : 'text-stone-700 hover:bg-stone-100'"
                    @click="applyFilter('open')"
                >
                    Açık sorunlar
                </button>
                <button
                    type="button"
                    class="rounded px-3 py-1.5 text-sm transition"
                    :class="filters.filter === 'all'
                        ? 'bg-stone-900 text-white'
                        : 'text-stone-700 hover:bg-stone-100'"
                    @click="applyFilter('all')"
                >
                    Tüm geçmiş
                </button>
            </div>
        </div>

        <div class="mt-6 overflow-x-auto rounded border border-stone-200 bg-white">
            <table class="w-full text-sm">
                <thead class="border-b border-stone-200 bg-stone-50">
                    <tr class="text-left text-xs uppercase tracking-wide text-stone-500">
                        <th class="px-4 py-3 font-medium">SKU</th>
                        <th class="px-4 py-3 font-medium">Durum</th>
                        <th class="px-4 py-3 font-medium">Sebep</th>
                        <th class="px-4 py-3 text-right font-medium">Bizde</th>
                        <th class="px-4 py-3 text-right font-medium">Kanalda</th>
                        <th class="px-4 py-3 text-right font-medium">Fark</th>
                        <th class="px-4 py-3 font-medium">Kontrol</th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-stone-100">
                    <tr v-for="row in rows" :key="row.id" class="hover:bg-stone-50">
                        <td class="px-4 py-3">
                            <p class="font-mono text-sm text-stone-900">{{ row.sku ?? '—' }}</p>
                            <p v-if="row.externalId" class="font-mono text-[11px] text-stone-500">
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

                        <td class="px-4 py-3 text-stone-600">{{ reasonFor(row.reason) }}</td>

                        <!--
                            FAZLA SATIŞTA İKİ DEĞER AYRIŞIR: kanonik bakiye
                            negatifse kanala giden değer 0'dır ve kanaldaki 0
                            DOĞRUDUR. Yalnızca biri gösterilseydi satıcı ya
                            olmayan bir sürüklenme arar ya fazla satışı hiç
                            göremezdi (§10 · §17 · P0).
                        -->
                        <td class="px-4 py-3 text-right">
                            <p class="font-mono text-stone-900">{{ row.expected_remote ?? '—' }}</p>
                            <p v-if="row.oversold" class="font-mono text-[11px] text-red-700">
                                bakiye {{ row.available }}
                            </p>
                        </td>

                        <td class="px-4 py-3 text-right font-mono text-stone-900">
                            {{ row.observed_remote ?? '—' }}
                        </td>

                        <td class="px-4 py-3 text-right font-mono">
                            <span :class="row.drift_magnitude > 0 ? 'text-amber-900' : 'text-stone-400'">
                                {{ row.drift_magnitude ?? '—' }}
                            </span>
                        </td>

                        <td class="px-4 py-3 text-xs text-stone-500">
                            {{ row.checkedAt ? new Date(row.checkedAt).toLocaleString('tr-TR') : '—' }}
                        </td>
                    </tr>

                    <tr v-if="rows.length === 0">
                        <td colspan="7" class="px-4 py-12 text-center">
                            <p class="text-sm text-stone-600">
                                <template v-if="last_run">
                                    Açık sürüklenme yok — kanallardaki stok bizdekiyle uyuşuyor.
                                </template>
                                <template v-else>
                                    Henüz mutabakat turu koşmadı.
                                </template>
                            </p>
                            <p v-if="!last_run" class="mt-1 text-xs text-stone-500">
                                Turlar otomatik çalışır: sıcak 5 dakikada, ılık saatlik, soğuk günlük.
                            </p>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </PanelLayout>
</template>
