<script setup>
import { Link, router } from '@inertiajs/vue3';
import PageHeader from '../../Components/PageHeader.vue';
import PanelLayout from '../../Layouts/PanelLayout.vue';
import { intlLocale, useI18n } from '../../lib/i18n';

// Site sprint 1 · 34pazar.com/iletisim formundan gelen mesajlar (en yeni üstte).
// "Posta gitmedi" = kayıt var ama info@34devs.com'a gönderim başarısız oldu.
defineProps({
    messages: { type: Object, required: true },
});

const { t } = useI18n();

const go = (page) => router.get('/admin/contact-messages', { page }, { preserveState: true });
const date = (iso) => new Date(iso).toLocaleString(intlLocale(), { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
</script>

<template>
    <PanelLayout>
        <PageHeader :section="t('Yönetim')" :title="t('İletişim mesajları')" :description="t(':count mesaj', { count: messages.total })">
            <template #actions>
                <Link href="/admin" class="rounded-md border border-stone-300 px-4 py-2 text-sm text-stone-700 transition hover:bg-stone-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring">{{ t('Platform özeti') }}</Link>
            </template>
        </PageHeader>

        <p v-if="!messages.data.length" class="mt-4 rounded-lg border border-stone-200 bg-white px-4 py-6 text-center text-sm text-stone-500">{{ t('Henüz mesaj yok.') }}</p>

        <ul v-else class="mt-4 divide-y divide-stone-200 rounded-lg border border-stone-200 bg-white">
            <li v-for="row in messages.data" :key="row.id" class="px-4 py-4">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <p class="text-sm">
                        <span class="font-medium text-stone-900">{{ row.name }}</span>
                        <a :href="`mailto:${row.email}`" class="ml-2 text-stone-600 underline-offset-2 hover:underline">{{ row.email }}</a>
                        <span v-if="row.phone" class="ml-2 text-stone-600">{{ row.phone }}</span>
                    </p>
                    <p class="text-xs text-stone-500">{{ date(row.createdAt) }}</p>
                </div>
                <p class="mt-1 text-xs text-stone-600">
                    {{ row.topic }}<template v-if="row.service"> · {{ row.service }}</template>
                    <span v-if="!row.mailed" class="ml-2 font-medium text-red-700">{{ t('Posta gitmedi') }}</span>
                </p>
                <p class="mt-2 whitespace-pre-line text-sm text-stone-800">{{ row.message }}</p>
            </li>
        </ul>

        <nav v-if="messages.lastPage > 1" class="mt-4 flex items-center justify-between text-sm" :aria-label="t('Sayfalar')">
            <button type="button" class="rounded-md border border-stone-300 px-3 py-1.5 disabled:opacity-40" :disabled="messages.currentPage <= 1" @click="go(messages.currentPage - 1)">{{ t('Önceki') }}</button>
            <span class="text-stone-600">{{ messages.currentPage }} / {{ messages.lastPage }}</span>
            <button type="button" class="rounded-md border border-stone-300 px-3 py-1.5 disabled:opacity-40" :disabled="messages.currentPage >= messages.lastPage" @click="go(messages.currentPage + 1)">{{ t('Sonraki') }}</button>
        </nav>
    </PanelLayout>
</template>
