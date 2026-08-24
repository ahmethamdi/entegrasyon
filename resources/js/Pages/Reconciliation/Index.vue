<script setup>
import { router } from '@inertiajs/vue3';
import { computed } from 'vue';
import PageHeader from '../../Components/PageHeader.vue';
import StatCard from '../../Components/StatCard.vue';
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
        class: 'bg-amber-50 text-amber-900 border-amber-300',
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
        <PageHeader section="Mutabakat" title="Kanal sürüklenmesi">
            <template #actions>
                <p v-if="lastRunText" class="text-xs text-stone-500">
                    Son tur: {{ lastRunText }}
                </p>
            </template>
        </PageHeader>

        <!--
            ÜÇ SAYI, ÜÇ FARKLI EYLEM. Tek sayıda birleştirilselerdi satıcı
            hangi eylemin gerektiğini bilemezdi: elle inceleme müdahale
            ister, sürüklenme kendiliğinden onarılır, okunamayan kanal
            bağlantı sağlığına bakmayı gerektirir.
        -->
        <div class="mt-6 grid gap-3 sm:grid-cols-4">
            <StatCard
                label="Elle inceleme"
                :value="summary.manual_review ?? 0"
                :tone="summary.manual_review > 0 ? 'error' : 'neutral'"
                :hint="summary.manual_review > 0 ? 'Otomatik onarım durdu' : null"
            />

            <!-- Sürüklenme kendiliğinden onarılır: UYARI, hata değil. -->
            <StatCard
                label="Sürüklenme"
                :value="summary.drift ?? 0"
                :tone="summary.drift > 0 ? 'warning' : 'neutral'"
            />

            <!--
                `REMOTE_UNREACHABLE` sürüklenme SAYILMAZ (§10) ama ayrı
                gösterilir: sessizce yutulsaydı satıcı kanalının
                okunamadığını hiç bilmezdi.
            -->
            <StatCard label="Kanal okunamadı" :value="summary.unreachable ?? 0" />

            <StatCard label="Onarıldı" :value="summary.repaired ?? 0" tone="good" />
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
            <div class="flex rounded-md border border-stone-300 bg-white p-0.5">
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

        <!--
            Asgari genişlik KIRPMAYI önler: `w-full` tablo dar ekranda
            sütunları sıkıştırır ve "Sebep" gibi metin taşıyan sütun
            okunamaz hâle gelir. Genişlik verilince kutu KAYAR.
        -->
        <div class="mt-6 overflow-x-auto rounded-lg border border-stone-200 bg-white">
            <table class="w-full min-w-3xl text-sm">
                <thead class="border-b border-stone-200 bg-stone-50 text-left">
                    <tr>
                        <th class="px-4 py-2.5 font-mono text-[10px] font-medium uppercase tracking-wider text-stone-600">SKU</th>
                        <th class="px-4 py-2.5 font-mono text-[10px] font-medium uppercase tracking-wider text-stone-600">Durum</th>
                        <th class="px-4 py-2.5 font-mono text-[10px] font-medium uppercase tracking-wider text-stone-600">Sebep</th>
                        <th class="px-4 py-2.5 text-right font-mono text-[10px] font-medium uppercase tracking-wider text-stone-600">Bizde</th>
                        <th class="px-4 py-2.5 text-right font-mono text-[10px] font-medium uppercase tracking-wider text-stone-600">Kanalda</th>
                        <th class="px-4 py-2.5 text-right font-mono text-[10px] font-medium uppercase tracking-wider text-stone-600">Fark</th>
                        <th class="px-4 py-2.5 font-mono text-[10px] font-medium uppercase tracking-wider text-stone-600">Kontrol</th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-stone-100">
                    <tr v-for="row in rows" :key="row.id" class="hover:bg-stone-50">
                        <td class="px-4 py-3">
                            <!-- SKU bir KİMLİKTİR; kelime ortasından bölünürse okunmaz. -->
                            <p class="font-mono text-sm whitespace-nowrap text-stone-900">{{ row.sku ?? '—' }}</p>
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
