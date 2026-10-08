<script setup>
import { Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import PageHeader from '../../Components/PageHeader.vue';
import PanelLayout from '../../Layouts/PanelLayout.vue';
import { intlLocale, useI18n } from '../../lib/i18n';

const props = defineProps({
    search: { type: String, default: '' },
    tenants: { type: Object, required: true },
});

const { t } = useI18n();
const q = ref(props.search);

const submit = () => router.get('/admin/tenants', q.value ? { q: q.value } : {}, { preserveState: true, replace: true });
const go = (page) => router.get('/admin/tenants', { ...(q.value ? { q: q.value } : {}), page }, { preserveState: true });

const date = (iso) => (iso ? new Date(iso).toLocaleDateString(intlLocale(), { day: 'numeric', month: 'short', year: 'numeric' }) : '—');
</script>

<template>
    <PanelLayout>
        <PageHeader :section="t('Yönetim')" :title="t('Müşteriler')" :description="t(':count müşteri hesabı', { count: tenants.total })">
            <template #actions>
                <Link href="/admin" class="rounded-md border border-stone-300 px-4 py-2 text-sm text-stone-700 transition hover:bg-stone-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring">{{ t('Platform özeti') }}</Link>
            </template>
        </PageHeader>

        <form class="mt-2 flex gap-2" role="search" @submit.prevent="submit">
            <label for="admin-q" class="sr-only">{{ t('Ad ya da e-posta ara') }}</label>
            <input id="admin-q" v-model="q" type="search" :placeholder="t('Ad ya da e-posta ara')" class="w-full max-w-sm rounded-md border border-stone-300 px-3 py-2 text-sm" />
            <button type="submit" class="rounded-md bg-stone-900 px-4 py-2 text-sm text-white hover:bg-stone-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring">{{ t('Ara') }}</button>
        </form>

        <div class="mt-4 overflow-x-auto rounded-lg border border-stone-200 bg-white">
            <table class="w-full min-w-[760px] text-sm">
                <thead class="bg-stone-50 text-left text-xs text-stone-500">
                    <tr>
                        <th class="px-4 py-2 font-medium">{{ t('Müşteri') }}</th>
                        <th class="px-4 py-2 font-medium">{{ t('Plan') }}</th>
                        <th class="px-4 py-2 text-right font-medium">{{ t('Kanal') }}</th>
                        <th class="px-4 py-2 text-right font-medium">{{ t('Ürün') }}</th>
                        <th class="px-4 py-2 text-right font-medium">{{ t('Sipariş (30 gün)') }}</th>
                        <th class="px-4 py-2 font-medium">{{ t('Kayıt') }}</th>
                        <th class="px-4 py-2 font-medium">{{ t('Son giriş') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-stone-100">
                    <tr v-if="!tenants.data.length">
                        <td colspan="7" class="px-4 py-6 text-center text-stone-500">{{ t('Eşleşen müşteri yok.') }}</td>
                    </tr>
                    <tr v-for="row in tenants.data" :key="row.id" class="hover:bg-stone-50">
                        <td class="px-4 py-2">
                            <Link :href="`/admin/tenants/${row.id}`" class="font-medium text-stone-900 underline-offset-2 hover:underline">{{ row.name }}</Link>
                            <div class="text-xs text-stone-500">{{ row.ownerEmail }}</div>
                        </td>
                        <td class="px-4 py-2 text-stone-700">{{ row.plan ?? t('Ücretsiz') }}</td>
                        <td class="px-4 py-2 text-right tabular-nums">{{ row.channels }}</td>
                        <td class="px-4 py-2 text-right tabular-nums">{{ row.products }}</td>
                        <td class="px-4 py-2 text-right tabular-nums">{{ row.orders30 }}</td>
                        <td class="px-4 py-2 text-stone-700">{{ date(row.createdAt) }}</td>
                        <td class="px-4 py-2 text-stone-700">{{ date(row.lastLoginAt) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <nav v-if="tenants.lastPage > 1" class="mt-4 flex items-center justify-between text-sm" :aria-label="t('Sayfalar')">
            <button type="button" class="rounded-md border border-stone-300 px-3 py-1.5 disabled:opacity-40" :disabled="tenants.currentPage <= 1" @click="go(tenants.currentPage - 1)">{{ t('Önceki') }}</button>
            <span class="text-stone-600">{{ tenants.currentPage }} / {{ tenants.lastPage }}</span>
            <button type="button" class="rounded-md border border-stone-300 px-3 py-1.5 disabled:opacity-40" :disabled="tenants.currentPage >= tenants.lastPage" @click="go(tenants.currentPage + 1)">{{ t('Sonraki') }}</button>
        </nav>
    </PanelLayout>
</template>
