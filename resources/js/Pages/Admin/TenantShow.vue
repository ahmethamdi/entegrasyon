<script setup>
import { Link, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import PageHeader from '../../Components/PageHeader.vue';
import StatCard from '../../Components/StatCard.vue';
import PanelLayout from '../../Layouts/PanelLayout.vue';
import { money } from '../../lib/format.js';
import { intlLocale, useI18n } from '../../lib/i18n';

const props = defineProps({
    tenant: { type: Object, required: true },
    users: { type: Array, default: () => [] },
    connections: { type: Array, default: () => [] },
    subscriptions: { type: Array, default: () => [] },
    usage: { type: Object, required: true },
    plans: { type: Array, default: () => [] },
});

const { t } = useI18n();
const page = usePage();
const flashSuccess = computed(() => page.props.flash?.success);

const current = computed(() => props.subscriptions.find((s) => ['active', 'trialing'].includes(s.status)) ?? null);
const currentPlan = computed(() => props.plans.find((p) => p.code === current.value?.plan) ?? null);

const form = useForm({ plan_code: current.value?.plan ?? '', ends_at: '' });
const submit = () => form.post(`/admin/tenants/${props.tenant.id}/plan`, { preserveScroll: true });

const date = (iso) => (iso ? new Date(iso).toLocaleDateString(intlLocale(), { day: 'numeric', month: 'short', year: 'numeric' }) : '—');
const limit = (n) => (n === null || n === undefined ? t('Sınırsız') : n);
const planLabel = (p) => `${p.name} · ${Number(p.price) > 0 ? money(p.price, p.currency) : t('Ücretsiz')}${p.isPublic ? '' : ' · ' + t('Özel')}`;
</script>

<template>
    <PanelLayout>
        <PageHeader :section="t('Yönetim · müşteri')" :title="tenant.name" :description="t('Kayıt: :date', { date: date(tenant.createdAt) })">
            <template #actions>
                <Link href="/admin/tenants" class="rounded-md border border-stone-300 px-4 py-2 text-sm text-stone-700 transition hover:bg-stone-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring">{{ t('Müşteriler') }}</Link>
            </template>
        </PageHeader>

        <div v-if="flashSuccess" class="mt-2 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900" role="status">{{ flashSuccess }}</div>

        <div class="mt-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
            <StatCard :label="t('Plan')" :value="currentPlan?.name ?? t('Ücretsiz')" :hint="current?.endsAt ? t('Bitiş: :date', { date: date(current.endsAt) }) : null" />
            <StatCard :label="t('Ürün')" :value="usage.products" :hint="t('Sınır: :limit', { limit: limit(currentPlan?.maxProducts) })" />
            <StatCard :label="t('Kanal')" :value="usage.channels" :hint="t('Sınır: :limit', { limit: limit(currentPlan?.maxChannels) })" />
            <StatCard :label="t('Sipariş (30 gün)')" :value="usage.orders30" />
        </div>

        <section class="mt-6 rounded-lg border border-stone-200 bg-white p-5">
            <h2 class="text-sm font-semibold text-stone-900">{{ t('Plan ata') }}</h2>
            <p class="mt-1 text-sm text-stone-600">{{ t('Müşteriye özel plan ya da ödemesi fatura ile alınan anlaşmalar için. Ödeme sağlayıcısının aktif aboneliği varsa atanmaz.') }}</p>
            <form class="mt-4 grid gap-3 sm:grid-cols-[1fr_200px_auto] sm:items-end" @submit.prevent="submit">
                <div>
                    <label for="plan" class="block text-xs font-medium text-stone-600">{{ t('Plan') }}</label>
                    <select id="plan" v-model="form.plan_code" required class="mt-1 w-full rounded-md border border-stone-300 px-3 py-2 text-sm">
                        <option value="" disabled>{{ t('Plan seç') }}</option>
                        <option v-for="p in plans" :key="p.code" :value="p.code">{{ planLabel(p) }}</option>
                    </select>
                </div>
                <div>
                    <label for="ends" class="block text-xs font-medium text-stone-600">{{ t('Bitiş tarihi (isteğe bağlı)') }}</label>
                    <input id="ends" v-model="form.ends_at" type="date" class="mt-1 w-full rounded-md border border-stone-300 px-3 py-2 text-sm" />
                </div>
                <button type="submit" :disabled="form.processing || !form.plan_code" class="rounded-md bg-stone-900 px-4 py-2 text-sm text-white hover:bg-stone-700 disabled:opacity-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring">{{ t('Ata') }}</button>
            </form>
            <p v-if="form.errors.plan_code" class="mt-2 text-sm text-red-700" role="alert">{{ form.errors.plan_code }}</p>
            <p v-if="form.errors.ends_at" class="mt-2 text-sm text-red-700" role="alert">{{ form.errors.ends_at }}</p>
        </section>

        <div class="mt-6 grid gap-6 lg:grid-cols-2">
            <section class="rounded-lg border border-stone-200 bg-white p-5">
                <h2 class="text-sm font-semibold text-stone-900">{{ t('Kullanıcılar') }}</h2>
                <ul class="mt-3 divide-y divide-stone-100 text-sm">
                    <li v-for="u in users" :key="u.email" class="py-2">
                        <div class="flex justify-between gap-2">
                            <span class="font-medium text-stone-900">{{ u.name }}</span>
                            <span class="text-xs text-stone-500">{{ u.role }}</span>
                        </div>
                        <div class="text-xs text-stone-600">
                            {{ u.email }}
                            <span v-if="!u.verified" class="ml-1 rounded bg-amber-100 px-1.5 py-0.5 text-amber-900">{{ t('Doğrulanmadı') }}</span>
                            · {{ t('Son giriş: :date', { date: date(u.lastLoginAt) }) }}
                        </div>
                    </li>
                </ul>
            </section>

            <section class="rounded-lg border border-stone-200 bg-white p-5">
                <h2 class="text-sm font-semibold text-stone-900">{{ t('Kanallar') }}</h2>
                <p v-if="!connections.length" class="mt-3 text-sm text-stone-500">{{ t('Henüz bağlı kanal yok.') }}</p>
                <ul v-else class="mt-3 divide-y divide-stone-100 text-sm">
                    <li v-for="(c, i) in connections" :key="i" class="py-2">
                        <div class="flex justify-between gap-2">
                            <span class="font-medium text-stone-900">{{ c.label || c.channel }} <span class="text-xs font-normal text-stone-500">· {{ c.channel }}</span></span>
                            <span :class="c.health === 'healthy' ? 'text-emerald-700' : 'text-red-700'" class="text-xs">{{ c.health === 'healthy' ? t('Sağlıklı') : t('Sorunlu') }} · {{ c.status }}</span>
                        </div>
                        <p v-if="c.lastError" class="mt-1 break-words text-xs text-stone-500">{{ c.lastError }}</p>
                    </li>
                </ul>
            </section>
        </div>

        <section class="mt-6 rounded-lg border border-stone-200 bg-white p-5">
            <h2 class="text-sm font-semibold text-stone-900">{{ t('Abonelik geçmişi') }}</h2>
            <div class="mt-3 overflow-x-auto">
                <table class="w-full min-w-[520px] text-sm">
                    <thead class="text-left text-xs text-stone-500">
                        <tr><th class="py-1 font-medium">{{ t('Plan') }}</th><th class="py-1 font-medium">{{ t('Durum') }}</th><th class="py-1 font-medium">{{ t('Kaynak') }}</th><th class="py-1 font-medium">{{ t('Başlangıç') }}</th><th class="py-1 font-medium">{{ t('Bitiş') }}</th></tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100">
                        <tr v-if="!subscriptions.length"><td colspan="5" class="py-2 text-stone-500">{{ t('Abonelik yok — ücretsiz plan.') }}</td></tr>
                        <tr v-for="(s, i) in subscriptions" :key="i">
                            <td class="py-1.5">{{ s.plan }}</td><td class="py-1.5">{{ s.status }}</td><td class="py-1.5">{{ s.provider ?? '—' }}</td><td class="py-1.5">{{ date(s.startedAt) }}</td><td class="py-1.5">{{ date(s.endsAt) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </PanelLayout>
</template>
