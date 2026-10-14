<script setup>
import { Link, router } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted } from 'vue';
import PageHeader from '../../Components/PageHeader.vue';
import PanelLayout from '../../Layouts/PanelLayout.vue';
import { intlLocale, k, useI18n } from '../../lib/i18n';

const props = defineProps({
    source: { type: Object, required: true },
    run: { type: Object, required: true },
    kind: { type: String, default: '' },
    kinds: { type: Object, default: () => ({}) },
    changes: { type: Object, required: true },
});

const { t } = useI18n();

// Özet: "500 güncellendi" değil — gerçekte ne değişti.
const countLabels = [
    ['records', k('Akıştaki kayıt')],
    ['created_products', k('Yeni ürün')],
    ['created_variants', k('Yeni varyant')],
    ['matched', k('Mevcut ürünle eşleşti')],
    ['stock_changed', k('Stok değişti')],
    ['price_changed', k('Fiyat değişti')],
    ['cost_changed', k('Alış fiyatı değişti')],
    ['unchanged', k('Değişmedi')],
    ['skipped', k('Atlanan alan')],
    ['unmatched', k('Eşleşmedi')],
    ['ambiguous', k('Belirsiz eşleşme')],
    ['conflict', k('Çakışma')],
    ['duplicate', k('Tekrarlanan kimlik')],
    ['invalid', k('Kimliksiz kayıt')],
    ['quota', k('Ürün sınırı')],
    ['missing', k('Akışta artık yok')],
    ['errors', k('Hata')],
];

const kindLabels = {
    created_product: k('Yeni ürün'),
    created_variant: k('Yeni varyant'),
    matched: k('Eşleşti'),
    stock: k('Stok'),
    price: k('Fiyat'),
    cost: k('Alış fiyatı'),
    skipped: k('Atlandı'),
    unmatched: k('Eşleşmedi'),
    ambiguous: k('Belirsiz'),
    conflict: k('Çakışma'),
    duplicate: k('Tekrar'),
    invalid: k('Kimliksiz'),
    quota: k('Ürün sınırı'),
    missing: k('Akışta yok'),
    error: k('Hata'),
};

const statuses = {
    running: k('Çalışıyor'),
    complete: k('Tamamlandı'),
    partial: k('Kısmen'),
    fetch_failed: k('İndirilemedi'),
    parse_failed: k('XML okunamadı'),
    validation_failed: k('Eşleme hatalı'),
    failed: k('Yarıda kaldı'),
};

const isPreview = computed(() => props.run.mode === 'preview');
const running = computed(() => props.run.status === 'running');

let timer = null;

onMounted(() => {
    if (running.value) {
        timer = setInterval(() => router.reload({ preserveScroll: true, onFinish: () => {
            if (props.run.status !== 'running') clearInterval(timer);
        } }), 3000);
    }
});

onUnmounted(() => clearInterval(timer));

function when(iso) {
    return iso ? new Date(iso).toLocaleString(intlLocale(), { dateStyle: 'short', timeStyle: 'medium' }) : '—';
}

function filter(kind) {
    router.get(`/suppliers/${props.source.id}/runs/${props.run.id}`, kind ? { kind } : {}, { preserveScroll: true });
}
</script>

<template>
    <PanelLayout>
        <PageHeader
            :section="source.name"
            :title="isPreview ? t('Önizleme') : t('Uygulama')"
            :description="isPreview ? t('Önizleme hiçbir şeyi değiştirmedi. Aşağıdakiler uygularsan olacaklar.') : t('Bu turda kataloğunda yapılan değişiklikler.')"
        />

        <div class="mt-6 flex flex-wrap items-center gap-3 text-sm">
            <span class="rounded border border-stone-300 px-2 py-0.5">{{ t(statuses[run.status] ?? run.status) }}</span>
            <span v-if="run.reason" class="text-stone-700">{{ run.reason }}</span>
            <span class="text-stone-500">{{ when(run.startedAt) }}</span>
            <span v-if="run.durationMs !== null" class="text-stone-500">· {{ (run.durationMs / 1000).toFixed(1) }} sn</span>
            <Link :href="`/suppliers/${source.id}`" class="ml-auto underline">{{ t('Kaynağa dön') }}</Link>
        </div>
        <p v-if="run.errorDetail" class="mt-3 rounded border border-red-200 bg-red-50 px-3 py-2 font-mono text-xs text-red-900">{{ run.errorDetail }}</p>
        <p v-if="running" class="mt-3 text-sm text-sky-800">{{ t('Tur sürüyor, sayfa kendiliğinden yenilenir…') }}</p>

        <dl class="mt-6 grid max-w-5xl grid-cols-2 gap-3 sm:grid-cols-4">
            <div v-for="[key, label] in countLabels" v-show="key === 'records' || run.counts[key] > 0" :key="key" class="rounded-md border border-stone-200 p-3">
                <dt class="text-xs text-stone-600">{{ t(label) }}</dt>
                <dd class="text-xl font-semibold tabular-nums">{{ run.counts[key] }}</dd>
            </div>
        </dl>

        <div class="mt-8 flex flex-wrap gap-2 text-sm">
            <button type="button" class="rounded-full border px-3 py-1" :class="kind === '' ? 'border-stone-900 bg-stone-900 text-white' : 'border-stone-300'" @click="filter('')">{{ t('Hepsi') }}</button>
            <button v-for="(total, name) in kinds" :key="name" type="button" class="rounded-full border px-3 py-1" :class="kind === name ? 'border-stone-900 bg-stone-900 text-white' : 'border-stone-300'" @click="filter(name)">
                {{ t(kindLabels[name] ?? name) }} ({{ total }})
            </button>
        </div>

        <div class="mt-4 overflow-x-auto rounded-lg border border-stone-200">
            <table class="min-w-full divide-y divide-stone-200 text-sm">
                <thead class="bg-stone-50 text-left text-xs text-stone-600">
                    <tr>
                        <th class="px-4 py-2 font-medium">{{ t('Tedarikçi kodu') }}</th>
                        <th class="px-4 py-2 font-medium">{{ t('Ne oldu') }}</th>
                        <th class="px-4 py-2 font-medium">{{ t('Önce') }}</th>
                        <th class="px-4 py-2 font-medium">{{ t('Sonra') }}</th>
                        <th class="px-4 py-2 font-medium">{{ t('Neden') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-stone-100 bg-white">
                    <tr v-for="change in changes.data" :key="change.id">
                        <td class="px-4 py-2 font-mono text-xs">{{ change.sourceKey ?? '—' }}</td>
                        <td class="px-4 py-2">{{ t(kindLabels[change.kind] ?? change.kind) }}</td>
                        <td class="px-4 py-2 tabular-nums">{{ change.before ?? '' }}</td>
                        <td class="px-4 py-2 tabular-nums">{{ change.after ?? '' }}</td>
                        <td class="px-4 py-2 text-stone-600">{{ change.reason ?? '' }}</td>
                    </tr>
                    <tr v-if="changes.data.length === 0">
                        <td colspan="5" class="px-4 py-6 text-center text-stone-500">{{ t('Değişiklik yok.') }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-if="changes.links?.length > 3" class="mt-4 flex flex-wrap gap-1 text-sm">
            <template v-for="link in changes.links" :key="link.label">
                <Link v-if="link.url" :href="link.url" preserve-scroll class="rounded border px-2 py-1" :class="link.active ? 'border-stone-900' : 'border-stone-200'" v-html="link.label" />
            </template>
        </div>
    </PanelLayout>
</template>
