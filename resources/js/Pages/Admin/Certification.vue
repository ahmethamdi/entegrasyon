<script setup>
import { Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import PageHeader from '../../Components/PageHeader.vue';
import PanelLayout from '../../Layouts/PanelLayout.vue';
import { useI18n } from '../../lib/i18n';

/*
 * S15 · kanal × yetenek sertifikasyon matrisi (yalnız süper admin).
 * MOCK_TESTED ile VERIFIED ayrı renkte: "test geçti" gerçek doğrulama sayılmaz.
 */
const props = defineProps({
    capabilities: { type: Array, default: () => [] },
    statuses: { type: Array, default: () => [] },
    rows: { type: Array, default: () => [] },
    services: { type: Array, default: () => [] },
});

const { t } = useI18n();

const statusLabel = computed(() => Object.fromEntries(props.statuses.map((s) => [s.key, s.label])));

const short = {
    NOT_SUPPORTED: 'yok',
    NOT_IMPLEMENTED: '—',
    IMPLEMENTED: 'kod',
    MOCK_TESTED: 'test',
    SANDBOX_VERIFIED: 'sandbox',
    PRODUCTION_VERIFIED: 'canlı',
    FAILED: 'hata',
};

const tone = {
    NOT_SUPPORTED: 'bg-stone-100 text-stone-500',
    NOT_IMPLEMENTED: 'bg-white text-stone-400',
    IMPLEMENTED: 'bg-amber-50 text-amber-800',
    MOCK_TESTED: 'bg-sky-50 text-sky-800',
    SANDBOX_VERIFIED: 'bg-emerald-50 text-emerald-800',
    PRODUCTION_VERIFIED: 'bg-emerald-600 text-white',
    FAILED: 'bg-red-600 text-white',
};

const selected = ref(null);
const pick = (row, cap) => {
    selected.value = { channel: row.name, capability: cap.label, ...row.cells[cap.key] };
};
</script>

<template>
    <PanelLayout>
        <PageHeader :section="t('Yönetim')" :title="t('Kanal sertifikasyonu')" :description="t('Her kanal × yetenek: koddan, test taramasından ve kanıtlı gerçek doğrulamalardan.')">
            <template #actions>
                <Link href="/admin" class="rounded-md border border-stone-300 px-4 py-2 text-sm text-stone-700 transition hover:bg-stone-100">{{ t('Yönetim') }}</Link>
            </template>
        </PageHeader>

        <ul class="mt-4 flex flex-wrap gap-2 text-xs">
            <li v-for="s in statuses" :key="s.key" class="rounded px-2 py-1" :class="tone[s.key]">{{ t(short[s.key]) }} · {{ t(s.label) }}</li>
        </ul>

        <div class="relative mt-4 overflow-x-auto rounded-lg border border-stone-200 bg-white">
            <table class="w-full min-w-[1100px] text-xs">
                <thead class="bg-stone-50 text-left text-stone-500">
                    <tr>
                        <th class="px-3 py-2 font-medium">{{ t('Kanal') }}</th>
                        <th v-for="c in capabilities" :key="c.key" class="px-1 py-2 text-center font-medium">{{ t(c.label) }}</th>
                        <th class="px-3 py-2 text-right font-medium">{{ t('Hata (24 sa)') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-stone-100">
                    <tr v-for="row in rows" :key="row.code">
                        <th scope="row" class="px-3 py-2 text-left font-medium text-stone-900">{{ row.name }}</th>
                        <td v-for="c in capabilities" :key="c.key" class="px-1 py-1 text-center">
                            <button
                                type="button"
                                class="w-full rounded px-1 py-1 font-medium"
                                :class="tone[row.cells[c.key].status]"
                                :title="t(statusLabel[row.cells[c.key].status])"
                                @click="pick(row, c)"
                            >
                                {{ t(short[row.cells[c.key].status]) }}<span v-if="row.cells[c.key].note" aria-hidden="true">*</span>
                            </button>
                        </td>
                        <td class="px-3 py-2 text-right tabular-nums" :class="row.errors.api_errors + row.errors.dead_operations > 0 ? 'text-red-700' : 'text-stone-400'">
                            {{ row.errors.api_errors }} / {{ row.errors.dead_operations }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <p class="mt-2 text-xs text-stone-500">{{ t('Hata sütunu: kanala giden başarısız istek / ölen senkron işlem, son 24 saat, bütün kiracılar.') }}</p>

        <section v-if="selected" class="mt-6 rounded-lg border border-stone-200 bg-white p-5" aria-live="polite">
            <h2 class="text-sm font-semibold text-stone-900">{{ selected.channel }} · {{ t(selected.capability) }}</h2>
            <p class="mt-1 text-sm">{{ t(statusLabel[selected.status]) }}</p>
            <p v-if="selected.evidence" class="mt-3 text-sm text-stone-700">
                <strong>{{ selected.evidence.date }}</strong> · {{ selected.evidence.by }} — {{ selected.evidence.evidence }}
            </p>
            <p v-if="selected.note" class="mt-3 text-sm text-stone-700">{{ selected.note }}</p>
            <div v-if="selected.tests.length" class="mt-3">
                <p class="text-xs font-medium text-stone-500">{{ t('Sahte HTTP testleri (gerçek hesap DEĞİL):') }}</p>
                <ul class="mt-1 space-y-0.5 font-mono text-[11px] text-stone-600">
                    <li v-for="test in selected.tests" :key="test" class="break-all">{{ test }}</li>
                </ul>
            </div>
        </section>

        <section class="mt-6 rounded-lg border border-stone-200 bg-white p-5">
            <h2 class="text-sm font-semibold text-stone-900">{{ t('Kanal dışı servisler') }}</h2>
            <ul class="mt-3 space-y-2 text-sm">
                <li v-for="s in services" :key="s.service" class="flex flex-wrap items-start gap-2">
                    <span class="rounded px-2 py-0.5 text-xs" :class="tone[s.status]">{{ t(short[s.status]) }}</span>
                    <span class="font-medium text-stone-900">{{ s.service }}</span>
                    <span class="text-stone-600">{{ s.date }} — {{ s.evidence }}</span>
                </li>
            </ul>
        </section>
    </PanelLayout>
</template>
