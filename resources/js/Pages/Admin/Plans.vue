<script setup>
import { Link, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import PageHeader from '../../Components/PageHeader.vue';
import PanelLayout from '../../Layouts/PanelLayout.vue';
import { money } from '../../lib/format.js';
import { useI18n } from '../../lib/i18n';

defineProps({
    plans: { type: Array, default: () => [] },
});

const { t } = useI18n();
const page = usePage();
const flashSuccess = computed(() => page.props.flash?.success);

/* Boş limit = sınırsız (`Plan::limitFor()` sözleşmesi). */
const form = useForm({ name: '', price_monthly: '', currency: 'TRY', max_products: '', max_channels: '' });
const submit = () => form.post('/admin/plans', { preserveScroll: true, onSuccess: () => form.reset() });

const limit = (n) => (n === null || n === undefined ? t('Sınırsız') : n);
</script>

<template>
    <PanelLayout>
        <PageHeader :section="t('Yönetim')" :title="t('Planlar')" :description="t('Özel planlar müşteriye görünmez ve satın alınamaz; yalnız müşteri sayfasından atanır.')">
            <template #actions>
                <Link href="/admin" class="rounded-md border border-stone-300 px-4 py-2 text-sm text-stone-700 transition hover:bg-stone-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring">{{ t('Platform özeti') }}</Link>
            </template>
        </PageHeader>

        <div v-if="flashSuccess" class="mt-2 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900" role="status">{{ flashSuccess }}</div>

        <div class="mt-4 overflow-x-auto rounded-lg border border-stone-200 bg-white">
            <table class="w-full min-w-[620px] text-sm">
                <thead class="bg-stone-50 text-left text-xs text-stone-500">
                    <tr>
                        <th class="px-4 py-2 font-medium">{{ t('Plan') }}</th>
                        <th class="px-4 py-2 text-right font-medium">{{ t('Aylık') }}</th>
                        <th class="px-4 py-2 text-right font-medium">{{ t('Ürün sınırı') }}</th>
                        <th class="px-4 py-2 text-right font-medium">{{ t('Kanal sınırı') }}</th>
                        <th class="px-4 py-2 text-right font-medium">{{ t('Müşteri') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-stone-100">
                    <tr v-for="p in plans" :key="p.code">
                        <td class="px-4 py-2">
                            <span class="font-medium text-stone-900">{{ p.name }}</span>
                            <span v-if="!p.isPublic" class="ml-1 rounded bg-amber-100 px-1.5 py-0.5 text-xs text-amber-900">{{ t('Özel') }}</span>
                            <div class="font-mono text-xs text-stone-500">{{ p.code }}</div>
                        </td>
                        <td class="px-4 py-2 text-right tabular-nums">{{ Number(p.price) > 0 ? money(p.price, p.currency) : t('Ücretsiz') }}</td>
                        <td class="px-4 py-2 text-right tabular-nums">{{ limit(p.maxProducts) }}</td>
                        <td class="px-4 py-2 text-right tabular-nums">{{ limit(p.maxChannels) }}</td>
                        <td class="px-4 py-2 text-right tabular-nums">{{ p.tenants }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <section class="mt-6 rounded-lg border border-stone-200 bg-white p-5">
            <h2 class="text-sm font-semibold text-stone-900">{{ t('Müşteriye özel plan oluştur') }}</h2>
            <form class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-5 lg:items-end" @submit.prevent="submit">
                <div class="lg:col-span-2">
                    <label for="p-name" class="block text-xs font-medium text-stone-600">{{ t('Plan adı') }}</label>
                    <input id="p-name" v-model="form.name" required maxlength="80" class="mt-1 w-full rounded-md border border-stone-300 px-3 py-2 text-sm" :placeholder="t('Ör. Konfor Halı özel')" />
                    <p v-if="form.errors.name" class="mt-1 text-xs text-red-700">{{ form.errors.name }}</p>
                </div>
                <div>
                    <label for="p-price" class="block text-xs font-medium text-stone-600">{{ t('Aylık fiyat') }}</label>
                    <div class="mt-1 flex">
                        <input id="p-price" v-model="form.price_monthly" required type="number" min="0" step="0.01" class="w-full rounded-l-md border border-stone-300 px-3 py-2 text-sm" />
                        <label for="p-cur" class="sr-only">{{ t('Para birimi') }}</label>
                        <select id="p-cur" v-model="form.currency" class="rounded-r-md border border-l-0 border-stone-300 px-2 text-sm">
                            <option>TRY</option><option>EUR</option><option>USD</option>
                        </select>
                    </div>
                    <p v-if="form.errors.price_monthly" class="mt-1 text-xs text-red-700">{{ form.errors.price_monthly }}</p>
                </div>
                <div>
                    <label for="p-prod" class="block text-xs font-medium text-stone-600">{{ t('Ürün sınırı (boş = sınırsız)') }}</label>
                    <input id="p-prod" v-model="form.max_products" type="number" min="1" class="mt-1 w-full rounded-md border border-stone-300 px-3 py-2 text-sm" />
                </div>
                <div>
                    <label for="p-chan" class="block text-xs font-medium text-stone-600">{{ t('Kanal sınırı (boş = sınırsız)') }}</label>
                    <input id="p-chan" v-model="form.max_channels" type="number" min="1" class="mt-1 w-full rounded-md border border-stone-300 px-3 py-2 text-sm" />
                </div>
                <div class="sm:col-span-2 lg:col-span-5">
                    <button type="submit" :disabled="form.processing" class="rounded-md bg-stone-900 px-4 py-2 text-sm text-white hover:bg-stone-700 disabled:opacity-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring">{{ t('Planı oluştur') }}</button>
                </div>
            </form>
        </section>
    </PanelLayout>
</template>
