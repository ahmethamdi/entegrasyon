<script setup>
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import PageHeader from '../../Components/PageHeader.vue';
import PanelLayout from '../../Layouts/PanelLayout.vue';
import { intlLocale, k, useI18n } from '../../lib/i18n';
import { usePermissions } from '../../lib/permissions.js';

defineProps({
    sources: { type: Array, default: () => [] },
});

const { t } = useI18n();
// S7 · tedarikçi ekleme yazma işidir.
const { can } = usePermissions();
const page = usePage();
const flashSuccess = computed(() => page.props.flash?.success);

// Son turun sonucu — "indirilemedi" ile "XML bozuk" ayrı söylenir.
const statuses = {
    complete: { text: k('Tamamlandı'), class: 'bg-emerald-50 text-emerald-800 border-emerald-200' },
    partial: { text: k('Kısmen'), class: 'bg-amber-50 text-amber-900 border-amber-300' },
    fetch_failed: { text: k('İndirilemedi'), class: 'bg-red-50 text-red-900 border-red-300' },
    parse_failed: { text: k('XML okunamadı'), class: 'bg-red-50 text-red-900 border-red-300' },
    validation_failed: { text: k('Eşleme hatalı'), class: 'bg-red-50 text-red-900 border-red-300' },
    failed: { text: k('Yarıda kaldı'), class: 'bg-red-50 text-red-900 border-red-300' },
};

function statusFor(status) {
    return statuses[status] ?? null;
}

function when(iso) {
    return iso ? new Date(iso).toLocaleString(intlLocale(), { dateStyle: 'short', timeStyle: 'short' }) : '—';
}
</script>

<template>
    <PanelLayout>
        <PageHeader
            :section="t('Ürünler')"
            :title="t('Tedarikçi XML')"
            :description="t('Toptancının XML ürün listesini bağla: yeni ürünleri aç, stok ve fiyatı her saat güncelle. Önce önizlersin, sonra uygularsın.')"
        />

        <p v-if="flashSuccess" class="mt-6 rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">{{ flashSuccess }}</p>

        <div class="mt-6">
            <Link v-if="can('catalog.manage')" href="/suppliers/create" class="inline-flex rounded-md bg-stone-900 px-4 py-2 text-sm font-medium text-white hover:bg-stone-700">
                {{ t('Tedarikçi ekle') }}
            </Link>
        </div>

        <p v-if="sources.length === 0" class="mt-8 text-sm text-stone-600">{{ t('Henüz tedarikçi yok.') }}</p>

        <div v-else class="relative mt-6 overflow-x-auto rounded-lg border border-stone-200">
            <table class="min-w-full divide-y divide-stone-200 text-sm">
                <thead class="bg-stone-50 text-left text-xs text-stone-600">
                    <tr>
                        <th class="px-4 py-2 font-medium">{{ t('Tedarikçi') }}</th>
                        <th class="px-4 py-2 font-medium">{{ t('Saatlik güncelleme') }}</th>
                        <th class="px-4 py-2 font-medium">{{ t('Son sonuç') }}</th>
                        <th class="px-4 py-2 font-medium">{{ t('Son başarılı') }}</th>
                        <th class="px-4 py-2 font-medium">{{ t('Sonraki') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-stone-100 bg-white">
                    <tr v-for="source in sources" :key="source.id">
                        <td class="px-4 py-3">
                            <Link :href="`/suppliers/${source.id}`" class="font-medium text-stone-900 underline">{{ source.name }}</Link>
                            <div class="text-xs text-stone-500">{{ source.host }}</div>
                        </td>
                        <td class="px-4 py-3">
                            <span v-if="source.running" class="text-sky-800">{{ t('Güncelleniyor…') }}</span>
                            <span v-else-if="source.isActive" class="text-emerald-800">{{ t('Açık') }}</span>
                            <span v-else class="text-stone-500">{{ t('Kapalı') }}</span>
                        </td>
                        <td class="px-4 py-3">
                            <span v-if="statusFor(source.lastStatus)" class="rounded border px-2 py-0.5 text-xs" :class="statusFor(source.lastStatus).class">
                                {{ t(statusFor(source.lastStatus).text) }}
                            </span>
                            <span v-else class="text-stone-400">—</span>
                            <div v-if="source.lastReason" class="mt-1 text-xs text-stone-600">{{ source.lastReason }}</div>
                        </td>
                        <td class="px-4 py-3 tabular-nums text-stone-700">{{ when(source.lastSucceededAt) }}</td>
                        <td class="px-4 py-3 tabular-nums text-stone-700">{{ source.isActive ? when(source.nextRunAt) : '—' }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </PanelLayout>
</template>
