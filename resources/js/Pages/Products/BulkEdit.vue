<script setup>
import { Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import PageHeader from '../../Components/PageHeader.vue';
import WriteGuard from '../../Components/WriteGuard.vue';
import PanelLayout from '../../Layouts/PanelLayout.vue';
import { intlLocale, k, useI18n } from '../../lib/i18n';

defineProps({
    imports: { type: Array, default: () => [] },
    maxRows: { type: Number, required: true },
    maxKilobytes: { type: Number, required: true },
});

const { t } = useI18n();
const form = useForm({ file: null });
const fileInput = ref(null);

function submit() {
    form.post('/products/bulk-edit', {
        forceFormData: true,
        onSuccess: () => {
            form.reset('file');
            if (fileInput.value) fileInput.value.value = '';
        },
    });
}

const statuses = {
    parsed: k('Önizlemede'),
    rejected: k('Reddedildi'),
    applying: k('Uygulanıyor'),
    applied: k('Uygulandı'),
    failed: k('Başarısız'),
};

function when(iso) {
    return iso ? new Date(iso).toLocaleString(intlLocale(), { dateStyle: 'short', timeStyle: 'short' }) : '—';
}
</script>

<template>
    <PanelLayout>
        <PageHeader
            :section="t('Ürünler')"
            :title="t('Excel ile toplu fiyat ve stok')"
            :description="t('Listeyi indir, Yeni stok ve Yeni fiyat sütunlarını doldur, geri yükle. Yüklemek hiçbir şeyi değiştirmez; önce ne olacağını görürsün.')"
        />
        <WriteGuard permission="catalog.manage">

            <section class="mt-8 max-w-3xl space-y-6">
                <div class="rounded-lg border border-stone-200 p-5">
                    <h2 class="text-base font-semibold text-stone-900">{{ t('1. Listeyi indir') }}</h2>
                    <p class="mt-1 text-sm text-stone-600">{{ t('Bütün ürünlerin, şu anki stok ve fiyatlarıyla. 34Pazar ID sütununu değiştirme.') }}</p>
                    <a href="/products/bulk-edit/export" class="mt-3 inline-flex rounded-md border border-stone-300 px-4 py-2 text-sm hover:bg-stone-50">{{ t('Excel indir') }}</a>
                </div>

                <div class="rounded-lg border border-stone-200 p-5">
                    <h2 class="text-base font-semibold text-stone-900">{{ t('2. Düzenle') }}</h2>
                    <ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-stone-700">
                        <li>{{ t('Yeni stok = rafta olacak sayı. 5 yazarsan stok 5 olur, 5 eklenmez.') }}</li>
                        <li>{{ t('Boş bıraktığın hücre değişmez.') }}</li>
                        <li>{{ t('Formül değil, sayı yaz.') }}</li>
                        <li>{{ t('İndirdikten sonra satış olduysa o satır uygulanmaz; listeyi yeniden indir.') }}</li>
                    </ul>
                </div>

                <form class="rounded-lg border border-stone-200 p-5" @submit.prevent="submit">
                    <h2 class="text-base font-semibold text-stone-900">{{ t('3. Yükle ve önizle') }}</h2>
                    <p class="mt-1 text-xs text-stone-500">{{ t('Yalnız .xlsx, en çok :kb KB ve :rows satır.', { kb: maxKilobytes, rows: maxRows }) }}</p>
                    <input ref="fileInput" type="file" accept=".xlsx" class="mt-3 block text-sm" @change="form.file = $event.target.files[0] ?? null">
                    <p v-if="form.errors.file" class="mt-1 text-sm text-red-700">{{ form.errors.file }}</p>
                    <button type="submit" :disabled="!form.file || form.processing" class="mt-3 rounded-md bg-stone-900 px-4 py-2 text-sm font-medium text-white hover:bg-stone-700 disabled:opacity-50">
                        {{ form.processing ? t('Okunuyor…') : t('Yükle ve önizle') }}
                    </button>
                </form>
            </section>

            <section v-if="imports.length" class="mt-10 max-w-3xl">
                <h2 class="text-sm font-medium text-stone-900">{{ t('Son yüklemeler') }}</h2>
                <ul class="mt-2 divide-y divide-stone-100 rounded-lg border border-stone-200 text-sm">
                    <li v-for="item in imports" :key="item.id" class="flex flex-wrap items-center gap-3 px-4 py-2">
                        <Link :href="`/products/bulk-edit/${item.id}`" class="font-medium underline">{{ item.filename }}</Link>
                        <span class="text-stone-600">{{ t(statuses[item.status] ?? item.status) }}</span>
                        <span v-if="item.reason" class="text-red-800">{{ item.reason }}</span>
                        <span class="ml-auto tabular-nums text-stone-500">{{ when(item.createdAt) }}</span>
                    </li>
                </ul>
            </section>
        </WriteGuard>
    </PanelLayout>
</template>
